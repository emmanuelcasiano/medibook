<?php

declare(strict_types=1);

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The cancel form of "My appointments". The booking comes from the URL, never from a field. The 2 hour rule and the
 * rest of the rules are in CancelAppointment, so the AI assistant can reuse them later.
 */
final class CancelAppointmentRequest extends FormRequest
{
    /** Who may cancel is decided by the Policy in the controller and again in the Action. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The reason is optional for a patient. 255 is the width of `appointments.cancel_reason`.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
