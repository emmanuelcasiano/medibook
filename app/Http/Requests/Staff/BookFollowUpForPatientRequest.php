<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Actions\Booking\BookAppointment;
use App\Actions\Booking\CheckSlotIsOffered;
use App\Models\Doctor;
use App\Models\FollowUp;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * The "Book for patient" form in the staff panel: the chosen time and nothing else. The patient, the visit type and the
 * doctor all come from the follow-up, so the browser can't swap them. The checks are the ones the patient's own
 * confirm request makes (the window, a bookable doctor, a time the slot finders offer), because BookAppointment does
 * not re-check working hours, time off or the lead time.
 */
final class BookFollowUpForPatientRequest extends FormRequest
{
    /** What the slot lists send: ISO 8601 UTC with a Z, no fractions. */
    private const string STARTS_AT_FORMAT = 'Y-m-d\TH:i:s\Z';

    private ?Doctor $doctor = null;

    private bool $doctorLoaded = false;

    /**
     * Checked here as well as in the controller, so an admin (who passes the `staff` route group to look at the list)
     * gets a 403 and not a validation message that confirms the route exists and what it wants.
     */
    public function authorize(): bool
    {
        return Gate::allows('manage', $this->route('followUp'));
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'string', 'date_format:'.self::STARTS_AT_FORMAT],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'starts_at.required' => 'Pick a time.',
            'starts_at.date_format' => 'Pick a time from the list.',
        ];
    }

    /**
     * The checks that need the database. The browser's word is not enough for any of them.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(CheckSlotIsOffered $isOffered): array
    {
        return [
            function (Validator $validator) use ($isOffered): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $followUp = $this->followUp();

                if (! $followUp->windowIncludes($this->startsAt())) {
                    $validator->errors()->add('starts_at', 'Pick a time between '.$followUp->windowLabel().'.');

                    return;
                }

                if ($followUp->same_doctor && $this->doctor() === null) {
                    $validator->errors()->add('starts_at', 'This doctor is not taking bookings. Please book another way.');

                    return;
                }

                if (! $followUp->service->is_active) {
                    $validator->errors()->add('starts_at', 'This visit type is switched off.');

                    return;
                }

                if (! $isOffered->handle($followUp->service, $this->doctor(), $this->startsAt())) {
                    $validator->errors()->add('starts_at', BookAppointment::TIME_TAKEN_MESSAGE);
                }
            },
        ];
    }

    /** The follow-up from the address, with its service. `loadMissing` because strict mode throws on a lazy load. */
    public function followUp(): FollowUp
    {
        /** @var FollowUp $followUp */
        $followUp = $this->route('followUp');

        return $followUp->loadMissing('service');
    }

    /** The doctor the follow-up names, or null for "Any doctor" or for one who can't be booked now (see FollowUp). */
    public function doctor(): ?Doctor
    {
        if (! $this->doctorLoaded) {
            $this->doctor = $this->followUp()->bookableDoctor();
            $this->doctorLoaded = true;
        }

        return $this->doctor;
    }

    /** The chosen start time as a UTC instant. Call it only after validation passed. */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(self::STARTS_AT_FORMAT, (string) $this->input('starts_at'), 'UTC')
            ?? throw new LogicException('The start time is read before validation passed.');
    }

    /** On a refusal, go back to the same panel with the error, not to the previous page. */
    protected function getRedirectUrl(): string
    {
        return route('staff.follow-ups.index', ['selected' => $this->followUp()->ulid]);
    }
}
