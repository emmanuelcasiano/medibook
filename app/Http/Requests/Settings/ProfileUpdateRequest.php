<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // `profileRules()` takes null for registration. Here a missing user must fail, not switch to those rules.
        $user = $this->user() ?? throw new LogicException('The profile form is for a signed-in user.');

        return $this->profileRules($user->id);
    }
}
