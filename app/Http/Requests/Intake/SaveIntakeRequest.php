<?php

declare(strict_types=1);

namespace App\Http\Requests\Intake;

use App\Enums\IntakeDuration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The intake form (PROJECT.md F5). The appointment comes from the URL, never from a field, and who may save is decided
 * by the Policy in SaveIntake. Every free-text field has a `max:` (security.md section 3).
 */
final class SaveIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The three yes/no answers are required, so a missing one is a visible error and not a silent "no". The form
     * always sends an explicit 1 or 0 for each.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
            'symptoms' => ['nullable', 'string', 'max:2000'],
            'duration' => ['nullable', Rule::enum(IntakeDuration::class)],
            'allergies' => ['nullable', 'string', 'max:500'],
            'medicines' => ['nullable', 'string', 'max:500'],
            'fever' => ['required', 'boolean'],
            'pregnant' => ['required', 'boolean'],
            'recent_surgery' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'reason' => 'reason for the visit',
            'medicines' => 'current medicines',
            'recent_surgery' => 'recent surgery',
        ];
    }

    /**
     * The validated answers in the exact shape `SaveIntake` stores. Built field by field, so nothing else in the
     * request can be saved, and the types are fixed here (an empty text field arrives as null).
     *
     * @return array{reason: string, symptoms: string|null, duration: string|null, allergies: string|null, medicines: string|null, fever: bool, pregnant: bool, recent_surgery: bool}
     */
    public function answers(): array
    {
        $validated = $this->safe();

        return [
            'reason' => $validated->string('reason')->toString(),
            'symptoms' => $this->textOrNull('symptoms'),
            'duration' => $this->textOrNull('duration'),
            'allergies' => $this->textOrNull('allergies'),
            'medicines' => $this->textOrNull('medicines'),
            'fever' => $validated->boolean('fever'),
            'pregnant' => $validated->boolean('pregnant'),
            'recent_surgery' => $validated->boolean('recent_surgery'),
        ];
    }

    private function textOrNull(string $key): ?string
    {
        $value = $this->safe()->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
