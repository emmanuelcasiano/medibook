<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VisitNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $appointment_id
 * @property int $doctor_id
 * @property string $body The doctor's private note, encrypted at rest. Never log it.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['appointment_id', 'doctor_id', 'body'])]
class VisitNote extends Model
{
    /** @use HasFactory<VisitNoteFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
