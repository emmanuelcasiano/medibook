<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FollowUpCallOutcome;
use App\Enums\FollowUpEventType;
use Carbon\CarbonImmutable;
use Database\Factories\FollowUpEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One thing staff did about a follow-up: a call they logged or a reminder they sent. It feeds the timeline in the
 * staff panel. Append-only like the audit log, and it holds no free text, so it is safe for staff to read.
 *
 * @property int $id
 * @property int $follow_up_id
 * @property int $actor_id
 * @property FollowUpEventType $type
 * @property FollowUpCallOutcome|null $outcome Set for a call, null for a reminder.
 * @property CarbonImmutable $created_at
 * @property-read FollowUp $followUp
 * @property-read User $actor
 */
#[Fillable(['follow_up_id', 'actor_id', 'type', 'outcome', 'created_at'])]
// The table has `created_at` only, so Eloquent must not try to write `updated_at`.
#[WithoutTimestamps]
class FollowUpEvent extends Model
{
    /** @use HasFactory<FollowUpEventFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // A history you can edit proves nothing, so fail loudly instead of silently ignoring the call.
        static::updating(static fn (): never => throw new LogicException('Follow-up events cannot be updated.'));
        static::deleting(static fn (): never => throw new LogicException('Follow-up events cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'type' => FollowUpEventType::class,
            'outcome' => FollowUpCallOutcome::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<FollowUp, $this> */
    public function followUp(): BelongsTo
    {
        return $this->belongsTo(FollowUp::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
