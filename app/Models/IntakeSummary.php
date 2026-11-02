<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SummaryStatus;
use Carbon\CarbonImmutable;
use Database\Factories\IntakeSummaryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $intake_id
 * @property SummaryStatus $status
 * @property array{chief_complaint: string, key_points: list<string>, allergies_and_meds: string, questions_for_doctor: list<string>}|null $summary AI draft, encrypted at rest.
 * @property string|null $model
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property string|null $error Exception class name only, never a message.
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
// `reviewed_by` and `reviewed_at` are not fillable: only MarkSummaryReviewed sets them, from the logged-in doctor.
#[Fillable(['intake_id', 'status', 'summary', 'model', 'input_tokens', 'output_tokens', 'error'])]
class IntakeSummary extends Model
{
    /** @use HasFactory<IntakeSummaryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => SummaryStatus::class,
            'summary' => 'encrypted:array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    /**
     * A token for "this exact draft". "Mark reviewed" sends it back, so a review can't land on a draft the doctor has
     * not read: an edited intake gives a new row or new text, and the token changes with it. The review columns are
     * not part of it, so reviewing does not change it. Keyed with the app key, so it is not a bare hash of health text.
     */
    public function version(): string
    {
        return hash_hmac('sha256', $this->id.'|'.$this->status->value.'|'.json_encode($this->summary), (string) config('app.key'));
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
