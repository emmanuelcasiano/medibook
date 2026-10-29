<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            ['General Consultation', 'general-consultation', 'A check-up for common illnesses and health concerns.', 30, 10],
            ['Pediatric Consultation', 'pediatric-consultation', 'Care for babies, children and teens.', 30, 10],
            ['Skin Consultation', 'skin-consultation', 'Rashes, acne and other skin concerns.', 20, 5],
            ['Annual Physical Exam', 'annual-physical-exam', 'A full yearly check-up with basic screening.', 45, 15],
            ['Vaccination', 'vaccination', 'Routine vaccines for children and adults.', 15, 5],
            ['Women\'s Health Consultation', 'womens-health-consultation', 'Reproductive and general health for women.', 30, 10],
        ];

        foreach ($services as [$name, $slug, $description, $duration, $buffer]) {
            Service::query()->create([
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'duration_minutes' => $duration,
                'buffer_minutes' => $buffer,
                'is_active' => true,
            ]);
        }
    }
}
