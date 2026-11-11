<?php

declare(strict_types=1);

use App\Actions\Admin\UpdateUser;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

describe('editing a user', function () {
    it('shows the edit page with the name, email and the role as read-only text', function () {
        $target = User::factory()->staff()->create(['name' => 'Sam Staff']);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.users.edit', $target))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/edit')
                ->where('user.id', $target->id)
                ->where('user.name', 'Sam Staff')
                ->where('user.email', $target->email)
                ->where('user.roleLabel', 'Staff')
                ->missing('user.password'));
    });

    it('changes the name only: stays verified, sends no email, logs the field name', function () {
        Notification::fake();
        $admin = userWithRole(Role::Admin);
        $target = User::factory()->staff()->create(['name' => 'Sam Staff']);
        $verifiedAt = $target->email_verified_at;

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $target), ['name' => 'Samuel Staff', 'email' => $target->email])
            ->assertRedirect(route('admin.users.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Samuel Staff has been updated.']);

        $log = AuditLog::query()->where('action', 'user.updated')->sole();
        expect($target->fresh()->name)->toBe('Samuel Staff')
            ->and($target->fresh()->email_verified_at?->equalTo($verifiedAt))->toBeTrue()
            ->and($log->actor_id)->toBe($admin->id)
            ->and($log->subject_id)->toBe($target->id)
            ->and($log->metadata)->toBe(['fields' => ['name']]);
        Notification::assertNothingSent();
    });

    it('changes the email: unverifies the account, emails the new address, logs no email value', function () {
        Notification::fake();
        $target = User::factory()->staff()->create(['email' => 'old.address@example.test']);

        $this->actingAs(userWithRole(Role::Admin))
            ->patch(route('admin.users.update', $target), ['name' => $target->name, 'email' => 'new.address@example.test'])
            ->assertRedirect(route('admin.users.index'));

        $log = AuditLog::query()->where('action', 'user.updated')->sole();
        expect($target->fresh()->email)->toBe('new.address@example.test')
            ->and($target->fresh()->email_verified_at)->toBeNull()
            ->and($log->metadata)->toBe(['fields' => ['email']])
            ->and(json_encode($log->metadata))->not->toContain('address@example.test');
        Notification::assertSentTo($target->fresh(), VerifyEmail::class, fn ($n, $channels) => $channels === ['mail']);
        Notification::assertSentTimes(VerifyEmail::class, 1);
    });

    it('writes one row listing both fields when the name and the email change', function () {
        Notification::fake();
        $target = User::factory()->staff()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->patch(route('admin.users.update', $target), ['name' => 'Someone New', 'email' => 'someone.new@example.test']);

        expect(AuditLog::query()->where('action', 'user.updated')->sole()->metadata)->toBe(['fields' => ['name', 'email']]);
    });

    it('does nothing and writes no audit row when nothing changed', function () {
        Notification::fake();
        $target = User::factory()->staff()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->patch(route('admin.users.update', $target), ['name' => $target->name, 'email' => $target->email])
            ->assertRedirect(route('admin.users.index'));

        expect(AuditLog::query()->where('action', 'user.updated')->exists())->toBeFalse();
        Notification::assertNothingSent();
    });
});

describe('who may edit a user', function () {
    it('denies everyone but admins on edit and update, and changes nothing', function (Role $role) {
        Notification::fake();
        $target = User::factory()->staff()->create(['name' => 'Sam Staff']);
        $before = $target->fresh()->only(['name', 'email', 'email_verified_at']);

        $this->actingAs(userWithRole($role))->get(route('admin.users.edit', $target))->assertForbidden();
        $this->actingAs(userWithRole($role))
            ->patch(route('admin.users.update', $target), ['name' => 'Hacked', 'email' => 'hacked@example.test'])
            ->assertForbidden();

        expect($target->fresh()->only(array_keys($before)))->toEqual($before)
            ->and(AuditLog::query()->where('action', 'user.updated')->exists())->toBeFalse();
        Notification::assertNothingSent();
    })->with('non_admins');

    it('sends a guest to the login page', function () {
        $target = User::factory()->staff()->create();

        $this->get(route('admin.users.edit', $target))->assertRedirect(route('login'));
        $this->patch(route('admin.users.update', $target), ['name' => 'X', 'email' => 'x@example.test'])->assertRedirect(route('login'));

        expect($target->fresh()->name)->not->toBe('X');
    });

    it('refuses an admin editing their own account, and changes nothing', function () {
        $admin = userWithRole(Role::Admin);
        $name = $admin->name;

        $this->actingAs($admin)->get(route('admin.users.edit', $admin))->assertForbidden();
        $this->actingAs($admin)
            ->patch(route('admin.users.update', $admin), ['name' => 'Renamed', 'email' => $admin->email])
            ->assertForbidden();

        expect($admin->fresh()->name)->toBe($name);
    });

    it('allows only an admin, and never on themselves (policy)', function (Role $role) {
        $user = userWithRole($role);
        $isAdmin = $role === Role::Admin;

        expect(Gate::forUser($user)->allows('update', User::factory()->create()))->toBe($isAdmin)
            ->and(Gate::forUser($user)->denies('update', $user))->toBeTrue();
    })->with('roles');

    it('answers 404 for a user id that does not exist', function () {
        $this->actingAs(userWithRole(Role::Admin))->get(route('admin.users.edit', 999999))->assertNotFound();
    });
});

describe('validation', function () {
    it('requires a name and an email', function () {
        $target = User::factory()->staff()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->patch(route('admin.users.update', $target), ['name' => '', 'email' => ''])
            ->assertSessionHasErrors(['name', 'email']);
    });

    it('rejects a malformed email and an over-long name', function () {
        $target = User::factory()->staff()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->patch(route('admin.users.update', $target), ['name' => str_repeat('a', 256), 'email' => 'not-an-email'])
            ->assertSessionHasErrors(['name', 'email']);

        expect($target->fresh()->email)->not->toBe('not-an-email');
    });

    it('rejects an email that another account uses, and changes nothing', function () {
        Notification::fake();
        $other = User::factory()->create(['email' => 'taken@example.test']);
        $target = User::factory()->staff()->create();
        $email = $target->email;

        $this->actingAs(userWithRole(Role::Admin))
            ->patch(route('admin.users.update', $target), ['name' => $target->name, 'email' => $other->email])
            ->assertSessionHasErrors('email');

        expect($target->fresh()->email)->toBe($email)
            ->and(AuditLog::query()->where('action', 'user.updated')->exists())->toBeFalse();
        Notification::assertNothingSent();
    });

    it('accepts the user’s own current email unchanged', function () {
        $target = User::factory()->staff()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->patch(route('admin.users.update', $target), ['name' => 'Renamed Person', 'email' => $target->email])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        expect($target->fresh()->name)->toBe('Renamed Person');
    });

    it('ignores role, is_active, password and verification fields in the request', function () {
        $target = User::factory()->staff()->create();
        $before = $target->fresh()->only(['role', 'is_active', 'password', 'email_verified_at']);

        $this->actingAs(userWithRole(Role::Admin))
            ->patch(route('admin.users.update', $target), [
                'name' => 'Renamed Person',
                'email' => $target->email,
                'role' => 'admin',
                'is_active' => false,
                'password' => 'a-new-Password-123!',
                'password_confirmation' => 'a-new-Password-123!',
                'email_verified_at' => null,
            ])
            ->assertSessionHasNoErrors();

        expect($target->fresh()->name)->toBe('Renamed Person')
            ->and($target->fresh()->only(array_keys($before)))->toEqual($before)
            ->and($target->fresh()->role)->toBe(Role::Staff);
    });
});

describe('the users list', function () {
    it('offers edit on every row but the admin’s own', function () {
        $admin = userWithRole(Role::Admin);
        $other = User::factory()->staff()->create();

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', fn ($rows) => collect($rows)->firstWhere('id', $admin->id)['can']['update'] === false
                    && collect($rows)->firstWhere('id', $other->id)['can']['update'] === true));
    });
});

describe('the doctor edit page', function () {
    it('gives the link to the doctor’s user account screen', function () {
        $doctor = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.doctors.edit', $doctor))
            ->assertInertia(fn (Assert $page) => $page->where('userId', $doctor->user_id));
    });
});

describe('UpdateUser', function () {
    it('does not send the email when the transaction fails', function () {
        Notification::fake();
        $admin = userWithRole(Role::Admin);
        $target = User::factory()->staff()->create();
        $email = $target->email;

        // A duplicate email makes the save throw inside the transaction (the unique index), so nothing is kept or sent.
        $other = User::factory()->create();

        expect(fn () => app(UpdateUser::class)->handle($admin, $target, $target->name, $other->email))
            ->toThrow(UniqueConstraintViolationException::class);

        expect($target->fresh()->email)->toBe($email);
        Notification::assertNothingSent();
    });
});
