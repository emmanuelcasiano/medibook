<?php

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\TimeOff;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A user with the given role. Staff, doctors and admins get 2FA, because they need it to reach the app
 * (pass `twoFactor: false` to test the redirect). A doctor also gets a doctor profile.
 */
function userWithRole(Role $role, bool $twoFactor = true): User
{
    $factory = User::factory()->state(['role' => $role]);

    if ($role !== Role::Patient) {
        $factory = $factory->state(['birth_date' => null]);

        if ($twoFactor) {
            $factory = $factory->withTwoFactor();
        }
    }

    $user = $factory->create();

    if ($role === Role::Doctor) {
        Doctor::factory()->create(['user_id' => $user->id]);
    }

    return $user;
}

/** A clinic date (Asia/Manila) as the slot Actions receive it. */
function slotDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, 'Asia/Manila');
}

/**
 * A doctor who offers $service and works the given ranges.
 *
 * @param  list<array{int, string, string}>  $ranges  [weekday, 'H:i' start, 'H:i' end], clinic time.
 */
function doctorWorking(Service $service, array $ranges): Doctor
{
    $doctor = Doctor::factory()->create();
    $doctor->services()->attach($service);

    foreach ($ranges as [$weekday, $startsAt, $endsAt]) {
        WorkingHour::factory()->for($doctor)->onWeekday($weekday)->between($startsAt, $endsAt)->create();
    }

    return $doctor;
}

/**
 * The slots as clinic-time strings, so a test reads like the doctor's day.
 *
 * @param  Collection<int, CarbonImmutable>  $slots
 * @return list<string>
 */
function slotTimes(Collection $slots, string $format = 'H:i'): array
{
    return $slots->map(fn (CarbonImmutable $slot): string => $slot->setTimezone('Asia/Manila')->format($format))->values()->all();
}

/**
 * A booking for $doctor starting at a clinic time. $state is an AppointmentFactory state
 * (booked, checkedIn, completed, cancelled, noShow). Its end and buffer come from $service.
 */
function bookingAt(Doctor $doctor, Service $service, string $startsAt, string $state = 'booked'): Appointment
{
    return Appointment::factory()->for($doctor)->for($service)->{$state}()->create([
        'starts_at' => CarbonImmutable::parse($startsAt, 'Asia/Manila')->utc(),
    ]);
}

/** Time off for $doctor between two clinic times. */
function slotTimeOff(Doctor $doctor, string $startsAt, string $endsAt): TimeOff
{
    return TimeOff::factory()->for($doctor)->create([
        'starts_at' => CarbonImmutable::parse($startsAt, 'Asia/Manila')->utc(),
        'ends_at' => CarbonImmutable::parse($endsAt, 'Asia/Manila')->utc(),
    ]);
}
