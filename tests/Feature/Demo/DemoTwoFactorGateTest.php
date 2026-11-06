<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Support\DemoMode;

// The three roles that need 2FA, and the screen each one opens.
dataset('two_factor_roles', [
    'staff' => [Role::Staff, 'staff.home'],
    'doctor' => [Role::Doctor, 'doctor.home'],
    'admin' => [Role::Admin, 'admin.users.index'],
]);

it('lets the demo staff, doctor and admin past the 2FA gate in demo mode', function (Role $role, string $routeName) {
    config()->set('medibook.demo.enabled', true);

    $this->actingAs(demoUser($role))->get(route($routeName))->assertOk();
})->with('two_factor_roles');

it('keeps the 2FA gate for the demo users when demo mode is off', function (Role $role, string $routeName) {
    config()->set('medibook.demo.enabled', false);

    $this->actingAs(demoUser($role))->get(route($routeName))->assertRedirect(route('security.edit'));
})->with('two_factor_roles');

it('keeps the 2FA gate for every other user in demo mode', function (Role $role, string $routeName) {
    config()->set('medibook.demo.enabled', true);

    $this->actingAs(userWithRole($role, twoFactor: false))->get(route($routeName))->assertRedirect(route('security.edit'));
})->with('two_factor_roles');

it('does not let a user borrow a demo email to skip 2FA under another role', function () {
    config()->set('medibook.demo.enabled', true);
    $staff = User::factory()->staff()->create(['email' => DemoMode::emailFor(Role::Admin)]);

    $this->actingAs($staff)->get(route('staff.home'))->assertRedirect(route('security.edit'));
});
