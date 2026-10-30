<?php

declare(strict_types=1);

use App\Enums\Role;
use Inertia\Testing\AssertableInertia as Assert;

// `auth.user` is sent with every page, so it must never carry secrets or the birth date.
it('does not send secrets or the birth date to the browser', function (Role $role) {
    $user = userWithRole($role);
    $user->forceFill(['birth_date' => '1990-04-12'])->save();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $user->id)
            ->where('auth.user.role', $role->value)
            ->missing('auth.user.birth_date')
            ->missing('auth.user.password')
            ->missing('auth.user.remember_token')
            ->missing('auth.user.two_factor_secret')
            ->missing('auth.user.two_factor_recovery_codes'));
})->with('roles');
