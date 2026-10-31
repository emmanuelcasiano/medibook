<?php

declare(strict_types=1);

namespace App\Http\Requests\Booking;

use App\Actions\Booking\BookAppointment;
use App\Actions\Booking\CheckSlotIsOffered;
use App\Models\Doctor;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The chosen time for the confirm page (GET, in the query string) and for the booking itself (POST, in the body).
 * The service comes from the URL. The doctor is optional: no `doctor_id` means "Any doctor".
 */
final class ConfirmBookingRequest extends FormRequest
{
    /** What the slot pages send: ISO 8601 UTC with a Z, no fractions. */
    private const string STARTS_AT_FORMAT = 'Y-m-d\TH:i:s\Z';

    private ?Doctor $doctor = null;

    private bool $doctorLoaded = false;

    /** Who may book is decided by the Policy in the controller and by the `role:patient` group. */
    public function authorize(): bool
    {
        return true;
    }

    /** A switched-off service can't be booked, and its pages are a 404 for everyone (as in the public booking pages). */
    protected function prepareForValidation(): void
    {
        abort_unless($this->service()->is_active, 404);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'string', 'date_format:'.self::STARTS_AT_FORMAT],
            'doctor_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * The two checks that need the database. The browser's word is not enough for either:
     * - the doctor must be bookable and offer this service;
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

                if ($this->filled('doctor_id') && $this->doctor() === null) {
                    $validator->errors()->add('doctor_id', 'Pick a doctor who offers this service.');

                    return;
                }

                if (! $isOffered->handle($this->service(), $this->doctor(), $this->startsAt())) {
                    $validator->errors()->add('starts_at', BookAppointment::TIME_TAKEN_MESSAGE);
                }
            },
        ];
    }

    public function service(): Service
    {
        /** @var Service */
        return $this->route('service');
    }

    /**
     * The chosen doctor, or null for "Any doctor" (and for a doctor who can't be booked for this service: the
     * validation above turns that into an error). The doctor is loaded through the service's own list, so a doctor
     * who does not offer it, or whose account is off, is never returned.
     */
    public function doctor(): ?Doctor
    {
        if (! $this->doctorLoaded && $this->filled('doctor_id')) {
            $this->doctor = $this->service()->doctors()->bookable()->with('user')->find($this->integer('doctor_id'));
            $this->doctorLoaded = true;
        }

        return $this->doctor;
    }

    /** The chosen start time as a UTC instant. Call it only after validation passed. */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(self::STARTS_AT_FORMAT, (string) $this->input('starts_at'), 'UTC');
    }

    /**
     * On a bad or taken time, go back to the time picker of the same service and doctor, with the error. The
     * default is the previous page, which after a failed booking would be the confirm page of a time that is gone.
     */
    protected function getRedirectUrl(): string
    {
        $service = $this->service();

        if (! $this->filled('doctor_id')) {
            return route('booking.slots.any', $service);
        }

        $doctor = $this->doctor();

        return $doctor === null
            ? route('booking.doctors', $service)
            : route('booking.slots.doctor', [$service, $doctor]);
    }
}
