<?php

declare(strict_types=1);

use App\Actions\Admin\CreateStaffUser;
use App\Actions\Admin\DeactivateUser;
use App\Enums\Role;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Database\QueryException;

describe('CreateStaffUser', function () {
    it('creates a doctor together with the doctor profile', function () {
        $user = app(CreateStaffUser::class)->handle('Dr. Ana Reyes', 'ana@example.test', 'a-long-demo-password-1', Role::Doctor, 'Dermatology');

        expect($user->role)->toBe(Role::Doctor)
            ->and(Doctor::query()->where('user_id', $user->id)->value('specialty'))->toBe('Dermatology');
    });

    it('refuses to create a patient', function () {
        app(CreateStaffUser::class)->handle('Pat Ient', 'pat@example.test', 'a-long-demo-password-1', Role::Patient);
    })->throws(InvalidArgumentException::class);

    it('refuses a doctor without a specialty, and creates nothing', function (?string $specialty) {
        try {
            app(CreateStaffUser::class)->handle('Dr. No Specialty', 'nospec@example.test', 'a-long-demo-password-1', Role::Doctor, $specialty);
        } catch (InvalidArgumentException) {
            // expected
        }

        expect(User::query()->where('email', 'nospec@example.test')->exists())->toBeFalse();
    })->with([null, '']);

    it('rolls back the user when the doctor profile can not be saved', function () {
        // A specialty longer than the column makes the doctors insert fail after the user row was written.
        expect(fn () => app(CreateStaffUser::class)->handle('Dr. Long', 'long@example.test', 'a-long-demo-password-1', Role::Doctor, str_repeat('x', 300)))
            ->toThrow(QueryException::class);

        expect(User::query()->where('email', 'long@example.test')->exists())->toBeFalse();
    });

    it('hashes the password', function () {
        $user = app(CreateStaffUser::class)->handle('Sam Staff', 'sam@example.test', 'a-long-demo-password-1', Role::Staff);

        expect($user->password)->not->toBe('a-long-demo-password-1');
    });
});

describe('DeactivateUser', function () {
    it('turns the account off and clears the remember token', function () {
        $user = User::factory()->create(['remember_token' => 'abc123']);

        app(DeactivateUser::class)->handle($user);

        $fresh = $user->fresh();
        expect($fresh->is_active)->toBeFalse()
            ->and($fresh->remember_token)->toBeNull();
    });

    it('is safe to run twice', function () {
        $user = User::factory()->inactive()->create();

        app(DeactivateUser::class)->handle($user);

        expect($user->fresh()->is_active)->toBeFalse();
    });
});
