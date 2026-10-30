<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\UpdateDoctor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDoctorRequest;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class DoctorController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Doctor::class);

        $doctors = Doctor::query()
            ->with('user')
            ->withCount('services')
            // The name lives on the user, so sort by it with a subquery instead of loading every doctor to sort in PHP.
            ->orderBy(User::query()->select('name')->whereColumn('users.id', 'doctors.user_id'))
            ->orderBy('id')
            ->paginate(15)
            ->through(fn (Doctor $doctor): array => [
                'id' => $doctor->id,
                'name' => $doctor->user->name,
                'specialty' => $doctor->specialty,
                'servicesCount' => $doctor->services_count,
                'isActive' => $doctor->user->is_active,
                'can' => ['update' => Gate::allows('update', $doctor)],
            ]);

        return Inertia::render('admin/doctors/index', ['doctors' => $doctors]);
    }

    public function edit(Doctor $doctor): Response
    {
        Gate::authorize('update', $doctor);

        $doctor->loadMissing('user');

        // Every service is listed, turned off or not. If the list held only bookable services, saving the form
        // would quietly drop the doctor's link to a service that is switched off for a while.
        $services = Service::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (Service $service): array => [
                'id' => $service->id,
                'name' => $service->name,
                'durationMinutes' => $service->duration_minutes,
                'isActive' => $service->is_active,
            ])
            ->all();

        return Inertia::render('admin/doctors/edit', [
            'doctor' => [
                'id' => $doctor->id,
                'name' => $doctor->user->name,
                'specialty' => $doctor->specialty,
                'bio' => $doctor->bio,
                'serviceIds' => $doctor->services()->pluck('services.id')->map(fn ($id): int => (int) $id)->all(),
            ],
            'services' => $services,
        ]);
    }

    public function update(UpdateDoctorRequest $request, Doctor $doctor, UpdateDoctor $update): RedirectResponse
    {
        Gate::authorize('update', $doctor);

        $doctor->loadMissing('user');

        $data = $request->validated();

        $update->handle(
            $doctor,
            $data['specialty'],
            $data['bio'] ?? null,
            array_values(array_map('intval', $data['service_ids'])),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name has been saved.', ['name' => $doctor->user->name])]);

        return to_route('admin.doctors.index');
    }
}
