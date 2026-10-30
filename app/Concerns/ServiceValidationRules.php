<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Service;
use Illuminate\Validation\Rule;

trait ServiceValidationRules
{
    /**
     * The rules shared by create and update. Every field has a type and an upper bound, so a crafted request
     * can't push a value the `unsignedSmallInteger` columns can't hold, or a minutes value no clinic day can fit.
     *
     * @return array<string, array<mixed>>
     */
    protected function serviceRules(?Service $ignore = null): array
    {
        return [
            // Two services with the same name would look identical in the booking list.
            'name' => ['required', 'string', 'max:255', Rule::unique(Service::class, 'name')->ignore($ignore)],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:480'],
            'buffer_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
