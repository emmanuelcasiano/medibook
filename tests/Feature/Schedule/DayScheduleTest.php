<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// The staff day grid (step 7.1): who may open it, which bookings it shows for a clinic day, and how the hours of the
// grid are chosen. "Now" is Friday 30 October 2026, 09:00 in Manila, and the day under test is Monday 2 November.
// Action buttons (check in, no-show, cancel, move) come in steps 7.2 to 7.4.

const SCHED_DAY = '2026-11-02';

/** A doctor with the given name who works Mondays between $start and $end (clinic time), or not at all with null. */
function schedDoctor(string $name, ?array $hours = ['08:00', '17:00']): Doctor
{
    $doctor = Doctor::factory()->create();
    $doctor->user->forceFill(['name' => $name])->save();

    if ($hours !== null) {
        WorkingHour::factory()->for($doctor)->onWeekday(1)->between($hours[0], $hours[1])->create();
    }

    return $doctor;
}

/** A booking for $doctor starting at a clinic time. $state is an AppointmentFactory state. */
function schedBooking(Doctor $doctor, string $clinicTime, string $state = 'booked', ?string $patientName = null, ?Service $service = null): Appointment
{
    $patient = User::factory()->patient()->create($patientName === null ? [] : ['name' => $patientName]);

    return Appointment::factory()
        ->for($doctor)
        ->for($service ?? Service::factory()->lasting(30)->create())
        ->for($patient, 'patient')
        ->{$state}()
        ->create(['starts_at' => CarbonImmutable::parse($clinicTime, 'Asia/Manila')->utc()]);
}

function schedStaff(): User
{
    return userWithRole(Role::Staff);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));
});

describe('who may open it', function () {
    it('shows the schedule to staff', function () {
        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => SCHED_DAY]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/schedule')
                ->where('schedule.date', SCHED_DAY));
    });

    it('shows an admin the same bookings as staff', function () {
        $doctor = schedDoctor('Dr. Reyes');
        schedBooking($doctor, '2026-11-02 09:00', patientName: 'Ana Cruz');

        $staffView = $this->actingAs(schedStaff())->get(route('staff.home', ['date' => SCHED_DAY]))->assertOk();
        $adminView = $this->actingAs(userWithRole(Role::Admin))->get(route('staff.home', ['date' => SCHED_DAY]))->assertOk();

        expect($adminView->inertiaProps('schedule'))->toEqual($staffView->inertiaProps('schedule'));
    });

    it('answers 403 to a patient or a doctor, and sends no bookings', function (Role $role) {
        schedBooking(schedDoctor('Dr. Reyes'), '2026-11-02 09:00', patientName: 'Ana Cruz');

        $this->actingAs(userWithRole($role))
            ->get(route('staff.home', ['date' => SCHED_DAY]))
            ->assertForbidden()
            ->assertDontSee('Ana Cruz');
    })->with([
        'patient' => Role::Patient,
        'doctor' => Role::Doctor,
    ]);

    it('sends a guest to the login page', function () {
        $this->get(route('staff.home'))->assertRedirect(route('login'));
    });
});

describe('the day', function () {
    it('puts each booking in its doctor\'s column with the visit, the patient and the status', function () {
        $reyes = schedDoctor('Dr. Reyes');
        $santos = schedDoctor('Dr. Santos');
        $checkup = Service::factory()->lasting(45)->create(['name' => 'Check-up']);
        $booking = schedBooking($reyes, '2026-11-02 09:30', patientName: 'Ana Cruz', service: $checkup);
        schedBooking($santos, '2026-11-02 10:00', 'checkedIn', 'Ben Lim');

        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => SCHED_DAY]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.doctors.0.name', 'Dr. Reyes')
                ->where('schedule.doctors.0.appointments', fn ($rows) => collect($rows)->pluck('ulid')->all() === [$booking->ulid])
                ->where('schedule.doctors.0.appointments.0.patientName', 'Ana Cruz')
                ->where('schedule.doctors.0.appointments.0.serviceName', 'Check-up')
                ->where('schedule.doctors.0.appointments.0.status', 'booked')
                ->where('schedule.doctors.0.appointments.0.statusLabel', 'Booked')
                // 09:30 is 570 minutes after clinic midnight, and the visit is 45 minutes long.
                ->where('schedule.doctors.0.appointments.0.offsetMinutes', 570)
                ->where('schedule.doctors.0.appointments.0.durationMinutes', 45)
                ->where('schedule.doctors.0.appointments.0.startsAt', '2026-11-02T01:30:00Z')
                ->where('schedule.doctors.0.appointments.0.endsAt', '2026-11-02T02:15:00Z')
                ->where('schedule.doctors.1.name', 'Dr. Santos')
                ->where('schedule.doctors.1.appointments.0.patientName', 'Ben Lim')
                ->where('schedule.doctors.1.appointments.0.statusLabel', 'Checked in'));
    });

    it('lists the doctors by name', function () {
        schedDoctor('Dr. Zamora');
        schedDoctor('Dr. Abad');
        schedDoctor('Dr. Mendoza');

        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => SCHED_DAY]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.doctors', fn ($rows) => collect($rows)->pluck('name')->all() === ['Dr. Abad', 'Dr. Mendoza', 'Dr. Zamora']));
    });

    it('keeps two doctors with the same name in a steady order, by id', function () {
        $first = schedDoctor('Dr. Reyes');
        $second = schedDoctor('Dr. Reyes');

        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => SCHED_DAY]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.doctors', fn ($rows) => collect($rows)->pluck('id')->all() === [$first->id, $second->id]));
    });

    it('shows a booking in each status except cancelled', function () {
        $doctor = schedDoctor('Dr. Reyes');
        schedBooking($doctor, '2026-11-02 08:00', 'booked');
        schedBooking($doctor, '2026-11-02 09:00', 'checkedIn');
        schedBooking($doctor, '2026-11-02 10:00', 'completed');
        schedBooking($doctor, '2026-11-02 11:00', 'noShow');
        schedBooking($doctor, '2026-11-02 12:00', 'cancelled');

        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => SCHED_DAY]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.doctors.0.appointments', fn ($rows) => collect($rows)->pluck('status')->all() === ['booked', 'checked_in', 'completed', 'no_show']));
    });

    it('reads the day in clinic time, so the edges against UTC land on the right day', function (string $clinicTime, bool $onTheDay) {
        $doctor = schedDoctor('Dr. Reyes');
        $booking = schedBooking($doctor, $clinicTime);

        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => SCHED_DAY]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.doctors.0.appointments', fn ($rows) => collect($rows)->pluck('ulid')->all() === ($onTheDay ? [$booking->ulid] : [])));
    })->with([
        // Midnight in Manila is 16:00 UTC the day before; the day ends at the next midnight.
        'last minute of the day before' => ['2026-11-01 23:59', false],
        'first minute of the day' => ['2026-11-02 00:00', true],
        'early morning, the day before in UTC' => ['2026-11-02 07:30', true],
        'last minute of the day' => ['2026-11-02 23:59', true],
        'first minute of the day after' => ['2026-11-03 00:00', false],
    ]);

    it('opens on today in clinic time when no date is given', function () {
        // 00:30 on Saturday 31 October in Manila is still Friday 30 October in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-10-31 00:30', 'Asia/Manila'));

        $this->actingAs(schedStaff())
            ->get(route('staff.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.date', '2026-10-31')
                ->where('schedule.today', '2026-10-31'));
    });

    it('gives the previous and next day, and today, for the buttons', function () {
        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => '2026-11-01']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.date', '2026-11-01')
                ->where('schedule.previousDate', '2026-10-31')
                ->where('schedule.nextDate', '2026-11-02')
                ->where('schedule.today', '2026-10-30'));
    });

    it('steps over a month and a year end', function () {
        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => '2026-12-31']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.previousDate', '2026-12-30')
                ->where('schedule.nextDate', '2027-01-01'));
    });

    it('tells the page the row size from the slot step', function (int $configured, int $expected) {
        config(['medibook.slot_step_minutes' => $configured]);

        $this->actingAs(schedStaff())
            ->get(route('staff.home'))
            ->assertInertia(fn (Assert $page) => $page->where('schedule.stepMinutes', $expected));
    })->with([
        'the default' => [15, 15],
        'another step' => [30, 30],
        'a step that would never move' => [0, 1],
    ]);
});

describe('the doctors', function () {
    it('marks a doctor who does not work that weekday', function () {
        schedDoctor('Dr. Reyes');
        schedDoctor('Dr. Santos', null);

        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => SCHED_DAY]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.doctors.0.isWorking', true)
                ->where('schedule.doctors.1.isWorking', false));
    });

    it('does not count hours of another weekday as working', function () {
        $doctor = schedDoctor('Dr. Reyes', null);
        WorkingHour::factory()->for($doctor)->onWeekday(2)->between('08:00', '17:00')->create();

        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => SCHED_DAY]))
            ->assertInertia(fn (Assert $page) => $page->where('schedule.doctors.0.isWorking', false));
    });

    it('keeps a deactivated doctor who still has a booking that day, and hides one who has none', function () {
        $withBooking = schedDoctor('Dr. Reyes');
        $withBooking->user->forceFill(['is_active' => false])->save();
        schedBooking($withBooking, '2026-11-02 09:00');

        $without = schedDoctor('Dr. Santos');
        $without->user->forceFill(['is_active' => false])->save();

        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => SCHED_DAY]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.doctors', fn ($rows) => collect($rows)->pluck('name')->all() === ['Dr. Reyes']));
    });

    it('hides a deactivated doctor whose only booking that day was cancelled', function () {
        $inactive = schedDoctor('Dr. Reyes');
        $inactive->user->forceFill(['is_active' => false])->save();
        schedBooking($inactive, '2026-11-02 09:00', 'cancelled');
        schedDoctor('Dr. Santos');

        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => SCHED_DAY]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.doctors', fn ($rows) => collect($rows)->pluck('name')->all() === ['Dr. Santos']));
    });
});

describe('the hours of the grid', function () {
    it('covers the working hours of the doctors', function () {
        schedDoctor('Dr. Reyes', ['09:30', '12:15']);
        schedDoctor('Dr. Santos', ['13:00', '16:00']);

        // The earliest start (09:30) is rounded down to 09:00 and the latest end (16:00) stays. The fallback would be
        // 08:00 to 17:00, so this proves the hours are used.
        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => SCHED_DAY]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.startMinute', 9 * 60)
                ->where('schedule.endMinute', 16 * 60));
    });

    it('is widened so that a booking outside the working hours is not cut off', function () {
        $doctor = schedDoctor('Dr. Reyes', ['09:00', '12:00']);
        schedBooking($doctor, '2026-11-02 07:30');
        schedBooking($doctor, '2026-11-02 17:45');

        // The last visit is 30 minutes, so it ends at 18:15 and the grid ends at 19:00.
        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => SCHED_DAY]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.startMinute', 7 * 60)
                ->where('schedule.endMinute', 19 * 60));
    });

    it('stops at midnight when a visit runs past the end of the day', function () {
        $doctor = schedDoctor('Dr. Reyes', ['09:00', '12:00']);
        schedBooking($doctor, '2026-11-02 23:30', service: Service::factory()->lasting(60)->create());

        // The visit ends at 00:30 the next day. The grid keeps to the one clinic day.
        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => SCHED_DAY]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.startMinute', 9 * 60)
                ->where('schedule.endMinute', 1440));
    });

    it('falls back to 08:00 to 17:00 when nobody works and nothing is booked', function () {
        schedDoctor('Dr. Reyes', null);

        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => SCHED_DAY]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.startMinute', 480)
                ->where('schedule.endMinute', 1020));
    });

    it('shows an empty grid when there are no doctors at all', function () {
        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => SCHED_DAY]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('schedule.doctors', []));
    });
});

describe('what the page may learn', function () {
    it('sends the patient\'s name and nothing else about them', function () {
        $booking = schedBooking(schedDoctor('Dr. Reyes'), '2026-11-02 09:00', patientName: 'Ana Cruz');
        $booking->patient->forceFill(['email' => 'ana.private@example.test'])->save();

        $response = $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => SCHED_DAY]))
            ->assertOk()
            ->assertDontSee('ana.private@example.test');

        expect(collect($response->inertiaProps('schedule.doctors.0.appointments.0'))->keys()->sort()->values()->all())->toBe([
            'durationMinutes', 'endsAt', 'offsetMinutes', 'patientName', 'serviceName', 'startsAt', 'status', 'statusLabel', 'ulid',
        ]);
    });

    it('sends only the doctor\'s name, specialty and whether they work', function () {
        schedDoctor('Dr. Reyes');

        $response = $this->actingAs(schedStaff())->get(route('staff.home', ['date' => SCHED_DAY]))->assertOk();

        expect(collect($response->inertiaProps('schedule.doctors.0'))->keys()->sort()->values()->all())
            ->toBe(['appointments', 'id', 'isActive', 'isWorking', 'name', 'specialty']);
    });

    it('marks a deactivated doctor so the page can say so', function () {
        $active = schedDoctor('Dr. Reyes');
        $inactive = schedDoctor('Dr. Santos');
        $inactive->user->forceFill(['is_active' => false])->save();
        schedBooking($inactive, '2026-11-02 09:00');

        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => SCHED_DAY]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.doctors.0.id', $active->id)
                ->where('schedule.doctors.0.isActive', true)
                ->where('schedule.doctors.1.id', $inactive->id)
                ->where('schedule.doctors.1.isActive', false));
    });

    it('runs the same number of queries however many bookings there are', function () {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs(schedStaff())->get(route('staff.home', ['date' => SCHED_DAY]))->assertOk();

            return count(DB::getQueryLog());
        };

        $doctor = schedDoctor('Dr. Reyes');
        schedBooking($doctor, '2026-11-02 08:00');
        $few = $queries();

        foreach (['09:00', '10:00', '11:00'] as $time) {
            schedBooking($doctor, "2026-11-02 $time");
        }

        foreach (['Dr. Santos', 'Dr. Lim'] as $name) {
            schedBooking(schedDoctor($name), '2026-11-02 09:00');
        }

        expect($queries())->toBe($few);
    });
});

describe('the date', function () {
    it('refuses a date that is not a real Y-m-d day', function (mixed $date) {
        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => $date]))
            ->assertSessionHasErrors('date');
    })->with([
        'a word' => 'tomorrow',
        'a month that does not exist' => '2026-13-01',
        'a day that does not exist' => '2026-02-31',
        'day first' => '02-11-2026',
        'with a time' => '2026-11-02 09:00',
        'a list' => [['2026-11-02']],
    ]);

    it('opens on today for an empty date', function () {
        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => '']))
            ->assertSessionHasNoErrors()
            ->assertInertia(fn (Assert $page) => $page->where('schedule.date', '2026-10-30'));
    });

    it('shows a past day and a far future day', function (string $date) {
        $this->actingAs(schedStaff())
            ->get(route('staff.home', ['date' => $date]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('schedule.date', $date));
    })->with(['2020-01-06', '2030-06-03']);
});
