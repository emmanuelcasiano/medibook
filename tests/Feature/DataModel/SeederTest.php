<?php

declare(strict_types=1);

use App\Actions\Booking\BookAppointment;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoScheduleSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

it('seeds the clinic with the expected counts', function () {
    expect(Service::query()->count())->toBe(6)
        // 4 from DoctorSeeder and 4 more from DemoScheduleSeeder.
        ->and(Doctor::query()->count())->toBe(8)
        // 30 fake patients plus the demo patient.
        ->and(User::query()->where('role', Role::Patient)->count())->toBe(31);
});

it('seeds one demo login per role', function (string $email, Role $role) {
    expect(User::query()->where('email', $email)->firstOrFail()->role)->toBe($role);
})->with([
    ['patient@medibook.test', Role::Patient],
    ['staff@medibook.test', Role::Staff],
    ['doctor@medibook.test', Role::Doctor],
    ['nurse@medibook.test', Role::Nurse],
    ['admin@medibook.test', Role::Admin],
]);

it('gives the demo doctor a doctor profile', function () {
    $doctor = User::query()->where('email', 'doctor@medibook.test')->firstOrFail()->doctor;

    expect($doctor)->not->toBeNull();
});

it('gives every doctor services and hours with a lunch break', function () {
    $doctors = Doctor::query()->with(['services', 'workingHours'])->get();

    expect($doctors)->toHaveCount(8);

    foreach ($doctors as $doctor) {
        expect($doctor->services)->not->toBeEmpty();

        // Two ranges on every working weekday, and the first ends before the second starts.
        foreach ($doctor->workingHours->groupBy('weekday') as $ranges) {
            $ranges = $ranges->sortBy('starts_at')->values();

            expect($ranges)->toHaveCount(2)
                ->and($ranges[0]->ends_at < $ranges[1]->starts_at)->toBeTrue();
        }
    }
});

it('gives most doctors bookings that never overlap, buffer included', function () {
    $withBookings = 0;

    foreach (Doctor::query()->get() as $doctor) {
        $bookings = Appointment::query()->where('doctor_id', $doctor->id)->active()->orderBy('starts_at')->get();

        if ($bookings->isNotEmpty()) {
            $withBookings++;
        }

        foreach ($bookings->skip(1) as $index => $booking) {
            // `skip(1)` keeps the original keys, so the booking before this one is at $index - 1.
            expect($booking->starts_at >= $bookings[$index - 1]->blocked_until)->toBeTrue();
        }
    }

    // The demo needs a busy schedule. Weekends and days off can leave a doctor empty, so not all 8 are required.
    expect($withBookings)->toBeGreaterThanOrEqual(4);
});

it('keeps every patient inside the upcoming-booking limit', function () {
    $upcoming = Appointment::query()
        ->active()
        ->where('starts_at', '>', CarbonImmutable::now())
        ->get()
        ->countBy('patient_id');

    expect($upcoming->max() ?? 0)->toBeLessThanOrEqual(BookAppointment::MAX_UPCOMING_PER_PATIENT);
});

it('never double books a patient', function () {
    $byPatient = Appointment::query()->active()->orderBy('starts_at')->get()->groupBy('patient_id');

    foreach ($byPatient as $bookings) {
        foreach ($bookings->skip(1) as $index => $booking) {
            expect($booking->starts_at >= $bookings[$index - 1]->ends_at)->toBeTrue();
        }
    }
});

it('adds nothing the second time it runs', function () {
    $doctors = Doctor::query()->count();
    $appointments = Appointment::query()->count();

    $this->seed(DemoScheduleSeeder::class);

    expect(Doctor::query()->count())->toBe($doctors)
        ->and(Appointment::query()->count())->toBe($appointments);
});

it('offers every service through at least one doctor', function () {
    $unoffered = Service::query()->whereDoesntHave('doctors')->count();

    expect($unoffered)->toBe(0);
});
