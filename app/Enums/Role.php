<?php

declare(strict_types=1);

namespace App\Enums;

enum Role: string
{
    case Patient = 'patient';
    case Staff = 'staff';
    case Doctor = 'doctor';
    case Admin = 'admin';

    /** Roles that need 2FA (PROJECT.md section 3). */
    public function requiresTwoFactor(): bool
    {
        return $this !== self::Patient;
    }

    /** The route name of the screen this role works in. `/dashboard` sends each user here. */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Patient => 'my.home',
            self::Staff => 'staff.home',
            self::Doctor => 'doctor.home',
            self::Admin => 'admin.users.index',
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
