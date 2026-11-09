<?php

declare(strict_types=1);

use App\Actions\Booking\BookAppointment;
use App\Actions\Booking\MoveAppointment;
use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// These tests call the Action directly. The staff route and the picker are tested in SchedulePanelMoveTest. The
// Action checks AppointmentPolicy::move itself, and a refused move writes nothing: the old booking stays `booked`.
// "Now" is Friday 30 October 2026, 09:00 in Manila, so the earliest offered start is 11:00 (2 hour lead time).
// The service lasts 30 minutes with a 15 minute buffer, so a booking at 14:00 blocks the doctor until 14:45.

function moveAs(User $actor, Appointment $appointment, Doctor $doctor, string $clinicTime): Appointment
{
    return app(MoveAppointment::class)->handle(
        $actor,
        $appointment,
        $doctor,
        CarbonImmutable::parse($clinicTime, 'Asia/Manila')->utc(),
    );
}

/**
 * The validation errors of a move that must fail.
 *
 * @return array<string, list<string>>
 */
function moveErrors(Closure $attempt): array
{
    try {
        $attempt();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The move was expected to fail, but it went through.');
}

/** A booking for $patient with $doctor at a clinic time. $state is an AppointmentFactory state. */
function bookingFor(User $patient, Doctor $doctor, Service $service, string $clinicTime, string $state = 'booked'): Appointment
{
    return Appointment::factory()
        ->for($doctor)->for($service)->for($patient, 'patient')
        ->{$state}()
        ->create(['starts_at' => CarbonImmutable::parse($clinicTime, 'Asia/Manila')->utc()]);
}

const MOVE_TIME_TAKEN = 'This time is no longer free. Please pick another slot.';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));
    $this->service = Service::factory()->lasting(30, 15)->create();
    // Friday is weekday 5. A lunch break from 12:00 to 13:00.
    $this->doctor = doctorWorking($this->service, [[5, '08:00', '12:00'], [5, '13:00', '17:00']]);
    $this->otherDoctor = doctorWorking($this->service, [[5, '08:00', '12:00'], [5, '13:00', '17:00']]);
    $this->patient = User::factory()->patient()->create();
    $this->staff = User::factory()->staff()->create();
    $this->appointment = bookingFor($this->patient, $this->doctor, $this->service, '2026-10-30 14:00');

    // The old row as it is in the database, and no new row, to prove a refused move wrote nothing.
    $this->unchanged = function (): void {
        $fresh = $this->appointment->fresh();

        expect($fresh->status)->toBe(AppointmentStatus::Booked)
            ->and($fresh->cancelled_at)->toBeNull()
            ->and($fresh->cancelled_by)->toBeNull()
            ->and($fresh->cancel_reason)->toBeNull()
            ->and(Appointment::query()->count())->toBe($this->bookingsBefore);
    };
    $this->bookingsBefore = Appointment::query()->count();
});

// Happy path

it('moves a booking to another time with the same doctor', function () {
    $moved = moveAs($this->staff, $this->appointment, $this->doctor, '2026-10-30 15:00');

    expect($moved->status)->toBe(AppointmentStatus::Booked)
        ->and($moved->doctor_id)->toBe($this->doctor->id)
        ->and($moved->patient_id)->toBe($this->patient->id)
        ->and($moved->service_id)->toBe($this->service->id)
        ->and($moved->starts_at)->toEqual(CarbonImmutable::parse('2026-10-30 15:00', 'Asia/Manila'))
        ->and($moved->ends_at)->toEqual(CarbonImmutable::parse('2026-10-30 15:30', 'Asia/Manila'))
        ->and($moved->blocked_until)->toEqual(CarbonImmutable::parse('2026-10-30 15:45', 'Asia/Manila'))
        ->and($moved->id)->not->toBe($this->appointment->id)
        ->and($moved->reminder_sent_at)->toBeNull();

    $old = $this->appointment->fresh();

    expect($old->status)->toBe(AppointmentStatus::Cancelled)
        ->and($old->cancelled_by)->toBe($this->staff->id)
        ->and($old->cancel_reason)->toBe('Moved to 30 Oct 2026, 3:00 PM.')
        ->and($old->active_key)->toBeNull();
});

it('moves a booking to another doctor who offers the service', function () {
    $moved = moveAs($this->staff, $this->appointment, $this->otherDoctor, '2026-10-30 14:00');

    expect($moved->doctor_id)->toBe($this->otherDoctor->id)
        ->and($moved->patient_id)->toBe($this->patient->id)
        ->and($moved->status)->toBe(AppointmentStatus::Booked)
        ->and($this->appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled);
});

it('lets a booking move 15 minutes into its own old time', function () {
    // Cancelling first frees the old time, so the new one does not clash with the booking it replaces.
    $moved = moveAs($this->staff, $this->appointment, $this->doctor, '2026-10-30 14:15');

    expect($moved->starts_at)->toEqual(CarbonImmutable::parse('2026-10-30 14:15', 'Asia/Manila'))
        ->and($this->appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled);
});

it('moves a patient who already has the most upcoming bookings', function () {
    bookingFor($this->patient, $this->doctor, $this->service, '2026-10-30 11:00');
    bookingFor($this->patient, $this->doctor, $this->service, '2026-10-30 13:00');

    expect(Appointment::query()->where('patient_id', $this->patient->id)->active()->count())
        ->toBe(BookAppointment::MAX_UPCOMING_PER_PATIENT);

    $moved = moveAs($this->staff, $this->appointment, $this->otherDoctor, '2026-10-30 16:00');

    expect($moved->status)->toBe(AppointmentStatus::Booked)
        ->and(Appointment::query()->where('patient_id', $this->patient->id)->active()->count())
        ->toBe(BookAppointment::MAX_UPCOMING_PER_PATIENT);
});

it('frees the old time for another patient', function () {
    moveAs($this->staff, $this->appointment, $this->doctor, '2026-10-30 15:00');

    $other = app(BookAppointment::class)->handle(
        User::factory()->patient()->create(),
        $this->doctor,
        $this->service,
        CarbonImmutable::parse('2026-10-30 14:00', 'Asia/Manila'),
    );

    expect($other->status)->toBe(AppointmentStatus::Booked);
});

// The 2 hour lead time counts from now (09:00), so 11:00 is the first start the finder offers.
it('offers a move exactly at the lead time and refuses the step before it', function (string $clinicTime, bool $allowed) {
    if ($allowed) {
        expect(moveAs($this->staff, $this->appointment, $this->doctor, $clinicTime)->status)->toBe(AppointmentStatus::Booked);

        return;
    }

    expect(moveErrors(fn () => moveAs($this->staff, $this->appointment, $this->doctor, $clinicTime)))
        ->toBe(['starts_at' => [MOVE_TIME_TAKEN]]);
    ($this->unchanged)();
})->with([
    'exactly 2 hours from now' => ['2026-10-30 11:00', true],
    'one step before that' => ['2026-10-30 10:45', false],
]);

// Guards: the move goes through the same three layers as a patient's booking. One process can't truly race itself,
// so each layer is checked on its own (testing.md section 8).

// A deadlock needs two transactions that take the same locks in opposite orders. BookAppointment takes the patient and
// then the doctor, and CancelAppointment takes the appointment, so the move must take them in exactly that order.
it('takes its row locks in a fixed order: the booking, then the patient, then the doctor', function () {
    $locks = [];
    $base = DB::transactionLevel();
    DB::listen(function ($query) use (&$locks): void {
        if (! str_contains($query->sql, 'for update')) {
            return;
        }

        foreach (['appointments', 'users', 'doctors'] as $table) {
            if (str_contains($query->sql, "from `{$table}`")) {
                $locks[] = [$table, DB::transactionLevel()];
            }
        }
    });

    moveAs($this->staff, $this->appointment, $this->doctor, '2026-10-30 15:00');

    // Every lock is taken inside the move's transaction, so none is let go before the new booking is saved.
    expect(array_column($locks, 0))->toBe(['appointments', 'users', 'doctors'])
        ->and(collect($locks)->every(fn (array $lock): bool => $lock[1] > $base))->toBeTrue();
});

it('leaves the new booking guarded by the database unique index', function () {
    $moved = moveAs($this->staff, $this->appointment, $this->doctor, '2026-10-30 15:00');

    // The query builder skips the Action and the model, so only the unique index (doctor_id, starts_at, active_key)
    // is left to refuse a second active row at the same start.
    $insert = fn (string $status) => DB::table('appointments')->insert([
        'ulid' => (string) Str::ulid(),
        'patient_id' => User::factory()->patient()->create()->id,
        'doctor_id' => $moved->doctor_id,
        'service_id' => $moved->service_id,
        'starts_at' => $moved->starts_at->utc()->format('Y-m-d H:i:s'),
        'ends_at' => $moved->ends_at->utc()->format('Y-m-d H:i:s'),
        'blocked_until' => $moved->blocked_until->utc()->format('Y-m-d H:i:s'),
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $insert('booked'))->toThrow(UniqueConstraintViolationException::class);

    // The old row is cancelled, so it has no active_key and does not count against the index.
    expect($this->appointment->fresh()->active_key)->toBeNull();
    $insert('cancelled');
});

// Denied access

it('turns away everyone but staff and changes nothing', function (Role $role) {
    $actor = userWithRole($role);
    $this->bookingsBefore = Appointment::query()->count();

    expect(fn () => moveAs($actor, $this->appointment, $this->doctor, '2026-10-30 15:00'))
        ->toThrow(AuthorizationException::class);

    ($this->unchanged)();
})->with([
    'admin' => Role::Admin,
    'nurse' => Role::Nurse,
    'doctor' => Role::Doctor,
    'patient' => Role::Patient,
]);

it('turns away the patient who owns the booking', function () {
    // The Policy lets a patient cancel their own booking. Moving is staff only.
    expect(fn () => moveAs($this->patient, $this->appointment, $this->doctor, '2026-10-30 15:00'))
        ->toThrow(AuthorizationException::class);

    ($this->unchanged)();
});

// Refused moves keep the old booking

it('keeps the old booking when the new time is taken', function () {
    bookingFor(User::factory()->patient()->create(), $this->doctor, $this->service, '2026-10-30 15:00');
    $this->bookingsBefore = Appointment::query()->count();

    $errors = moveErrors(fn () => moveAs($this->staff, $this->appointment, $this->doctor, '2026-10-30 15:00'));

    expect($errors)->toBe(['starts_at' => [MOVE_TIME_TAKEN]]);
    ($this->unchanged)();
});

it('keeps the old booking when the new time is inside another booking\'s buffer', function () {
    // A booking at 15:00 blocks the doctor until 15:45, and a visit at 15:30 would start inside that.
    bookingFor(User::factory()->patient()->create(), $this->doctor, $this->service, '2026-10-30 15:00');
    $this->bookingsBefore = Appointment::query()->count();

    $errors = moveErrors(fn () => moveAs($this->staff, $this->appointment, $this->doctor, '2026-10-30 15:30'));

    expect($errors)->toBe(['starts_at' => [MOVE_TIME_TAKEN]]);
    ($this->unchanged)();
});

it('allows the time right after another booking\'s buffer ends', function () {
    bookingFor(User::factory()->patient()->create(), $this->doctor, $this->service, '2026-10-30 15:00');

    expect(moveAs($this->staff, $this->appointment, $this->doctor, '2026-10-30 15:45')->status)->toBe(AppointmentStatus::Booked);
});

it('keeps the old booking when the time is not one the doctor offers', function (string $clinicTime) {
    $errors = moveErrors(fn () => moveAs($this->staff, $this->appointment, $this->doctor, $clinicTime));

    expect($errors)->toBe(['starts_at' => [MOVE_TIME_TAKEN]]);
    ($this->unchanged)();
})->with([
    'during the lunch break' => '2026-10-30 12:15',
    'before opening' => '2026-10-30 07:30',
    'a visit that would run past closing' => '2026-10-30 16:45',
    'inside the 2 hour lead time' => '2026-10-30 10:00',
    'in the past' => '2026-10-30 08:00',
    'on a day with no working hours' => '2026-10-31 10:00',
    'beyond the 30 day window' => '2026-12-04 10:00',
    'not on the 15 minute step' => '2026-10-30 15:10',
]);

it('keeps the old booking when the other doctor does not offer the service', function () {
    $doctor = doctorWorking(Service::factory()->create(), [[5, '08:00', '17:00']]);

    $errors = moveErrors(fn () => moveAs($this->staff, $this->appointment, $doctor, '2026-10-30 15:00'));

    expect($errors)->toHaveKey('starts_at');
    ($this->unchanged)();
});

it('keeps the old booking when the other doctor is switched off', function () {
    $this->otherDoctor->user->forceFill(['is_active' => false])->save();

    $errors = moveErrors(fn () => moveAs($this->staff, $this->appointment, $this->otherDoctor, '2026-10-30 15:00'));

    expect($errors)->toBe(['doctor_id' => ['This doctor is not taking bookings.']]);
    ($this->unchanged)();
});

it('keeps the old booking when the new time clashes with another of the patient\'s bookings', function () {
    // The patient is with the other doctor at 15:00. They can't be in two places at once.
    bookingFor($this->patient, $this->otherDoctor, $this->service, '2026-10-30 15:00');
    $this->bookingsBefore = Appointment::query()->count();

    $errors = moveErrors(fn () => moveAs($this->staff, $this->appointment, $this->doctor, '2026-10-30 15:00'));

    expect($errors)->toBe(['starts_at' => ['You already have an appointment at this time. Please pick another slot.']]);
    ($this->unchanged)();
});

it('refuses a move to where the booking already is', function () {
    $errors = moveErrors(fn () => moveAs($this->staff, $this->appointment, $this->doctor, '2026-10-30 14:00'));

    expect($errors)->toBe(['starts_at' => ['The appointment is already at this time. Please pick another slot.']]);
    ($this->unchanged)();
});

it('refuses a booking that is not booked', function (string $state) {
    $appointment = bookingFor($this->patient, $this->doctor, $this->service, '2026-10-30 11:00', $state);
    $this->bookingsBefore = Appointment::query()->count();

    $errors = moveErrors(fn () => moveAs($this->staff, $appointment, $this->doctor, '2026-10-30 15:00'));

    expect($errors)->toBe(['appointment' => ['This appointment can no longer be moved.']])
        ->and($appointment->fresh()->status)->toBe($appointment->status)
        ->and($appointment->fresh()->cancel_reason)->toBeNull()
        ->and(Appointment::query()->count())->toBe($this->bookingsBefore);
})->with(['checkedIn', 'completed', 'noShow', 'cancelled']);

it('refuses a stale copy of a booking that was checked in since it was loaded', function () {
    // The model in the caller's hand says `booked`, but another request checked the patient in since.
    $stale = $this->appointment;
    Appointment::query()->whereKey($stale->id)->update(['status' => AppointmentStatus::CheckedIn]);
    $this->bookingsBefore = Appointment::query()->count();

    $errors = moveErrors(fn () => moveAs($this->staff, $stale, $this->doctor, '2026-10-30 15:00'));

    expect($errors)->toHaveKey('appointment')
        ->and($this->appointment->fresh()->status)->toBe(AppointmentStatus::CheckedIn)
        ->and(Appointment::query()->count())->toBe($this->bookingsBefore);
});
