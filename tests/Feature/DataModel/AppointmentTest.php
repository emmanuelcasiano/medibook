<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

// The `active_key` generated column must agree with AppointmentStatus::active(). If someone edits one
// list and not the other, this test fails, and the unique index would silently stop guarding the right rows.
it('sets active_key in MySQL to match the PHP active statuses', function (AppointmentStatus $status) {
    $appointment = Appointment::factory()->create(['status' => $status]);

    expect($appointment->fresh()->active_key)->toBe($status->isActive() ? 1 : null);
})->with(AppointmentStatus::cases());

it('lets cancelled and no-show rows share a slot with a new booking', function () {
    $doctor = Doctor::factory()->create();
    $startsAt = CarbonImmutable::parse('2026-11-02 01:00:00');

    // Same doctor, same start time. Only the active one counts for the unique index.
    Appointment::factory()->for($doctor)->cancelled()->create(['starts_at' => $startsAt]);
    Appointment::factory()->for($doctor)->noShow()->create(['starts_at' => $startsAt]);
    Appointment::factory()->for($doctor)->cancelled()->create(['starts_at' => $startsAt]);
    $booked = Appointment::factory()->for($doctor)->booked()->create(['starts_at' => $startsAt]);

    expect($booked->exists)->toBeTrue()
        ->and(Appointment::query()->where('doctor_id', $doctor->id)->count())->toBe(4);
});

it('filters to active appointments with the active scope', function () {
    $doctor = Doctor::factory()->create();

    foreach (AppointmentStatus::cases() as $index => $status) {
        Appointment::factory()->for($doctor)->create([
            'status' => $status,
            'starts_at' => CarbonImmutable::parse('2026-11-02 01:00:00')->addHours($index),
        ]);
    }

    expect(Appointment::query()->active()->count())->toBe(3);
});

it('fills a ULID and routes by it, not by the numeric id', function () {
    $appointment = Appointment::factory()->create();

    expect($appointment->ulid)->toHaveLength(26)
        ->and($appointment->id)->toBeInt()
        ->and($appointment->getRouteKeyName())->toBe('ulid')
        ->and($appointment->getRouteKey())->toBe($appointment->ulid)
        ->and(new Appointment()->resolveRouteBinding($appointment->ulid)?->is($appointment))->toBeTrue();
});

it('does not resolve an appointment from its numeric id', function () {
    $appointment = Appointment::factory()->create();

    // A guessed /appointments/1 must be a 404, so IDs can't be counted up.
    expect(fn () => (new Appointment)->resolveRouteBinding((string) $appointment->id))
        ->toThrow(ModelNotFoundException::class);
});

it('works out end and buffer from the service when using startingIn', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:30'));
    $service = Service::factory()->lasting(30, 10)->create();

    $appointment = Appointment::factory()->for($service)->startingIn(hours: 5)->create()->fresh();

    expect($appointment->starts_at->format('Y-m-d H:i:s'))->toBe('2026-11-02 13:00:00')
        ->and($appointment->ends_at->format('Y-m-d H:i:s'))->toBe('2026-11-02 13:30:00')
        ->and($appointment->blocked_until->format('Y-m-d H:i:s'))->toBe('2026-11-02 13:40:00');
});

it('casts dates to immutable Carbon and status to the enum', function () {
    $appointment = Appointment::factory()->create()->fresh();

    expect($appointment->starts_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($appointment->blocked_until)->toBeInstanceOf(CarbonImmutable::class)
        ->and($appointment->status)->toBe(AppointmentStatus::Booked);
});
