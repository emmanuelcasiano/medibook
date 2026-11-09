<?php

use App\Http\Controllers\Admin\AiUsageController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DoctorController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\TimeOffController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WorkingHourController;
use App\Http\Controllers\Booking\BookingController;
use App\Http\Controllers\Booking\DoctorController as BookingDoctorController;
use App\Http\Controllers\Booking\ServiceController as BookingServiceController;
use App\Http\Controllers\Booking\SlotController;
use App\Http\Controllers\DemoLoginController;
use App\Http\Controllers\Doctor\AppointmentController as DoctorAppointmentController;
use App\Http\Controllers\Doctor\TodayController;
use App\Http\Controllers\Doctor\VisitNoteController;
use App\Http\Controllers\Patient\AppointmentController;
use App\Http\Controllers\Patient\FollowUpBookingController;
use App\Http\Controllers\Patient\IntakeController;
use App\Http\Controllers\Staff\AppointmentController as StaffAppointmentController;
use App\Http\Controllers\Staff\FollowUpController as StaffFollowUpController;
use App\Http\Controllers\Staff\ScheduleController;
use App\Http\Controllers\WelcomeController;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('home');

// Public booking: anyone can look at services, doctors and free times. Login or registration is asked only at the
// confirm step (further down, behind `auth`). These pages only read, so there is no Policy. `scopeBindings` loads `{doctor}` through the
// service in the URL, so a doctor who does not offer it is a 404. The order matters: `any` is checked before `{doctor}`.
// A slot page runs several queries per doctor, so the `booking-pages` limiter (AppServiceProvider) caps each visitor.
Route::middleware('throttle:booking-pages')->prefix('book')->name('booking.')->group(function () {
    Route::get('/', [BookingServiceController::class, 'index'])->name('services');
    Route::get('{service:slug}', [BookingDoctorController::class, 'index'])->name('doctors');
    Route::get('{service:slug}/any', [SlotController::class, 'any'])->name('slots.any');
    Route::get('{service:slug}/doctors/{doctor}', [SlotController::class, 'doctor'])->scopeBindings()->name('slots.doctor');
});

// The one-click demo logins (PROJECT.md F12). `demo-mode` makes the route a 404 unless APP_DEMO is on, `guest` keeps a
// signed-in user from being swapped into another account, and `{role}` is checked by enum binding (any other value is a 404).
Route::post('demo/login/{role}', DemoLoginController::class)
    ->middleware(['demo-mode', 'guest', 'throttle:demo-login'])
    ->name('demo.login');

// Every app route needs a signed-in, verified user. `two-factor` sends staff, doctors and admins to the 2FA
// setup page until it is on. `role:` closes a whole group to other roles; Policies check each action inside it.
Route::middleware(['auth', 'verified', 'two-factor'])->group(function () {
    // There is no dashboard page: each role has its own work screen. The name stays, because Fortify's `home` and
    // the links after login, register and verify point here. The middleware above runs first, so a user who must
    // set up 2FA goes to the security page before this redirect.
    Route::get('dashboard', fn (#[CurrentUser] User $user) => redirect()->route($user->role->homeRoute()))->name('dashboard');

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('users', [UserController::class, 'store'])->middleware('throttle:20,1')->name('users.store');
        Route::patch('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');

        Route::resource('services', ServiceController::class)->except(['show', 'store']);
        Route::post('services', [ServiceController::class, 'store'])->middleware('throttle:20,1')->name('services.store');

        // No create or delete: a doctor row is made with the doctor's account (users.store).
        Route::resource('doctors', DoctorController::class)->only(['index', 'edit', 'update']);

        // The weekly schedule is saved as a whole: the form sends every range and the old ones are replaced.
        Route::get('doctors/{doctor}/working-hours', [WorkingHourController::class, 'edit'])->name('doctors.working-hours.edit');
        Route::put('doctors/{doctor}/working-hours', [WorkingHourController::class, 'update'])->name('doctors.working-hours.update');

        // Time off is added and removed one at a time. `scopeBindings` makes `{timeOff}` load through the doctor
        // in the URL, so another doctor's time off can't be removed from here (404).
        Route::get('doctors/{doctor}/time-offs', [TimeOffController::class, 'index'])->name('doctors.time-offs.index');
        Route::post('doctors/{doctor}/time-offs', [TimeOffController::class, 'store'])->middleware('throttle:20,1')->name('doctors.time-offs.store');
        Route::delete('doctors/{doctor}/time-offs/{timeOff}', [TimeOffController::class, 'destroy'])->scopeBindings()->name('doctors.time-offs.destroy');

        // Read only: the audit log has a list and nothing else, so there is no route that can change a row.
        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

        // Read only too: this month's AI summaries, tokens and cost. One GET, nothing to change.
        Route::get('ai-usage', [AiUsageController::class, 'index'])->name('ai-usage.index');
    });

    // The schedule is for staff, and an admin may look at it (PROJECT.md section 3). The action routes in this
    // group (steps 7.2 to 7.4) are staff only: their Policy methods refuse an admin.
    Route::middleware('role:staff,admin')->prefix('staff')->name('staff.')->group(function () {
        Route::get('/', [ScheduleController::class, 'index'])->name('home');

        // Side panel buttons. Each writes under a row lock, so they are capped per signed-in user.
        Route::post('appointments/{appointment}/check-in', [StaffAppointmentController::class, 'checkIn'])->middleware('throttle:staff-actions')->name('appointments.check-in');
        Route::post('appointments/{appointment}/no-show', [StaffAppointmentController::class, 'noShow'])->middleware('throttle:staff-actions')->name('appointments.no-show');
        Route::post('appointments/{appointment}/cancel', [StaffAppointmentController::class, 'cancel'])->middleware('throttle:staff-actions')->name('appointments.cancel');
        Route::post('appointments/{appointment}/move', [StaffAppointmentController::class, 'move'])->middleware('throttle:staff-actions')->name('appointments.move');

        // Follow-ups a doctor asked for (PROJECT.md section 9). An admin may look at the list, like the schedule. The
        // three buttons are staff only: `{followUp}` is a ULID, and the Policy refuses an admin. Each writes under a
        // row lock, so they share the front-desk limiter.
        Route::get('follow-ups', [StaffFollowUpController::class, 'index'])->name('follow-ups.index');
        Route::post('follow-ups/{followUp}/book', [StaffFollowUpController::class, 'book'])->middleware('throttle:staff-actions')->name('follow-ups.book');
        Route::post('follow-ups/{followUp}/call', [StaffFollowUpController::class, 'call'])->middleware('throttle:staff-actions')->name('follow-ups.call');
        Route::post('follow-ups/{followUp}/remind', [StaffFollowUpController::class, 'remind'])->middleware('throttle:staff-actions')->name('follow-ups.remind');
    });

    // The doctor's own patients only: the Today list is scoped to the signed-in doctor, and `{appointment}` (a ULID)
    // goes through the Policy, which refuses an appointment of another doctor.
    Route::middleware('role:doctor')->prefix('doctor')->name('doctor.')->group(function () {
        Route::get('/', [TodayController::class, 'index'])->name('home');
        Route::get('appointments/{appointment}', [DoctorAppointmentController::class, 'show'])->name('appointments.show');
        Route::post('appointments/{appointment}/summary/review', [DoctorAppointmentController::class, 'review'])->middleware('throttle:doctor-actions')->name('appointments.summary.review');
        Route::post('appointments/{appointment}/complete', [DoctorAppointmentController::class, 'complete'])->middleware('throttle:doctor-actions')->name('appointments.complete');
        // One note per appointment, so there is one URL and PUT: the first save creates it and later saves edit it.
        Route::put('appointments/{appointment}/visit-note', [VisitNoteController::class, 'update'])->middleware('throttle:doctor-actions')->name('appointments.visit-note.update');
    });

    Route::middleware('role:patient')->group(function () {
        // The last step of booking. A guest who picked a time lands here through `auth` and `verified`, which send
        // them to log in, register or verify and then back to this address. Booking is rate limited per patient.
        Route::get('book/{service:slug}/confirm', [BookingController::class, 'confirm'])->middleware('throttle:booking-pages')->name('booking.confirm');
        Route::post('book/{service:slug}/confirm', [BookingController::class, 'store'])->middleware('throttle:booking')->name('booking.store');

        Route::prefix('my')->name('my.')->group(function () {
            Route::get('/', [AppointmentController::class, 'index'])->name('home');

            // `{appointment}` is a ULID, and the Policy lets a patient open or cancel their own booking only.
            Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])->middleware('throttle:booking')->name('appointments.cancel');
            Route::get('appointments/{appointment}/confirmation', [BookingController::class, 'confirmation'])->name('appointments.confirmation');
            // One address for the form: PUT creates the intake the first time and replaces its answers after that.
            Route::get('appointments/{appointment}/intake', [IntakeController::class, 'edit'])->name('appointments.intake');
            Route::put('appointments/{appointment}/intake', [IntakeController::class, 'update'])->middleware('throttle:intake')->name('appointments.intake.update');

            // Booking from a follow-up the doctor asked for. `{followUp}` is a ULID, and the Policy lets only its own
            // patient in while it is open. Same two steps and limiters as the public flow; the service and doctor
            // come from the follow-up, so the address carries only the time.
            Route::get('follow-ups/{followUp}/book', [FollowUpBookingController::class, 'slots'])->middleware('throttle:booking-pages')->name('follow-ups.book');
            Route::get('follow-ups/{followUp}/confirm', [FollowUpBookingController::class, 'confirm'])->middleware('throttle:booking-pages')->name('follow-ups.confirm');
            Route::post('follow-ups/{followUp}/confirm', [FollowUpBookingController::class, 'store'])->middleware('throttle:booking')->name('follow-ups.store');
        });
    });
});

require __DIR__.'/settings.php';
