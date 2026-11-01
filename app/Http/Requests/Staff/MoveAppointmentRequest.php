<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Models\Appointment;
use App\Models\Doctor;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * The Move form in the staff schedule's side panel: the doctor and the new start time. The booking comes from the URL
 * and the service is the booking's own, so neither is a field. Whether the time is free is decided by MoveAppointment
 * (the slot finder and BookAppointment's three guards), not here.
 */
final class MoveAppointmentRequest extends FormRequest
{
    /** What the slot lists send: ISO 8601 UTC with a Z, no fractions. */
    private const string STARTS_AT_FORMAT = 'Y-m-d\TH:i:s\Z';

    private ?Doctor $doctor = null;

    /**
     * Checked here as well as in the controller, so an admin (who passes the `staff` route group to look at the
     * schedule) gets a 403 and not a validation message that confirms the route exists and what it wants.
     */
    public function authorize(): bool
    {
        $appointment = $this->route('appointment');

        return $appointment instanceof Appointment && Gate::allows('move', $appointment);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'doctor_id' => ['required', 'integer'],
            'starts_at' => ['required', 'string', 'date_format:'.self::STARTS_AT_FORMAT],
        ];
    }

    /**
     * The doctor must be one the booking's service can go to. The browser's id is looked up through that service, so
     * an unknown doctor, one who does not offer it and one whose account is off all give the same answer.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Appointment $appointment */
                $appointment = $this->route('appointment');

                $this->doctor = Doctor::query()
                    ->bookable()
                    ->whereHas('services', fn ($services) => $services->whereKey($appointment->service_id))
                    ->find($this->integer('doctor_id'));

                if ($this->doctor === null) {
                    $validator->errors()->add('doctor_id', 'Pick a doctor who offers this service.');
                }
            },
        ];
    }

    /** The doctor to move to. Call it only after validation passed. */
    public function doctor(): Doctor
    {
        return $this->doctor ?? throw new LogicException('The doctor is read before validation passed.');
    }

    /** The new start time as a UTC instant. Call it only after validation passed. */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(self::STARTS_AT_FORMAT, (string) $this->input('starts_at'), 'UTC');
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'doctor_id.required' => 'Pick a doctor.',
            'starts_at.required' => 'Pick a new time.',
            'starts_at.date_format' => 'Pick a new time from the list.',
        ];
    }
}
