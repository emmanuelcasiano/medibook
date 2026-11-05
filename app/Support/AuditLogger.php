<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class AuditLogger
{
    /**
     * Write one audit row. Call it on purpose from Actions and controllers, because model observers can't see reads.
     *
     * `$meta` holds safe facts only (for example status from and to). Never put health text in it.
     * A null actor means a guest or the system (a queue job, a command).
     *
     * @param  array<string, mixed>  $meta
     */
    public function record(?User $actor, string $action, ?Model $subject = null, array $meta = []): AuditLog
    {
        // An unsaved subject has no id yet, so the row would point at nothing.
        if ($subject !== null && ! $subject->exists) {
            throw new InvalidArgumentException('The audit subject must be saved before it is logged.');
        }

        $ip = request()->ip();

        return AuditLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject === null ? null : (int) $subject->getKey(),
            // An HMAC shows "the same address twice" without storing the address, and can't be reversed without the app key.
            'ip_hash' => $ip === null ? null : hash_hmac('sha256', $ip, (string) config('app.key')),
            'metadata' => $meta === [] ? null : $meta,
            'created_at' => now(),
        ]);
    }
}
