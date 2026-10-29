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

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
