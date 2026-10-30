<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\ServiceValidationRules;
use App\Models\Service;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class StoreServiceRequest extends FormRequest
{
    use ServiceValidationRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Service::class) ?? false;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return $this->serviceRules();
    }

    /**
     * Different names can give the same slug ("Check-up" and "Check up"). The slug has a unique index,
     * so catch it here as a normal form error instead of a database exception.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('name')) {
                    return;
                }

                if (Service::query()->where('slug', Str::slug($this->string('name')->toString()))->exists()) {
                    $validator->errors()->add('name', 'A service with a very similar name already exists.');
                }
            },
        ];
    }
}
