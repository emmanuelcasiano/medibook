<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

// The staff side panel (step 7.2): the `selected` prop that opens it, and the check-in and no-show routes. The rules
// themselves (status, "only after the start", the row lock) are tested in CheckInAppointmentTest and MarkNoShowTest.
// These tests cover what the HTTP layer adds: who may call the routes, where they send the browser, how a refusal
// comes back, and what the panel is told. "Now" is Friday 30 October 2026, 09:00 in Manila.

/** A booking on the day under test for a fresh doctor. $clinicTime is Manila time, $state an AppointmentFactory state. */
function panelBooking(string $clinicTime, string $state = 'booked'): Appointment
{
    return Appointment::factory()
        ->for(doctorWorking($service = Service::factory()->lasting(30)->create(), []))
        ->for($service)
        ->for(User::factory()->patient()->create(), 'patient')
        ->{$state}()
        ->create(['starts_at' => CarbonImmutable::parse($clinicTime, 'Asia/Manila')->utc()]);
}

/**
 * An Inertia `where()` check that the booking with this ULID has this status on the grid. Doctors are sorted by
 * name, so a test can't rely on which column holds its booking.
 */
function panelShowsStatus(Appointment $appointment, string $status): Closure
{
    return fn ($doctors): bool => collect($doctors)
        ->flatMap(fn (array $doctor): array => $doctor['appointments'])
        ->firstWhere('ulid', $appointment->ulid)['status'] === $status;
}

/** The schedule page as the browser has it open, so a validation redirect has somewhere to go back to. */
function panelPage(Appointment $appointment): string
{
    return route('staff.home', ['date' => '2026-10-30', 'appointment' => $appointment->ulid]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));
    $this->staff = userWithRole(Role::Staff);
    $this->started = panelBooking('2026-10-30 08:00');
    $this->upcoming = panelBooking('2026-10-30 15:00');
});

describe('the selected prop', function () {
    it('opens the panel for a booking on the shown day, with what staff may do', function () {
        $this->actingAs($this->staff)
            ->get(route('staff.home', ['date' => '2026-10-30', 'appointment' => $this->started->ulid]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.ulid', $this->started->ulid)
                ->where('selected.hasStarted', true)
                ->where('selected.can.checkIn', true)
                ->where('selected.can.markNoShow', true));
    });

    it('says a booking that has not started has not started', function () {
        $this->actingAs($this->staff)
            ->get(panelPage($this->upcoming))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.hasStarted', false)
                ->where('selected.can.checkIn', true)
                ->where('selected.can.markNoShow', true));
    });

    it('treats a booking that starts exactly now as started', function () {
        $now = panelBooking('2026-10-30 09:00');

        $this->actingAs($this->staff)
            ->get(panelPage($now))
            ->assertInertia(fn (Assert $page) => $page->where('selected.hasStarted', true));
    });

    it('offers no buttons once the booking is not booked any more', function (string $state) {
        $booking = panelBooking('2026-10-30 10:00', $state);

        $this->actingAs($this->staff)
            ->get(panelPage($booking))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.ulid', $booking->ulid)
                ->where('selected.can.checkIn', false)
                ->where('selected.can.markNoShow', false)
                ->where('selected.can.cancel', false));
    })->with(['checkedIn', 'completed', 'noShow']);

    it('gives an admin the panel but no buttons, and the same schedule as staff', function () {
        $staffView = $this->actingAs($this->staff)->get(panelPage($this->started))->assertOk();
        $adminView = $this->actingAs(userWithRole(Role::Admin))->get(panelPage($this->started))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.ulid', $this->started->ulid)
                ->where('selected.can.checkIn', false)
                ->where('selected.can.markNoShow', false));

        expect($adminView->inertiaProps('schedule'))->toEqual($staffView->inertiaProps('schedule'));
    });

    it('opens no panel without an appointment in the address', function () {
        $this->actingAs($this->staff)
            ->get(route('staff.home', ['date' => '2026-10-30']))
            ->assertInertia(fn (Assert $page) => $page->where('selected', null));
    });

    it('opens no panel for a booking that is on another day', function () {
        $tomorrow = panelBooking('2026-10-31 10:00');

        $this->actingAs($this->staff)
            ->get(route('staff.home', ['date' => '2026-10-30', 'appointment' => $tomorrow->ulid]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('selected', null))
            ->assertDontSee($tomorrow->patient->name);
    });

    it('opens no panel for a cancelled booking, which the grid does not show', function () {
        $cancelled = panelBooking('2026-10-30 11:00', 'cancelled');

        $this->actingAs($this->staff)
            ->get(panelPage($cancelled))
            ->assertInertia(fn (Assert $page) => $page->where('selected', null));
    });

    it('opens no panel for an unknown ULID', function () {
        $this->actingAs($this->staff)
            ->get(route('staff.home', ['date' => '2026-10-30', 'appointment' => '01JAAAAAAAAAAAAAAAAAAAAAAA']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('selected', null));
    });

    it('refuses an appointment value that is not a ULID', function (string $value) {
        $this->actingAs($this->staff)
            ->from(route('staff.home'))
            ->get(route('staff.home', ['appointment' => $value]))
            ->assertRedirect(route('staff.home'))
            ->assertSessionHasErrors('appointment');
    })->with(['12', 'not-a-ulid', '01JAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA']);

    it('runs the same number of queries with the panel open as without', function () {
        $queries = function (array $query): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->actingAs($this->staff)->get(route('staff.home', $query))->assertOk();

            return count(DB::getQueryLog());
        };

        $without = $queries(['date' => '2026-10-30']);

        // The panel looks the booking up in the schedule the page already holds, so it costs no query of its own.
        expect($queries(['date' => '2026-10-30', 'appointment' => $this->started->ulid]))->toBe($without);
    });

    it('still carries the patient\'s name only, with no contact or health fields', function () {
        $patient = $this->started->patient;

        $this->actingAs($this->staff)
            ->get(panelPage($this->started))
            ->assertOk()
            ->assertSee($patient->name)
            ->assertDontSee($patient->email);
    });
});

describe('checking in', function () {
    it('checks in a booked appointment and returns to the same day with the panel open', function () {
        $this->actingAs($this->staff)
            ->post(route('staff.appointments.check-in', $this->started))
            ->assertRedirect(panelPage($this->started));

        $fresh = $this->started->fresh();

        expect($fresh->status)->toBe(AppointmentStatus::CheckedIn)
            ->and($fresh->checked_in_at)->toEqual(CarbonImmutable::now()->startOfSecond());
    });

    it('tells staff who was checked in', function () {
        $this->actingAs($this->staff)
            ->post(route('staff.appointments.check-in', $this->started))
            ->assertSessionHas('inertia.flash_data.toast.type', 'success')
            ->assertSessionHas('inertia.flash_data.toast.message', $this->started->patient->name.' is checked in.');
    });

    it('says nothing of success when the check-in was refused', function () {
        $this->started->update(['status' => AppointmentStatus::Cancelled]);

        $this->actingAs($this->staff)
            ->from(panelPage($this->started))
            ->post(route('staff.appointments.check-in', $this->started))
            ->assertSessionMissing('inertia.flash_data.toast');
    });

    it('returns to the clinic day, not the UTC day', function () {
        // 07:30 in Manila is 23:30 UTC the day before.
        $early = panelBooking('2026-10-30 07:30');

        $this->actingAs($this->staff)
            ->post(route('staff.appointments.check-in', $early))
            ->assertRedirect(route('staff.home', ['date' => '2026-10-30', 'appointment' => $early->ulid]));
    });

    it('shows the new status after the redirect', function () {
        $this->actingAs($this->staff)
            ->followingRedirects()
            ->post(route('staff.appointments.check-in', $this->started))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.ulid', $this->started->ulid)
                ->where('selected.can.checkIn', false)
                ->where('schedule.doctors', panelShowsStatus($this->started, 'checked_in')));
    });

    it('turns away everyone but staff with a 403 and changes nothing', function (Role $role) {
        $this->actingAs(userWithRole($role))
            ->post(route('staff.appointments.check-in', $this->started))
            ->assertForbidden();

        expect($this->started->fresh()->status)->toBe(AppointmentStatus::Booked)
            ->and($this->started->fresh()->checked_in_at)->toBeNull();
    })->with([
        'admin' => Role::Admin,
        'doctor' => Role::Doctor,
        'patient' => Role::Patient,
    ]);

    it('turns away the patient who owns the booking', function () {
        $this->actingAs($this->started->patient)
            ->post(route('staff.appointments.check-in', $this->started))
            ->assertForbidden();

        expect($this->started->fresh()->status)->toBe(AppointmentStatus::Booked);
    });

    it('sends a guest to the login page', function () {
        $this->post(route('staff.appointments.check-in', $this->started))->assertRedirect(route('login'));

        expect($this->started->fresh()->status)->toBe(AppointmentStatus::Booked);
    });

    it('sends staff without two-factor to set it up first', function () {
        $this->actingAs(userWithRole(Role::Staff, twoFactor: false))
            ->post(route('staff.appointments.check-in', $this->started))
            ->assertRedirect();

        expect($this->started->fresh()->status)->toBe(AppointmentStatus::Booked);
    });

    it('sends the error back to the panel when the booking is not booked', function () {
        $this->started->update(['status' => AppointmentStatus::Cancelled]);

        $this->actingAs($this->staff)
            ->from(panelPage($this->started))
            ->post(route('staff.appointments.check-in', $this->started))
            ->assertRedirect(panelPage($this->started))
            ->assertSessionHasErrors(['appointment' => 'Only a booked appointment can be checked in.']);

        expect($this->started->fresh()->status)->toBe(AppointmentStatus::Cancelled);
    });

    it('answers 404 for a ULID that does not exist', function () {
        $this->actingAs($this->staff)
            ->post(route('staff.appointments.check-in', '01JAAAAAAAAAAAAAAAAAAAAAAA'))
            ->assertNotFound();
    });
});

describe('marking a no-show', function () {
    it('marks a started booking as a no-show and returns to the same day with the panel open', function () {
        $this->actingAs($this->staff)
            ->post(route('staff.appointments.no-show', $this->started))
            ->assertRedirect(panelPage($this->started));

        expect($this->started->fresh()->status)->toBe(AppointmentStatus::NoShow)
            ->and($this->started->fresh()->active_key)->toBeNull();
    });

    it('tells staff who was marked as a no-show', function () {
        $this->actingAs($this->staff)
            ->post(route('staff.appointments.no-show', $this->started))
            ->assertSessionHas('inertia.flash_data.toast.type', 'success')
            ->assertSessionHas('inertia.flash_data.toast.message', $this->started->patient->name.' was marked as a no-show.');
    });

    it('says nothing of success when the no-show was refused', function () {
        $this->actingAs($this->staff)
            ->from(panelPage($this->upcoming))
            ->post(route('staff.appointments.no-show', $this->upcoming))
            ->assertSessionMissing('inertia.flash_data.toast');
    });

    it('keeps the no-show on the schedule, with no buttons', function () {
        $this->actingAs($this->staff)
            ->followingRedirects()
            ->post(route('staff.appointments.no-show', $this->started))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.doctors', panelShowsStatus($this->started, 'no_show'))
                ->where('selected.can.checkIn', false)
                ->where('selected.can.markNoShow', false));
    });

    it('turns away everyone but staff with a 403 and changes nothing', function (Role $role) {
        $this->actingAs(userWithRole($role))
            ->post(route('staff.appointments.no-show', $this->started))
            ->assertForbidden();

        expect($this->started->fresh()->status)->toBe(AppointmentStatus::Booked);
    })->with([
        'admin' => Role::Admin,
        'doctor' => Role::Doctor,
        'patient' => Role::Patient,
    ]);

    it('sends a guest to the login page', function () {
        $this->post(route('staff.appointments.no-show', $this->started))->assertRedirect(route('login'));

        expect($this->started->fresh()->status)->toBe(AppointmentStatus::Booked);
    });

    it('sends the error back to the panel when the booking has not started', function () {
        $this->actingAs($this->staff)
            ->from(panelPage($this->upcoming))
            ->post(route('staff.appointments.no-show', $this->upcoming))
            ->assertRedirect(panelPage($this->upcoming))
            ->assertSessionHasErrors(['appointment' => 'A no-show can only be marked once the appointment has started.']);

        expect($this->upcoming->fresh()->status)->toBe(AppointmentStatus::Booked);
    });

    it('sends the error back to the panel when the booking is already checked in', function () {
        $this->started->update(['status' => AppointmentStatus::CheckedIn]);

        $this->actingAs($this->staff)
            ->from(panelPage($this->started))
            ->post(route('staff.appointments.no-show', $this->started))
            ->assertRedirect(panelPage($this->started))
            ->assertSessionHasErrors('appointment');

        expect($this->started->fresh()->status)->toBe(AppointmentStatus::CheckedIn);
    });

    it('answers 404 for a ULID that does not exist', function () {
        $this->actingAs($this->staff)
            ->post(route('staff.appointments.no-show', '01JAAAAAAAAAAAAAAAAAAAAAAA'))
            ->assertNotFound();
    });
});

describe('cancelling', function () {
    it('cancels a booking with a reason and returns to the same day', function () {
        $this->actingAs($this->staff)
            ->post(route('staff.appointments.cancel', $this->upcoming), ['reason' => 'The doctor is ill.'])
            ->assertRedirect(route('staff.home', ['date' => '2026-10-30']))
            ->assertSessionHas('inertia.flash_data.toast.type', 'success')
            ->assertSessionHas('inertia.flash_data.toast.message', 'The appointment of '.$this->upcoming->patient->name.' was cancelled.');

        $fresh = $this->upcoming->fresh();

        expect($fresh->status)->toBe(AppointmentStatus::Cancelled)
            ->and($fresh->cancel_reason)->toBe('The doctor is ill.')
            ->and($fresh->cancelled_by)->toBe($this->staff->id)
            ->and($fresh->cancelled_at)->toEqual(CarbonImmutable::now()->startOfSecond())
            ->and($fresh->active_key)->toBeNull();
    });

    it('lets staff cancel a booking that starts in less than 2 hours, or has already started', function () {
        // The 2 hour rule is for patients only.
        $soon = panelBooking('2026-10-30 10:00');

        foreach ([$soon, $this->started] as $booking) {
            $this->actingAs($this->staff)
                ->post(route('staff.appointments.cancel', $booking), ['reason' => 'Walk-in emergency.'])
                ->assertSessionHasNoErrors();

            expect($booking->fresh()->status)->toBe(AppointmentStatus::Cancelled);
        }
    });

    it('takes the booking off the grid and frees the slot', function () {
        $this->actingAs($this->staff)
            ->followingRedirects()
            ->post(route('staff.appointments.cancel', $this->upcoming), ['reason' => 'Patient called.'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected', null)
                ->where('schedule.doctors', fn ($doctors): bool => collect($doctors)
                    ->flatMap(fn (array $doctor): array => $doctor['appointments'])
                    ->doesntContain('ulid', $this->upcoming->ulid)));

        // The unique index ignores a cancelled booking, so the same doctor and time can be booked again.
        expect(Appointment::factory()->booked()->create([
            'doctor_id' => $this->upcoming->doctor_id,
            'service_id' => $this->upcoming->service_id,
            'starts_at' => $this->upcoming->starts_at,
        ])->exists)->toBeTrue();
    });

    it('turns away everyone but staff with a 403 and changes nothing', function (Role $role) {
        $this->actingAs(userWithRole($role))
            ->post(route('staff.appointments.cancel', $this->upcoming), ['reason' => 'A valid reason.'])
            ->assertForbidden();

        expect($this->upcoming->fresh()->status)->toBe(AppointmentStatus::Booked)
            ->and($this->upcoming->fresh()->cancel_reason)->toBeNull();
    })->with([
        'admin' => Role::Admin,
        'doctor' => Role::Doctor,
        'patient' => Role::Patient,
    ]);

    it('turns away the patient who owns the booking', function () {
        // The Policy lets a patient cancel their own booking from "My appointments". This staff route must not.
        $this->actingAs($this->upcoming->patient)
            ->post(route('staff.appointments.cancel', $this->upcoming), ['reason' => 'A valid reason.'])
            ->assertForbidden();

        expect($this->upcoming->fresh()->status)->toBe(AppointmentStatus::Booked)
            ->and($this->upcoming->fresh()->cancel_reason)->toBeNull();
    });

    it('turns an admin away with a 403 even when the reason is missing', function () {
        // Authorization comes before validation, so an admin learns nothing about what the route wants.
        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('staff.appointments.cancel', $this->upcoming))
            ->assertForbidden();
    });

    it('sends a guest to the login page', function () {
        $this->post(route('staff.appointments.cancel', $this->upcoming), ['reason' => 'A valid reason.'])
            ->assertRedirect(route('login'));

        expect($this->upcoming->fresh()->status)->toBe(AppointmentStatus::Booked);
    });

    it('refuses a missing, blank or too long reason and changes nothing', function (array $input) {
        $this->actingAs($this->staff)
            ->from(panelPage($this->upcoming))
            ->post(route('staff.appointments.cancel', $this->upcoming), $input)
            ->assertRedirect(panelPage($this->upcoming))
            ->assertSessionHasErrors('reason')
            ->assertSessionMissing('inertia.flash_data.toast');

        expect($this->upcoming->fresh()->status)->toBe(AppointmentStatus::Booked)
            ->and($this->upcoming->fresh()->cancelled_at)->toBeNull();
    })->with([
        'missing' => [[]],
        'empty' => [['reason' => '']],
        'spaces only' => [['reason' => '   ']],
        'not a string' => [['reason' => ['a']]],
        'over 255 characters' => [['reason' => str_repeat('a', 256)]],
    ]);

    it('accepts a reason of exactly 255 characters', function () {
        $this->actingAs($this->staff)
            ->post(route('staff.appointments.cancel', $this->upcoming), ['reason' => str_repeat('a', 255)])
            ->assertSessionHasNoErrors();

        expect($this->upcoming->fresh()->status)->toBe(AppointmentStatus::Cancelled);
    });

    it('sends the error back to the panel when the booking is not booked', function (string $state) {
        $booking = panelBooking('2026-10-30 12:00', $state);

        $this->actingAs($this->staff)
            ->from(panelPage($booking))
            ->post(route('staff.appointments.cancel', $booking), ['reason' => 'A valid reason.'])
            ->assertRedirect(panelPage($booking))
            ->assertSessionHasErrors(['appointment' => 'This appointment can no longer be cancelled.'])
            ->assertSessionMissing('inertia.flash_data.toast');

        expect($booking->fresh()->status)->toBe($booking->status)
            ->and($booking->fresh()->cancel_reason)->toBeNull();
    })->with(['checkedIn', 'completed', 'noShow', 'cancelled']);

    it('answers 404 for a ULID that does not exist', function () {
        $this->actingAs($this->staff)
            ->post(route('staff.appointments.cancel', '01JAAAAAAAAAAAAAAAAAAAAAAA'), ['reason' => 'A valid reason.'])
            ->assertNotFound();
    });

    it('tells the panel that staff may cancel a booked booking and nobody may cancel the rest', function () {
        $this->actingAs($this->staff)
            ->get(panelPage($this->started))
            ->assertInertia(fn (Assert $page) => $page->where('selected.can.cancel', true));

        $checkedIn = panelBooking('2026-10-30 10:00', 'checkedIn');

        $this->actingAs($this->staff)
            ->get(panelPage($checkedIn))
            ->assertInertia(fn (Assert $page) => $page->where('selected.can.cancel', false));

        $this->actingAs(userWithRole(Role::Admin))
            ->get(panelPage($this->started))
            ->assertInertia(fn (Assert $page) => $page->where('selected.can.cancel', false));
    });
});

describe('rate limit', function () {
    it('allows 60 clicks a minute per staff member and then answers 429', function () {
        RateLimiter::clear('staff-actions');

        foreach (range(1, 60) as $click) {
            $this->actingAs($this->staff)->post(route('staff.appointments.check-in', $this->upcoming));
        }

        $this->actingAs($this->staff)
            ->post(route('staff.appointments.check-in', $this->upcoming))
            ->assertStatus(429);

        // The count is per person, so another staff member is not held up.
        $this->actingAs(userWithRole(Role::Staff))
            ->post(route('staff.appointments.check-in', $this->started))
            ->assertRedirect();
    });

    it('also caps the cancel route', function () {
        RateLimiter::clear('staff-actions');

        // A refused cancel (no reason) counts too: the limit is on clicks, not on successes.
        foreach (range(1, 60) as $click) {
            $this->actingAs($this->staff)->post(route('staff.appointments.cancel', $this->upcoming));
        }

        $this->actingAs($this->staff)
            ->post(route('staff.appointments.cancel', $this->upcoming), ['reason' => 'A valid reason.'])
            ->assertStatus(429);

        expect($this->upcoming->fresh()->status)->toBe(AppointmentStatus::Booked);
    });
});
