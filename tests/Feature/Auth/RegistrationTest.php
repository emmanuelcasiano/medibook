<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

/** @return array<string, string> */
function registrationInput(array $overrides = []): array
{
    return [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'birth_date' => '1990-04-12',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...$overrides,
    ];
}

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), registrationInput());

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('registration saves the birth date encrypted', function () {
    $this->post(route('register.store'), registrationInput());

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $stored = DB::table('users')->where('id', $user->id)->value('birth_date');

    expect($user->birth_date)->toBe('1990-04-12')
        ->and($stored)->not->toBe('1990-04-12')
        ->and($stored)->not->toContain('1990');
});

test('registration always makes an active patient, whatever the form says', function () {
    $this->post(route('register.store'), registrationInput(['role' => 'admin', 'is_active' => '0']));

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();

    expect($user->role)->toBe(Role::Patient)
        ->and($user->is_active)->toBeTrue();
});

test('registration needs a valid birth date in the past', function (array $overrides) {
    $this->post(route('register.store'), registrationInput($overrides))->assertSessionHasErrors('birth_date');

    $this->assertGuest();
    expect(User::query()->where('email', 'test@example.com')->exists())->toBeFalse();
})->with([
    'missing' => [['birth_date' => '']],
    'not a date' => [['birth_date' => 'last tuesday']],
    'wrong format' => [['birth_date' => '12/04/1990']],
    // A closure is run when the test starts, so "today" is today and not the day the file was loaded.
    'today' => [fn () => ['birth_date' => now()->toDateString()]],
    'in the future' => [fn () => ['birth_date' => now()->addYear()->toDateString()]],
    'before 1900' => [['birth_date' => '1850-01-01']],
]);
