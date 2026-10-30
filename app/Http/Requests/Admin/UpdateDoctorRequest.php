<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Doctor;
use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateDoctorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $doctor = $this->route('doctor');

        return $doctor instanceof Doctor && ($this->user()?->can('update', $doctor) ?? false);
    }

    /**
     * Only these three fields can change. The doctor's name, email and account stay on the user screens.
     * `service_ids` must be present (an empty list is allowed), so a request that leaves it out can't wipe the
     * doctor's services by accident.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'specialty' => ['required', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'service_ids' => ['present', 'array', 'max:100'],
            // `exists` stops a forged id from reaching the pivot table. `distinct` rejects a repeated id.
            'service_ids.*' => ['integer', 'distinct', Rule::exists(Service::class, 'id')],
        ];
    }
}
