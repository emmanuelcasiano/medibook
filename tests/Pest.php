<?php

use App\Enums\Role;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
