<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\IntakeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $appointment_id
 * @property array<string, mixed> $answers Health text, encrypted at rest. Never log it.
 * @property CarbonImmutable $submitted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['appointment_id', 'answers', 'submitted_at'])]
class Intake extends Model
{
    /** @use HasFactory<IntakeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            // Encrypted with APP_KEY. Losing the key means losing this data, and SQL can't search it.
            'answers' => 'encrypted:array',
            'submitted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** @return HasOne<IntakeSummary, $this> */
    public function summary(): HasOne
    {
        return $this->hasOne(IntakeSummary::class);
    }
}
