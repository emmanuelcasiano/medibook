<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Doctor;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class StoreTimeOffRequest extends FormRequest
{
    /** What a `datetime-local` input sends: clinic time, zero-padded, no seconds, no timezone. */
    private const string INPUT_FORMAT = 'Y-m-d\TH:i';

    public function authorize(): bool
    {
        $doctor = $this->route('doctor');

        return $doctor instanceof Doctor && ($this->user()?->can('update', $doctor) ?? false);
    }

    /**
     * The doctor is never a field: it comes from the URL. The rules that compare the two times with each other,
     * and with now, are in `after()`.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'string', 'date_format:'.self::INPUT_FORMAT],
            'ends_at' => ['required', 'string', 'date_format:'.self::INPUT_FORMAT],
            // Matches the `reason` column (a 255-character string).
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'starts_at' => 'start',
            'ends_at' => 'end',
        ];
    }

    /**
     * The end must be after the start, and in the future. A time off that is already over would block nothing
     * and would not show in the upcoming list, so it is refused here instead of saved and lost.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // A time that already failed the format rule can't be compared.
                if ($validator->errors()->hasAny(['starts_at', 'ends_at'])) {
                    return;
                }

                $startsAt = $this->startsAt();
                $endsAt = $this->endsAt();

                if ($endsAt->lte($startsAt)) {
                    $validator->errors()->add('ends_at', 'The end must be after the start.');

                    return;
                }

                if ($endsAt->lte(now())) {
                    $validator->errors()->add('ends_at', 'The end must be in the future.');
                }
            },
        ];
    }

    /** The start as UTC. Call it only after validation passed. */
    public function startsAt(): CarbonImmutable
    {
        return $this->clinicInputToUtc((string) $this->input('starts_at'));
    }

    /** The end as UTC. Call it only after validation passed. */
    public function endsAt(): CarbonImmutable
    {
        return $this->clinicInputToUtc((string) $this->input('ends_at'));
    }

    /** The admin types clinic time. The database keeps UTC, so convert here, at the edge. */
    private function clinicInputToUtc(string $value): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(self::INPUT_FORMAT, $value, config('app.clinic_timezone'))->utc();
    }
}
