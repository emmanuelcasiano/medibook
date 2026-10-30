<?php

declare(strict_types=1);

use App\Actions\Booking\BookAppointment;
use App\Actions\Booking\CancelAppointment;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// There is no cancel route yet (the patient button is step 6.3, the staff panel is step 7.3), so the denied-access
// test calls the Action, which checks AppointmentPolicy::cancel itself. A denied call writes nothing.
// "Now" is fixed at Friday 30 October 2026, 09:00 in Manila. The 2 hour limit counts from the start of the visit.

function cancelAs(User $actor, Appointment $appointment, ?string $reason = null): Appointment
{
    return app(CancelAppointment::class)->handle($actor, $appointment, $reason);
}

/**
 * The validation errors of a cancel that must fail.
 *
 * @return array<string, list<string>>
 */
function cancelErrors(Closure $attempt): array
{
    try {
        $attempt();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The cancel was expected to fail, but it went through.');
}

const CANCEL_TOO_LATE = 'Appointments can only be cancelled up to 2 hours before they start. Please call the clinic.';
const CANCEL_NOT_BOOKED = 'This appointment can no longer be cancelled.';
const CANCEL_NEEDS_REASON = 'Please give a reason for cancelling this appointment.';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));
    $this->service = Service::factory()->lasting(30, 15)->create();
    $this->doctor = doctorWorking($this->service, []);
    $this->patient = User::factory()->patient()->create();
    $this->staff = User::factory()->staff()->create();

    // The patient's own booking, starting this far from now. $state is an AppointmentFactory state.
    $this->bookingIn = fn (int $hours, int $minutes = 0, string $state = 'booked'): Appointment => Appointment::factory()
        ->for($this->doctor)->for($this->service)->for($this->patient, 'patient')
        ->{$state}()->startingIn(hours: $hours, minutes: $minutes)->create();

    // The row as it is in the database, to prove a refused cancel wrote nothing.
    $this->unchanged = function (Appointment $appointment): void {
        $fresh = $appointment->fresh();

        expect($fresh->status)->toBe($appointment->status)
            ->and($fresh->cancelled_at)->toEqual($appointment->cancelled_at)
            ->and($fresh->cancelled_by)->toBe($appointment->cancelled_by)
            ->and($fresh->cancel_reason)->toBe($appointment->cancel_reason);
    };
});

// Happy path

it('lets a patient cancel their own booking 3 hours before the start', function () {
    $appointment = ($this->bookingIn)(3);

    $cancelled = cancelAs($this->patient, $appointment);

    expect($cancelled->status)->toBe(AppointmentStatus::Cancelled)
        ->and($cancelled->cancelled_at)->toEqual(now())
        ->and($cancelled->cancelled_by)->toBe($this->patient->id)
        ->and($cancelled->cancel_reason)->toBeNull();

    $this->assertDatabaseHas('appointments', [
        'id' => $appointment->id,
        'status' => 'cancelled',
        'cancelled_by' => $this->patient->id,
        'active_key' => null,
    ]);
});

it('lets a patient cancel exactly 2 hours before the start', function () {
    $appointment = ($this->bookingIn)(2);

    expect(cancelAs($this->patient, $appointment)->status)->toBe(AppointmentStatus::Cancelled);
});

it('saves the patient\'s reason without the spaces around it', function () {
    $appointment = ($this->bookingIn)(5);

    expect(cancelAs($this->patient, $appointment, "  I'm feeling better.  ")->cancel_reason)->toBe("I'm feeling better.");
});

it('treats a blank reason from a patient as no reason', function () {
    $appointment = ($this->bookingIn)(5);

    expect(cancelAs($this->patient, $appointment, '   ')->cancel_reason)->toBeNull();
});

it('lets staff cancel any patient\'s booking with a reason and records who did it', function () {
    $appointment = ($this->bookingIn)(24);

    $cancelled = cancelAs($this->staff, $appointment, 'Doctor is out sick.');

    expect($cancelled->status)->toBe(AppointmentStatus::Cancelled)
        ->and($cancelled->cancelled_by)->toBe($this->staff->id)
        ->and($cancelled->cancel_reason)->toBe('Doctor is out sick.')
        ->and($cancelled->cancelled_at)->toEqual(now());
});

it('lets staff cancel inside the 2 hour limit and after the start', function (int $hours, int $minutes) {
    $appointment = ($this->bookingIn)($hours, $minutes);

    expect(cancelAs($this->staff, $appointment, 'Patient called to cancel.')->status)->toBe(AppointmentStatus::Cancelled);
})->with([
    '1 hour before' => [1, 0],
    '1 minute before' => [0, 1],
    'already started' => [0, -30],
]);

it('frees the slot for another patient', function () {
    $appointment = ($this->bookingIn)(3);
    $other = User::factory()->patient()->create();

    cancelAs($this->patient, $appointment);

    $rebooked = app(BookAppointment::class)->handle($other, $this->doctor, $this->service, $appointment->starts_at);

    expect($rebooked->status)->toBe(AppointmentStatus::Booked)
        ->and($rebooked->starts_at)->toEqual($appointment->starts_at);
});

// Validation

it('refuses a patient inside the 2 hour limit and changes nothing', function (int $hours, int $minutes) {
    $appointment = ($this->bookingIn)($hours, $minutes);

    expect(cancelErrors(fn () => cancelAs($this->patient, $appointment)))->toBe(['appointment' => [CANCEL_TOO_LATE]]);

    ($this->unchanged)($appointment);
    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Booked);
})->with([
    '1 hour before' => [1, 0],
    '1 minute short of 2 hours' => [1, 59],
    'at the start' => [0, 0],
    'already started' => [0, -30],
]);

it('makes staff give a reason', function (?string $reason) {
    $appointment = ($this->bookingIn)(5);

    expect(cancelErrors(fn () => cancelAs($this->staff, $appointment, $reason)))->toBe(['reason' => [CANCEL_NEEDS_REASON]]);

    ($this->unchanged)($appointment);
    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Booked);
})->with([
    'none' => [null],
    'empty' => [''],
    'only spaces' => ['   '],
]);

it('refuses a reason longer than the column', function (string $role) {
    $appointment = ($this->bookingIn)(5);
    $actor = $role === 'staff' ? $this->staff : $this->patient;

    expect(cancelErrors(fn () => cancelAs($actor, $appointment, str_repeat('a', 256))))
        ->toBe(['reason' => ['The reason may not be longer than 255 characters.']]);

    ($this->unchanged)($appointment);
})->with(['patient', 'staff']);

it('accepts a reason of exactly 255 characters', function () {
    $appointment = ($this->bookingIn)(5);

    expect(cancelAs($this->staff, $appointment, str_repeat('é', 255))->cancel_reason)->toHaveLength(255);
});

it('only cancels a booked appointment', function (string $state) {
    $appointment = ($this->bookingIn)(5, 0, $state);

    expect(cancelErrors(fn () => cancelAs($this->staff, $appointment, 'Too late to cancel.')))
        ->toBe(['appointment' => [CANCEL_NOT_BOOKED]]);

    ($this->unchanged)($appointment);
})->with(['cancelled', 'checkedIn', 'completed', 'noShow']);

it('keeps who cancelled first when a booking is cancelled twice', function () {
    $appointment = ($this->bookingIn)(5);
    cancelAs($this->patient, $appointment, 'Changed my mind.');

    expect(cancelErrors(fn () => cancelAs($this->staff, $appointment, 'Second try.')))->toBe(['appointment' => [CANCEL_NOT_BOOKED]]);

    expect($appointment->fresh())
        ->cancelled_by->toBe($this->patient->id)
        ->cancel_reason->toBe('Changed my mind.');
});

// The model in the caller's hand may be old. The check must use the row as it is in the database.
it('reads the status again, so an old copy of the booking cannot cancel it', function () {
    $stale = ($this->bookingIn)(5);
    Appointment::query()->whereKey($stale->id)->update(['status' => AppointmentStatus::CheckedIn]);

    expect($stale->status)->toBe(AppointmentStatus::Booked)
        ->and(cancelErrors(fn () => cancelAs($this->staff, $stale, 'Cancelling.')))->toBe(['appointment' => [CANCEL_NOT_BOOKED]]);

    expect($stale->fresh()->status)->toBe(AppointmentStatus::CheckedIn);
});

// Denied access

it('answers 403 when a patient cancels someone else\'s booking, and changes nothing', function () {
    $appointment = ($this->bookingIn)(5);
    $stranger = User::factory()->patient()->create();

    $attempt = fn () => cancelAs($stranger, $appointment, 'Not mine.');

    expect($attempt)->toThrow(AuthorizationException::class);
    ($this->unchanged)($appointment);
    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Booked);
});

it('does not let a doctor or an admin cancel a booking', function (string $role) {
    $appointment = ($this->bookingIn)(5);
    $actor = User::factory()->{$role}()->create();

    expect(fn () => cancelAs($actor, $appointment, 'Not my job.'))->toThrow(AuthorizationException::class);
    ($this->unchanged)($appointment);
})->with(['doctor', 'admin']);

// A denied user must not learn anything about the booking, so the check comes before the status and time rules.
it('answers 403, not a validation error, when a stranger tries to cancel a booking that is already cancelled', function () {
    $appointment = ($this->bookingIn)(0, 30, 'cancelled');
    $stranger = User::factory()->patient()->create();

    expect(fn () => cancelAs($stranger, $appointment))->toThrow(AuthorizationException::class);
});

it('cancels the patient\'s own booking but not another patient\'s in the same run', function () {
    $mine = ($this->bookingIn)(5);
    $theirs = Appointment::factory()->for($this->doctor)->for($this->service)->startingIn(hours: 6)->create();

    expect(cancelAs($this->patient, $mine)->status)->toBe(AppointmentStatus::Cancelled)
        ->and(fn () => cancelAs($this->patient, $theirs))->toThrow(AuthorizationException::class);

    expect($theirs->fresh()->status)->toBe(AppointmentStatus::Booked);
});

// One PHP process can't truly race itself, so this checks the row is locked inside the transaction, before the
// status is read. A lock outside the transaction would be released at once and protect nothing.
it('locks the appointment row inside the transaction', function () {
    $appointment = ($this->bookingIn)(5);

    $locks = [];
    DB::listen(function ($query) use (&$locks): void {
        if (str_contains($query->sql, 'from `appointments`') && str_contains($query->sql, 'for update')) {
            $locks[] = DB::transactionLevel();
        }
    });

    cancelAs($this->patient, $appointment);

    // The test itself runs in one transaction (RefreshDatabase), so the Action's transaction is a level deeper.
    expect($locks)->toHaveCount(1)
        ->and($locks[0])->toBeGreaterThan(1);
});
