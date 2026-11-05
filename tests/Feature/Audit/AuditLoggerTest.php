<?php

declare(strict_types=1);

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;

function fromIp(?string $ip): void
{
    app()->instance('request', Request::create('/', 'GET', server: ['REMOTE_ADDR' => $ip]));
}

it('writes one row with the actor, subject, safe metadata and a hashed IP', function () {
    $actor = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();
    fromIp('203.0.113.7');

    $log = app(AuditLogger::class)->record($actor, 'appointment.cancelled', $appointment, [
        'status_from' => 'booked',
        'status_to' => 'cancelled',
    ]);

    expect(AuditLog::count())->toBe(1)
        ->and($log->fresh())
        ->actor_id->toBe($actor->id)
        ->action->toBe('appointment.cancelled')
        ->subject_type->toBe(Appointment::class)
        ->subject_id->toBe($appointment->id)
        // MySQL JSON columns don't keep key order, so compare without order.
        ->metadata->toEqual(['status_from' => 'booked', 'status_to' => 'cancelled'])
        ->ip_hash->toBe(hash_hmac('sha256', '203.0.113.7', config('app.key')))
        ->created_at->not->toBeNull();
});

it('never stores the real IP address', function () {
    fromIp('203.0.113.7');

    $log = app(AuditLogger::class)->record(null, 'auth.login_failed');

    expect($log->ip_hash)->not->toContain('203.0.113.7')->toHaveLength(64);
});

it('hashes the same IP to the same value', function () {
    fromIp('203.0.113.7');
    $first = app(AuditLogger::class)->record(null, 'a.one');
    $second = app(AuditLogger::class)->record(null, 'a.two');

    expect($first->ip_hash)->toBe($second->ip_hash);
});

it('allows a null actor, subject and IP for system events', function () {
    fromIp(null);

    $log = app(AuditLogger::class)->record(null, 'reminder.sent');

    expect($log->fresh())
        ->actor_id->toBeNull()
        ->subject_type->toBeNull()
        ->subject_id->toBeNull()
        ->ip_hash->toBeNull()
        ->metadata->toBeNull();
});

it('rejects a subject that is not saved and writes nothing', function () {
    $unsaved = Appointment::factory()->make();

    expect(fn () => app(AuditLogger::class)->record(null, 'appointment.booked', $unsaved))
        ->toThrow(InvalidArgumentException::class);

    expect(AuditLog::count())->toBe(0);
});
