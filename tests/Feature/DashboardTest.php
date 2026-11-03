<?php

declare(strict_types=1);

use App\Enums\Role;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

// The route homes are written out here on purpose: the test must fail if `Role::homeRoute()` points a role wrong.
dataset('role_homes', [
    'patient' => [Role::Patient, 'my.home'],
    'staff' => [Role::Staff, 'staff.home'],
    'doctor' => [Role::Doctor, 'doctor.home'],
    'admin' => [Role::Admin, 'admin.users.index'],
]);

test('the dashboard sends each role to its own screen', function (Role $role, string $homeRoute) {
    $this->actingAs(userWithRole($role))
        ->get(route('dashboard'))
        ->assertRedirect(route($homeRoute));
})->with('role_homes');
