<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('lets only admins manage services', function (Role $role) {
    $service = Service::factory()->create();
    $user = userWithRole($role);
    $isAdmin = $role === Role::Admin;

    expect(Gate::forUser($user)->allows('viewAny', Service::class))->toBe($isAdmin)
        ->and(Gate::forUser($user)->allows('view', $service))->toBe($isAdmin)
        ->and(Gate::forUser($user)->allows('create', Service::class))->toBe($isAdmin)
        ->and(Gate::forUser($user)->allows('update', $service))->toBe($isAdmin)
        ->and(Gate::forUser($user)->allows('delete', $service))->toBe($isAdmin);
})->with('roles');

it('lets only admins list, view and update doctors', function (Role $role) {
    $doctor = Doctor::factory()->create();
    $user = userWithRole($role);
    $isAdmin = $role === Role::Admin;

    expect(Gate::forUser($user)->allows('viewAny', Doctor::class))->toBe($isAdmin)
        ->and(Gate::forUser($user)->allows('view', $doctor))->toBe($isAdmin)
        ->and(Gate::forUser($user)->allows('update', $doctor))->toBe($isAdmin);
})->with('roles');

it('denies creating and deleting doctors to every role, admins included', function (Role $role) {
    $doctor = Doctor::factory()->create();
    $user = userWithRole($role);

    expect(Gate::forUser($user)->denies('create', Doctor::class))->toBeTrue()
        ->and(Gate::forUser($user)->denies('delete', $doctor))->toBeTrue();
})->with('roles');

it('does not let a doctor update their own profile through the policy', function () {
    $doctor = Doctor::factory()->for(User::factory()->doctor()->withTwoFactor(), 'user')->create();

    expect(Gate::forUser($doctor->user)->denies('update', $doctor))->toBeTrue();
});

it('lets only admins list and create users', function (Role $role) {
    $user = userWithRole($role);
    $isAdmin = $role === Role::Admin;

    expect(Gate::forUser($user)->allows('viewAny', User::class))->toBe($isAdmin)
        ->and(Gate::forUser($user)->allows('create', User::class))->toBe($isAdmin);
})->with('roles');

it('lets an admin deactivate another user but not themselves', function () {
    $admin = userWithRole(Role::Admin);

    expect(Gate::forUser($admin)->allows('deactivate', User::factory()->create()))->toBeTrue()
        ->and(Gate::forUser($admin)->denies('deactivate', $admin))->toBeTrue();
});

it('does not let non-admins deactivate anyone', function (Role $role) {
    expect(Gate::forUser(userWithRole($role))->denies('deactivate', User::factory()->create()))->toBeTrue();
})->with('non_admins');

it('lets only admins read the audit log', function (Role $role) {
    $entry = AuditLog::factory()->create();
    $user = userWithRole($role);
    $isAdmin = $role === Role::Admin;

    expect(Gate::forUser($user)->allows('viewAny', AuditLog::class))->toBe($isAdmin)
        ->and(Gate::forUser($user)->allows('view', $entry))->toBe($isAdmin);
})->with('roles');

it('denies by default: an ability no policy defines is denied to every role, admins included', function (Role $role) {
    $user = userWithRole($role);

    expect(Gate::forUser($user)->denies('update', AuditLog::factory()->create()))->toBeTrue()
        ->and(Gate::forUser($user)->denies('delete', AuditLog::factory()->create()))->toBeTrue()
        ->and(Gate::forUser($user)->denies('delete', User::factory()->create()))->toBeTrue()
        ->and(Gate::forUser($user)->denies('some-made-up-ability', Service::factory()->create()))->toBeTrue();
})->with('roles');
