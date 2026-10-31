<?php

use App\Http\Controllers\Admin\DoctorController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\TimeOffController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WorkingHourController;
use App\Http\Controllers\Booking\BookingController;
use App\Http\Controllers\Booking\DoctorController as BookingDoctorController;
use App\Http\Controllers\Booking\ServiceController as BookingServiceController;
use App\Http\Controllers\Booking\SlotController;
use App\Http\Controllers\Patient\IntakeController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

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

// Every app route needs a signed-in, verified user. `two-factor` sends staff, doctors and admins to the 2FA
// setup page until it is on. `role:` closes a whole group to other roles; Policies check each action inside it.
Route::middleware(['auth', 'verified', 'two-factor'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

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
    });

    // The screens for these groups come on later days (booking Day 6, schedule Day 7, doctor view Day 10).
    // Until then each group has one landing page, so the role gate is real and testable now.
    Route::middleware('role:staff')->prefix('staff')->name('staff.')->group(function () {
        Route::inertia('/', 'role-home', ['title' => 'Schedule', 'note' => 'The day schedule is built on Day 7.'])->name('home');
    });

    Route::middleware('role:doctor')->prefix('doctor')->name('doctor.')->group(function () {
        Route::inertia('/', 'role-home', ['title' => 'Today', 'note' => 'The doctor view is built on Day 10.'])->name('home');
    });

    Route::middleware('role:patient')->group(function () {
        // The last step of booking. A guest who picked a time lands here through `auth` and `verified`, which send
        // them to log in, register or verify and then back to this address. Booking is rate limited per patient.
        Route::get('book/{service:slug}/confirm', [BookingController::class, 'confirm'])->middleware('throttle:booking-pages')->name('booking.confirm');
        Route::post('book/{service:slug}/confirm', [BookingController::class, 'store'])->middleware('throttle:booking')->name('booking.store');

        Route::prefix('my')->name('my.')->group(function () {
            Route::inertia('/', 'role-home', ['title' => 'My appointments', 'note' => '"My appointments" is built in step 6.3.'])->name('home');

            // `{appointment}` is a ULID, and the Policy lets a patient open their own booking only.
            Route::get('appointments/{appointment}/confirmation', [BookingController::class, 'confirmation'])->name('appointments.confirmation');
            Route::get('appointments/{appointment}/intake', [IntakeController::class, 'edit'])->name('appointments.intake');
        });
    });
});

require __DIR__.'/settings.php';
