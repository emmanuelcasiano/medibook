<?php

declare(strict_types=1);

use App\Actions\Admin\UpdateWorkingHours;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\User;
use App\Models\WorkingHour;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array<string, array<string, mixed>> */
function lunchBreakWeek(): array
{
    return [
        'ranges' => [
            ['weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00'],
            ['weekday' => 1, 'starts_at' => '13:00', 'ends_at' => '17:00'],
            ['weekday' => 3, 'starts_at' => '09:00', 'ends_at' => '12:00'],
        ],
    ];
}

/** @return list<array{int, string, string}> The stored ranges of a doctor as [weekday, from, to], in a fixed order. */
function storedRanges(Doctor $doctor): array
{
    return $doctor->workingHours()
        ->orderBy('weekday')
        ->orderBy('starts_at')
        ->get()
        ->map(fn (WorkingHour $range): array => [$range->weekday, $range->starts_at, $range->ends_at])
        ->all();
}

describe('guests', function () {
    it('are sent to the login page from the working hours routes, and change nothing', function (string $method, string $route) {
        $doctor = Doctor::factory()->create();
        WorkingHour::factory()->for($doctor)->create();

        $this->{$method}(route($route, $doctor), lunchBreakWeek())->assertRedirect(route('login'));

        expect($doctor->workingHours()->count())->toBe(1);
    })->with([
        'edit form' => ['get', 'admin.doctors.working-hours.edit'],
        'update' => ['put', 'admin.doctors.working-hours.update'],
    ]);
});

describe('the working hours form', function () {
    it('shows the saved ranges in weekday and time order, as clinic-local H:i', function () {
        $doctor = Doctor::factory()->create();
        WorkingHour::factory()->for($doctor)->onWeekday(3)->between('09:00:00', '12:00:00')->create();
        WorkingHour::factory()->for($doctor)->onWeekday(1)->between('13:00:00', '17:30:00')->create();
        WorkingHour::factory()->for($doctor)->onWeekday(1)->between('08:00:00', '12:00:00')->create();
        // Another doctor's hours are never shown here.
        WorkingHour::factory()->onWeekday(2)->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.doctors.working-hours.edit', $doctor))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/doctors/working-hours')
                ->where('doctor.id', $doctor->id)
                ->where('doctor.name', $doctor->user->name)
                ->has('ranges', 3)
                ->where('ranges.0', ['weekday' => 1, 'startsAt' => '08:00', 'endsAt' => '12:00'])
                ->where('ranges.1', ['weekday' => 1, 'startsAt' => '13:00', 'endsAt' => '17:30'])
                ->where('ranges.2', ['weekday' => 3, 'startsAt' => '09:00', 'endsAt' => '12:00'])
                ->missing('doctor.user_id'));
    });

    it('shows an empty list for a doctor with no hours', function () {
        $doctor = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.doctors.working-hours.edit', $doctor))
            ->assertInertia(fn (Assert $page) => $page->has('ranges', 0));
    });

    it('answers 404 for a doctor that does not exist', function () {
        $this->actingAs(userWithRole(Role::Admin))->get(route('admin.doctors.working-hours.edit', 999999))->assertNotFound();
    });
});

describe('saving working hours', function () {
    it('stores several ranges on several weekdays, including a lunch break', function () {
        $doctor = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.working-hours.update', $doctor), lunchBreakWeek())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.doctors.working-hours.edit', $doctor));

        expect(storedRanges($doctor))->toBe([
            [1, '08:00:00', '12:00:00'],
            [1, '13:00:00', '17:00:00'],
            [3, '09:00:00', '12:00:00'],
        ]);
    });

    it('replaces the old ranges instead of adding to them', function () {
        $doctor = Doctor::factory()->create();
        WorkingHour::factory()->for($doctor)->onWeekday(5)->between('07:00:00', '15:00:00')->create();
        WorkingHour::factory()->for($doctor)->onWeekday(1)->between('08:00:00', '12:00:00')->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.working-hours.update', $doctor), ['ranges' => [['weekday' => 2, 'starts_at' => '10:00', 'ends_at' => '11:00']]])
            ->assertSessionHasNoErrors();

        expect(storedRanges($doctor))->toBe([[2, '10:00:00', '11:00:00']]);
    });

    it('closes every day with an empty list', function () {
        $doctor = Doctor::factory()->create();
        WorkingHour::factory()->for($doctor)->count(2)->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.working-hours.update', $doctor), ['ranges' => []])
            ->assertSessionHasNoErrors();

        expect($doctor->workingHours()->count())->toBe(0);
    });

    it('accepts the same hours on different weekdays, and ranges that only touch', function () {
        $doctor = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.working-hours.update', $doctor), ['ranges' => [
                ['weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00'],
                ['weekday' => 2, 'starts_at' => '08:00', 'ends_at' => '12:00'],
                ['weekday' => 1, 'starts_at' => '12:00', 'ends_at' => '16:00'],
                ['weekday' => 0, 'starts_at' => '00:00', 'ends_at' => '23:59'],
            ]])
            ->assertSessionHasNoErrors();

        expect($doctor->workingHours()->count())->toBe(4);
    });

    it('accepts ranges that touch even when the later one is listed first', function () {
        $doctor = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.working-hours.update', $doctor), ['ranges' => [
                ['weekday' => 1, 'starts_at' => '13:00', 'ends_at' => '17:00'],
                ['weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '13:00'],
            ]])
            ->assertSessionHasNoErrors();

        expect(storedRanges($doctor))->toBe([[1, '08:00:00', '13:00:00'], [1, '13:00:00', '17:00:00']]);
    });

    it('gives the same result when the form is submitted twice', function () {
        $doctor = Doctor::factory()->create();
        $admin = userWithRole(Role::Admin);

        $this->actingAs($admin)->put(route('admin.doctors.working-hours.update', $doctor), lunchBreakWeek())->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('admin.doctors.working-hours.update', $doctor), lunchBreakWeek())->assertSessionHasNoErrors();

        expect($doctor->workingHours()->count())->toBe(3);
    });

    it('ignores extra fields inside a range, such as another doctor id', function () {
        $doctor = Doctor::factory()->create();
        $other = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.working-hours.update', $doctor), ['ranges' => [
                ['weekday' => 2, 'starts_at' => '08:00', 'ends_at' => '12:00', 'doctor_id' => $other->id, 'id' => 1],
            ]])
            ->assertSessionHasNoErrors();

        expect(storedRanges($doctor))->toBe([[2, '08:00:00', '12:00:00']])
            ->and($other->workingHours()->count())->toBe(0);
    });

    it('accepts the ranges as a keyed object and still stores them', function () {
        $doctor = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.working-hours.update', $doctor), ['ranges' => ['first' => ['weekday' => 4, 'starts_at' => '08:00', 'ends_at' => '09:00']]])
            ->assertSessionHasNoErrors();

        expect(storedRanges($doctor))->toBe([[4, '08:00:00', '09:00:00']]);
    });

    it('only changes the doctor in the URL', function () {
        $doctor = Doctor::factory()->create();
        $other = Doctor::factory()->create();
        WorkingHour::factory()->for($other)->onWeekday(6)->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.working-hours.update', $doctor), [...lunchBreakWeek(), 'doctor_id' => $other->id]);

        expect($doctor->workingHours()->count())->toBe(3)
            ->and(storedRanges($other))->toBe([[6, '08:00:00', '12:00:00']]);
    });

    it('leaves existing bookings alone', function () {
        $doctor = Doctor::factory()->create();
        $appointment = Appointment::factory()->booked()->create(['doctor_id' => $doctor->id]);

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.working-hours.update', $doctor), ['ranges' => []])
            ->assertSessionHasNoErrors();

        expect($appointment->fresh())->not->toBeNull()
            ->and($appointment->fresh()->doctor_id)->toBe($doctor->id);
    });
});

describe('access', function () {
    it('denies the working hours to everyone but admins, and changes nothing', function (Role $role) {
        // The doctor case is the doctor's own schedule: a doctor can't edit it here either.
        $doctor = Doctor::factory()->for(User::factory()->doctor()->withTwoFactor(), 'user')->create();
        WorkingHour::factory()->for($doctor)->onWeekday(1)->create();
        $actor = $role === Role::Doctor ? $doctor->user : userWithRole($role);

        $this->actingAs($actor)->get(route('admin.doctors.working-hours.edit', $doctor))->assertForbidden();
        $this->actingAs($actor)->put(route('admin.doctors.working-hours.update', $doctor), lunchBreakWeek())->assertForbidden();

        expect(storedRanges($doctor))->toBe([[1, '08:00:00', '12:00:00']]);
    })->with('non_admins');
});

describe('validation', function () {
    it('rejects bad input and keeps the saved hours', function (array $ranges, string $invalidField) {
        $doctor = Doctor::factory()->create();
        WorkingHour::factory()->for($doctor)->onWeekday(1)->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.working-hours.update', $doctor), ['ranges' => $ranges])
            ->assertSessionHasErrors($invalidField);

        expect(storedRanges($doctor))->toBe([[1, '08:00:00', '12:00:00']]);
    })->with([
        'the end is before the start' => [[['weekday' => 1, 'starts_at' => '12:00', 'ends_at' => '08:00']], 'ranges.0.ends_at'],
        'the end equals the start' => [[['weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '08:00']], 'ranges.0.ends_at'],
        'a range overlaps the one before it' => [[
            ['weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00'],
            ['weekday' => 1, 'starts_at' => '11:00', 'ends_at' => '14:00'],
        ], 'ranges.1.starts_at'],
        'a range sits inside another one' => [[
            ['weekday' => 2, 'starts_at' => '08:00', 'ends_at' => '17:00'],
            ['weekday' => 2, 'starts_at' => '10:00', 'ends_at' => '11:00'],
        ], 'ranges.1.starts_at'],
        'a range is listed twice' => [[
            ['weekday' => 2, 'starts_at' => '08:00', 'ends_at' => '12:00'],
            ['weekday' => 2, 'starts_at' => '08:00', 'ends_at' => '12:00'],
        ], 'ranges.1.starts_at'],
        'a later row overlaps an earlier row that came after it in time' => [[
            ['weekday' => 4, 'starts_at' => '13:00', 'ends_at' => '17:00'],
            ['weekday' => 4, 'starts_at' => '08:00', 'ends_at' => '14:00'],
        ], 'ranges.1.starts_at'],
        'a range overlaps by one minute' => [[
            ['weekday' => 5, 'starts_at' => '08:00', 'ends_at' => '12:00'],
            ['weekday' => 5, 'starts_at' => '11:59', 'ends_at' => '14:00'],
        ], 'ranges.1.starts_at'],
        'the weekday is above 6' => [[['weekday' => 7, 'starts_at' => '08:00', 'ends_at' => '12:00']], 'ranges.0.weekday'],
        'the weekday is below 0' => [[['weekday' => -1, 'starts_at' => '08:00', 'ends_at' => '12:00']], 'ranges.0.weekday'],
        'the weekday is not a number' => [[['weekday' => 'monday', 'starts_at' => '08:00', 'ends_at' => '12:00']], 'ranges.0.weekday'],
        'the weekday is missing' => [[['starts_at' => '08:00', 'ends_at' => '12:00']], 'ranges.0.weekday'],
        'the start time is missing' => [[['weekday' => 1, 'ends_at' => '12:00']], 'ranges.0.starts_at'],
        'the end time is missing' => [[['weekday' => 1, 'starts_at' => '08:00']], 'ranges.0.ends_at'],
        'the start time has seconds' => [[['weekday' => 1, 'starts_at' => '08:00:00', 'ends_at' => '12:00']], 'ranges.0.starts_at'],
        'the start time is not zero-padded' => [[['weekday' => 1, 'starts_at' => '8:00', 'ends_at' => '12:00']], 'ranges.0.starts_at'],
        'the end time is not a time' => [[['weekday' => 1, 'starts_at' => '08:00', 'ends_at' => 'noon']], 'ranges.0.ends_at'],
        'the end time is past 23:59' => [[['weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '24:00']], 'ranges.0.ends_at'],
        'a row is not an object' => [['08:00-12:00'], 'ranges.0'],
    ]);

    it('rejects a request that leaves out the ranges, and keeps the saved hours', function () {
        $doctor = Doctor::factory()->create();
        WorkingHour::factory()->for($doctor)->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.working-hours.update', $doctor), [])
            ->assertSessionHasErrors('ranges');

        expect($doctor->workingHours()->count())->toBe(1);
    });

    it('rejects ranges that are not a list', function () {
        $doctor = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.working-hours.update', $doctor), ['ranges' => 'all week'])
            ->assertSessionHasErrors('ranges');
    });

    it('accepts 70 ranges and rejects 71', function () {
        $doctor = Doctor::factory()->create();
        // Ten one-hour ranges for each of the seven weekdays: 70 in all, none overlapping.
        $seventy = collect(range(0, 6))->flatMap(fn (int $weekday): array => array_map(
            fn (int $hour): array => ['weekday' => $weekday, 'starts_at' => sprintf('%02d:00', $hour), 'ends_at' => sprintf('%02d:30', $hour)],
            range(8, 17),
        ))->all();

        $admin = userWithRole(Role::Admin);

        $this->actingAs($admin)
            ->put(route('admin.doctors.working-hours.update', $doctor), ['ranges' => $seventy])
            ->assertSessionHasNoErrors();

        expect($doctor->workingHours()->count())->toBe(70);

        $this->actingAs($admin)
            ->put(route('admin.doctors.working-hours.update', $doctor), ['ranges' => [...$seventy, ['weekday' => 0, 'starts_at' => '19:00', 'ends_at' => '20:00']]])
            ->assertSessionHasErrors('ranges');

        expect($doctor->workingHours()->count())->toBe(70);
    });

    it('reports every wrong row at once', function () {
        $doctor = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.working-hours.update', $doctor), ['ranges' => [
                ['weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00'],
                ['weekday' => 1, 'starts_at' => '10:00', 'ends_at' => '13:00'],
                ['weekday' => 2, 'starts_at' => '15:00', 'ends_at' => '09:00'],
            ]])
            ->assertSessionHasErrors(['ranges.1.starts_at', 'ranges.2.ends_at']);

        expect($doctor->workingHours()->count())->toBe(0);
    });
});

describe('the update working hours action', function () {
    it('saves the new ranges or keeps the old ones', function () {
        $doctor = Doctor::factory()->create();
        WorkingHour::factory()->for($doctor)->onWeekday(1)->create();

        // A weekday that does not fit the column breaks the insert, after the old rows were deleted.
        expect(fn () => app(UpdateWorkingHours::class)->handle($doctor, [
            ['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '10:00'],
            ['weekday' => 999, 'starts_at' => '09:00', 'ends_at' => '10:00'],
        ]))->toThrow(Exception::class);

        expect(storedRanges($doctor))->toBe([[1, '08:00:00', '12:00:00']]);
    });

    it('does not touch the hours of another doctor', function () {
        $doctor = Doctor::factory()->create();
        $other = Doctor::factory()->create();
        WorkingHour::factory()->for($other)->onWeekday(2)->create();

        app(UpdateWorkingHours::class)->handle($doctor, [['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '10:00']]);
        app(UpdateWorkingHours::class)->handle($doctor, []);

        expect($doctor->workingHours()->count())->toBe(0)
            ->and($other->workingHours()->count())->toBe(1);
    });

    it('has no routes to create or delete single ranges', function () {
        $doctor = Doctor::factory()->create();
        $admin = userWithRole(Role::Admin);

        $this->actingAs($admin)->post("/admin/doctors/{$doctor->id}/working-hours", lunchBreakWeek())->assertStatus(405);
        $this->actingAs($admin)->delete("/admin/doctors/{$doctor->id}/working-hours")->assertStatus(405);
    });
});
