<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array<string, mixed> */
function newUserInput(array $overrides = []): array
{
    return [
        'name' => 'Dr. Maria Santos',
        'email' => 'maria.santos@example.test',
        'role' => 'doctor',
        'specialty' => 'Pediatrics',
        'password' => 'a-long-demo-password-1',
        'password_confirmation' => 'a-long-demo-password-1',
        ...$overrides,
    ];
}

describe('users list', function () {
    it('shows a paginated list to an admin', function () {
        User::factory()->count(16)->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/index')
                ->has('users.data', 15)
                ->where('users.total', 17)
                ->missing('users.data.0.password')
                ->missing('users.data.0.birth_date'));
    });

    it('lets an admin deactivate others but not themselves', function () {
        $admin = userWithRole(Role::Admin);
        $other = User::factory()->create(['name' => 'Zed Patient']);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', fn ($rows) => collect($rows)->firstWhere('id', $admin->id)['can']['deactivate'] === false
                    && collect($rows)->firstWhere('id', $other->id)['can']['deactivate'] === true));
    });

    it('does not offer deactivate for an account that is already inactive', function () {
        $inactive = User::factory()->inactive()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', fn ($rows) => collect($rows)->firstWhere('id', $inactive->id)['can']['deactivate'] === false
                    && collect($rows)->firstWhere('id', $inactive->id)['isActive'] === false));
    });

    it('denies the list to everyone but admins', function (Role $role) {
        $this->actingAs(userWithRole($role))->get(route('admin.users.index'))->assertForbidden();
    })->with('non_admins');
});

describe('creating a user', function () {
    it('shows the create form to an admin, with every role but patient', function () {
        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.users.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/create')
                ->where('roles', fn ($roles) => collect($roles)->pluck('value')->sort()->values()->all() === ['admin', 'doctor', 'staff']));
    });

    it('limits how fast users can be created', function () {
        $admin = userWithRole(Role::Admin);

        foreach (range(1, 20) as $attempt) {
            $this->actingAs($admin)->post(route('admin.users.store'), [])->assertSessionHasErrors();
        }

        $this->actingAs($admin)->post(route('admin.users.store'), newUserInput())->assertTooManyRequests();
        expect(User::query()->where('email', 'maria.santos@example.test')->exists())->toBeFalse();
    });

    it('lets an admin create a doctor account with a doctor profile', function () {
        $admin = userWithRole(Role::Admin);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), newUserInput())
            ->assertRedirect(route('admin.users.index'));

        $doctor = User::query()->where('email', 'maria.santos@example.test')->firstOrFail();

        expect($doctor->role)->toBe(Role::Doctor)
            ->and($doctor->is_active)->toBeTrue()
            ->and($doctor->email_verified_at)->not->toBeNull()
            ->and($doctor->birth_date)->toBeNull()
            ->and(Hash::check('a-long-demo-password-1', $doctor->password))->toBeTrue()
            ->and(Doctor::query()->where('user_id', $doctor->id)->value('specialty'))->toBe('Pediatrics');
    });

    it('lets an admin create staff and admin accounts without a doctor profile', function (string $role) {
        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.users.store'), newUserInput(['role' => $role, 'specialty' => null, 'email' => "$role@example.test"]))
            ->assertRedirect(route('admin.users.index'));

        $user = User::query()->where('email', "$role@example.test")->firstOrFail();

        expect($user->role->value)->toBe($role)
            ->and(Doctor::query()->where('user_id', $user->id)->exists())->toBeFalse();
    })->with(['staff', 'admin']);

    it('can log in once created, and is then asked for 2FA', function () {
        $this->actingAs(userWithRole(Role::Admin))->post(route('admin.users.store'), newUserInput(['role' => 'staff', 'specialty' => null]));
        Auth::logout();

        $this->post(route('login.store'), ['email' => 'maria.santos@example.test', 'password' => 'a-long-demo-password-1']);
        $this->assertAuthenticated();

        $this->get(route('staff.home'))->assertRedirect(route('security.edit'));
    });

    it('denies creating users to everyone but admins, and creates nothing', function (Role $role) {
        $actor = userWithRole($role);
        $before = User::query()->count();

        $this->actingAs($actor)->get(route('admin.users.create'))->assertForbidden();
        $this->actingAs($actor)->post(route('admin.users.store'), newUserInput())->assertForbidden();

        expect(User::query()->count())->toBe($before);
    })->with('non_admins');

    it('validates the input', function (array $overrides, string|array $invalidField) {
        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.users.store'), newUserInput($overrides))
            ->assertSessionHasErrors($invalidField);

        expect(User::query()->where('email', 'maria.santos@example.test')->exists())->toBeFalse();
    })->with([
        'name is required' => [['name' => ''], 'name'],
        'email is required' => [['email' => ''], 'email'],
        'email must be an email' => [['email' => 'not-an-email'], 'email'],
        'role is required' => [['role' => ''], 'role'],
        'role must exist' => [['role' => 'superuser'], 'role'],
        'a patient can not be created here' => [['role' => 'patient'], 'role'],
        'a doctor needs a specialty' => [['role' => 'doctor', 'specialty' => ''], 'specialty'],
        'password is required' => [['password' => '', 'password_confirmation' => ''], 'password'],
        'password must be confirmed' => [['password_confirmation' => 'something-else'], 'password'],
        'password is too short' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    ]);

    it('rejects an email that is already used', function () {
        User::factory()->create(['email' => 'maria.santos@example.test']);

        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.users.store'), newUserInput())
            ->assertSessionHasErrors('email');

        expect(User::query()->where('email', 'maria.santos@example.test')->count())->toBe(1);
    });

    it('ignores an is_active or verified value sent in the form', function () {
        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.users.store'), newUserInput(['role' => 'staff', 'is_active' => false, 'email_verified_at' => null]));

        expect(User::query()->where('email', 'maria.santos@example.test')->firstOrFail()->is_active)->toBeTrue();
    });
});

describe('deactivating a user', function () {
    it('lets an admin deactivate a user, who then can not log in', function () {
        $target = userWithRole(Role::Staff);

        $this->actingAs(userWithRole(Role::Admin))
            ->patch(route('admin.users.deactivate', $target))
            ->assertRedirect(route('admin.users.index'));

        expect($target->fresh()->is_active)->toBeFalse();

        Auth::logout();
        $this->post(route('login.store'), ['email' => $target->email, 'password' => 'password']);
        $this->assertGuest();
    });

    it('keeps the user row, so appointments and the audit log still point at it', function () {
        $target = User::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))->patch(route('admin.users.deactivate', $target));

        expect(User::query()->whereKey($target->id)->exists())->toBeTrue();
    });

    it('removes the stored sessions of the deactivated user', function () {
        config(['session.driver' => 'database']);
        $target = User::factory()->create();
        $other = User::factory()->create();

        foreach ([$target, $other] as $user) {
            DB::table('sessions')->insert([
                'id' => "session-{$user->id}",
                'user_id' => $user->id,
                'payload' => '',
                'last_activity' => time(),
            ]);
        }

        $this->actingAs(userWithRole(Role::Admin))->patch(route('admin.users.deactivate', $target));

        expect(DB::table('sessions')->where('user_id', $target->id)->exists())->toBeFalse()
            ->and(DB::table('sessions')->where('user_id', $other->id)->exists())->toBeTrue();
    });

    it('does not let an admin deactivate themselves', function () {
        $admin = userWithRole(Role::Admin);

        $this->actingAs($admin)->patch(route('admin.users.deactivate', $admin))->assertForbidden();

        expect($admin->fresh()->is_active)->toBeTrue();
    });

    it('denies deactivating to everyone but admins, and changes nothing', function (Role $role) {
        $target = User::factory()->create();

        $this->actingAs(userWithRole($role))->patch(route('admin.users.deactivate', $target))->assertForbidden();

        expect($target->fresh()->is_active)->toBeTrue();
    })->with('non_admins');

    it('answers 404 for a user that does not exist', function () {
        $this->actingAs(userWithRole(Role::Admin))->patch(route('admin.users.deactivate', 999999))->assertNotFound();
    });

    it('sends a guest to the login page', function () {
        $target = User::factory()->create();

        $this->patch(route('admin.users.deactivate', $target))->assertRedirect(route('login'));

        expect($target->fresh()->is_active)->toBeTrue();
    });
});
