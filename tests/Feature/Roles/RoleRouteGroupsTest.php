<?php

declare(strict_types=1);

use App\Enums\Role;

// Each role-only route group and the roles that may enter it. An admin may also open the staff area, because the
// day schedule is shown to staff and admin (PROJECT.md section 3).
dataset('role_route_groups', [
    'admin area' => ['admin.users.index', [Role::Admin]],
    'staff area' => ['staff.home', [Role::Staff, Role::Admin]],
    'doctor area' => ['doctor.home', [Role::Doctor]],
    'nurse area' => ['nurse.home', [Role::Nurse]],
    'patient area' => ['my.home', [Role::Patient]],
]);

it('lets only the right roles into a route group', function (string $routeName, array $allowed, Role $actual) {
    $response = $this->actingAs(userWithRole($actual))->get(route($routeName));

    if (in_array($actual, $allowed, true)) {
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
    'nurse area' => 'nurse.home',
    'patient area' => 'my.home',
    'dashboard' => 'dashboard',
]);

// Controllers get the signed-in user from `#[CurrentUser]`. A guest must be stopped by the `auth` middleware before the
// controller is built, so every write route answers with a redirect to login and never a 500.
it('sends guests to the login page on every write route', function (string $method, string $routeName, array $parameters) {
    $this->call($method, route($routeName, $parameters))->assertRedirect(route('login'));

    $this->assertGuest();
})->with([
    'update doctor' => ['PUT', 'admin.doctors.update', ['doctor' => 1]],
    'add time off' => ['POST', 'admin.doctors.time-offs.store', ['doctor' => 1]],
    'remove time off' => ['DELETE', 'admin.doctors.time-offs.destroy', ['doctor' => 1, 'timeOff' => 1]],
    'save working hours' => ['PUT', 'admin.doctors.working-hours.update', ['doctor' => 1]],
    'create service' => ['POST', 'admin.services.store', []],
    'update service' => ['PUT', 'admin.services.update', ['service' => 1]],
    'delete service' => ['DELETE', 'admin.services.destroy', ['service' => 1]],
    'create user' => ['POST', 'admin.users.store', []],
    'deactivate user' => ['PATCH', 'admin.users.deactivate', ['user' => 1]],
    'reactivate user' => ['PATCH', 'admin.users.reactivate', ['user' => 1]],
    'confirm booking' => ['POST', 'booking.store', ['service' => 'any-service']],
    'finish visit' => ['POST', 'doctor.appointments.complete', ['appointment' => 'x']],
    'review summary' => ['POST', 'doctor.appointments.summary.review', ['appointment' => 'x']],
    'save visit note' => ['PUT', 'doctor.appointments.visit-note.update', ['appointment' => 'x']],
    'patient cancel' => ['POST', 'my.appointments.cancel', ['appointment' => 'x']],
    'save intake' => ['PUT', 'my.appointments.intake.update', ['appointment' => 'x']],
    'change password' => ['PUT', 'user-password.update', []],
    'update profile' => ['PATCH', 'profile.update', []],
    'delete profile' => ['DELETE', 'profile.destroy', []],
    'staff cancel' => ['POST', 'staff.appointments.cancel', ['appointment' => 'x']],
    'check in' => ['POST', 'staff.appointments.check-in', ['appointment' => 'x']],
    'move' => ['POST', 'staff.appointments.move', ['appointment' => 'x']],
    'no-show' => ['POST', 'staff.appointments.no-show', ['appointment' => 'x']],
]);

it('keeps unverified users out of every route group', function (string $routeName, Role $role) {
    $user = userWithRole($role);
    $user->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($user)->get(route($routeName))->assertRedirect(route('verification.notice'));
})->with([
    'admin area' => ['admin.users.index', Role::Admin],
    'staff area' => ['staff.home', Role::Staff],
    'doctor area' => ['doctor.home', Role::Doctor],
    'nurse area' => ['nurse.home', Role::Nurse],
    'patient area' => ['my.home', Role::Patient],
]);
