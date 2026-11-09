<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Enums\FollowUpCallOutcome;
use App\Models\FollowUp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The "Log a call" dialog: one outcome from a fixed list, and nothing else. There is no free-text field on purpose, so
 * no health detail can be typed into a record that staff can read and that is not encrypted.
 */
final class LogFollowUpCallRequest extends FormRequest
{
    /**
     * Checked here as well as in the controller, so an admin (who passes the `staff` route group to look at the list)
     * gets a 403 and not a validation message that confirms the route exists and what it wants.
     */
    public function authorize(): bool
    {
        $followUp = $this->route('followUp');

        return $followUp instanceof FollowUp && Gate::allows('manage', $followUp);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'outcome' => ['required', 'string', Rule::enum(FollowUpCallOutcome::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'outcome.required' => 'Pick how the call went.',
            'outcome.enum' => 'Pick one of the outcomes in the list.',
        ];
    }

    /** The outcome staff picked. Call it only after validation passed. */
    public function outcome(): FollowUpCallOutcome
    {
        return FollowUpCallOutcome::from((string) $this->validated('outcome'));
    }
}
