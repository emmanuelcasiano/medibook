<?php

declare(strict_types=1);

use App\Ai\Evals\IntakeEvalFixtures;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Booking\BookingController;
use App\Http\Controllers\Booking\SlotController;
use App\Http\Controllers\Doctor\AppointmentController as DoctorAppointmentController;
use App\Http\Controllers\Patient\AppointmentController as PatientAppointmentController;
use App\Http\Controllers\Staff\AppointmentController as StaffAppointmentController;
use Database\Seeders\DemoScheduleSeeder;

arch()->preset()->php();

// `array_rand` picks demo rows in the seeder. It is not used for tokens, codes or anything secret.
arch()->preset()->security()
    ->ignoring(DemoScheduleSeeder::class);

// - These controllers name their methods after the use case (`checkIn`, `cancel`, `move`, `deactivate`...),
//   each one a thin call to one Action. Wayfinder and the React pages import them by name, so they stay.
// - `IntakeEvalFixtures` reads `SaveIntakeRequest::rules()`, so the eval cases follow the real form's limits
//   and cannot drift from them. It only reads the rules, it never handles a request.
arch()->preset()->laravel()
    ->ignoring([
        IntakeEvalFixtures::class,
        AdminUserController::class,
        BookingController::class,
        SlotController::class,
        DoctorAppointmentController::class,
        PatientAppointmentController::class,
        StaffAppointmentController::class,
    ]);

arch('controllers do not query the DB facade')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB');

arch('actions are final')
    ->expect('App\Actions')
    ->toBeFinal();

arch('strict types everywhere')
    ->expect('App')
    ->toUseStrictTypes();
