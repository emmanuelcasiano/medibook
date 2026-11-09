<?php

declare(strict_types=1);

use App\Actions\Demo\LoginAsDemoUser;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

it('signs a visitor in as the demo user of the role and lands on that role\'s screen', function (Role $role) {
    config()->set('medibook.demo.enabled', true);
    $user = demoUser($role);

    $this->post(route('demo.login', $role->value))->assertRedirect(route($role->homeRoute()));

    $this->assertAuthenticatedAs($user);
    // Staff, doctor, nurse and admin have no 2FA here, so a 200 shows the gate lets the demo user through.
    $this->get(route($role->homeRoute()))->assertOk();
})->with('roles');

it('is a 404 for every role when demo mode is off', function (Role $role) {
    config()->set('medibook.demo.enabled', false);
    demoUser($role);

    $this->post(route('demo.login', $role->value))->assertNotFound();

    $this->assertGuest();
})->with('roles');

it('shows the demo logins and the banner only when demo mode is on', function (bool $demo) {
    config()->set('medibook.demo.enabled', $demo);

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('demo', $demo));
})->with([true, false]);

it('is a 404 for a role that is not one of the five', function () {
    config()->set('medibook.demo.enabled', true);

    $this->post('/demo/login/superadmin')->assertNotFound();

    $this->assertGuest();
});

it('never signs in a real user who only has the same role', function () {
    config()->set('medibook.demo.enabled', true);
    // A real admin exists, but the seeded demo admin does not (not seeded, or removed).
    User::factory()->admin()->create(['email' => 'real.admin@example.com']);

    $this->post(route('demo.login', Role::Admin->value))->assertNotFound();

    $this->assertGuest();
});

it('does not swap a signed-in user into a demo account', function () {
    config()->set('medibook.demo.enabled', true);
    demoUser(Role::Admin);
    $patient = userWithRole(Role::Patient);

    $this->actingAs($patient)->post(route('demo.login', Role::Admin->value))->assertRedirect();

    $this->assertAuthenticatedAs($patient);
});

it('refuses in the Action too when demo mode is off, even if the route middleware is skipped', function () {
    config()->set('medibook.demo.enabled', false);
    demoUser(Role::Admin);

    expect(fn () => app(LoginAsDemoUser::class)->handle(request(), Role::Admin))
        ->toThrow(NotFoundHttpException::class);

    $this->assertGuest();
});

it('signs out a demo user that was deactivated, like any other user', function () {
    config()->set('medibook.demo.enabled', true);
    demoUser(Role::Admin)->forceFill(['is_active' => false])->save();

    $this->post(route('demo.login', Role::Admin->value));
    $this->get(route('admin.users.index'))->assertRedirect(route('login'));

    $this->assertGuest();
});

it('limits the one-click login to 20 a minute per visitor', function () {
    config()->set('medibook.demo.enabled', true);
    RateLimiter::clear('demo-login');

    foreach (range(1, 20) as $attempt) {
        $this->post(route('demo.login', Role::Admin->value))->assertNotFound();
    }

    $this->post(route('demo.login', Role::Admin->value))->assertTooManyRequests();
});
