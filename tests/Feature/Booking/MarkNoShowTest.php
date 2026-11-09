<?php

declare(strict_types=1);

use App\Actions\Booking\BookAppointment;
use App\Actions\Booking\MarkNoShow;
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
// Action checks AppointmentPolicy::markNoShow itself, and a denied or refused call writes nothing.
// "Now" is fixed at Friday 30 October 2026, 09:00 in Manila. A no-show is allowed from the start time on.

function noShowAs(User $actor, Appointment $appointment): Appointment
{
    return app(MarkNoShow::class)->handle($actor, $appointment);
}

/**
 * The validation errors of a no-show that must fail.
 *
 * @return array<string, list<string>>
 */
function noShowErrors(Closure $attempt): array
{
    try {
        $attempt();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The no-show was expected to fail, but it went through.');
}

const NO_SHOW_NOT_BOOKED = 'Only a booked appointment can be marked as a no-show.';
const NO_SHOW_TOO_EARLY = 'A no-show can only be marked once the appointment has started.';

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

    // The row as it is in the database, to prove a refused no-show wrote nothing.
    $this->unchanged = function (Appointment $appointment): void {
        $fresh = $appointment->fresh();

        expect($fresh->status)->toBe($appointment->status)
            ->and($fresh->checked_in_at)->toEqual($appointment->checked_in_at);
    };
});

// Happy path

it('lets staff mark a booked appointment as a no-show after its start', function () {
    $appointment = ($this->bookingIn)(-1);

    $result = noShowAs($this->staff, $appointment);

    expect($result->status)->toBe(AppointmentStatus::NoShow)
        ->and($appointment->fresh()->status)->toBe(AppointmentStatus::NoShow);
});

it('allows a no-show exactly at the start time', function () {
    $appointment = ($this->bookingIn)(0);

    noShowAs($this->staff, $appointment);

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::NoShow);
});

it('allows a no-show for an appointment from days ago that was never closed', function () {
    $appointment = Appointment::factory()
        ->for($this->doctor)->for($this->service)->for($this->patient, 'patient')
        ->booked()->startingIn(days: -3)->create();

    noShowAs($this->staff, $appointment);

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::NoShow);
});

it('frees the slot at once, so it can be booked again', function () {
    $appointment = ($this->bookingIn)(-1);

    noShowAs($this->staff, $appointment);

    expect($appointment->fresh()->active_key)->toBeNull();

    // Now the same doctor can take that very time again. The step-5 booking rules apply, so the lead time is not
    // involved here: BookAppointment only checks overlap, patient limits and the pair.
    $other = User::factory()->patient()->create();
    $rebooked = app(BookAppointment::class)->handle($other, $this->doctor, $this->service, $appointment->starts_at);

    expect($rebooked->status)->toBe(AppointmentStatus::Booked);
});

// Denied access

it('refuses everyone but staff and writes nothing', function (string $who) {
    $appointment = ($this->bookingIn)(-1);

    $actor = match ($who) {
        'the patient themselves' => $this->patient,
        'another patient' => User::factory()->patient()->create(),
        'the appointment\'s own doctor' => $this->doctor->user,
        'an admin' => userWithRole(Role::Admin),
        'a nurse' => userWithRole(Role::Nurse),
    };

    expect(fn () => noShowAs($actor, $appointment))->toThrow(AuthorizationException::class);

    ($this->unchanged)($appointment);
})->with(['the patient themselves', 'another patient', 'the appointment\'s own doctor', 'an admin']);

it('refuses before it says anything about the booking state or time', function () {
    // A patient asking about a future booking must get the 403, not "...once the appointment has started".
    $appointment = ($this->bookingIn)(3);

    expect(fn () => noShowAs($this->patient, $appointment))->toThrow(AuthorizationException::class);
});

// Rules

it('refuses a no-show before the start', function (int $hours, int $minutes) {
    $appointment = ($this->bookingIn)($hours, $minutes);

    $errors = noShowErrors(fn () => noShowAs($this->staff, $appointment));

    expect($errors)->toBe(['appointment' => [NO_SHOW_TOO_EARLY]]);

    ($this->unchanged)($appointment);
})->with([
    'one minute before' => [0, 1],
    'one hour before' => [1, 0],
    'tomorrow' => [24, 0],
]);

it('refuses to mark anything that is not booked', function (string $state) {
    $appointment = ($this->bookingIn)(-1, state: $state);

    $errors = noShowErrors(fn () => noShowAs($this->staff, $appointment));

    expect($errors)->toBe(['appointment' => [NO_SHOW_NOT_BOOKED]]);

    ($this->unchanged)($appointment);
})->with(['cancelled', 'checkedIn', 'completed', 'noShow']);

it('does not trust a stale model', function () {
    $appointment = ($this->bookingIn)(-1);

    // The patient was checked in by someone else after this request loaded its copy, which still says "booked".
    Appointment::query()->whereKey($appointment->id)->update(['status' => AppointmentStatus::CheckedIn]);

    $errors = noShowErrors(fn () => noShowAs($this->staff, $appointment));

    expect($errors)->toBe(['appointment' => [NO_SHOW_NOT_BOOKED]])
        ->and($appointment->fresh()->status)->toBe(AppointmentStatus::CheckedIn);
});

it('reads the start time from the database, not from the caller\'s copy', function () {
    $appointment = ($this->bookingIn)(-1);

    // The copy in hand says the visit is over an hour old, but the row says it is still ahead.
    Appointment::query()->whereKey($appointment->id)->update(['starts_at' => CarbonImmutable::now()->addHours(3)]);

    expect(fn () => noShowAs($this->staff, $appointment))->toThrow(ValidationException::class)
        ->and($appointment->fresh()->status)->toBe(AppointmentStatus::Booked);
});

// One PHP process can't truly race itself, so this checks the row is locked inside the transaction, before the
// status is read. A lock outside the transaction would be released at once and protect nothing.
it('locks the appointment row inside the transaction', function () {
    $appointment = ($this->bookingIn)(-1);

    $locks = [];
    DB::listen(function ($query) use (&$locks): void {
        if (str_contains($query->sql, 'from `appointments`') && str_contains($query->sql, 'for update')) {
            $locks[] = DB::transactionLevel();
        }
    });

    noShowAs($this->staff, $appointment);

    // The test itself runs in one transaction (RefreshDatabase), so the Action's transaction is a level deeper.
    expect($locks)->toHaveCount(1)
        ->and($locks[0])->toBeGreaterThan(1);
});
