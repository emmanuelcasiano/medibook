<?php

declare(strict_types=1);

use App\Actions\Booking\BookAppointment;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// There is no route or policy yet, so there is no denied-access test. A booking that clashes is refused and
// writes nothing, which is the "denied" case here. The booking screen (step 6.2) calls this Action behind its own
// Form Request and Policy, and gets its own 403 tests.
// Monday 2 November 2026 is the day used below. A 30-minute visit with a 15-minute buffer blocks the doctor for
// 45 minutes, so a booking at 09:00 holds the doctor until 09:45.
// slotDay(), doctorWorking() and bookingAt() are shared helpers in tests/Pest.php.

function bookAt(User $patient, Doctor $doctor, Service $service, string $startsAt): Appointment
{
    return app(BookAppointment::class)->handle($patient, $doctor, $service, slotDay($startsAt));
}

/**
 * The validation errors of a booking that must fail.
 *
 * @return array<string, list<string>>
 */
function bookingErrors(Closure $attempt): array
{
    try {
        $attempt();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The booking was expected to fail, but it went through.');
}

const SLOT_TAKEN = 'This time is no longer free. Please pick another slot.';

beforeEach(function () {
    $this->service = Service::factory()->lasting(30, 15)->create();
    $this->doctor = doctorWorking($this->service, []);
    $this->patient = User::factory()->patient()->create();
});

it('books a free slot and works out the end and the buffer from the service', function () {
    $appointment = bookAt($this->patient, $this->doctor, $this->service, '2026-11-02 09:00');

    expect($appointment->status)->toBe(AppointmentStatus::Booked)
        ->and($appointment->patient_id)->toBe($this->patient->id)
        ->and($appointment->doctor_id)->toBe($this->doctor->id)
        ->and($appointment->service_id)->toBe($this->service->id);

    // Manila is UTC+8: 09:00 in Manila is stored as 01:00 UTC.
    $stored = Appointment::query()->findOrFail($appointment->id);
    expect($stored->starts_at->toDateTimeString())->toBe('2026-11-02 01:00:00')
        ->and($stored->ends_at->toDateTimeString())->toBe('2026-11-02 01:30:00')
        ->and($stored->blocked_until->toDateTimeString())->toBe('2026-11-02 01:45:00')
        ->and($stored->active_key)->toBe(1);
});

it('refuses the same slot twice and writes nothing for the second try', function () {
    bookAt($this->patient, $this->doctor, $this->service, '2026-11-02 09:00');
    $other = User::factory()->patient()->create();

    $errors = bookingErrors(fn () => bookAt($other, $this->doctor, $this->service, '2026-11-02 09:00'));

    expect($errors)->toBe(['starts_at' => [SLOT_TAKEN]])
        ->and(Appointment::query()->count())->toBe(1)
        ->and(Appointment::query()->where('patient_id', $other->id)->exists())->toBeFalse();
});

it('refuses a start time that overlaps a booking or its buffer, and allows one that touches it', function (string $time, bool $works) {
    bookAt($this->patient, $this->doctor, $this->service, '2026-11-02 09:00');
    $other = User::factory()->patient()->create();

    if ($works) {
        bookAt($other, $this->doctor, $this->service, "2026-11-02 {$time}");

        expect(Appointment::query()->count())->toBe(2);

        return;
    }

    expect(bookingErrors(fn () => bookAt($other, $this->doctor, $this->service, "2026-11-02 {$time}")))
        ->toBe(['starts_at' => [SLOT_TAKEN]]);
})->with([
    'starts inside the first visit' => ['09:15', false],
    'starts inside the first buffer' => ['09:30', false],
    'ends inside the first booking' => ['08:45', false],
    'its own buffer runs into the first booking' => ['08:30', false],
    'starts right after the buffer ends' => ['09:45', true],
    'its buffer ends right when the first booking starts' => ['08:15', true],
    'much later' => ['14:00', true],
]);

it('lets two doctors have a patient at the same time', function () {
    $secondDoctor = doctorWorking($this->service, []);
    bookAt($this->patient, $this->doctor, $this->service, '2026-11-02 09:00');

    $appointment = bookAt(User::factory()->patient()->create(), $secondDoctor, $this->service, '2026-11-02 09:00');

    expect($appointment->doctor_id)->toBe($secondDoctor->id);
});

it('counts a booking with a longer buffer from its own service', function () {
    $longBuffer = Service::factory()->lasting(30, 60)->create();
    $this->doctor->services()->attach($longBuffer);
    bookAt($this->patient, $this->doctor, $longBuffer, '2026-11-02 09:00');
    $other = User::factory()->patient()->create();

    // The first booking holds the doctor until 10:30, because its own service has a 60-minute buffer.
    expect(bookingErrors(fn () => bookAt($other, $this->doctor, $this->service, '2026-11-02 10:15')))
        ->toBe(['starts_at' => [SLOT_TAKEN]]);

    expect(bookAt($other, $this->doctor, $this->service, '2026-11-02 10:30')->exists)->toBeTrue();
});

it('frees the slot when the earlier booking is cancelled or a no-show', function (string $state) {
    bookingAt($this->doctor, $this->service, '2026-11-02 09:00', $state);

    $appointment = bookAt($this->patient, $this->doctor, $this->service, '2026-11-02 09:00');

    expect($appointment->status)->toBe(AppointmentStatus::Booked)
        ->and(Appointment::query()->active()->count())->toBe(1)
        ->and(Appointment::query()->count())->toBe(2);
})->with(['cancelled', 'noShow']);

it('keeps the slot taken for every active status', function (string $state) {
    bookingAt($this->doctor, $this->service, '2026-11-02 09:00', $state);

    expect(bookingErrors(fn () => bookAt($this->patient, $this->doctor, $this->service, '2026-11-02 09:00')))
        ->toBe(['starts_at' => [SLOT_TAKEN]]);
})->with(['booked', 'checkedIn', 'completed']);

it('refuses a service that is switched off', function () {
    $this->service->update(['is_active' => false]);

    expect(bookingErrors(fn () => bookAt($this->patient, $this->doctor, $this->service, '2026-11-02 09:00')))
        ->toHaveKey('service_id')
        ->and(Appointment::query()->count())->toBe(0);
});

it('refuses a service that was switched off after the caller loaded it', function () {
    // The caller holds a stale copy that still says the service is active.
    $stale = Service::query()->findOrFail($this->service->id);
    Service::query()->whereKey($this->service->id)->update(['is_active' => false]);

    expect(bookingErrors(fn () => bookAt($this->patient, $this->doctor, $stale, '2026-11-02 09:00')))
        ->toHaveKey('service_id')
        ->and(Appointment::query()->count())->toBe(0);
});

it('refuses a doctor who does not offer the service', function () {
    $otherService = Service::factory()->lasting(30, 15)->create();

    expect(bookingErrors(fn () => bookAt($this->patient, $this->doctor, $otherService, '2026-11-02 09:00')))
        ->toHaveKey('doctor_id')
        ->and(Appointment::query()->count())->toBe(0);
});

it('reads the start time as the instant it is, whatever timezone it comes in', function () {
    $appointment = app(BookAppointment::class)->handle(
        $this->patient,
        $this->doctor,
        $this->service,
        slotDay('2026-11-02 09:00')->setTimezone('America/New_York'),
    );

    expect(Appointment::query()->findOrFail($appointment->id)->starts_at->toDateTimeString())->toBe('2026-11-02 01:00:00');

    // The overlap check uses the same instant, so a New York caller can't slip a clash past it.
    expect(bookingErrors(fn () => app(BookAppointment::class)->handle(
        User::factory()->patient()->create(),
        $this->doctor,
        $this->service,
        slotDay('2026-11-02 09:15')->setTimezone('Europe/Paris'),
    )))->toBe(['starts_at' => [SLOT_TAKEN]]);
});

// Guard 1: one PHP process can't truly race itself, so this checks the lock is asked for, on the doctor's row,
// while the transaction is open. A lock taken outside the transaction would be released at once and protect nothing.
it('locks the doctor row inside the transaction before it checks for a clash', function () {
    $locks = [];
    DB::listen(function ($query) use (&$locks): void {
        if (str_contains($query->sql, 'from `doctors`') && str_contains($query->sql, 'for update')) {
            $locks[] = DB::transactionLevel();
        }
    });

    bookAt($this->patient, $this->doctor, $this->service, '2026-11-02 09:00');

    // The test itself runs in one transaction (RefreshDatabase), so the Action's transaction is a level deeper.
    expect($locks)->toHaveCount(1)
        ->and($locks[0])->toBeGreaterThan(1);
});

// Guard 3: the database refuses the same start twice even if the code above had a bug. The query builder skips
// the Action and the model, so only the unique index (doctor_id, starts_at, active_key) is left to say no.
it('refuses a duplicate active row inserted straight into the database', function () {
    $first = bookAt($this->patient, $this->doctor, $this->service, '2026-11-02 09:00');

    $insert = fn (string $status) => DB::table('appointments')->insert([
        'ulid' => (string) Str::ulid(),
        'patient_id' => User::factory()->patient()->create()->id,
        'doctor_id' => $first->doctor_id,
        'service_id' => $first->service_id,
        'starts_at' => '2026-11-02 01:00:00',
        'ends_at' => '2026-11-02 01:30:00',
        'blocked_until' => '2026-11-02 01:45:00',
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $insert('booked'))->toThrow(UniqueConstraintViolationException::class)
        ->and(Appointment::query()->count())->toBe(1);

    // A cancelled row has no active_key, so the same insert is allowed. That is what frees a cancelled slot.
    $insert('cancelled');

    expect(Appointment::query()->count())->toBe(2);
});
