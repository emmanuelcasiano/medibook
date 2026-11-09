<?php

declare(strict_types=1);

namespace App\Actions\Staff;

/**
 * The three facts staff type to make a patient account at the desk. Plain values, so an Action can take them without
 * a Request. `birthDate` is a 'Y-m-d' string, like registration.
 */
final readonly class NewPatient
{
    public function __construct(
        public string $name,
        public string $email,
        public string $birthDate,
    ) {}
}
