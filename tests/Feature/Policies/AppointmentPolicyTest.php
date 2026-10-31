<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('lets only staff and admins open the day schedule', function (Role $role, bool $allowed) {
    expect(Gate::forUser(userWithRole($role))->allows('viewSchedule', Appointment::class))->toBe($allowed);
})->with([
    'patient' => [Role::Patient, false],
    'staff' => [Role::Staff, true],
    'doctor' => [Role::Doctor, false],
    'admin' => [Role::Admin, true],
]);

it('lets a patient see and cancel only their own appointment', function () {
    $appointment = Appointment::factory()->booked()->create();
    $stranger = userWithRole(Role::Patient);
    $owner = User::query()->findOrFail($appointment->patient_id);

    expect(Gate::forUser($owner)->allows('view', $appointment))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('cancel', $appointment))->toBeTrue()
        ->and(Gate::forUser($stranger)->allows('view', $appointment))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('cancel', $appointment))->toBeFalse();
});

it('lets a doctor see only their own appointments', function () {
    $appointment = Appointment::factory()->booked()->create();
    $ownDoctor = User::query()->findOrFail(Doctor::query()->findOrFail($appointment->doctor_id)->user_id);
    $otherDoctor = userWithRole(Role::Doctor);

    expect(Gate::forUser($ownDoctor)->allows('view', $appointment))->toBeTrue()
        ->and(Gate::forUser($otherDoctor)->allows('view', $appointment))->toBeFalse();
});

it('lets only the appointment\'s own doctor finish the visit', function () {
    $appointment = Appointment::factory()->checkedIn()->create();
    $ownDoctor = User::query()->findOrFail(Doctor::query()->findOrFail($appointment->doctor_id)->user_id);

    expect(Gate::forUser($ownDoctor)->allows('complete', $appointment))->toBeTrue()
        ->and(Gate::forUser(userWithRole(Role::Doctor))->allows('complete', $appointment))->toBeFalse()
        ->and(Gate::forUser(userWithRole(Role::Staff))->allows('complete', $appointment))->toBeFalse()
        ->and(Gate::forUser(userWithRole(Role::Admin))->allows('complete', $appointment))->toBeFalse();
});

it('matches the role table for each appointment ability', function (string $ability, array $allowedRoles) {
    $appointment = Appointment::factory()->booked()->create();

    foreach (Role::cases() as $role) {
        expect(Gate::forUser(userWithRole($role))->allows($ability, $appointment))
            ->toBe(in_array($role, $allowedRoles, true), "$ability for {$role->value}");
    }
})->with([
    // `view` and `cancel` also depend on ownership, which the tests above cover. A stranger patient is denied here.
    'view' => ['view', [Role::Staff, Role::Admin]],
    'cancel' => ['cancel', [Role::Staff]],
    'checkIn' => ['checkIn', [Role::Staff]],
    'markNoShow' => ['markNoShow', [Role::Staff]],
    'move' => ['move', [Role::Staff]],
    'complete' => ['complete', []],
]);

it('lets only patients book', function (Role $role) {
    expect(Gate::forUser(userWithRole($role))->allows('create', Appointment::class))->toBe($role === Role::Patient);
})->with('roles');

it('lets every role open an appointment list, which the controller scopes to the user', function (Role $role) {
    expect(Gate::forUser(userWithRole($role))->allows('viewAny', Appointment::class))->toBeTrue();
})->with('roles');
