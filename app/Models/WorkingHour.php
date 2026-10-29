<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WorkingHourFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One range of working time. A doctor has several rows per weekday (for example a lunch break splits the day).
 *
 * @property int $id
 * @property int $doctor_id
 * @property int $weekday 0 = Sunday ... 6 = Saturday
 * @property string $starts_at Clinic-local 'H:i:s'
 * @property string $ends_at Clinic-local 'H:i:s'
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['doctor_id', 'weekday', 'starts_at', 'ends_at'])]
class WorkingHour extends Model
{
    /** @use HasFactory<WorkingHourFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
        ];
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
