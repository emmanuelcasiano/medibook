<?php

declare(strict_types=1);

use App\Models\User;
use App\Providers\AppServiceProvider;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoScheduleSeeder;
use Database\Seeders\DoctorSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Support\Facades\DB;

// The seeders are called directly: `db:seed` itself refuses to run in production without --force, which would hide
// the guard under test.
it('refuses to seed in production unless demo mode is on', function (string $seeder) {
    config()->set('medibook.demo.enabled', false);
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => (new $seeder)->run())->toThrow(RuntimeException::class, 'Refusing to seed in production');

    expect(User::query()->count())->toBe(0);
})->with([
    'all' => DatabaseSeeder::class,
    'users' => UserSeeder::class,
    'doctors' => DoctorSeeder::class,
    'schedule' => DemoScheduleSeeder::class,
]);

it('seeds on the production demo server', function () {
    config()->set('medibook.demo.enabled', true);
    app()->detectEnvironment(fn (): string => 'production');

    (new UserSeeder)->run();

    expect(User::query()->where('email', 'admin@medibook.test')->exists())->toBeTrue();
});

it('schedules the nightly reset at 03:00 Manila time', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'migrate:fresh'));

    expect($event)->not->toBeNull()
        ->and($event->command)->toContain('--seed')->toContain('--force')
        ->and($event->expression)->toBe('0 3 * * *')
        ->and($event->timezone)->toBe('Asia/Manila');
});

it('runs the nightly reset only in demo mode', function (bool $demo) {
    config()->set('medibook.demo.enabled', $demo);

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'migrate:fresh'));

    expect($event->filtersPass(app()))->toBe($demo);
})->with([true, false]);

it('lets migrate:fresh run in production on the demo server only', function (bool $demo, bool $prohibited) {
    config()->set('medibook.demo.enabled', $demo);
    app()->detectEnvironment(fn (): string => 'production');

    try {
        app()->getProvider(AppServiceProvider::class)->boot();

        expect((new ReflectionProperty(FreshCommand::class, 'prohibitedFromRunning'))->getValue())->toBe($prohibited);
    } finally {
        // The flag is static, so put it back for the tests that follow.
        DB::prohibitDestructiveCommands(false);
    }
})->with([
    'demo server' => [true, false],
    'real production' => [false, true],
]);
