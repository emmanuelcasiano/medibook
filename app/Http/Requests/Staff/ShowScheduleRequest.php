<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class ShowScheduleRequest extends FormRequest
{
    private const string DATE_FORMAT = 'Y-m-d';

    /** Who may open the schedule is decided by the `role:` group and the Policy in the controller. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The day to show, as a clinic date (optional: without it the page opens on today), and the booking whose panel
     * is open (optional).
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'string', 'date_format:'.self::DATE_FORMAT],
            // The booking whose side panel is open. A well-formed ULID that is not on the day just opens no panel.
            'appointment' => ['nullable', 'string', 'ulid'],
        ];
    }

    /** The ULID of the booking whose panel is open, or null. Call it only after validation passed. */
    public function selectedUlid(): ?string
    {
        $ulid = $this->query('appointment');

        return is_string($ulid) && $ulid !== '' ? $ulid : null;
    }

    /** The chosen clinic day at 00:00 clinic time, or today when none was sent. Call it only after validation passed. */
    public function day(): CarbonImmutable
    {
        $timezone = (string) config('app.clinic_timezone');
        $date = $this->query('date');

        if (! is_string($date) || $date === '') {
            return CarbonImmutable::now($timezone)->startOfDay();
        }

        return CarbonImmutable::createFromFormat('!'.self::DATE_FORMAT, $date, $timezone);
    }
}
