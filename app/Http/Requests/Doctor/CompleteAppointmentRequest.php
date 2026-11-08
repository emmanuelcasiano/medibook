<?php

declare(strict_types=1);

namespace App\Http\Requests\Doctor;

use App\Actions\Doctor\FollowUpData;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The "Finish visit" form on the doctor's appointment page. The visit comes from the URL and the doctor from the
 * server. The only input is the optional `follow_up` group, sent when the doctor asks the patient to come back. No
 * `follow_up` at all means a plain finish, as before.
 *
 * This checks the shape (types, formats, lengths). The rules that depend on the database or on today's date (the
 * service is offered, the window is in range, one follow-up per visit) live in RequestFollowUp, which is also called
 * from places that never see a Form Request.
 */
final class CompleteAppointmentRequest extends FormRequest
{
    /**
     * Checked here as well as in the controller, so a user who may not finish this visit gets a 403 and not a
     * validation message that confirms what the route wants.
     */
    public function authorize(): bool
    {
        $appointment = $this->route('appointment');

        return $appointment instanceof Appointment && Gate::allows('complete', $appointment);
    }

    /**
     * `exclude_without:follow_up` skips every rule below when the group is missing, so a plain finish has nothing to
     * validate. The note is capped at 200 characters, a short message to the patient and not a second visit note.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'follow_up' => ['nullable', 'array'],
            'follow_up.service_id' => ['exclude_without:follow_up', 'required', 'integer'],
            'follow_up.starts_on' => ['exclude_without:follow_up', 'required', 'date_format:Y-m-d'],
            'follow_up.ends_on' => ['exclude_without:follow_up', 'required', 'date_format:Y-m-d', 'after_or_equal:follow_up.starts_on'],
            'follow_up.same_doctor' => ['exclude_without:follow_up', 'required', 'boolean'],
            'follow_up.note' => ['exclude_without:follow_up', 'nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'follow_up.service_id.required' => 'Pick a visit type.',
            'follow_up.service_id.integer' => 'Pick a visit type from the list.',
            'follow_up.starts_on.required' => 'Pick the first day.',
            'follow_up.starts_on.date_format' => 'Pick the first day from the calendar.',
            'follow_up.ends_on.required' => 'Pick the last day.',
            'follow_up.ends_on.date_format' => 'Pick the last day from the calendar.',
            'follow_up.ends_on.after_or_equal' => 'The last day must be on or after the first day.',
            'follow_up.same_doctor.required' => 'Choose who the patient should see.',
            'follow_up.same_doctor.boolean' => 'Choose who the patient should see.',
            'follow_up.note.max' => 'The note can be up to :max characters.',
        ];
    }

    /** The follow-up the doctor asked for, or null for a plain finish. Call it only after validation has passed. */
    public function followUp(): ?FollowUpData
    {
        $data = $this->validated('follow_up');

        // An empty group is a plain finish too: `exclude_without` skipped every field, so there is nothing to read.
        if ($data === null || $data === []) {
            return null;
        }

        $timezone = (string) config('app.clinic_timezone');
        $note = trim((string) ($data['note'] ?? ''));

        return new FollowUpData(
            serviceId: (int) $data['service_id'],
            // `date_format:Y-m-d` has passed, so these are real clinic days. Midnight in the clinic's own time zone.
            startsOn: CarbonImmutable::parse($data['starts_on'], $timezone)->startOfDay(),
            endsOn: CarbonImmutable::parse($data['ends_on'], $timezone)->startOfDay(),
            sameDoctor: (bool) $data['same_doctor'],
            note: $note === '' ? null : $note,
        );
    }
}
