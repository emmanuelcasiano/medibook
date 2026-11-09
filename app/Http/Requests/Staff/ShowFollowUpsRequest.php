<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Enums\FollowUpState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ShowFollowUpsRequest extends FormRequest
{
    private const string DATE_FORMAT = 'Y-m-d';

    /** Who may open the list is decided by the `role:` group and the Policy in the controller. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The state tile that filters the list, the follow-up whose panel is open, and the day the Book picker looks at.
     * All are optional. A well-formed ULID that is not on the list just opens no panel.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['nullable', 'string', Rule::enum(FollowUpState::class)],
            'selected' => ['nullable', 'string', 'ulid'],
            // Only read when the panel's Book section asks for its own prop. An unknown day shows the first free one.
            'book_date' => ['nullable', 'string', 'date_format:'.self::DATE_FORMAT],
        ];
    }

    public function filter(): ?FollowUpState
    {
        return $this->filled('filter') ? FollowUpState::from((string) $this->query('filter')) : null;
    }

    /** The ULID of the follow-up whose panel is open, or null. Call it only after validation passed. */
    public function selectedUlid(): ?string
    {
        return $this->filled('selected') ? (string) $this->query('selected') : null;
    }

    /** The clinic day ('Y-m-d') the Book picker asked for, or null. Call it only after validation passed. */
    public function bookDate(): ?string
    {
        return $this->filled('book_date') ? (string) $this->query('book_date') : null;
    }
}
