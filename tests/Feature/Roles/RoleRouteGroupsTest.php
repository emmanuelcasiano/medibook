<?php

declare(strict_types=1);

use App\Enums\Role;

// Each role-only route group and the one role that may enter it.
dataset('role_route_groups', [
    'admin area' => ['admin.users.index', Role::Admin],
    'staff area' => ['staff.home', Role::Staff],
    'doctor area' => ['doctor.home', Role::Doctor],
    'patient area' => ['my.home', Role::Patient],
]);

it('lets only the right role into a route group', function (string $routeName, Role $allowed, Role $actual) {
    $response = $this->actingAs(userWithRole($actual))->get(route($routeName));

    if ($actual === $allowed) {
        $response->assertOk();
    } else {
        $response->assertForbidden();
    }
})->with('role_route_groups')->with('roles');

it('sends guests to the login page', function (string $routeName) {
    $this->get(route($routeName))->assertRedirect(route('login'));
})->with([
    'admin area' => 'admin.users.index',
    'staff area' => 'staff.home',
    'doctor area' => 'doctor.home',
    'patient area' => 'my.home',
]);

it('keeps unverified users out of every route group', function (string $routeName, Role $role) {
    $user = userWithRole($role);
    $user->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($user)->get(route($routeName))->assertRedirect(route('verification.notice'));
})->with([
    'admin area' => ['admin.users.index', Role::Admin],
    'staff area' => ['staff.home', Role::Staff],
    'doctor area' => ['doctor.home', Role::Doctor],
    'patient area' => ['my.home', Role::Patient],
]);
