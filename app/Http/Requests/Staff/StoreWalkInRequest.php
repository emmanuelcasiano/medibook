<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Actions\Staff\NewPatient;
use App\Concerns\ProfileValidationRules;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * The walk-in dialog: who (an existing patient, or the three facts for a new one), which visit type, which doctor (an id
 * or "any"), the start time and whether to check in. No health text is asked for. Whether the time is free, today and
 * offered from now is decided by BookWalkIn (the slot finders and BookAppointment's three guards), not here, so it has
 * one home.
 */
final class StoreWalkInRequest extends FormRequest
{
    use ProfileValidationRules;

    /** What the slot lists send: ISO 8601 UTC with a Z, no fractions. */
    private const string STARTS_AT_FORMAT = 'Y-m-d\TH:i:s\Z';

    private ?Service $service = null;

    private ?Doctor $doctor = null;

    /** Checked here as well as in the controller, so an admin gets a 403 and not a validation message. */
    public function authorize(): bool
    {
        return Gate::allows('bookWalkIn', Appointment::class);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            // Exactly one of the two: an account from the search, or the facts for a new one.
            'patient_id' => [
                'nullable',
                'required_without:new_patient',
                'prohibits:new_patient',
                'integer',
                Rule::exists(User::class, 'id')->where('role', Role::Patient->value)->where('is_active', true),
            ],
            'new_patient' => ['nullable', 'required_without:patient_id', 'array'],
            // `exclude_without` skips these when there is no `new_patient`, so an existing patient is not asked for a name.
            'new_patient.name' => ['exclude_without:new_patient', ...$this->nameRules()],
            // The column is named: `Rule::unique(User::class)` in the shared rules guesses it from the field name, and
            // `new_patient.email` is not a column.
            'new_patient.email' => ['exclude_without:new_patient', 'required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            // The same rule as registration: a real past date. Only the doctor sees the age it gives.
            'new_patient.birth_date' => ['exclude_without:new_patient', 'required', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'service_id' => ['required', 'integer', 'min:1'],
            'doctor' => ['required', 'string', 'regex:/^(any|[0-9]{1,10})$/'],
            'starts_at' => ['required', 'string', 'date_format:'.self::STARTS_AT_FORMAT],
            'check_in' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'patient_id.required_without' => 'Pick a patient, or add a new one.',
            'patient_id.prohibits' => 'Pick a patient, or add a new one, not both.',
            'patient_id.exists' => 'Pick a patient from the list.',
            'new_patient.required_without' => 'Pick a patient, or add a new one.',
            'new_patient.name.required' => 'Enter the patient\'s name.',
            'new_patient.email.required' => 'Enter the patient\'s email address.',
            'new_patient.email.unique' => 'This email address already has an account. Search for the patient instead.',
            'new_patient.birth_date.required' => 'Enter the patient\'s birth date.',
            'new_patient.birth_date.date_format' => 'Enter a valid birth date.',
            'new_patient.birth_date.before' => 'The birth date must be in the past.',
            'new_patient.birth_date.after' => 'Enter a valid birth date.',
            'service_id.required' => 'Pick a visit type.',
            'doctor.required' => 'Pick a doctor, or Any doctor.',
            'doctor.regex' => 'Pick a doctor from the list.',
            'starts_at.required' => 'Pick a time.',
            'starts_at.date_format' => 'Pick a time from the list.',
        ];
    }

    /**
     * The visit type and the doctor must be ones the dialog could have offered. Looked up the way the dialog's lists
     * are (a bookable service, a bookable doctor who offers it), so an unknown, switched-off or wrong-pair id all give
     * the same answer.
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

                $this->service = Service::query()->bookable()->find($this->integer('service_id'));

                if ($this->service === null) {
                    $validator->errors()->add('service_id', 'Pick a visit type from the list.');

                    return;
                }

                if ($this->input('doctor') !== 'any') {
                    $this->doctor = $this->service->doctors()->bookable()->whereKey((int) $this->input('doctor'))->first();

                    if ($this->doctor === null) {
                        $validator->errors()->add('doctor', 'Pick a doctor from the list.');
                    }
                }
            },
        ];
    }

    /** The existing patient, or the facts for a new one. Call it only after validation passed. */
    public function patient(): User|NewPatient
    {
        if ($this->filled('patient_id')) {
            return User::query()->findOrFail($this->integer('patient_id'));
        }

        return new NewPatient(
            name: trim((string) $this->validated('new_patient.name')),
            email: trim((string) $this->validated('new_patient.email')),
            birthDate: (string) $this->validated('new_patient.birth_date'),
        );
    }

    /** The visit type. Call it only after validation passed. */
    public function service(): Service
    {
        return $this->service ?? throw new LogicException('The visit type is read before validation passed.');
    }

    /** The doctor, or null for "Any doctor". Call it only after validation passed. */
    public function doctor(): ?Doctor
    {
        return $this->doctor;
    }

    /** The chosen start time as a UTC instant. Call it only after validation passed. */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(self::STARTS_AT_FORMAT, (string) $this->input('starts_at'), 'UTC')
            ?? throw new LogicException('The start time is read before validation passed.');
    }

    public function checkIn(): bool
    {
        return $this->boolean('check_in');
    }
}
