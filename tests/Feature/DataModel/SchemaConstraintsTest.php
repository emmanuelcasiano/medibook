<?php

declare(strict_types=1);

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Intake;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

// These prove the database itself refuses bad data, even if the PHP code has a bug.

it('refuses the same service twice for one doctor', function () {
    $doctor = Doctor::factory()->create();
    $service = Service::factory()->create();

    $doctor->services()->attach($service);

    expect(fn () => $doctor->services()->attach($service))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('refuses a second doctor profile for the same user', function () {
    $user = User::factory()->doctor()->create();
    Doctor::factory()->create(['user_id' => $user->id]);

    expect(fn () => Doctor::factory()->create(['user_id' => $user->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('refuses a second intake for the same appointment', function () {
    $appointment = Appointment::factory()->create();
    Intake::factory()->for($appointment)->create();

    expect(fn () => Intake::factory()->for($appointment)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('refuses two services with the same slug', function () {
    Service::factory()->create(['slug' => 'check-up']);

    expect(fn () => Service::factory()->create(['slug' => 'check-up']))
        ->toThrow(UniqueConstraintViolationException::class);
});
