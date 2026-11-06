<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use RuntimeException;

/**
 * The one place that knows whether the app runs as the public demo (PROJECT.md F12) and which four users the
 * one-click logins may open. Everything else asks here, so "demo mode" can't mean two things in two files.
 */
final class DemoMode
{
    /**
     * The seeded demo users, by role. The one-click login opens only these, and only by role: a visitor never
     * types an email. `UserSeeder` creates them with the public password "password".
     *
     * @var array<string, string>
     */
    public const array ACCOUNTS = [
        'patient' => 'patient@medibook.test',
        'staff' => 'staff@medibook.test',
        'doctor' => 'doctor@medibook.test',
        'admin' => 'admin@medibook.test',
    ];

    public static function enabled(): bool
    {
        return (bool) config('medibook.demo.enabled');
    }

    public static function emailFor(Role $role): string
    {
        return self::ACCOUNTS[$role->value];
    }

    /**
     * True only in demo mode, and only for a user whose email and role are both the seeded pair. Checking the role
     * too means a user can't borrow a demo email to skip 2FA under another role.
     */
    public static function isDemoUser(User $user): bool
    {
        return self::enabled() && $user->email === self::emailFor($user->role);
    }

    /**
     * Seeders create logins with the public demo password, so they must never fill a real production database.
     * A production server may seed only when it is the demo (APP_DEMO on).
     */
    public static function ensureSeedingAllowed(): void
    {
        if (app()->isProduction() && ! self::enabled()) {
            throw new RuntimeException('Refusing to seed in production: the demo users have a public password. Set APP_DEMO=true only on the demo server.');
        }
    }
}
