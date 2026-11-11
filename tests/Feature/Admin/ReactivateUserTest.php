<?php

declare(strict_types=1);

use App\Actions\Admin\ReactivateUser;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

describe('reactivating a user', function () {
    it('lets an admin reactivate an inactive user, with a toast, and writes one audit row', function () {
        $admin = userWithRole(Role::Admin);
        $target = User::factory()->staff()->inactive()->create(['name' => 'Sam Staff']);

        $this->actingAs($admin)
            ->patch(route('admin.users.reactivate', $target))
            ->assertRedirect(route('admin.users.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Sam Staff has been reactivated.']);

        $log = AuditLog::query()->where('action', 'user.reactivated')->sole();
        expect($target->fresh()->is_active)->toBeTrue()
            ->and($log->actor_id)->toBe($admin->id)
            ->and($log->subject_id)->toBe($target->id)
            ->and(json_encode($log->metadata))->not->toContain($target->email);
    });

    it('keeps the role, password, 2FA and the doctor profile as they were', function () {
        $doctor = Doctor::factory()->create();
        $user = $doctor->user;
        $user->forceFill(['is_active' => false])->save();
        $before = $user->fresh()->only(['role', 'password', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);

        $this->actingAs(userWithRole(Role::Admin))->patch(route('admin.users.reactivate', $user))->assertRedirect();

        expect($user->fresh()->only(array_keys($before)))->toEqual($before)
            ->and($doctor->fresh()->user_id)->toBe($user->id)
            ->and(Doctor::query()->count())->toBe(1);
    });

    it('lets the user log in again with the same password', function () {
        $target = User::factory()->staff()->inactive()->create();

        $this->post(route('login.store'), ['email' => $target->email, 'password' => 'password']);
        $this->assertGuest();

        $this->actingAs(userWithRole(Role::Admin))->patch(route('admin.users.reactivate', $target));
        auth()->logout();

        $this->post(route('login.store'), ['email' => $target->email, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($target);
    });
});

describe('who may reactivate', function () {
    it('denies everyone but admins, and changes nothing', function (Role $role) {
        $target = User::factory()->inactive()->create();

        $this->actingAs(userWithRole($role))->patch(route('admin.users.reactivate', $target))->assertForbidden();

        expect($target->fresh()->is_active)->toBeFalse()
            ->and(AuditLog::query()->where('action', 'user.reactivated')->exists())->toBeFalse();
    })->with('non_admins');

    it('sends a guest to the login page', function () {
        $target = User::factory()->inactive()->create();

        $this->patch(route('admin.users.reactivate', $target))->assertRedirect(route('login'));

        expect($target->fresh()->is_active)->toBeFalse();
    });

    it('allows only an admin, and only on an inactive account (policy)', function (Role $role) {
        $user = userWithRole($role);
        $isAdmin = $role === Role::Admin;

        expect(Gate::forUser($user)->allows('reactivate', User::factory()->inactive()->create()))->toBe($isAdmin)
            ->and(Gate::forUser($user)->denies('reactivate', User::factory()->create()))->toBeTrue();
    })->with('roles');
});

describe('route binding (there is no request body to validate)', function () {
    it('answers 404 for a user id that does not exist', function () {
        $this->actingAs(userWithRole(Role::Admin))->patch(route('admin.users.reactivate', 999999))->assertNotFound();
    });

    it('answers 403 for a patient who is already active, and writes no audit row', function () {
        $patient = User::factory()->patient()->create();

        $this->actingAs(userWithRole(Role::Admin))->patch(route('admin.users.reactivate', $patient))->assertForbidden();

        expect(AuditLog::query()->where('action', 'user.reactivated')->exists())->toBeFalse();
    });

    it('answers 403 for a doctor who is already active', function () {
        $doctor = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))->patch(route('admin.users.reactivate', $doctor->user))->assertForbidden();
    });
});

describe('the users list', function () {
    it('offers reactivate on inactive rows only, and deactivate on active rows only', function () {
        $active = User::factory()->create();
        $inactive = User::factory()->inactive()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', function ($rows) use ($active, $inactive): bool {
                    $rows = collect($rows);

                    return $rows->firstWhere('id', $active->id)['can'] === ['deactivate' => true, 'reactivate' => false]
                        && $rows->firstWhere('id', $inactive->id)['can'] === ['deactivate' => false, 'reactivate' => true];
                }));
    });
});

describe('ReactivateUser', function () {
    it('writes user.reactivated once, not again for an account that is already on', function () {
        $admin = userWithRole(Role::Admin);
        $user = User::factory()->staff()->inactive()->create();

        app(ReactivateUser::class)->handle($admin, $user);
        app(ReactivateUser::class)->handle($admin, $user->fresh());
        app(ReactivateUser::class)->handle($admin, User::factory()->staff()->create());

        expect(AuditLog::count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'user.reactivated')->sole()->subject_id)->toBe($user->id)
            ->and($user->fresh()->is_active)->toBeTrue();
    });
});
