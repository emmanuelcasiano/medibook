<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The patient search in the walk-in dialog: one term. It is sent in the body of a POST, not in the address, so a
 * patient's name or email never lands in the browser history, a Referer header or an access log.
 */
final class ShowWalkInPatientsRequest extends FormRequest
{
    /**
     * Checked here as well as in the controller, so an admin (who passes the `staff` route group to look at the
     * schedule) gets a 403 and not a validation message that confirms what the route wants.
     */
    public function authorize(): bool
    {
        return Gate::allows('bookWalkIn', Appointment::class);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            // Two characters at least, so "a" can't list half the patient base. The Action caps the answer at 8 rows.
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }

    /** The term, trimmed. Call it only after validation passed. */
    public function term(): string
    {
        return trim((string) $this->validated('q'));
    }
}
