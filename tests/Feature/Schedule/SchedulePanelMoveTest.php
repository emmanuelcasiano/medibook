<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// The Move part of the staff side panel (step 7.4): the `move` prop that feeds the picker, `selected.can.move`, and the
// move route. The rules of the move itself (cancel first, the slot check, the three guards, the rollback) are tested
// in MoveAppointmentTest. These tests cover what the HTTP layer adds: who may call the route, where it sends the
// browser, how a refusal comes back, and what the picker is told. "Now" is Friday 30 October 2026, 09:00 in Manila,
// so the earliest offered start is 11:00. The service lasts 30 minutes with a 15 minute buffer.

/** The schedule page as the browser has it open, so a validation redirect has somewhere to go back to. */
function movePanelPage(Appointment $appointment, array $query = []): string
{
    return route('staff.home', ['date' => '2026-10-30', 'appointment' => $appointment->ulid, ...$query]);
}

/**
 * What the Move picker receives, as the browser asks for it: a partial reload of the `move` prop on the panel's page.
 * Returns null when the server sent null.
 *
 * @return array{doctors: list<array{id: int, name: string}>, slots: list<array{startsAt: string}>}|null
 */
function movePickerOf(TestResponse $response): ?array
{
    $move = null;

    // Not an arrow function: it would capture `$move` by value, and the assignment below would never reach this scope.
    $response->assertInertia(function (Assert $page) use (&$move): void {
        $page->reloadOnly('move', function (Assert $reload) use (&$move): void {
            $move = $reload->toArray()['props']['move'];
        });
    });

    return $move;
}

/**
 * The offered start times of a picker, as Manila clock times.
 *
 * @param  array{doctors: list<array{id: int, name: string}>, slots: list<array{startsAt: string}>}  $picker
 * @return list<string>
 */
function movePickerTimes(array $picker): array
{
    return collect($picker['slots'])
        ->map(fn (array $slot): string => CarbonImmutable::parse($slot['startsAt'])->setTimezone('Asia/Manila')->format('H:i'))
        ->all();
}

/** Every start the finder offers a free doctor on Friday 30 October: two before lunch, then 13:00 to 16:15. */
function movePickerFridayTimes(): array
{
    return [
        '11:00', '11:15',
        ...collect(range(0, 13))->map(fn (int $step): string => CarbonImmutable::parse('13:00')->addMinutes($step * 15)->format('H:i'))->all(),
    ];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));
    $this->staff = userWithRole(Role::Staff);
    $this->service = Service::factory()->lasting(30, 15)->create();

    // Friday is weekday 5 and Saturday 6. A lunch break from 12:00 to 13:00 on Friday.
    $hours = [[5, '08:00', '12:00'], [5, '13:00', '17:00'], [6, '08:00', '12:00']];
    $this->doctor = doctorWorking($this->service, $hours);
    $this->otherDoctor = doctorWorking($this->service, $hours);
    $this->doctor->user->update(['name' => 'Dr. Abe']);
    $this->otherDoctor->user->update(['name' => 'Dr. Zed']);

    $this->patient = User::factory()->patient()->create();
    $this->booking = Appointment::factory()
        ->for($this->doctor)->for($this->service)->for($this->patient, 'patient')->booked()
        ->create(['starts_at' => CarbonImmutable::parse('2026-10-30 14:00', 'Asia/Manila')->utc()]);

    // A move that must not happen: the old booking is still booked and no other row appeared.
    $this->bookingsBefore = Appointment::query()->count();
    $this->unchanged = function (): void {
        expect($this->booking->fresh()->status)->toBe(AppointmentStatus::Booked)
            ->and($this->booking->fresh()->cancel_reason)->toBeNull()
            ->and(Appointment::query()->count())->toBe($this->bookingsBefore);
    };

    // The new row after a move: the only booked row of the patient.
    $this->moved = fn (): Appointment => Appointment::query()
        ->where('patient_id', $this->patient->id)
        ->where('status', AppointmentStatus::Booked)
        ->sole();
});

describe('the move prop', function () {
    it('is not sent with the page, so opening a panel costs nothing extra', function () {
        $this->actingAs($this->staff)
            ->get(movePanelPage($this->booking))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('move'));
    });

    it('lists the bookable doctors who offer the service, by name, with the booking\'s own doctor', function () {
        doctorWorking(Service::factory()->create(), [[5, '08:00', '17:00']]);
        $retired = doctorWorking($this->service, [[5, '08:00', '17:00']]);
        $retired->user->forceFill(['is_active' => false])->save();

        $picker = movePickerOf($this->actingAs($this->staff)->get(movePanelPage($this->booking)));

        expect($picker['doctors'])->toBe([
            ['id' => $this->doctor->id, 'name' => 'Dr. Abe'],
            ['id' => $this->otherDoctor->id, 'name' => 'Dr. Zed'],
        ]);
    });

    it('sends no times until a doctor and a day are picked', function (array $query) {
        $picker = movePickerOf($this->actingAs($this->staff)->get(movePanelPage($this->booking, $query)));

        expect($picker['slots'])->toBe([])
            ->and($picker['doctors'])->not->toBe([]);
    })->with([
        'nothing' => [[]],
        'a doctor only' => [['move_doctor' => 1]],
        'a day only' => [['move_date' => '2026-10-30']],
    ]);

    it('offers the free times of the picked doctor on the picked day', function () {
        $picker = movePickerOf($this->actingAs($this->staff)->get(movePanelPage($this->booking, [
            'move_doctor' => $this->otherDoctor->id,
            'move_date' => '2026-10-30',
        ])));

        expect(movePickerTimes($picker))->toBe(movePickerFridayTimes());
    });

    it('does not count the booking\'s own time as taken, so a 15 minute shift is offered', function () {
        // The booking is at 14:00 and blocks the doctor until 14:45. Without ignoring it, 14:00 to 14:30 would be gone.
        $picker = movePickerOf($this->actingAs($this->staff)->get(movePanelPage($this->booking, [
            'move_doctor' => $this->doctor->id,
            'move_date' => '2026-10-30',
        ])));

        expect(movePickerTimes($picker))->toBe(movePickerFridayTimes());
    });

    it('still leaves out the time of another patient\'s booking and its buffer', function () {
        Appointment::factory()->for($this->otherDoctor)->for($this->service)->booked()
            ->create(['starts_at' => CarbonImmutable::parse('2026-10-30 15:00', 'Asia/Manila')->utc()]);

        $picker = movePickerOf($this->actingAs($this->staff)->get(movePanelPage($this->booking, [
            'move_doctor' => $this->otherDoctor->id,
            'move_date' => '2026-10-30',
        ])));

        // A visit starting from 14:30 to 15:30 would end after 15:00 or start inside the buffer that ends at 15:45.
        expect(movePickerTimes($picker))->toBe(array_values(array_diff(movePickerFridayTimes(), ['14:30', '14:45', '15:00', '15:15', '15:30'])));
    });

    it('offers the times of another day', function () {
        $picker = movePickerOf($this->actingAs($this->staff)->get(movePanelPage($this->booking, [
            'move_doctor' => $this->doctor->id,
            'move_date' => '2026-10-31',
        ])));

        expect(movePickerTimes($picker))->toBe(['08:00', '08:15', '08:30', '08:45', '09:00', '09:15', '09:30', '09:45', '10:00', '10:15', '10:30', '10:45', '11:00', '11:15']);
    });

    it('gives no times for a doctor who cannot take the service, and says nothing about why', function (string $case) {
        $doctor = match ($case) {
            'other service' => doctorWorking(Service::factory()->create(), [[5, '08:00', '17:00']]),
            'switched off' => tap(doctorWorking($this->service, [[5, '08:00', '17:00']]), fn (Doctor $doctor) => $doctor->user->forceFill(['is_active' => false])->save()),
            'unknown' => null,
        };

        $picker = movePickerOf($this->actingAs($this->staff)->get(movePanelPage($this->booking, [
            'move_doctor' => $doctor?->id ?? 999999,
            'move_date' => '2026-10-30',
        ])));

        expect($picker['slots'])->toBe([]);
    })->with(['other service', 'switched off', 'unknown']);

    it('gives no times for a day beyond the booking window', function () {
        $picker = movePickerOf($this->actingAs($this->staff)->get(movePanelPage($this->booking, [
            'move_doctor' => $this->doctor->id,
            'move_date' => '2026-12-31',
        ])));

        expect($picker['slots'])->toBe([]);
    });

    it('sends nothing to an admin, who may look at the schedule but not change it', function () {
        $response = $this->actingAs(userWithRole(Role::Admin))->get(movePanelPage($this->booking, [
            'move_doctor' => $this->doctor->id,
            'move_date' => '2026-10-30',
        ]));

        expect(movePickerOf($response))->toBeNull();
    });

    it('sends nothing for a booking that is not booked any more', function (string $state) {
        $booking = Appointment::factory()->for($this->doctor)->for($this->service)->{$state}()
            ->create(['starts_at' => CarbonImmutable::parse('2026-10-30 11:00', 'Asia/Manila')->utc()]);

        expect(movePickerOf($this->actingAs($this->staff)->get(movePanelPage($booking))))->toBeNull();
    })->with(['checkedIn', 'completed', 'noShow']);

    it('sends nothing when no panel is open', function () {
        $response = $this->actingAs($this->staff)->get(route('staff.home', ['date' => '2026-10-30']));

        expect(movePickerOf($response))->toBeNull();
    });

    it('refuses a doctor or a day that is not well formed', function (array $query, string $key) {
        $this->actingAs($this->staff)
            ->from(route('staff.home'))
            ->get(movePanelPage($this->booking, $query))
            ->assertRedirect(route('staff.home'))
            ->assertSessionHasErrors($key);
    })->with([
        'doctor not a number' => [['move_doctor' => 'abc'], 'move_doctor'],
        'doctor zero' => [['move_doctor' => '0'], 'move_doctor'],
        'day not a date' => [['move_date' => 'tomorrow'], 'move_date'],
        'day in the wrong format' => [['move_date' => '30-10-2026'], 'move_date'],
    ]);

    it('carries the picker without the patient\'s email or any health field', function () {
        $response = $this->actingAs($this->staff)->get(movePanelPage($this->booking, [
            'move_doctor' => $this->doctor->id,
            'move_date' => '2026-10-30',
        ]));

        expect(array_keys(movePickerOf($response)))->toBe(['doctors', 'slots'])
            ->and(json_encode(movePickerOf($response)))->not->toContain($this->patient->email);
    });
});

describe('what the panel is told', function () {
    it('lets staff move a booked booking', function () {
        $this->actingAs($this->staff)
            ->get(movePanelPage($this->booking))
            ->assertInertia(fn (Assert $page) => $page->where('selected.can.move', true));
    });

    it('offers no move once the booking is not booked any more', function (string $state) {
        $booking = Appointment::factory()->for($this->doctor)->for($this->service)->{$state}()
            ->create(['starts_at' => CarbonImmutable::parse('2026-10-30 11:00', 'Asia/Manila')->utc()]);

        $this->actingAs($this->staff)
            ->get(movePanelPage($booking))
            ->assertInertia(fn (Assert $page) => $page->where('selected.can.move', false));
    })->with(['checkedIn', 'completed', 'noShow']);

    it('offers an admin no move', function () {
        $this->actingAs(userWithRole(Role::Admin))
            ->get(movePanelPage($this->booking))
            ->assertInertia(fn (Assert $page) => $page->where('selected.can.move', false));
    });
});

describe('moving', function () {
    it('moves a booking to another time and opens the panel of the new booking', function () {
        $this->actingAs($this->staff)
            ->post(route('staff.appointments.move', $this->booking), [
                'doctor_id' => $this->otherDoctor->id,
                'starts_at' => '2026-10-30T07:00:00Z',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data.toast.type', 'success')
            ->assertSessionHas('inertia.flash_data.toast.message', 'The appointment of '.$this->patient->name.' was moved to 30 Oct 2026, 3:00 PM.');

        $moved = ($this->moved)();

        expect($moved->doctor_id)->toBe($this->otherDoctor->id)
            ->and($moved->service_id)->toBe($this->service->id)
            ->and($moved->starts_at)->toEqual(CarbonImmutable::parse('2026-10-30 15:00', 'Asia/Manila'))
            ->and($this->booking->fresh()->status)->toBe(AppointmentStatus::Cancelled)
            ->and($this->booking->fresh()->cancelled_by)->toBe($this->staff->id);
    });

    it('returns to the day of the new time with the new booking\'s panel open', function () {
        $response = $this->actingAs($this->staff)->post(route('staff.appointments.move', $this->booking), [
            'doctor_id' => $this->doctor->id,
            'starts_at' => '2026-10-30T07:15:00Z',
        ]);

        $response->assertRedirect(route('staff.home', ['date' => '2026-10-30', 'appointment' => ($this->moved)()->ulid]));
    });

    it('returns to the day of the new time when it is another day', function () {
        // 10:00 on Saturday 31 October in Manila.
        $response = $this->actingAs($this->staff)->post(route('staff.appointments.move', $this->booking), [
            'doctor_id' => $this->doctor->id,
            'starts_at' => '2026-10-31T02:00:00Z',
        ]);

        $response->assertRedirect(route('staff.home', ['date' => '2026-10-31', 'appointment' => ($this->moved)()->ulid]));
    });

    it('shows the booking in its new place and no longer in the old one', function () {
        $this->actingAs($this->staff)
            ->followingRedirects()
            ->post(route('staff.appointments.move', $this->booking), [
                'doctor_id' => $this->otherDoctor->id,
                'starts_at' => '2026-10-30T07:00:00Z',
            ])
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.ulid', ($this->moved)()->ulid)
                ->where('selected.can.move', true)
                ->where('schedule.doctors', function ($doctors): bool {
                    $byName = collect($doctors)->keyBy('name');

                    return collect($byName['Dr. Abe']['appointments'])->isEmpty()
                        && collect($byName['Dr. Zed']['appointments'])->pluck('ulid')->all() === [($this->moved)()->ulid];
                }));
    });

    it('frees the old time for another booking', function () {
        $this->actingAs($this->staff)->post(route('staff.appointments.move', $this->booking), [
            'doctor_id' => $this->doctor->id,
            'starts_at' => '2026-10-30T07:00:00Z',
        ]);

        // The unique index ignores a cancelled booking, so the same doctor and time can be booked again.
        expect(Appointment::factory()->booked()->create([
            'doctor_id' => $this->booking->doctor_id,
            'service_id' => $this->service->id,
            'starts_at' => $this->booking->starts_at,
        ])->exists)->toBeTrue();
    });

    it('turns away everyone but staff with a 403 and changes nothing', function (Role $role) {
        $this->actingAs(userWithRole($role))
            ->post(route('staff.appointments.move', $this->booking), [
                'doctor_id' => $this->otherDoctor->id,
                'starts_at' => '2026-10-30T07:00:00Z',
            ])
            ->assertForbidden();

        ($this->unchanged)();
    })->with([
        'admin' => Role::Admin,
        'nurse' => Role::Nurse,
        'doctor' => Role::Doctor,
        'patient' => Role::Patient,
    ]);

    it('turns away the patient who owns the booking', function () {
        // The Policy lets a patient cancel their own booking from "My appointments". This staff route must not.
        $this->actingAs($this->patient)
            ->post(route('staff.appointments.move', $this->booking), [
                'doctor_id' => $this->otherDoctor->id,
                'starts_at' => '2026-10-30T07:00:00Z',
            ])
            ->assertForbidden();

        ($this->unchanged)();
    });

    it('turns an admin away with a 403 even when the form is empty', function () {
        // Authorization comes before validation, so an admin learns nothing about what the route wants.
        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('staff.appointments.move', $this->booking))
            ->assertForbidden();
    });

    it('sends a guest to the login page', function () {
        $this->post(route('staff.appointments.move', $this->booking), [
            'doctor_id' => $this->otherDoctor->id,
            'starts_at' => '2026-10-30T07:00:00Z',
        ])->assertRedirect(route('login'));

        ($this->unchanged)();
    });

    it('sends staff without two-factor to set it up first', function () {
        $this->actingAs(userWithRole(Role::Staff, twoFactor: false))
            ->post(route('staff.appointments.move', $this->booking), [
                'doctor_id' => $this->otherDoctor->id,
                'starts_at' => '2026-10-30T07:00:00Z',
            ])
            ->assertRedirect();

        ($this->unchanged)();
    });

    it('asks for a doctor and a time', function () {
        $this->actingAs($this->staff)
            ->from(movePanelPage($this->booking))
            ->post(route('staff.appointments.move', $this->booking))
            ->assertRedirect(movePanelPage($this->booking))
            ->assertSessionHasErrors(['doctor_id' => 'Pick a doctor.', 'starts_at' => 'Pick a new time.'])
            ->assertSessionMissing('inertia.flash_data.toast');

        ($this->unchanged)();
    });

    it('refuses a time that is not an ISO 8601 UTC instant', function (mixed $value) {
        $this->actingAs($this->staff)
            ->from(movePanelPage($this->booking))
            ->post(route('staff.appointments.move', $this->booking), ['doctor_id' => $this->doctor->id, 'starts_at' => $value])
            ->assertRedirect(movePanelPage($this->booking))
            ->assertSessionHasErrors('starts_at');

        ($this->unchanged)();
    })->with([
        'a clock time' => '15:00',
        'no zone' => '2026-10-30T07:00:00',
        'a zone offset' => '2026-10-30T15:00:00+08:00',
        'a space instead of the T' => '2026-10-30 07:00:00Z',
        'words' => 'tomorrow afternoon',
        'an array' => [['2026-10-30T07:00:00Z']],
    ]);

    it('refuses a doctor who is not a number', function (mixed $value) {
        $this->actingAs($this->staff)
            ->from(movePanelPage($this->booking))
            ->post(route('staff.appointments.move', $this->booking), ['doctor_id' => $value, 'starts_at' => '2026-10-30T07:00:00Z'])
            ->assertRedirect(movePanelPage($this->booking))
            ->assertSessionHasErrors('doctor_id');

        ($this->unchanged)();
    })->with(['text' => 'abc', 'an array' => [[1]], 'a decimal' => '1.5']);

    it('refuses a doctor who cannot take the service', function (string $case) {
        $doctor = match ($case) {
            'other service' => doctorWorking(Service::factory()->create(), [[5, '08:00', '17:00']]),
            'switched off' => tap(doctorWorking($this->service, [[5, '08:00', '17:00']]), fn (Doctor $doctor) => $doctor->user->forceFill(['is_active' => false])->save()),
            'unknown' => null,
        };

        $this->actingAs($this->staff)
            ->from(movePanelPage($this->booking))
            ->post(route('staff.appointments.move', $this->booking), ['doctor_id' => $doctor?->id ?? 999999, 'starts_at' => '2026-10-30T07:00:00Z'])
            ->assertRedirect(movePanelPage($this->booking))
            ->assertSessionHasErrors(['doctor_id' => 'Pick a doctor who offers this service.']);

        ($this->unchanged)();
    })->with(['other service', 'switched off', 'unknown']);

    it('sends the clash message back to the panel when the time is taken, and keeps the old booking', function () {
        Appointment::factory()->for($this->otherDoctor)->for($this->service)->booked()
            ->create(['starts_at' => CarbonImmutable::parse('2026-10-30 15:00', 'Asia/Manila')->utc()]);
        $this->bookingsBefore = Appointment::query()->count();

        $this->actingAs($this->staff)
            ->from(movePanelPage($this->booking))
            ->post(route('staff.appointments.move', $this->booking), ['doctor_id' => $this->otherDoctor->id, 'starts_at' => '2026-10-30T07:00:00Z'])
            ->assertRedirect(movePanelPage($this->booking))
            ->assertSessionHasErrors(['starts_at' => 'This time is no longer free. Please pick another slot.'])
            ->assertSessionMissing('inertia.flash_data.toast');

        ($this->unchanged)();
    });

    it('refuses a time the doctor does not offer', function (string $startsAt) {
        $this->actingAs($this->staff)
            ->from(movePanelPage($this->booking))
            ->post(route('staff.appointments.move', $this->booking), ['doctor_id' => $this->otherDoctor->id, 'starts_at' => $startsAt])
            ->assertRedirect(movePanelPage($this->booking))
            ->assertSessionHasErrors(['starts_at' => 'This time is no longer free. Please pick another slot.']);

        ($this->unchanged)();
    })->with([
        'during the lunch break' => '2026-10-30T04:15:00Z',
        'inside the 2 hour lead time' => '2026-10-30T02:00:00Z',
        'on a day with no hours' => '2026-11-01T02:00:00Z',
    ]);

    it('refuses to move a booking to where it already is', function () {
        $this->actingAs($this->staff)
            ->from(movePanelPage($this->booking))
            ->post(route('staff.appointments.move', $this->booking), ['doctor_id' => $this->doctor->id, 'starts_at' => '2026-10-30T06:00:00Z'])
            ->assertRedirect(movePanelPage($this->booking))
            ->assertSessionHasErrors(['starts_at' => 'The appointment is already at this time. Please pick another slot.']);

        ($this->unchanged)();
    });

    it('sends the error back to the panel when the booking is not booked', function (string $state) {
        $booking = Appointment::factory()->for($this->doctor)->for($this->service)->{$state}()
            ->create(['starts_at' => CarbonImmutable::parse('2026-10-30 11:00', 'Asia/Manila')->utc()]);
        $this->bookingsBefore = Appointment::query()->count();

        $this->actingAs($this->staff)
            ->from(movePanelPage($booking))
            ->post(route('staff.appointments.move', $booking), ['doctor_id' => $this->otherDoctor->id, 'starts_at' => '2026-10-30T07:00:00Z'])
            ->assertRedirect(movePanelPage($booking))
            ->assertSessionHasErrors(['appointment' => 'This appointment can no longer be moved.'])
            ->assertSessionMissing('inertia.flash_data.toast');

        expect($booking->fresh()->status)->toBe($booking->status)
            ->and(Appointment::query()->count())->toBe($this->bookingsBefore);
    })->with(['checkedIn', 'completed', 'noShow', 'cancelled']);

    it('answers 404 for a ULID that does not exist', function () {
        $this->actingAs($this->staff)
            ->post(route('staff.appointments.move', '01JAAAAAAAAAAAAAAAAAAAAAAA'), ['doctor_id' => $this->doctor->id, 'starts_at' => '2026-10-30T07:00:00Z'])
            ->assertNotFound();
    });

});

describe('rate limit', function () {
    it('also caps the move route at 60 clicks a minute per staff member', function () {
        RateLimiter::clear('staff-actions');

        // A refused move (no body) counts too: the limit is on clicks, not on successes.
        foreach (range(1, 60) as $click) {
            $this->actingAs($this->staff)->post(route('staff.appointments.move', $this->booking));
        }

        $this->actingAs($this->staff)
            ->post(route('staff.appointments.move', $this->booking), ['doctor_id' => $this->otherDoctor->id, 'starts_at' => '2026-10-30T07:00:00Z'])
            ->assertStatus(429);

        ($this->unchanged)();
    });
});
