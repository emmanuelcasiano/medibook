<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The cancel form in the staff schedule's side panel. The booking comes from the URL, never from a field. Unlike the
 * patient's form, the reason is required: the clinic needs to know why staff cancelled. The status check and the rest
 * of the rules are in CancelAppointment.
 */
final class CancelAppointmentRequest extends FormRequest
{
    /**
     * Checked here as well as in the controller, so an admin (who passes the `staff` route group to look at the
     * schedule) gets a 403 and not a validation message that confirms the route exists and what it wants.
     */
    public function authorize(): bool
    {
        $appointment = $this->route('appointment');

        return $appointment instanceof Appointment && Gate::allows('cancel', $appointment);
    }

    /**
     * 255 is the width of `appointments.cancel_reason`. The trim and empty-to-null middleware turn a blank reason into
     * null, so `required` also refuses a reason of only spaces.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Please give a reason for cancelling this appointment.',
        ];
    }
}
