<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

it('seeds the clinic with the expected counts', function () {
    expect(Service::query()->count())->toBe(6)
        ->and(Doctor::query()->count())->toBe(4)
        // 30 fake patients plus the demo patient.
        ->and(User::query()->where('role', Role::Patient)->count())->toBe(31);
});

it('seeds one demo login per role', function (string $email, Role $role) {
    expect(User::query()->where('email', $email)->firstOrFail()->role)->toBe($role);
})->with([
    ['patient@medibook.test', Role::Patient],
    ['staff@medibook.test', Role::Staff],
    ['doctor@medibook.test', Role::Doctor],
    ['admin@medibook.test', Role::Admin],
]);

it('gives the demo doctor a doctor profile', function () {
    $doctor = User::query()->where('email', 'doctor@medibook.test')->firstOrFail()->doctor;

    expect($doctor)->not->toBeNull();
});

it('gives every doctor services and hours with a lunch break', function () {
    $doctors = Doctor::query()->with(['services', 'workingHours'])->get();

    expect($doctors)->toHaveCount(4);

    foreach ($doctors as $doctor) {
        expect($doctor->services)->not->toBeEmpty();

        // Two ranges on every working weekday, and the first ends before the second starts.
        foreach ($doctor->workingHours->groupBy('weekday') as $ranges) {
            $ranges = $ranges->sortBy('starts_at')->values();

            expect($ranges)->toHaveCount(2)
                ->and($ranges[0]->ends_at < $ranges[1]->starts_at)->toBeTrue();
        }
    }
});

it('offers every service through at least one doctor', function () {
    $unoffered = Service::query()->whereDoesntHave('doctors')->count();

    expect($unoffered)->toBe(0);
});
