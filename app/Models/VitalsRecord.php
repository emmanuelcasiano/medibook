<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\VitalsRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $appointment_id
 * @property int $recorded_by
 * @property array{systolic: int, diastolic: int, pulse: int, temperature: float, oxygen_saturation: int, weight: float} $readings Health data, encrypted at rest. Never log it.
 * @property CarbonImmutable $recorded_at When the readings were last saved (a correction moves it).
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Appointment $appointment `appointment_id` is a NOT NULL foreign key.
 * @property-read User $recordedBy `recorded_by` is a NOT NULL foreign key.
 */
#[Fillable(['appointment_id', 'recorded_by', 'readings', 'recorded_at'])]
class VitalsRecord extends Model
{
    /** @use HasFactory<VitalsRecordFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            // Encrypted with APP_KEY. Losing the key means losing this data, and SQL can't search it.
            'readings' => 'encrypted:array',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
