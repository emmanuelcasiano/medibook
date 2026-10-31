<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

// "My appointments" (step 6.3): the list at /my and the cancel button. The rules for cancelling (the 2 hour limit,
// the reason, the row lock) are tested in CancelAppointmentTest. These tests cover what the HTTP layer adds: who may
// open the page and the route, what the page shows, and that a refusal from the Action comes back as an error.
// "Now" is fixed at Friday 30 October 2026, 09:00 in Manila.

/**
 * An Inertia `where()` check that a list prop holds exactly these values for $key, in this order. (Inertia's
 * `where()` has no `*` wildcard.)
 *
 * @param  list<mixed>  $expected
 */
function pluckEquals(string $key, array $expected): Closure
{
    return fn ($rows): bool => collect($rows)->pluck($key)->all() === $expected;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));
    $this->patient = User::factory()->patient()->create();

    // One of the patient's bookings, starting this far from now (negative for the past). $state is an
    // AppointmentFactory state. Each one gets its own doctor, so two bookings never clash on the unique index.
    $this->bookingIn = fn (int $hours, int $minutes = 0, string $state = 'booked', ?User $patient = null): Appointment => Appointment::factory()
        ->for($patient ?? $this->patient, 'patient')
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

describe('the list', function () {
    it('shows the patient their upcoming appointments, earliest first, and their past ones, newest first', function () {
        $later = ($this->bookingIn)(72);
        $sooner = ($this->bookingIn)(5);
        $oldest = ($this->bookingIn)(-72, state: 'completed');
        $newest = ($this->bookingIn)(-5, state: 'completed');

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('my/appointments/index')
                ->where('upcoming', pluckEquals('ulid', [$sooner->ulid, $later->ulid]))
                ->where('past.data', pluckEquals('ulid', [$newest->ulid, $oldest->ulid]))
                ->where('past.total', 2));
    });

    it('shows the visit, the doctor and a status label, and nothing else about the people', function () {
        $service = Service::factory()->lasting(45)->create(['name' => 'Skin check']);
        $appointment = Appointment::factory()->for($this->patient, 'patient')->for($service)->startingIn(hours: 5)->create();
        $appointment->doctor->update(['specialty' => 'Dermatology']);
        $appointment->doctor->user->forceFill(['name' => 'Dr. Reyes'])->save();

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('upcoming.0.ulid', $appointment->ulid)
                ->where('upcoming.0.startsAt', $appointment->starts_at->toIso8601ZuluString())
                ->where('upcoming.0.endsAt', $appointment->starts_at->addMinutes(45)->toIso8601ZuluString())
                ->where('upcoming.0.status', 'booked')
                ->where('upcoming.0.statusLabel', 'Booked')
                ->where('upcoming.0.service', ['name' => 'Skin check', 'durationMinutes' => 45])
                // Names only: no doctor id, user id or email.
                ->where('upcoming.0.doctor', ['name' => 'Dr. Reyes', 'specialty' => 'Dermatology']));
    });

    it('puts a booked or checked-in future appointment under upcoming and everything else under past', function (string $state, bool $upcoming) {
        $appointment = ($this->bookingIn)(5, state: $state);

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('upcoming', pluckEquals('ulid', $upcoming ? [$appointment->ulid] : []))
                ->where('past.data', pluckEquals('ulid', $upcoming ? [] : [$appointment->ulid])));
    })->with([
        'booked' => ['booked', true],
        'checked in' => ['checkedIn', true],
        'completed' => ['completed', false],
        'cancelled' => ['cancelled', false],
        'no-show' => ['noShow', false],
    ]);

    it('moves a booking that has already started to past, even if it is still booked', function () {
        $started = ($this->bookingIn)(0, -10);

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('upcoming', [])
                ->where('past.data', pluckEquals('ulid', [$started->ulid])));
    });

    it('counts a booking as upcoming only when it starts after now', function (int $minutes, bool $upcoming) {
        $appointment = ($this->bookingIn)(0, $minutes);

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('upcoming', pluckEquals('ulid', $upcoming ? [$appointment->ulid] : []))
                ->where('past.data', pluckEquals('ulid', $upcoming ? [] : [$appointment->ulid])));
    })->with([
        'one minute from now' => [1, true],
        'exactly now' => [0, false],
        'one minute ago' => [-1, false],
    ]);

    it('never lists another patient\'s appointments', function () {
        $mine = ($this->bookingIn)(5);
        $other = User::factory()->patient()->create();
        ($this->bookingIn)(6, patient: $other);
        ($this->bookingIn)(-6, state: 'completed', patient: $other);

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('upcoming', pluckEquals('ulid', [$mine->ulid]))
                ->where('past.total', 0));
    });

    it('shows empty lists to a patient with no appointments', function () {
        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('upcoming', [])
                ->where('past.data', [])
                ->where('past.total', 0));
    });

    it('pages the past list', function () {
        foreach (range(1, 12) as $day) {
            ($this->bookingIn)(-24 * $day, state: 'completed');
        }

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('past.data', 10)
                ->where('past.total', 12)
                ->where('past.last_page', 2));

        $this->actingAs($this->patient)
            ->get(route('my.home', ['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('past.data', 2));
    });

    it('shows who cancelled and why', function () {
        $byPatient = ($this->bookingIn)(-24, state: 'cancelled');
        $byPatient->update(['cancel_reason' => 'Changed my mind.']);

        $byClinic = ($this->bookingIn)(-48, state: 'cancelled');
        $byClinic->update(['cancelled_by' => User::factory()->staff()->create()->id, 'cancel_reason' => 'Doctor is away.']);

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('past.data.0.cancelReason', 'Changed my mind.')
                ->where('past.data.0.cancelledByClinic', false)
                ->where('past.data.1.cancelReason', 'Doctor is away.')
                ->where('past.data.1.cancelledByClinic', true));
    });
});

describe('the booking limit', function () {
    it('tells the page the cancel limit and the booking limit', function () {
        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('cancelHours', 2)
                ->where('maxUpcoming', 3));
    });

    it('lets the patient book while under the limit and not at it', function (int $upcoming, bool $canBook) {
        foreach (range(1, $upcoming) as $hours) {
            ($this->bookingIn)(24 * $hours);
        }

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page->where('canBook', $canBook));
    })->with([
        'one upcoming' => [1, true],
        'two upcoming' => [2, true],
        'three upcoming' => [3, false],
    ]);

    it('does not count cancelled or past appointments against the limit', function () {
        ($this->bookingIn)(24);
        ($this->bookingIn)(48);
        ($this->bookingIn)(72, state: 'cancelled');
        ($this->bookingIn)(-24, state: 'completed');

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page->where('canBook', true));
    });
});

describe('who cancelled', function () {
    it('does not blame the clinic when nobody is recorded as the canceller', function () {
        // `cancelled_by` is a nullable column. If it is ever empty on a cancelled booking we can't tell who cancelled
        // it, so the page does not say "by the clinic".
        $appointment = ($this->bookingIn)(-24, state: 'cancelled');
        $appointment->update(['cancelled_by' => null]);

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page->where('past.data.0.cancelledByClinic', false));
    });

    it('shows a cancel reason only on a cancelled appointment', function () {
        $appointment = ($this->bookingIn)(-24, state: 'noShow');
        $appointment->update(['cancel_reason' => 'Left over from an earlier cancel.']);

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('past.data.0.status', 'no_show')
                ->where('past.data.0.cancelReason', null));
    });
});

describe('can.cancel', function () {
    it('is true for a booked appointment at least 2 hours away, exactly 2 hours included', function (int $hours, int $minutes, bool $expected) {
        ($this->bookingIn)($hours, $minutes);

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page->where('upcoming.0.can.cancel', $expected));
    })->with([
        'a day away' => [24, 0, true],
        'exactly 2 hours away' => [2, 0, true],
        '1 hour 59 minutes away' => [1, 59, false],
        '30 minutes away' => [0, 30, false],
    ]);

    it('is false once the patient has checked in', function () {
        ($this->bookingIn)(24, state: 'checkedIn');

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page->where('upcoming.0.can.cancel', false));
    });

    it('is false for past appointments', function () {
        ($this->bookingIn)(-24, state: 'cancelled');
        ($this->bookingIn)(-48, state: 'completed');
        ($this->bookingIn)(-72, state: 'noShow');

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('past.data', pluckEquals('can.cancel', [false, false, false])));
    });

    it('gives the last moment the patient can cancel', function () {
        ($this->bookingIn)(24);

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page
                // Starts Saturday 31 October 09:00 Manila = 01:00 UTC; 2 hours before that is 23:00 UTC the day before.
                ->where('upcoming.0.cancelUntil', '2026-10-30T23:00:00Z'));
    });
});

describe('cancelling', function () {
    it('cancels the patient\'s own booking and goes back to the list with a message', function () {
        $appointment = ($this->bookingIn)(24);

        $this->actingAs($this->patient)
            ->post(route('my.appointments.cancel', $appointment), ['reason' => 'Changed my mind.'])
            ->assertRedirect(route('my.home'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        $fresh = $appointment->fresh();

        expect($fresh->status)->toBe(AppointmentStatus::Cancelled)
            ->and($fresh->cancelled_by)->toBe($this->patient->id)
            ->and($fresh->cancelled_at)->not->toBeNull()
            ->and($fresh->cancel_reason)->toBe('Changed my mind.')
            // The slot is free again: MySQL clears the generated key for a cancelled booking.
            ->and($fresh->active_key)->toBeNull();
    });

    it('moves the cancelled appointment from upcoming to past', function () {
        $appointment = ($this->bookingIn)(24);

        $this->actingAs($this->patient)->post(route('my.appointments.cancel', $appointment));

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('upcoming', [])
                ->where('past.data.0.ulid', $appointment->ulid)
                ->where('past.data.0.statusLabel', 'Cancelled'));
    });

    it('does not need a reason', function (array $input) {
        $appointment = ($this->bookingIn)(24);

        $this->actingAs($this->patient)
            ->post(route('my.appointments.cancel', $appointment), $input)
            ->assertRedirect(route('my.home'))
            ->assertSessionHasNoErrors();

        expect($appointment->fresh())
            ->status->toBe(AppointmentStatus::Cancelled)
            ->cancel_reason->toBeNull();
    })->with([
        'no field' => [[]],
        'an empty field' => [['reason' => '']],
        'only spaces' => [['reason' => '   ']],
    ]);

    it('takes who cancelled from the signed-in user, not from the form', function () {
        $appointment = ($this->bookingIn)(24);
        $someoneElse = User::factory()->staff()->create();

        $this->actingAs($this->patient)
            ->post(route('my.appointments.cancel', $appointment), ['cancelled_by' => $someoneElse->id, 'status' => 'booked']);

        expect($appointment->fresh())
            ->status->toBe(AppointmentStatus::Cancelled)
            ->cancelled_by->toBe($this->patient->id);
    });
});

describe('cancelling is refused', function () {
    it('answers 403 and changes nothing when another patient cancels the booking', function () {
        $appointment = ($this->bookingIn)(24);
        $stranger = User::factory()->patient()->create();

        $this->actingAs($stranger)
            ->post(route('my.appointments.cancel', $appointment), ['reason' => 'Not mine.'])
            ->assertForbidden();

        ($this->unchanged)($appointment);
    });

    it('answers 403 to staff, doctors and admins, and changes nothing', function (Role $role) {
        $appointment = ($this->bookingIn)(24);

        // Staff can cancel any booking, but from their own schedule in step 7.3, not from the patient pages.
        $this->actingAs(userWithRole($role))
            ->post(route('my.appointments.cancel', $appointment), ['reason' => 'No.'])
            ->assertForbidden();

        ($this->unchanged)($appointment);
    })->with([
        'staff' => Role::Staff,
        'doctor' => Role::Doctor,
        'admin' => Role::Admin,
    ]);

    it('sends a guest to the login page', function () {
        $appointment = ($this->bookingIn)(24);

        $this->post(route('my.appointments.cancel', $appointment))->assertRedirect(route('login'));

        ($this->unchanged)($appointment);
    });

    it('rate limits cancelling to 10 a minute for one patient', function () {
        $appointment = ($this->bookingIn)(24);
        $this->actingAs($this->patient);

        foreach (range(1, 10) as $attempt) {
            $this->post(route('my.appointments.cancel', $appointment))->assertRedirect();
        }

        $this->post(route('my.appointments.cancel', $appointment))->assertTooManyRequests();
    });

    it('answers 404 for a booking that does not exist', function () {
        $this->actingAs($this->patient)
            ->post(route('my.appointments.cancel', ['appointment' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']))
            ->assertNotFound();
    });

    it('refuses a reason longer than 255 characters', function () {
        $appointment = ($this->bookingIn)(24);

        $this->actingAs($this->patient)
            ->post(route('my.appointments.cancel', $appointment), ['reason' => str_repeat('a', 256)])
            ->assertSessionHasErrors('reason');

        ($this->unchanged)($appointment);
    });

    it('accepts a reason of exactly 255 characters', function () {
        $appointment = ($this->bookingIn)(24);

        $this->actingAs($this->patient)
            ->post(route('my.appointments.cancel', $appointment), ['reason' => str_repeat('a', 255)])
            ->assertSessionHasNoErrors();

        expect($appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled);
    });

    it('refuses a reason that is not text', function () {
        $appointment = ($this->bookingIn)(24);

        $this->actingAs($this->patient)
            ->post(route('my.appointments.cancel', $appointment), ['reason' => ['a']])
            ->assertSessionHasErrors('reason');

        ($this->unchanged)($appointment);
    });

    it('refuses a cancel less than 2 hours before the start, with the message on `appointment`', function () {
        $appointment = ($this->bookingIn)(1, 59);

        $this->actingAs($this->patient)
            ->post(route('my.appointments.cancel', $appointment))
            ->assertSessionHasErrors(['appointment' => 'Appointments can only be cancelled up to 2 hours before they start. Please call the clinic.']);

        ($this->unchanged)($appointment);
    });

    it('refuses a booking that is not booked any more', function (string $state) {
        $appointment = ($this->bookingIn)(24, state: $state);

        $this->actingAs($this->patient)
            ->post(route('my.appointments.cancel', $appointment))
            ->assertSessionHasErrors(['appointment' => 'This appointment can no longer be cancelled.']);

        ($this->unchanged)($appointment);
    })->with([
        'checked in' => 'checkedIn',
        'cancelled' => 'cancelled',
    ]);
});
