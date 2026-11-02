<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoScheduleSeeder;
use Database\Seeders\DoctorSeeder;
use Database\Seeders\ServiceSeeder;
use Database\Seeders\UserSeeder;

// A Monday, 11:00 in the clinic (03:00 UTC). Frozen, so "past", "under way" and "later" are the same on every run.
const SEEDER_NOW = '2026-11-02 11:00:00';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(SEEDER_NOW, 'Asia/Manila'));

    $this->seed(ServiceSeeder::class);
    $this->seed(UserSeeder::class);
    $this->seed(DoctorSeeder::class);
});

it('gives each booking a status that fits the time of day', function () {
    $this->seed(DemoScheduleSeeder::class);

    $now = CarbonImmutable::now();
    $past = 0;
    $later = 0;

    foreach (Appointment::query()->get() as $appointment) {
        if ($appointment->ends_at <= $now) {
            $past++;

            expect($appointment->status)->toBeIn([
                AppointmentStatus::Completed,
                AppointmentStatus::NoShow,
                AppointmentStatus::Cancelled,
            ]);
        } elseif ($appointment->starts_at <= $now) {
            expect($appointment->status)->toBe(AppointmentStatus::CheckedIn);
        } else {
            $later++;

            expect($appointment->status)->toBeIn([AppointmentStatus::Booked, AppointmentStatus::Cancelled]);
        }

        // A visit that was attended has a check-in time; one that was not, has none.
        expect($appointment->checked_in_at !== null)->toBe(
            in_array($appointment->status, [AppointmentStatus::CheckedIn, AppointmentStatus::Completed], true),
        );
    }

    // Guard against a vacuous pass: the day has both finished visits and ones still to come.
    expect($past)->toBeGreaterThan(0)->and($later)->toBeGreaterThan(0);
});

it('books only inside working hours, counted in clinic time', function () {
    $this->seed(DemoScheduleSeeder::class);

    $doctors = Doctor::query()->with('workingHours')->get()->keyBy('id');

    expect(Appointment::query()->count())->toBeGreaterThan(0);

    foreach (Appointment::query()->get() as $appointment) {
        // The hours are clinic time and the bookings are stored in UTC, so convert before comparing.
        $starts = $appointment->starts_at->setTimezone('Asia/Manila');
        $blockedUntil = $appointment->blocked_until->setTimezone('Asia/Manila');

        $inside = $doctors[$appointment->doctor_id]->workingHours
            ->where('weekday', $starts->dayOfWeek)
            ->contains(fn ($range): bool => $starts->format('H:i:s') >= $range->starts_at
                && $blockedUntil->format('H:i:s') <= $range->ends_at
                && $starts->isSameDay($blockedUntil));

        expect($inside)->toBeTrue();
    }
});

it('leaves a doctor with no hours that day without bookings', function () {
    $this->seed(DemoScheduleSeeder::class);

    // Dr. Reyes works Tuesday to Saturday, so this Monday (the frozen day) he is off.
    $reyes = User::query()->where('email', 'doctor.reyes@medibook.test')->firstOrFail()->doctor;

    $monday = CarbonImmutable::now('Asia/Manila')->startOfDay();

    expect(Appointment::query()
        ->where('doctor_id', $reyes->id)
        ->whereBetween('starts_at', [$monday->utc(), $monday->endOfDay()->utc()])
        ->count())->toBe(0)
        ->and(Appointment::query()->where('doctor_id', $reyes->id)->count())->toBeGreaterThan(0);
});

it('never books the demo patient', function () {
    $this->seed(DemoScheduleSeeder::class);

    $demo = User::query()->where('email', 'patient@medibook.test')->firstOrFail();

    expect(Appointment::query()->where('patient_id', $demo->id)->count())->toBe(0);
});

it('leaves a day that already has a booking alone', function () {
    $doctor = Doctor::query()->whereHas('user', fn ($query) => $query->where('email', UserSeeder::DEMO_DOCTOR_EMAIL))->firstOrFail();
    $patient = User::query()->where('role', Role::Patient)->firstOrFail();
    $service = Service::query()->firstOrFail();

    // A booking made by hand, for today at 14:00 clinic time.
    $mine = Appointment::factory()->booked()->create([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'service_id' => $service->id,
        'starts_at' => CarbonImmutable::parse('2026-11-02 14:00', 'Asia/Manila')->utc(),
    ]);

    $this->seed(DemoScheduleSeeder::class);

    $monday = CarbonImmutable::now('Asia/Manila')->startOfDay();
    $today = Appointment::query()
        ->where('doctor_id', $doctor->id)
        ->whereBetween('starts_at', [$monday->utc(), $monday->endOfDay()->utc()])
        ->pluck('id');

    // Only the hand-made booking is on that day, but the doctor's later days were filled.
    expect($today->all())->toBe([$mine->id])
        ->and(Appointment::query()->where('doctor_id', $doctor->id)->count())->toBeGreaterThan(1);
});
