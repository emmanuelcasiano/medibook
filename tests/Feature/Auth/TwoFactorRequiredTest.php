<?php

declare(strict_types=1);

use App\Enums\Role;

// A route each privileged role uses, plus the dashboard that every role can open.
dataset('privileged_roles', [
    'staff' => [Role::Staff, 'staff.home'],
    'doctor' => [Role::Doctor, 'doctor.home'],
    'admin' => [Role::Admin, 'admin.users.index'],
]);

it('sends staff, doctors and admins without 2FA to the 2FA setup page', function (Role $role, string $routeName) {
    $user = userWithRole($role, twoFactor: false);

    $this->actingAs($user)->get(route($routeName))->assertRedirect(route('security.edit'));
    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('security.edit'));
})->with('privileged_roles');

it('lets staff, doctors and admins in once 2FA is on', function (Role $role, string $routeName) {
    $this->actingAs(userWithRole($role))->get(route($routeName))->assertOk();
})->with('privileged_roles');

it('does not ask patients for 2FA', function () {
    $patient = userWithRole(Role::Patient);

    expect($patient->two_factor_confirmed_at)->toBeNull();

    $this->actingAs($patient)->get(route('dashboard'))->assertOk();
    $this->actingAs($patient)->get(route('my.home'))->assertOk();
});

it('keeps the settings pages open, so the 2FA setup page can be reached', function (Role $role) {
    $user = userWithRole($role, twoFactor: false);

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk();
})->with(['staff' => Role::Staff, 'doctor' => Role::Doctor, 'admin' => Role::Admin]);

it('does not count a started but unconfirmed 2FA setup', function () {
    $admin = userWithRole(Role::Admin, twoFactor: false);
    $admin->forceFill(['two_factor_secret' => encrypt('secret')])->save();

    $this->actingAs($admin)->get(route('admin.users.index'))->assertRedirect(route('security.edit'));
});
