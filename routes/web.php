<?php

use App\Http\Controllers\Admin\DoctorController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\TimeOffController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WorkingHourController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

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

    Route::middleware('role:patient')->prefix('my')->name('my.')->group(function () {
        Route::inertia('/', 'role-home', ['title' => 'My appointments', 'note' => 'Booking and "My appointments" are built on Day 6.'])->name('home');
    });
});

require __DIR__.'/settings.php';
