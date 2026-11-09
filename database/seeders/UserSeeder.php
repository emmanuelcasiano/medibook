<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Support\DemoMode;
use Illuminate\Database\Seeder;

/**
 * One demo login per role (the emails are `DemoMode::ACCOUNTS`), plus 30 fake patients. The demo password is
 * "password": it is public on purpose, so this seeder refuses to run in production unless demo mode is on, and the
 * one-click demo login is blocked outside demo mode.
 */
class UserSeeder extends Seeder
{
    public const DEMO_DOCTOR_EMAIL = DemoMode::ACCOUNTS['doctor'];

    public function run(): void
    {
        DemoMode::ensureSeedingAllowed();

        User::factory()->patient()->create(['name' => 'Demo Patient', 'email' => DemoMode::ACCOUNTS['patient']]);
        User::factory()->staff()->create(['name' => 'Demo Staff', 'email' => DemoMode::ACCOUNTS['staff']]);
        // The doctor profile (specialty, hours, services) is added by DoctorSeeder.
        User::factory()->doctor()->create(['name' => 'Dr. Demo Doctor', 'email' => self::DEMO_DOCTOR_EMAIL]);
        User::factory()->nurse()->create(['name' => 'Demo Nurse', 'email' => DemoMode::ACCOUNTS['nurse']]);
        User::factory()->admin()->create(['name' => 'Demo Admin', 'email' => DemoMode::ACCOUNTS['admin']]);

        User::factory()->patient()->count(30)->create();
    }
}
