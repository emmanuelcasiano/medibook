<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateUserRequest extends FormRequest
{
    use ProfileValidationRules;

    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && ($this->user()?->can('update', $target) ?? false);
    }

    /**
     * Name and email only. `validated()` holds nothing else, so a posted `role`, `is_active` or `password` is dropped.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $target = $this->route('user');

        // The unique rule ignores this user's own row, so saving their current email unchanged passes.
        return $this->profileRules($target instanceof User ? $target->id : null);
    }
}
