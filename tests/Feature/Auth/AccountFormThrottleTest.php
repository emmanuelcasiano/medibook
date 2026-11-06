<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Notification;

// Step 14.4: register, "send me a reset link" and "set the new password" are limited to 5 a minute per IP
// (ThrottleAccountForms). Fortify only limits login and the 2FA challenge. There is no Policy (the forms are for guests)
// and the rules of the forms are tested in RegistrationTest and PasswordResetTest, so these tests cover the limit itself:
// the 6th request is refused, nothing is written for it, a second IP has its own count, and pages are not counted.
// Bad input is sent on purpose: a refused form still counts as a try, which is what stops a script guessing.

/** Sends $times empty forms to the route, each one refused by validation (a 302 back to the form). */
function submitEmptyForm(string $routeName, int $times): void
{
    for ($i = 0; $i < $times; $i++) {
        test()->post(route($routeName), [])->assertRedirect();
    }
}

it('refuses the 6th sign-up in a minute', function (): void {
    submitEmptyForm('register.store', 5);

    $this->post(route('register.store'), [
        'name' => 'Too Fast',
        'email' => 'too-fast@example.com',
        'birth_date' => '1990-04-12',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertTooManyRequests();

    $this->assertGuest();
    expect(User::query()->where('email', 'too-fast@example.com')->exists())->toBeFalse();
});

it('refuses the 6th reset link request in a minute and sends no email for it', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    submitEmptyForm('password.email', 5);

    $this->post(route('password.email'), ['email' => $user->email])->assertTooManyRequests();

    Notification::assertNothingSent();
});

it('counts the reset link request and the new password form together', function (): void {
    submitEmptyForm('password.email', 3);
    submitEmptyForm('password.update', 2);

    $this->post(route('password.update'), [])->assertTooManyRequests();
    $this->post(route('password.email'), [])->assertTooManyRequests();
});

it('keeps a separate count for each IP address', function (): void {
    submitEmptyForm('register.store', 5);
    $this->post(route('register.store'), [])->assertTooManyRequests();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->post(route('register.store'), [])
        ->assertRedirect();
});

it('does not count page views or the other routes', function (): void {
    submitEmptyForm('register.store', 5);

    foreach (['register', 'password.request', 'login'] as $page) {
        $this->get(route($page))->assertOk();
    }

    $this->post(route('login'), [])->assertRedirect();
});
