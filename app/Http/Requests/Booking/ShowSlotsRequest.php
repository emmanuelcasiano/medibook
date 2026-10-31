<?php

declare(strict_types=1);

namespace App\Http\Requests\Booking;

use App\Actions\Booking\FindBookableDays;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class ShowSlotsRequest extends FormRequest
{
    private const string DATE_FORMAT = 'Y-m-d';

    /** The slot pages are public: anyone may look. What they can see is limited in the controller. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The day to show, as a clinic date. It is optional: without it the page opens on the first day that has a
     * free time. The service and the doctor are never fields: they come from the URL.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'string', 'date_format:'.self::DATE_FORMAT],
        ];
    }

    /**
     * A day outside the booking window can't have a slot, so it is refused here instead of showing an empty page
     * that looks like "fully booked". The container passes in the Action that knows the window.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(FindBookableDays $bookableDays): array
    {
        return [
            function (Validator $validator) use ($bookableDays): void {
                // A date that failed the format rule can't be parsed (Carbon throws), so it is not compared.
                if ($validator->errors()->has('date')) {
                    return;
                }

                $date = $this->selectedDate();

                if ($date === null) {
                    return;
                }

                [$firstDay, $lastDay] = $bookableDays->window();

                if ($date->lt($firstDay) || $date->gt($lastDay)) {
                    $validator->errors()->add('date', 'Pick a day from '.$firstDay->format('j F').' to '.$lastDay->format('j F').'.');
                }
            },
        ];
    }

    /** The chosen clinic day at 00:00 clinic time, or null when none was sent. Call it only after validation passed. */
    public function selectedDate(): ?CarbonImmutable
    {
        $date = $this->query('date');

        if (! is_string($date) || $date === '') {
            return null;
        }

        return CarbonImmutable::createFromFormat('!'.self::DATE_FORMAT, $date, (string) config('app.clinic_timezone'));
    }

    /**
     * On a bad date, go back to this same page without the query string, with the error. The default is the
     * previous page, which would be wrong for a link that was typed in or bookmarked.
     */
    protected function getRedirectUrl(): string
    {
        return $this->url();
    }
}
