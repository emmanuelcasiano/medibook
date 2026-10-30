<?php

declare(strict_types=1);

use App\Actions\Admin\CountBookingsInTimeOff;
use App\Actions\Admin\CreateTimeOff;
use App\Actions\Admin\DeleteTimeOff;
use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\TimeOff;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// "Now" is Monday 2 November 2026, 08:00 in Manila (00:00 UTC). Every time below is clinic time unless it says UTC.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'Asia/Manila'));
});

/** A clinic-local 'Y-m-d H:i' string as a UTC CarbonImmutable. */
function clinicUtc(string $clinicTime): CarbonImmutable
{
    return CarbonImmutable::parse($clinicTime, 'Asia/Manila')->utc();
}

/** @return array<string, string> What the add form sends: `datetime-local` values in clinic time. */
function timeOffInput(array $overrides = []): array
{
    return [
        'starts_at' => '2026-11-10T08:00',
        'ends_at' => '2026-11-10T17:00',
        'reason' => 'Conference',
        ...$overrides,
    ];
}

function timeOffBetween(Doctor $doctor, string $from, string $to, ?string $reason = 'Conference'): TimeOff
{
    return TimeOff::factory()->for($doctor)->create([
        'starts_at' => clinicUtc($from),
        'ends_at' => clinicUtc($to),
        'reason' => $reason,
    ]);
}

/** A booking of $doctor from $from to $to (clinic time), in the given state (booked, checkedIn, cancelled...). */
function bookingBetween(Doctor $doctor, string $from, string $to, string $state = 'booked'): Appointment
{
    return Appointment::factory()->for($doctor)->{$state}()->create([
        'starts_at' => clinicUtc($from),
        'ends_at' => clinicUtc($to),
        'blocked_until' => clinicUtc($to),
    ]);
}

/** @return list<array{string, string, ?string}> The stored time off of a doctor as [from UTC, to UTC, reason], earliest first. */
function storedTimeOffs(Doctor $doctor): array
{
    return $doctor->timeOffs()
        ->orderBy('starts_at')
        ->orderBy('id')
        ->get()
        ->map(fn (TimeOff $timeOff): array => [
            $timeOff->starts_at->format('Y-m-d H:i'),
            $timeOff->ends_at->format('Y-m-d H:i'),
            $timeOff->reason,
        ])
        ->all();
}

describe('guests', function () {
    it('are sent to the login page from the time off routes, and change nothing', function (string $method, string $route, bool $needsTimeOff) {
        $doctor = Doctor::factory()->create();
        $timeOff = timeOffBetween($doctor, '2026-11-10 08:00', '2026-11-10 17:00');

        $this->{$method}(route($route, $needsTimeOff ? [$doctor, $timeOff] : [$doctor]), timeOffInput())->assertRedirect(route('login'));

        expect(storedTimeOffs($doctor))->toBe([['2026-11-10 00:00', '2026-11-10 09:00', 'Conference']]);
    })->with([
        'list' => ['get', 'admin.doctors.time-offs.index', false],
        'add' => ['post', 'admin.doctors.time-offs.store', false],
        'remove' => ['delete', 'admin.doctors.time-offs.destroy', true],
    ]);
});

describe('the time off page', function () {
    it('lists upcoming time off, earliest first, as UTC times with the reason', function () {
        $doctor = Doctor::factory()->create();
        timeOffBetween($doctor, '2026-11-20 08:00', '2026-11-20 17:00', 'Holiday');
        timeOffBetween($doctor, '2026-11-10 13:00', '2026-11-10 17:00', null);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.doctors.time-offs.index', $doctor))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/doctors/time-offs')
                ->where('doctor.id', $doctor->id)
                ->where('doctor.name', $doctor->user->name)
                ->has('timeOffs.data', 2)
                ->where('timeOffs.data.0.startsAt', '2026-11-10T05:00:00Z')
                ->where('timeOffs.data.0.endsAt', '2026-11-10T09:00:00Z')
                ->where('timeOffs.data.0.reason', null)
                ->where('timeOffs.data.0.bookingsInside', 0)
                ->where('timeOffs.data.1.reason', 'Holiday')
                ->missing('doctor.user_id')
                ->missing('timeOffs.data.0.doctor_id'));
    });

    it('hides finished time off and the time off of other doctors, and keeps one that is running', function () {
        $doctor = Doctor::factory()->create();
        timeOffBetween($doctor, '2026-10-30 08:00', '2026-10-30 17:00', 'Finished');
        timeOffBetween($doctor, '2026-11-02 07:59', '2026-11-02 08:00', 'Ended just now');
        timeOffBetween($doctor, '2026-11-02 07:00', '2026-11-02 12:00', 'Running');
        TimeOff::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.doctors.time-offs.index', $doctor))
            ->assertInertia(fn (Assert $page) => $page
                ->has('timeOffs.data', 1)
                ->where('timeOffs.data.0.reason', 'Running'));
    });

    it('shows an empty list for a doctor with no time off', function () {
        $doctor = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.doctors.time-offs.index', $doctor))
            ->assertInertia(fn (Assert $page) => $page->has('timeOffs.data', 0)->where('timeOffs.total', 0));
    });

    it('pages the list ten at a time', function () {
        $doctor = Doctor::factory()->create();

        foreach (range(1, 12) as $day) {
            $date = sprintf('2026-12-%02d', $day);
            timeOffBetween($doctor, "{$date} 08:00", "{$date} 17:00");
        }

        $admin = userWithRole(Role::Admin);

        $this->actingAs($admin)
            ->get(route('admin.doctors.time-offs.index', $doctor))
            ->assertInertia(fn (Assert $page) => $page->has('timeOffs.data', 10)->where('timeOffs.total', 12));

        $this->actingAs($admin)
            ->get(route('admin.doctors.time-offs.index', [$doctor, 'page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('timeOffs.data', 2)->where('timeOffs.data.0.startsAt', '2026-12-11T00:00:00Z'));
    });

    it('shows how many bookings are inside each time off', function () {
        $doctor = Doctor::factory()->create();
        $first = timeOffBetween($doctor, '2026-11-10 09:00', '2026-11-10 12:00');
        $second = timeOffBetween($doctor, '2026-11-12 09:00', '2026-11-12 12:00');
        bookingBetween($doctor, '2026-11-10 09:00', '2026-11-10 09:30');
        bookingBetween($doctor, '2026-11-10 10:00', '2026-11-10 10:30');
        bookingBetween($doctor, '2026-11-12 11:30', '2026-11-12 12:30');

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.doctors.time-offs.index', $doctor))
            ->assertInertia(fn (Assert $page) => $page
                ->where('timeOffs.data.0.id', $first->id)
                ->where('timeOffs.data.0.bookingsInside', 2)
                ->where('timeOffs.data.1.id', $second->id)
                ->where('timeOffs.data.1.bookingsInside', 1)
                // A count only: no patient names or booking details reach the page.
                ->missing('timeOffs.data.0.bookings')
                ->missing('timeOffs.data.0.patients'));
    });

    it('answers 404 for a doctor that does not exist', function () {
        $this->actingAs(userWithRole(Role::Admin))->get(route('admin.doctors.time-offs.index', 999999))->assertNotFound();
    });
});

describe('adding time off', function () {
    it('stores the time as UTC from the clinic time that was typed', function () {
        $doctor = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.doctors.time-offs.store', $doctor), timeOffInput())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.doctors.time-offs.index', $doctor))
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        // 08:00 to 17:00 in Manila (UTC+8) is 00:00 to 09:00 UTC.
        expect(storedTimeOffs($doctor))->toBe([['2026-11-10 00:00', '2026-11-10 09:00', 'Conference']]);
    });

    it('converts clinic time to UTC across midnight', function (string $from, string $to, string $expectedFrom, string $expectedTo) {
        $doctor = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.doctors.time-offs.store', $doctor), timeOffInput(['starts_at' => $from, 'ends_at' => $to, 'reason' => null]))
            ->assertSessionHasNoErrors();

        expect(storedTimeOffs($doctor))->toBe([[$expectedFrom, $expectedTo, null]]);
    })->with([
        // Manila is UTC+8 all year (no daylight saving), so before 08:00 local the UTC date is the day before.
        'midnight in Manila is 16:00 the day before in UTC' => ['2026-11-10T00:00', '2026-11-10T07:59', '2026-11-09 16:00', '2026-11-09 23:59'],
        '08:00 in Manila is exactly midnight UTC' => ['2026-11-10T08:00', '2026-11-10T23:59', '2026-11-10 00:00', '2026-11-10 15:59'],
        'a whole day off, midnight to midnight' => ['2026-11-10T00:00', '2026-11-11T00:00', '2026-11-09 16:00', '2026-11-10 16:00'],
        'across New Year' => ['2026-12-31T20:00', '2027-01-01T06:00', '2026-12-31 12:00', '2026-12-31 22:00'],
    ]);

    it('does not need a reason', function (?string $reason) {
        $doctor = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.doctors.time-offs.store', $doctor), timeOffInput(['reason' => $reason]))
            ->assertSessionHasNoErrors();

        expect(storedTimeOffs($doctor))->toBe([['2026-11-10 00:00', '2026-11-10 09:00', null]]);
    })->with([
        'an empty reason' => [''],
        'a reason of only spaces' => ['   '],
        'no reason sent' => [null],
    ]);

    it('warns how many bookings are inside, and still saves, and leaves those bookings alone', function () {
        $doctor = Doctor::factory()->create();
        $inside = bookingBetween($doctor, '2026-11-10 10:00', '2026-11-10 10:30');
        bookingBetween($doctor, '2026-11-10 11:00', '2026-11-10 11:30', 'checkedIn');
        bookingBetween($doctor, '2026-11-10 12:00', '2026-11-10 12:30', 'cancelled');

        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.doctors.time-offs.store', $doctor), timeOffInput())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data.toast.type', 'warning')
            ->assertSessionHas('inertia.flash_data.toast.message', fn (string $message): bool => str_contains($message, '2 bookings'));

        expect($doctor->timeOffs()->count())->toBe(1)
            ->and($inside->fresh()->status)->toBe(AppointmentStatus::Booked)
            ->and($inside->fresh()->starts_at->equalTo(clinicUtc('2026-11-10 10:00')))->toBeTrue();
    });

    it('says "1 booking" in the singular', function () {
        $doctor = Doctor::factory()->create();
        bookingBetween($doctor, '2026-11-10 10:00', '2026-11-10 10:30');

        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.doctors.time-offs.store', $doctor), timeOffInput())
            ->assertSessionHas('inertia.flash_data.toast.message', fn (string $message): bool => str_contains($message, '1 booking is inside'));
    });

    it('shows no warning when only a cancelled booking or another doctor\'s booking is inside', function () {
        $doctor = Doctor::factory()->create();
        bookingBetween($doctor, '2026-11-10 10:00', '2026-11-10 10:30', 'cancelled');
        bookingBetween(Doctor::factory()->create(), '2026-11-10 10:00', '2026-11-10 10:30');

        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.doctors.time-offs.store', $doctor), timeOffInput())
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');
    });

    it('accepts time off that overlaps another one, because overlapping time off does no harm', function () {
        $doctor = Doctor::factory()->create();
        timeOffBetween($doctor, '2026-11-10 08:00', '2026-11-10 17:00');

        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.doctors.time-offs.store', $doctor), timeOffInput(['starts_at' => '2026-11-10T12:00', 'ends_at' => '2026-11-11T12:00']))
            ->assertSessionHasNoErrors();

        expect($doctor->timeOffs()->count())->toBe(2);
    });

    it('accepts time off that spans several days, and one that is already running', function () {
        $doctor = Doctor::factory()->create();
        $admin = userWithRole(Role::Admin);

        $this->actingAs($admin)
            ->post(route('admin.doctors.time-offs.store', $doctor), timeOffInput(['starts_at' => '2026-12-24T00:00', 'ends_at' => '2026-12-27T23:59']))
            ->assertSessionHasNoErrors();
        // Started an hour ago, ends in an hour: it blocks the doctor right now.
        $this->actingAs($admin)
            ->post(route('admin.doctors.time-offs.store', $doctor), timeOffInput(['starts_at' => '2026-11-02T07:00', 'ends_at' => '2026-11-02T09:00']))
            ->assertSessionHasNoErrors();

        expect($doctor->timeOffs()->count())->toBe(2);
    });

    it('adds the time off to the doctor in the URL, whatever the form says', function () {
        $doctor = Doctor::factory()->create();
        $other = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.doctors.time-offs.store', $doctor), [...timeOffInput(), 'doctor_id' => $other->id, 'id' => 1])
            ->assertSessionHasNoErrors();

        expect($doctor->timeOffs()->count())->toBe(1)
            ->and($other->timeOffs()->count())->toBe(0);
    });

    it('answers 404 for a doctor that does not exist, and stores nothing', function () {
        $this->actingAs(userWithRole(Role::Admin))->post(route('admin.doctors.time-offs.store', 999999), timeOffInput())->assertNotFound();

        expect(TimeOff::query()->count())->toBe(0);
    });

    it('limits how fast time off can be added', function () {
        $doctor = Doctor::factory()->create();
        $admin = userWithRole(Role::Admin);

        foreach (range(1, 20) as $attempt) {
            $this->actingAs($admin)->post(route('admin.doctors.time-offs.store', $doctor), [])->assertSessionHasErrors();
        }

        $this->actingAs($admin)->post(route('admin.doctors.time-offs.store', $doctor), timeOffInput())->assertTooManyRequests();
        expect($doctor->timeOffs()->count())->toBe(0);
    });
});

describe('removing time off', function () {
    it('removes only that time off, and leaves the bookings that were inside it', function () {
        $doctor = Doctor::factory()->create();
        $remove = timeOffBetween($doctor, '2026-11-10 08:00', '2026-11-10 17:00', 'Conference');
        timeOffBetween($doctor, '2026-11-20 08:00', '2026-11-20 17:00', 'Holiday');
        $booking = bookingBetween($doctor, '2026-11-10 10:00', '2026-11-10 10:30');

        $this->actingAs(userWithRole(Role::Admin))
            ->delete(route('admin.doctors.time-offs.destroy', [$doctor, $remove]))
            ->assertRedirect(route('admin.doctors.time-offs.index', $doctor))
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        expect(storedTimeOffs($doctor))->toBe([['2026-11-20 00:00', '2026-11-20 09:00', 'Holiday']])
            ->and($booking->fresh())->not->toBeNull();
    });

    it('does not remove another doctor\'s time off through this doctor\'s URL', function () {
        $doctor = Doctor::factory()->create();
        $other = Doctor::factory()->create();
        $theirs = timeOffBetween($other, '2026-11-10 08:00', '2026-11-10 17:00');

        $this->actingAs(userWithRole(Role::Admin))
            ->delete(route('admin.doctors.time-offs.destroy', [$doctor, $theirs]))
            ->assertNotFound();

        expect($theirs->fresh())->not->toBeNull();
    });

    it('answers 404 for a time off that is already gone', function () {
        $doctor = Doctor::factory()->create();
        $timeOff = timeOffBetween($doctor, '2026-11-10 08:00', '2026-11-10 17:00');
        $admin = userWithRole(Role::Admin);

        $this->actingAs($admin)->delete(route('admin.doctors.time-offs.destroy', [$doctor, $timeOff]))->assertRedirect();
        $this->actingAs($admin)->delete(route('admin.doctors.time-offs.destroy', [$doctor, $timeOff->id]))->assertNotFound();
    });

    it('has no routes to edit a time off', function () {
        $doctor = Doctor::factory()->create();
        $timeOff = timeOffBetween($doctor, '2026-11-10 08:00', '2026-11-10 17:00');

        $this->actingAs(userWithRole(Role::Admin))
            ->put("/admin/doctors/{$doctor->id}/time-offs/{$timeOff->id}", timeOffInput())
            ->assertStatus(405);
    });
});

describe('access', function () {
    it('denies time off to everyone but admins, and changes nothing', function (Role $role) {
        // The doctor case is the doctor's own time off: a doctor can't manage it here either.
        $doctor = Doctor::factory()->for(User::factory()->doctor()->withTwoFactor(), 'user')->create();
        $timeOff = timeOffBetween($doctor, '2026-11-10 08:00', '2026-11-10 17:00');
        $actor = $role === Role::Doctor ? $doctor->user : userWithRole($role);

        $this->actingAs($actor)->get(route('admin.doctors.time-offs.index', $doctor))->assertForbidden();
        $this->actingAs($actor)->post(route('admin.doctors.time-offs.store', $doctor), timeOffInput(['reason' => 'Sneaky']))->assertForbidden();
        $this->actingAs($actor)->delete(route('admin.doctors.time-offs.destroy', [$doctor, $timeOff]))->assertForbidden();

        expect(storedTimeOffs($doctor))->toBe([['2026-11-10 00:00', '2026-11-10 09:00', 'Conference']]);
    })->with('non_admins');
});

describe('validation', function () {
    it('rejects bad input and stores nothing', function (array $overrides, string $invalidField) {
        $doctor = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.doctors.time-offs.store', $doctor), timeOffInput($overrides))
            ->assertSessionHasErrors($invalidField);

        expect($doctor->timeOffs()->count())->toBe(0);
    })->with([
        'the start is missing' => [['starts_at' => null], 'starts_at'],
        'the end is missing' => [['ends_at' => null], 'ends_at'],
        'the start has no time' => [['starts_at' => '2026-11-10'], 'starts_at'],
        'the end has a space instead of a T' => [['ends_at' => '2026-11-10 17:00'], 'ends_at'],
        'the start is not a date' => [['starts_at' => 'tomorrow'], 'starts_at'],
        'the start is a day that does not exist' => [['starts_at' => '2026-02-30T08:00'], 'starts_at'],
        'the end has seconds' => [['ends_at' => '2026-11-10T17:00:30'], 'ends_at'],
        'the start is a list' => [['starts_at' => ['2026-11-10T08:00']], 'starts_at'],
        'the end is before the start' => [['starts_at' => '2026-11-10T17:00', 'ends_at' => '2026-11-10T08:00'], 'ends_at'],
        'the end equals the start' => [['starts_at' => '2026-11-10T08:00', 'ends_at' => '2026-11-10T08:00'], 'ends_at'],
        'the end is one minute before the start' => [['starts_at' => '2026-11-10T08:00', 'ends_at' => '2026-11-10T07:59'], 'ends_at'],
        'it is already over' => [['starts_at' => '2026-10-30T08:00', 'ends_at' => '2026-10-30T17:00'], 'ends_at'],
        'it ends exactly now' => [['starts_at' => '2026-11-02T07:00', 'ends_at' => '2026-11-02T08:00'], 'ends_at'],
        'the reason is over 255 characters' => [['reason' => str_repeat('a', 256)], 'reason'],
        'the reason is a list' => [['reason' => ['Conference']], 'reason'],
    ]);

    it('accepts a reason of exactly 255 characters', function () {
        $doctor = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.doctors.time-offs.store', $doctor), timeOffInput(['reason' => str_repeat('a', 255)]))
            ->assertSessionHasNoErrors();

        expect($doctor->timeOffs()->count())->toBe(1);
    });

    it('accepts a time off that ends one minute from now', function () {
        $doctor = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.doctors.time-offs.store', $doctor), timeOffInput(['starts_at' => '2026-11-02T07:00', 'ends_at' => '2026-11-02T08:01']))
            ->assertSessionHasNoErrors();

        expect($doctor->timeOffs()->count())->toBe(1);
    });

    it('reports a wrong start and a wrong end at the same time', function () {
        $doctor = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.doctors.time-offs.store', $doctor), ['starts_at' => 'soon', 'ends_at' => ''])
            ->assertSessionHasErrors(['starts_at', 'ends_at']);
    });
});

describe('the count bookings in time off action', function () {
    it('counts a booking only when its visit overlaps the time off', function (string $from, string $to, string $state, int $expected) {
        $doctor = Doctor::factory()->create();
        $timeOff = timeOffBetween($doctor, '2026-11-10 09:00', '2026-11-10 12:00');
        bookingBetween($doctor, $from, $to, $state);

        $counts = app(CountBookingsInTimeOff::class)->handle($doctor, collect([$timeOff]));

        expect($counts)->toBe([$timeOff->id => $expected]);
    })->with([
        'a booking inside' => ['2026-11-10 10:00', '2026-11-10 10:30', 'booked', 1],
        'a checked-in patient inside' => ['2026-11-10 10:00', '2026-11-10 10:30', 'checkedIn', 1],
        'a booking that starts before and ends inside' => ['2026-11-10 08:45', '2026-11-10 09:15', 'booked', 1],
        'a booking that starts inside and ends after' => ['2026-11-10 11:45', '2026-11-10 12:15', 'booked', 1],
        'a booking that covers the whole time off' => ['2026-11-10 08:00', '2026-11-10 13:00', 'booked', 1],
        'a booking that ends when the time off starts' => ['2026-11-10 08:30', '2026-11-10 09:00', 'booked', 0],
        'a booking that starts when the time off ends' => ['2026-11-10 12:00', '2026-11-10 12:30', 'booked', 0],
        'a booking that ends one minute into the time off' => ['2026-11-10 08:31', '2026-11-10 09:01', 'booked', 1],
        'a cancelled booking inside' => ['2026-11-10 10:00', '2026-11-10 10:30', 'cancelled', 0],
        'a no-show inside' => ['2026-11-10 10:00', '2026-11-10 10:30', 'noShow', 0],
        'a booking on another day' => ['2026-11-11 10:00', '2026-11-11 10:30', 'booked', 0],
    ]);

    it('does not count a visit that is already over', function () {
        // The time off started before now (08:00) and runs on, with one visit that finished and one still to come.
        $doctor = Doctor::factory()->create();
        $timeOff = timeOffBetween($doctor, '2026-11-02 07:00', '2026-11-02 18:00');
        bookingBetween($doctor, '2026-11-02 07:00', '2026-11-02 07:30', 'completed');
        bookingBetween($doctor, '2026-11-02 07:30', '2026-11-02 08:00', 'completed');
        bookingBetween($doctor, '2026-11-02 09:00', '2026-11-02 09:30');

        expect(app(CountBookingsInTimeOff::class)->handle($doctor, collect([$timeOff])))->toBe([$timeOff->id => 1]);
    });

    it('counts a visit that ends one minute after now', function () {
        $doctor = Doctor::factory()->create();
        $timeOff = timeOffBetween($doctor, '2026-11-02 07:00', '2026-11-02 18:00');
        bookingBetween($doctor, '2026-11-02 07:31', '2026-11-02 08:01', 'checkedIn');

        expect(app(CountBookingsInTimeOff::class)->handle($doctor, collect([$timeOff])))->toBe([$timeOff->id => 1]);
    });

    it('counts for each time off on its own, and never counts another doctor\'s bookings', function () {
        $doctor = Doctor::factory()->create();
        $first = timeOffBetween($doctor, '2026-11-10 09:00', '2026-11-10 12:00');
        $second = timeOffBetween($doctor, '2026-11-12 09:00', '2026-11-12 12:00');
        $empty = timeOffBetween($doctor, '2026-11-14 09:00', '2026-11-14 12:00');
        bookingBetween($doctor, '2026-11-10 10:00', '2026-11-10 10:30');
        bookingBetween($doctor, '2026-11-10 10:30', '2026-11-10 11:00');
        bookingBetween($doctor, '2026-11-12 10:00', '2026-11-12 10:30');
        // Between the two time offs, so it falls inside the span of the query but in neither of them.
        bookingBetween($doctor, '2026-11-11 10:00', '2026-11-11 10:30');
        bookingBetween(Doctor::factory()->create(), '2026-11-14 10:00', '2026-11-14 10:30');

        expect(app(CountBookingsInTimeOff::class)->handle($doctor, collect([$first, $second, $empty])))
            ->toBe([$first->id => 2, $second->id => 1, $empty->id => 0]);
    });

    it('needs one query for the whole list', function () {
        $doctor = Doctor::factory()->create();
        $timeOffs = collect(range(10, 14))->map(fn (int $day): TimeOff => timeOffBetween($doctor, "2026-11-{$day} 09:00", "2026-11-{$day} 12:00"));
        bookingBetween($doctor, '2026-11-12 10:00', '2026-11-12 10:30');

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(CountBookingsInTimeOff::class)->handle($doctor, $timeOffs);

        expect($queries)->toHaveCount(1);
    });

    it('returns nothing for an empty list, without asking the database', function () {
        $doctor = Doctor::factory()->create();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        expect(app(CountBookingsInTimeOff::class)->handle($doctor, collect()))->toBe([])
            ->and($queries)->toBe([]);
    });
});

describe('the create and delete time off actions', function () {
    it('creates time off for the given doctor only', function () {
        $doctor = Doctor::factory()->create();
        $other = Doctor::factory()->create();

        $timeOff = app(CreateTimeOff::class)->handle($doctor, clinicUtc('2026-11-10 08:00'), clinicUtc('2026-11-10 17:00'), 'Conference');

        expect($timeOff->doctor_id)->toBe($doctor->id)
            ->and(storedTimeOffs($doctor))->toBe([['2026-11-10 00:00', '2026-11-10 09:00', 'Conference']])
            ->and($other->timeOffs()->count())->toBe(0);
    });

    it('deletes one time off and leaves the rest', function () {
        $doctor = Doctor::factory()->create();
        $remove = timeOffBetween($doctor, '2026-11-10 08:00', '2026-11-10 17:00');
        $keep = timeOffBetween($doctor, '2026-11-20 08:00', '2026-11-20 17:00');

        app(DeleteTimeOff::class)->handle($remove);

        expect(TimeOff::query()->pluck('id')->all())->toBe([$keep->id]);
    });
});
