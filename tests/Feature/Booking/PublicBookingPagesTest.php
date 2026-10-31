<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Doctor;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

// "Now" is Friday 30 October 2026, 09:00 in Manila. The booking window is 30 days, so the pages offer the 31 days
// from Friday 30 October to Sunday 29 November. The lead time is 2 hours, so nothing today starts before 11:00.
// Mondays in the window: 2, 9, 16 and 23 November. The slot rules themselves (time off, buffers, lead time) are
// tested in FindAvailableSlotsTest. These tests cover what the public pages show and refuse.
// The pages are public and read-only, so there is no Policy and no "denied" role. The refusals are the 404s for a
// service, doctor or pair that can't be booked, and the validation of the date.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));
});

/** A 60-minute service with no buffer, so a 08:00 to 12:00 Monday gives 13 start times (08:00 to 11:00). */
function hourService(array $state = []): Service
{
    return Service::factory()->lasting(60)->create($state);
}

/** A doctor with a name, who offers $service and works $ranges (see doctorWorking). */
function namedDoctor(string $name, Service $service, array $ranges = [[1, '08:00', '12:00']]): Doctor
{
    $doctor = doctorWorking($service, $ranges);
    $doctor->user->forceFill(['name' => $name])->save();

    return $doctor;
}

describe('services page', function () {
    it('lists the bookable services to a guest, by name, with public fields only', function () {
        $zeta = hourService(['name' => 'Zeta check', 'slug' => 'zeta-check', 'description' => 'Last in the list.']);
        $alpha = hourService(['name' => 'Alpha check', 'slug' => 'alpha-check', 'description' => null]);
        doctorWorking($zeta, []);
        doctorWorking($alpha, []);

        $this->get(route('booking.services'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('booking/services')
                ->has('services', 2)
                ->where('services.0', ['slug' => 'alpha-check', 'name' => 'Alpha check', 'description' => null, 'durationMinutes' => 60])
                ->where('services.1.slug', 'zeta-check')
                ->where('services.1.description', 'Last in the list.')
                ->missing('services.0.id')
                ->missing('services.0.bufferMinutes')
                ->missing('services.0.isActive'));
    });

    it('leaves out a switched-off service, a service nobody offers, and a service only a deactivated doctor offers', function () {
        $visible = hourService(['name' => 'Visible']);
        $off = hourService(['name' => 'Switched off', 'is_active' => false]);
        hourService(['name' => 'Nobody offers it']);
        $onlyInactive = hourService(['name' => 'Only an inactive doctor']);

        doctorWorking($visible, []);
        doctorWorking($off, []);
        $retired = doctorWorking($onlyInactive, []);
        $retired->user->forceFill(['is_active' => false])->save();

        $this->get(route('booking.services'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('services', 1)
                ->where('services.0.name', 'Visible'));
    });

    it('shows an empty list when nothing can be booked', function () {
        $this->get(route('booking.services'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('booking/services')->has('services', 0));
    });

    it('opens for a guest and for every role', function (?Role $role) {
        doctorWorking(hourService(), []);

        $request = $role === null ? $this : $this->actingAs(userWithRole($role));

        $request->get(route('booking.services'))->assertOk();
    })->with([
        'guest' => [null],
        'patient' => [Role::Patient],
        'staff' => [Role::Staff],
        'doctor' => [Role::Doctor],
        'admin' => [Role::Admin],
    ]);
});

describe('doctors page', function () {
    it('lists the doctors who offer the service, by name, with public fields only', function () {
        $service = hourService(['slug' => 'checkup']);
        $other = hourService();
        namedDoctor('Dr. Zara Zamora', $service);
        $ana = namedDoctor('Dr. Ana Aquino', $service);
        namedDoctor('Dr. Not Offering', $other);

        $this->get(route('booking.doctors', $service))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('booking/doctors')
                ->where('service.slug', 'checkup')
                ->where('service.durationMinutes', 60)
                ->has('doctors', 2)
                ->where('doctors.0.id', $ana->id)
                ->where('doctors.0.name', 'Dr. Ana Aquino')
                ->where('doctors.0.specialty', $ana->specialty)
                ->where('doctors.1.name', 'Dr. Zara Zamora')
                ->missing('doctors.0.email')
                ->missing('doctors.0.user_id'));
    });

    it('leaves out a doctor whose account is deactivated', function () {
        $service = hourService();
        namedDoctor('Dr. Active', $service);
        $retired = namedDoctor('Dr. Retired', $service);
        $retired->user->forceFill(['is_active' => false])->save();

        $this->get(route('booking.doctors', $service))
            ->assertInertia(fn (Assert $page) => $page
                ->has('doctors', 1)
                ->where('doctors.0.name', 'Dr. Active'));
    });

    it('opens for a patient too', function () {
        $service = hourService();
        doctorWorking($service, []);

        $this->actingAs(userWithRole(Role::Patient))->get(route('booking.doctors', $service))->assertOk();
    });

    it('answers 404 for a switched-off service, and for a slug that does not exist', function () {
        $off = hourService(['is_active' => false]);
        doctorWorking($off, []);

        $this->get(route('booking.doctors', $off))->assertNotFound();
        $this->get('/book/no-such-service')->assertNotFound();
    });
});

describe('slots page', function () {
    it('shows the whole window, the first day with free times, and its times as UTC', function () {
        $service = hourService();
        $doctor = namedDoctor('Dr. Ana Aquino', $service);

        $this->get(route('booking.slots.doctor', [$service, $doctor]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('booking/slots')
                ->where('service.name', $service->name)
                ->where('doctor.id', $doctor->id)
                ->where('doctor.name', 'Dr. Ana Aquino')
                ->has('days', 31)
                ->where('days.0', ['date' => '2026-10-30', 'slotsCount' => 0])
                ->where('days.3', ['date' => '2026-11-02', 'slotsCount' => 13])
                ->where('days.10', ['date' => '2026-11-09', 'slotsCount' => 13])
                ->where('days.30', ['date' => '2026-11-29', 'slotsCount' => 0])
                ->where('selectedDate', '2026-11-02')
                ->has('slots', 13)
                // 08:00 in Manila is 00:00 UTC.
                ->where('slots.0', ['startsAt' => '2026-11-02T00:00:00Z'])
                ->where('slots.12', ['startsAt' => '2026-11-02T03:00:00Z']));
    });

    it('shows the day that was asked for', function () {
        $service = hourService();
        $doctor = namedDoctor('Dr. Ana Aquino', $service, [[1, '08:00', '12:00'], [3, '13:00', '14:00']]);

        $this->get(route('booking.slots.doctor', [$service, $doctor, 'date' => '2026-11-04']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedDate', '2026-11-04')
                ->has('slots', 1)
                ->where('slots.0.startsAt', '2026-11-04T05:00:00Z'));
    });

    it('shows a day without free times as an empty list, not an error', function () {
        $service = hourService();
        $doctor = namedDoctor('Dr. Ana Aquino', $service);

        $this->get(route('booking.slots.doctor', [$service, $doctor, 'date' => '2026-11-03']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedDate', '2026-11-03')
                ->has('slots', 0));
    });

    it('opens on today when nothing is free in the whole window', function () {
        $service = hourService();
        $doctor = namedDoctor('Dr. Ana Aquino', $service, []);

        $this->get(route('booking.slots.doctor', [$service, $doctor]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedDate', '2026-10-30')
                ->has('days', 31)
                ->has('slots', 0));
    });

    it('does not offer a booked time, time off, or a time inside the lead time', function () {
        $service = hourService();
        // Friday works too, so today shows the lead time: now is 09:00, so nothing before 11:00.
        $doctor = namedDoctor('Dr. Ana Aquino', $service, [[1, '08:00', '12:00'], [5, '08:00', '13:00']]);
        bookingAt($doctor, $service, '2026-11-02 08:00');
        slotTimeOff($doctor, '2026-11-09 08:00', '2026-11-09 17:00');

        $this->get(route('booking.slots.doctor', [$service, $doctor]))
            ->assertInertia(fn (Assert $page) => $page
                // Today: 11:00, 11:15, 11:30, 11:45 and 12:00 (a one-hour visit must end by 13:00).
                ->where('days.0', ['date' => '2026-10-30', 'slotsCount' => 5])
                // 2 November: the visit 08:00 to 09:00 blocks the starts 07:15 to 08:45, so 09:00 to 11:00 are left.
                ->where('days.3', ['date' => '2026-11-02', 'slotsCount' => 9])
                // 9 November is time off, all day.
                ->where('days.10', ['date' => '2026-11-09', 'slotsCount' => 0])
                ->where('selectedDate', '2026-10-30'));
    });

    it('lists a time once under "Any doctor" and never says which doctor is free', function () {
        $service = hourService();
        namedDoctor('Dr. One', $service, [[1, '08:00', '10:00']]);
        namedDoctor('Dr. Two', $service, [[1, '08:00', '12:00']]);

        $this->get(route('booking.slots.any', $service))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('booking/slots')
                ->where('doctor', null)
                ->where('selectedDate', '2026-11-02')
                // Two doctors share 08:00 to 09:00, the second also works to 11:00 (start times 08:00 to 11:00).
                ->has('slots', 13)
                ->where('slots.0', ['startsAt' => '2026-11-02T00:00:00Z'])
                ->missing('slots.0.doctorId')
                ->missing('slots.0.doctor'));
    });

    it('keeps a time under "Any doctor" while one of the doctors is still free', function () {
        $service = hourService();
        $busy = namedDoctor('Dr. Busy', $service, [[1, '08:00', '09:00']]);
        namedDoctor('Dr. Free', $service, [[1, '08:00', '09:00']]);
        bookingAt($busy, $service, '2026-11-02 08:00');

        $this->get(route('booking.slots.any', $service))
            ->assertInertia(fn (Assert $page) => $page
                ->has('slots', 1)
                ->where('slots.0.startsAt', '2026-11-02T00:00:00Z'));

        // The busy doctor alone has nothing left on that day.
        $this->get(route('booking.slots.doctor', [$service, $busy, 'date' => '2026-11-02']))
            ->assertInertia(fn (Assert $page) => $page->where('selectedDate', '2026-11-02')->has('slots', 0));
    });

    it('opens for a patient too', function () {
        $service = hourService();
        $doctor = namedDoctor('Dr. Ana Aquino', $service);

        $this->actingAs(userWithRole(Role::Patient))->get(route('booking.slots.doctor', [$service, $doctor]))->assertOk();
        $this->actingAs(userWithRole(Role::Patient))->get(route('booking.slots.any', $service))->assertOk();
    });

    it('sends no patient or booking data in the props', function () {
        $service = hourService();
        $doctor = namedDoctor('Dr. Ana Aquino', $service);
        bookingAt($doctor, $service, '2026-11-02 08:00');

        // A scoped `has()` fails when the object holds any key that is not listed, so these are the exact keys.
        $this->get(route('booking.slots.doctor', [$service, $doctor, 'date' => '2026-11-09']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('doctor', fn (Assert $doctor) => $doctor->hasAll(['id', 'name', 'specialty', 'bio']))
                ->has('slots.0', fn (Assert $slot) => $slot->has('startsAt'))
                ->has('service', fn (Assert $service) => $service->hasAll(['slug', 'name', 'description', 'durationMinutes']))
                ->missing('appointments')
                ->missing('patients'));
    });
});

describe('rate limit', function () {
    it('lets a visitor view 60 pages a minute and then answers 429, on every booking page', function () {
        $service = hourService();
        $doctor = namedDoctor('Dr. Ana Aquino', $service);

        for ($view = 0; $view < 60; $view++) {
            $this->get(route('booking.services'))->assertOk();
        }

        // The counter is shared by the whole booking group, so the next page is refused too.
        $this->get(route('booking.services'))->assertTooManyRequests();
        $this->get(route('booking.doctors', $service))->assertTooManyRequests();
        $this->get(route('booking.slots.any', $service))->assertTooManyRequests();
        $this->get(route('booking.slots.doctor', [$service, $doctor]))->assertTooManyRequests();
    });

    it('counts each visitor on their own, and the limit ends after a minute', function () {
        for ($view = 0; $view < 60; $view++) {
            $this->get(route('booking.services'))->assertOk();
        }

        $this->get(route('booking.services'))->assertTooManyRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get(route('booking.services'))->assertOk();

        $this->travel(61)->seconds();

        $this->get(route('booking.services'))->assertOk();
    });
});

describe('slots page refusals', function () {
    it('answers 404 for a switched-off service, with or without a doctor', function () {
        $off = hourService(['is_active' => false]);
        $doctor = namedDoctor('Dr. Ana Aquino', $off);

        $this->get(route('booking.slots.any', $off))->assertNotFound();
        $this->get(route('booking.slots.doctor', [$off, $doctor]))->assertNotFound();
    });

    it('answers 404 for a doctor who does not offer the service', function () {
        $service = hourService();
        $other = hourService();
        $stranger = namedDoctor('Dr. Other', $other);

        $this->get(route('booking.slots.doctor', [$service, $stranger]))->assertNotFound();
    });

    it('answers 404 for a deactivated doctor, even when they offer the service', function () {
        $service = hourService();
        $retired = namedDoctor('Dr. Retired', $service);
        $retired->user->forceFill(['is_active' => false])->save();

        $this->get(route('booking.slots.doctor', [$service, $retired]))->assertNotFound();
    });

    it('answers 404 for a slug or a doctor that does not exist', function () {
        $service = hourService();

        $this->get('/book/no-such-service/any')->assertNotFound();
        $this->get('/book/'.$service->slug.'/doctors/999999')->assertNotFound();
    });

    it('sends a bad date back to the same page with an error on the date', function (string $date) {
        $service = hourService();
        $doctor = namedDoctor('Dr. Ana Aquino', $service);
        $url = route('booking.slots.doctor', [$service, $doctor]);

        $this->get($url.'?date='.$date)
            ->assertRedirect($url)
            ->assertSessionHasErrors('date');
    })->with([
        'not a date' => 'next-tuesday',
        'wrong format' => '02-11-2026',
        'impossible month' => '2026-13-45',
        'yesterday' => '2026-10-29',
        'one day past the window' => '2026-11-30',
        'far in the past' => '1999-01-01',
    ]);

    it('checks the date under "Any doctor" too', function () {
        $service = hourService();
        namedDoctor('Dr. Ana Aquino', $service);

        $this->get(route('booking.slots.any', [$service, 'date' => '2027-01-01']))
            ->assertRedirect(route('booking.slots.any', $service))
            ->assertSessionHasErrors('date');
    });

    it('takes the window for the date check from config', function () {
        config(['medibook.booking_window_days' => 7]);
        $service = hourService();
        $doctor = namedDoctor('Dr. Ana Aquino', $service);
        $url = route('booking.slots.doctor', [$service, $doctor]);

        // 6 November is the last day of a 7-day window. 7 November is one day past it.
        $this->get($url.'?date=2026-11-06')->assertOk();
        $this->get($url.'?date=2026-11-07')->assertRedirect($url)->assertSessionHasErrors('date');
    });

    it('accepts the first and the last day of the window', function (string $date) {
        $service = hourService();
        $doctor = namedDoctor('Dr. Ana Aquino', $service);

        $this->get(route('booking.slots.doctor', [$service, $doctor, 'date' => $date]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('selectedDate', $date));
    })->with([
        'today' => '2026-10-30',
        'last day' => '2026-11-29',
    ]);
});
