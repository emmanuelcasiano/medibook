<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Doctor;
use App\Models\User;

it('answers each role helper for exactly one role', function (Role $role) {
    $user = User::factory()->make(['role' => $role]);

    expect($user->isPatient())->toBe($role === Role::Patient)
        ->and($user->isStaff())->toBe($role === Role::Staff)
        ->and($user->isDoctor())->toBe($role === Role::Doctor)
        ->and($user->isAdmin())->toBe($role === Role::Admin);
})->with('roles');

it('needs 2FA setup only for staff, doctors and admins who have not confirmed 2FA', function (Role $role, bool $hasTwoFactor, bool $expected) {
    $user = User::factory()->state(['role' => $role])->when($hasTwoFactor, fn ($factory) => $factory->withTwoFactor())->make();

    expect($user->needsTwoFactorSetup())->toBe($expected);
})->with([
    'patient without 2FA' => [Role::Patient, false, false],
    'staff without 2FA' => [Role::Staff, false, true],
    'doctor without 2FA' => [Role::Doctor, false, true],
    'admin without 2FA' => [Role::Admin, false, true],
    'staff with 2FA' => [Role::Staff, true, false],
    'admin with 2FA' => [Role::Admin, true, false],
]);

it('treats a started but unconfirmed 2FA setup as not enabled', function () {
    $user = User::factory()->admin()->make(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => null]);

    expect($user->needsTwoFactorSetup())->toBeTrue();
});

it('finds the doctor profile id for a doctor and null for everyone else', function () {
    $doctor = userWithRole(Role::Doctor);

    expect($doctor->doctorId())->toBe(Doctor::query()->where('user_id', $doctor->id)->value('id'))
        ->and(userWithRole(Role::Staff)->doctorId())->toBeNull()
        ->and(userWithRole(Role::Patient)->doctorId())->toBeNull();
});
