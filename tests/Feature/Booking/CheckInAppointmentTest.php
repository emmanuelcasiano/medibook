<?php

declare(strict_types=1);

use App\Actions\Booking\CheckInAppointment;
use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// These tests call the Action directly. The staff panel route (step 7.2) is tested in SchedulePanelActionsTest. The
// Action checks AppointmentPolicy::checkIn itself, and a denied or refused call writes nothing.
// "Now" is fixed at Friday 30 October 2026, 09:00 in Manila.

function checkInAs(User $actor, Appointment $appointment): Appointment
{
    return app(CheckInAppointment::class)->handle($actor, $appointment);
}

/**
 * The validation errors of a check-in that must fail.
 *
 * @return array<string, list<string>>
 */
function checkInErrors(Closure $attempt): array
{
    try {
        $attempt();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The check-in was expected to fail, but it went through.');
}

const CHECK_IN_NOT_BOOKED = 'Only a booked appointment can be checked in.';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));
    $this->service = Service::factory()->lasting(30, 15)->create();
    $this->doctor = doctorWorking($this->service, []);
    $this->patient = User::factory()->patient()->create();
    $this->staff = User::factory()->staff()->create();

    // A booking starting this far from now (negative for the past). $state is an AppointmentFactory state.
    $this->bookingIn = fn (int $hours, int $minutes = 0, string $state = 'booked'): Appointment => Appointment::factory()
        ->for($this->doctor)->for($this->service)->for($this->patient, 'patient')
        ->{$state}()->startingIn(hours: $hours, minutes: $minutes)->create();

    // The row as it is in the database, to prove a refused check-in wrote nothing.
    $this->unchanged = function (Appointment $appointment): void {
        $fresh = $appointment->fresh();

        expect($fresh->status)->toBe($appointment->status)
            ->and($fresh->checked_in_at)->toEqual($appointment->checked_in_at);
    };
});

// Happy path

it('lets staff check in a booked appointment and records when', function () {
    $appointment = ($this->bookingIn)(-1);

    $result = checkInAs($this->staff, $appointment);

    expect($result->status)->toBe(AppointmentStatus::CheckedIn);

    $fresh = $appointment->fresh();

    expect($fresh->status)->toBe(AppointmentStatus::CheckedIn)
        ->and($fresh->checked_in_at)->toEqual(CarbonImmutable::now()->startOfSecond());
});

it('lets staff check in a patient who arrives early', function () {
    $appointment = ($this->bookingIn)(5);

    checkInAs($this->staff, $appointment);

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::CheckedIn);
});

it('keeps the slot held after a check-in', function () {
    $appointment = ($this->bookingIn)(2);

    checkInAs($this->staff, $appointment);

    // `checked_in` is an active status, so the generated column still blocks a second booking for this time.
    expect($appointment->fresh()->active_key)->toBe(1);
});

it('changes nothing but the status and the check-in time', function () {
    $appointment = ($this->bookingIn)(2);

    checkInAs($this->staff, $appointment);

    $fresh = $appointment->fresh();

    expect($fresh->starts_at)->toEqual($appointment->starts_at)
        ->and($fresh->doctor_id)->toBe($appointment->doctor_id)
        ->and($fresh->patient_id)->toBe($appointment->patient_id)
        ->and($fresh->cancelled_at)->toBeNull()
        ->and($fresh->emergency_flag)->toBeFalse();
});

// Denied access

it('refuses everyone but staff and writes nothing', function (string $who) {
    $appointment = ($this->bookingIn)(2);

    $actor = match ($who) {
        'the patient themselves' => $this->patient,
        'another patient' => User::factory()->patient()->create(),
        'the appointment\'s own doctor' => $this->doctor->user,
        'an admin' => userWithRole(Role::Admin),
        'a nurse' => userWithRole(Role::Nurse),
    };

    expect(fn () => checkInAs($actor, $appointment))->toThrow(AuthorizationException::class);

    ($this->unchanged)($appointment);
})->with(['the patient themselves', 'another patient', 'the appointment\'s own doctor', 'an admin']);

it('refuses before it says anything about the booking state', function () {
    // A patient asking about a cancelled booking must get the 403, not "only a booked appointment...".
    $appointment = ($this->bookingIn)(2, state: 'cancelled');

    expect(fn () => checkInAs($this->patient, $appointment))->toThrow(AuthorizationException::class);
});

// Rules

it('refuses to check in anything that is not booked', function (string $state) {
    $appointment = ($this->bookingIn)(-1, state: $state);

    $errors = checkInErrors(fn () => checkInAs($this->staff, $appointment));

    expect($errors)->toBe(['appointment' => [CHECK_IN_NOT_BOOKED]]);

    ($this->unchanged)($appointment);
})->with(['cancelled', 'checkedIn', 'completed', 'noShow']);

it('does not trust a stale model', function () {
    $appointment = ($this->bookingIn)(2);

    // Someone else cancels it after this request loaded its copy, which still says "booked".
    Appointment::query()->whereKey($appointment->id)->update(['status' => AppointmentStatus::Cancelled]);

    $errors = checkInErrors(fn () => checkInAs($this->staff, $appointment));

    expect($errors)->toBe(['appointment' => [CHECK_IN_NOT_BOOKED]])
        ->and($appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled)
        ->and($appointment->fresh()->checked_in_at)->toBeNull();
});

it('refuses a second check-in of the same appointment', function () {
    $appointment = ($this->bookingIn)(2);

    checkInAs($this->staff, $appointment);
    $firstTime = $appointment->fresh()->checked_in_at;

    $this->travel(10)->minutes();

    expect(fn () => checkInAs($this->staff, $appointment))->toThrow(ValidationException::class)
        ->and($appointment->fresh()->checked_in_at)->toEqual($firstTime);
});

// One PHP process can't truly race itself, so this checks the row is locked inside the transaction, before the
// status is read. A lock outside the transaction would be released at once and protect nothing.
it('locks the appointment row inside the transaction', function () {
    $appointment = ($this->bookingIn)(2);

    $locks = [];
    DB::listen(function ($query) use (&$locks): void {
        if (str_contains($query->sql, 'from `appointments`') && str_contains($query->sql, 'for update')) {
            $locks[] = DB::transactionLevel();
        }
    });

    checkInAs($this->staff, $appointment);

    // The test itself runs in one transaction (RefreshDatabase), so the Action's transaction is a level deeper.
    expect($locks)->toHaveCount(1)
        ->and($locks[0])->toBeGreaterThan(1);
});
