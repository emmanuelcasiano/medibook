<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The walk-in dialog asking what it can offer: the visit type, then a doctor id or "any". Both are optional, because
 * the dialog asks first with nothing picked (to get the visit types). An id that is not in the lists just gives no
 * doctors or times and says nothing about why.
 */
final class ShowWalkInOptionsRequest extends FormRequest
{
    /** Same reason as ShowWalkInPatientsRequest: an admin gets a plain 403. */
    public function authorize(): bool
    {
        return Gate::allows('bookWalkIn', Appointment::class);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'service' => ['nullable', 'integer', 'min:1'],
            'doctor' => ['nullable', 'string', 'regex:/^(any|[0-9]{1,10})$/'],
        ];
    }

    /** The picked visit type, or null. Call it only after validation passed. */
    public function serviceId(): ?int
    {
        return $this->filled('service') ? $this->integer('service') : null;
    }

    /** The picked doctor, or null for "Any doctor" and for nothing picked. Call it only after validation passed. */
    public function doctorId(): ?int
    {
        $doctor = $this->query('doctor');

        return is_string($doctor) && ctype_digit($doctor) ? (int) $doctor : null;
    }

    /** True when staff picked "Any doctor". */
    public function anyDoctor(): bool
    {
        return $this->query('doctor') === 'any';
    }
}
