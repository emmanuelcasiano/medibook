<?php

declare(strict_types=1);

namespace App\Http\Requests\Booking;

use App\Actions\Booking\BookAppointment;
use App\Actions\Booking\CheckSlotIsOffered;
use App\Models\Doctor;
use App\Models\FollowUp;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * The chosen time for the confirm page of a follow-up (GET, in the query string) and for the booking itself (POST).
 * Only the time is a field. The service and the doctor come from the follow-up, so the browser can't swap them.
 */
final class ConfirmFollowUpBookingRequest extends FormRequest
{
    /** What the slot pages send: ISO 8601 UTC with a Z, no fractions. */
    private const string STARTS_AT_FORMAT = 'Y-m-d\TH:i:s\Z';

    private ?Doctor $doctor = null;

    private bool $doctorLoaded = false;

    /**
     * Asked here and not only in the controller: a Form Request validates first, and its messages (the window dates)
     * must not reach a patient who is not the owner. The controller asks the Policy again, as every controller does.
     */
    public function authorize(): bool
    {
        $followUp = $this->followUp();

        if (! ($this->user()?->can('book', $followUp) ?? false)) {
            return false;
        }

        // A visit type switched off since the request can't be booked, and its pages are a 404 (as in the public
        // flow). After the Policy, so only the owner can learn it.
        abort_unless($followUp->service->is_active, 404);

        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'string', 'date_format:'.self::STARTS_AT_FORMAT],
        ];
    }

    /**
     * The checks that need the database. The browser's word is not enough for any of them:
     * - the day must be inside the window the doctor gave;
     * - the doctor (when the follow-up names one) must still be bookable for this service;
     * - the time must be one the slot finders offer, because BookAppointment does not re-check working hours,
     *   time off or the lead time.
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
                    $validator->errors()->add('doctor_id', 'This doctor is not taking bookings. Please call the clinic.');

                    return;
                }

                if (! $isOffered->handle($followUp->service, $this->doctor(), $this->startsAt())) {
                    $validator->errors()->add('starts_at', BookAppointment::TIME_TAKEN_MESSAGE);
                }
            },
        ];
    }

    /**
     * The follow-up from the address, with its service. The `{followUp}` binding is by ULID; the Policy decides who
     * may use it. `loadMissing` because strict mode throws on a lazy load.
     */
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

    /**
     * On a bad or taken time, go back to the time picker of this follow-up, with the error. The default is the
     * previous page, which after a failed booking would be the confirm page of a time that is gone.
     */
    protected function getRedirectUrl(): string
    {
        return route('my.follow-ups.book', $this->followUp());
    }
}
