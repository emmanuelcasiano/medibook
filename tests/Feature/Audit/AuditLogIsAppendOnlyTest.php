<?php

declare(strict_types=1);

use App\Models\AuditLog;
use Illuminate\Support\Facades\Route;

it('throws when a saved row is updated', function () {
    $log = AuditLog::factory()->create(['action' => 'appointment.booked']);

    expect(fn () => $log->update(['action' => 'appointment.cancelled']))
        ->toThrow(LogicException::class, 'cannot be updated');

    expect($log->fresh()->action)->toBe('appointment.booked');
});

it('throws when a changed row is saved again', function () {
    $log = AuditLog::factory()->create();
    $log->metadata = ['status_to' => 'completed'];

    expect(fn () => $log->save())->toThrow(LogicException::class);
});

it('throws when a row is deleted', function () {
    $log = AuditLog::factory()->create();

    expect(fn () => $log->delete())->toThrow(LogicException::class, 'cannot be deleted');

    expect(AuditLog::count())->toBe(1);
});

it('has no route that can change or remove a log row', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(
        fn ($route) => str_contains(strtolower($route->uri()), 'audit')
            || str_contains(strtolower($route->getActionName()), 'audit'),
    );

    $writes = $routes->filter(
        fn ($route) => array_diff($route->methods(), ['GET', 'HEAD']) !== [],
    );

    expect($writes->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())->all())->toBe([]);
});
