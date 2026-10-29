<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One demo login per role, plus 30 fake patients. The demo password is "password":
 * it is public on purpose, and the demo-login screen is blocked outside demo mode (Day 12).
 */
class UserSeeder extends Seeder
{
    public const DEMO_DOCTOR_EMAIL = 'doctor@medibook.test';

    public function run(): void
    {
        User::factory()->patient()->create(['name' => 'Demo Patient', 'email' => 'patient@medibook.test']);
        User::factory()->staff()->create(['name' => 'Demo Staff', 'email' => 'staff@medibook.test']);
        // The doctor profile (specialty, hours, services) is added by DoctorSeeder.
        User::factory()->doctor()->create(['name' => 'Dr. Demo Doctor', 'email' => self::DEMO_DOCTOR_EMAIL]);
        User::factory()->admin()->create(['name' => 'Demo Admin', 'email' => 'admin@medibook.test']);

        User::factory()->patient()->count(30)->create();
    }
}
