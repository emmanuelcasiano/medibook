<?php

declare(strict_types=1);

namespace App\Http\Requests\Doctor;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The "Mark reviewed" button on the doctor's appointment page. The appointment comes from the URL and the reviewer and
 * the time come from the server. The only field is the token of the draft the doctor read (IntakeSummary::version()).
 * Whether it still matches is checked in MarkSummaryReviewed, under the row lock.
 */
final class ReviewSummaryRequest extends FormRequest
{
    /**
     * Checked here as well as in the controller, so a user who may not open the appointment gets a 403 and not a
     * validation message that confirms the route exists and what it wants.
     */
    public function authorize(): bool
    {
        $appointment = $this->route('appointment');

        return $appointment instanceof Appointment && Gate::allows('view', $appointment);
    }

    /**
     * 64 is the length of the token (a SHA-256 hex string). The cap only keeps a long junk value out.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'max:64'],
        ];
    }
}
