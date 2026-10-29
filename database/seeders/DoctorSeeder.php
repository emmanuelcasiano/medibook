<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 4 doctors, each with services and working hours. Hours are clinic-local (Asia/Manila)
 * and every day has a lunch break (two ranges), so the slot finder has something to skip.
 */
class DoctorSeeder extends Seeder
{
    public function run(): void
    {
        // Weekday numbers follow Carbon: 0 = Sunday ... 6 = Saturday.
        $doctors = [
            [
                'user' => User::query()->where('email', UserSeeder::DEMO_DOCTOR_EMAIL)->firstOrFail(),
                'specialty' => 'General Medicine',
                'services' => ['general-consultation', 'annual-physical-exam', 'vaccination'],
                'weekdays' => [1, 2, 3, 4, 5],
                'ranges' => [['08:00', '12:00'], ['13:00', '17:00']],
            ],
            [
                'user' => null,
                'specialty' => 'Pediatrics',
                'services' => ['pediatric-consultation', 'vaccination', 'general-consultation'],
                'weekdays' => [1, 2, 3, 4, 5],
                'ranges' => [['09:00', '12:00'], ['13:00', '17:00']],
            ],
            [
                'user' => null,
                'specialty' => 'Dermatology',
                'services' => ['skin-consultation'],
                'weekdays' => [1, 3, 5],
                'ranges' => [['09:00', '12:00'], ['13:00', '18:00']],
            ],
            [
                'user' => null,
                'specialty' => 'Obstetrics and Gynecology',
                'services' => ['womens-health-consultation', 'general-consultation'],
                'weekdays' => [2, 3, 4, 5, 6],
                'ranges' => [['08:00', '12:00'], ['13:00', '17:00']],
            ],
        ];

        $serviceIds = Service::query()->pluck('id', 'slug');

        foreach ($doctors as $definition) {
            $user = $definition['user']
                ?? User::factory()->doctor()->create(['name' => 'Dr. '.fake('en_PH')->name()]);

            $doctor = Doctor::query()->create([
                'user_id' => $user->id,
                'specialty' => $definition['specialty'],
                'bio' => fake()->paragraph(),
            ]);

            $doctor->services()->attach(
                collect($definition['services'])->map(fn (string $slug): int => $serviceIds[$slug])->all(),
            );

            foreach ($definition['weekdays'] as $weekday) {
                foreach ($definition['ranges'] as [$startsAt, $endsAt]) {
                    $doctor->workingHours()->create([
                        'weekday' => $weekday,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                    ]);
                }
            }
        }
    }
}
