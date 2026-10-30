<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\ServiceValidationRules;
use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateServiceRequest extends FormRequest
{
    use ServiceValidationRules;

    public function authorize(): bool
    {
        $service = $this->route('service');

        return $service instanceof Service && ($this->user()?->can('update', $service) ?? false);
    }

    /**
     * The slug is not in the rules: it is set once on create and does not change on a rename,
     * so a saved link to the service keeps working.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $service = $this->route('service');

        return $this->serviceRules($service instanceof Service ? $service : null);
    }
}
