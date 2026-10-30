<?php

declare(strict_types=1);

use App\Enums\Role;

// Shared role datasets. Pest loads every file in tests/Datasets, so any test can use `->with('roles')`.

dataset('roles', fn () => collect(Role::cases())->mapWithKeys(fn (Role $role) => [$role->value => [$role]])->all());

dataset('non_admins', [
    'patient' => Role::Patient,
    'staff' => Role::Staff,
    'doctor' => Role::Doctor,
]);
