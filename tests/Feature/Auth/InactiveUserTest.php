<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;

it('does not let an inactive user log in, even with the right password', function () {
    $user = User::factory()->inactive()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('gives the same login error for an inactive account as for a wrong password', function () {
    $inactive = User::factory()->inactive()->create();
    $active = User::factory()->create();

    $this->post(route('login.store'), ['email' => $inactive->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $inactiveError = session('errors')->first('email');

    $this->post(route('login.store'), ['email' => $active->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');
    $wrongPasswordError = session('errors')->first('email');

    expect($inactiveError)->toBe($wrongPasswordError);
});

it('still lets an active user log in', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

it('signs out a user who is deactivated while logged in', function (Role $role) {
    $user = userWithRole($role);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
})->with('roles');

it('blocks an inactive user from a role route group', function () {
    $admin = userWithRole(Role::Admin);
    $admin->forceFill(['is_active' => false])->save();

    $this->actingAs($admin)->get(route('admin.users.index'))->assertRedirect(route('login'));
    $this->assertGuest();
});
