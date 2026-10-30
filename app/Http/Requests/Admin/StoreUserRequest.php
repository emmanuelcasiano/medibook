<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreUserRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            // Patients are not allowed here: they register themselves, with a birth date.
            'role' => ['required', Rule::enum(Role::class)->except(Role::Patient)],
            // The doctors table needs a specialty, so a doctor account has to come with one.
            'specialty' => [
                Rule::requiredIf(fn (): bool => $this->input('role') === Role::Doctor->value),
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }
}
