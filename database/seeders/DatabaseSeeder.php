<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

// No WithoutModelEvents here: HasUlids fills `appointments.ulid` in a `creating` event,
// so seeding with events off would leave seeded appointments without a ULID.
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with a fake clinic. Fake data only.
     */
    public function run(): void
    {
        $this->call([
            ServiceSeeder::class,
            UserSeeder::class,
            DoctorSeeder::class,
        ]);
    }
}
