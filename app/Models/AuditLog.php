<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only: a saved row can't be updated or deleted through the model. Rows are written by the AuditLogger.
 * The guard covers model calls only. The query builder skips model events, so no code path may touch this table that way.
 *
 * @property int $id
 * @property int|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $ip_hash
 * @property array<string, mixed>|null $metadata Safe facts only, never health text.
 * @property CarbonImmutable $created_at
 */
#[Fillable(['actor_id', 'action', 'subject_type', 'subject_id', 'ip_hash', 'metadata', 'created_at'])]
// The table has `created_at` only, so Eloquent must not try to write `updated_at`.
#[WithoutTimestamps]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // An audit trail you can edit proves nothing, so fail loudly instead of silently ignoring the call.
        static::updating(static fn (): never => throw new LogicException('Audit log rows cannot be updated.'));
        static::deleting(static fn (): never => throw new LogicException('Audit log rows cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
