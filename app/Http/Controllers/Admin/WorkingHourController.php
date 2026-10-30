<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\UpdateWorkingHours;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateWorkingHoursRequest;
use App\Models\Doctor;
use App\Models\WorkingHour;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class WorkingHourController extends Controller
{
    public function edit(Doctor $doctor): Response
    {
        Gate::authorize('update', $doctor);

        $doctor->loadMissing('user');

        return Inertia::render('admin/doctors/working-hours', [
            'doctor' => [
                'id' => $doctor->id,
                'name' => $doctor->user->name,
            ],
            // Times are clinic-local and sent as 'H:i', which is what a time input shows.
            'ranges' => $doctor->workingHours()
                ->orderBy('weekday')
                ->orderBy('starts_at')
                ->get()
                ->map(fn (WorkingHour $range): array => [
                    'weekday' => $range->weekday,
                    'startsAt' => substr($range->starts_at, 0, 5),
                    'endsAt' => substr($range->ends_at, 0, 5),
                ])
                ->all(),
        ]);
    }

    public function update(UpdateWorkingHoursRequest $request, Doctor $doctor, UpdateWorkingHours $update): RedirectResponse
    {
        Gate::authorize('update', $doctor);

        $doctor->loadMissing('user');

        $data = $request->validated();

        $update->handle($doctor, array_values(array_map(fn (array $range): array => [
            'weekday' => (int) $range['weekday'],
            'starts_at' => (string) $range['starts_at'],
            'ends_at' => (string) $range['ends_at'],
        ], $data['ranges'])));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Working hours of :name have been saved.', ['name' => $doctor->user->name])]);

        return to_route('admin.doctors.working-hours.edit', $doctor);
    }
}
