<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\CreateService;
use App\Actions\Admin\DeleteService;
use App\Actions\Admin\UpdateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServiceRequest;
use App\Http\Requests\Admin\UpdateServiceRequest;
use App\Models\Doctor;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class ServiceController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Service::class);

        $services = Service::query()
            ->with('doctors.user')
            ->withCount('appointments')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->through(fn (Service $service): array => [
                'id' => $service->id,
                'name' => $service->name,
                'description' => $service->description,
                'durationMinutes' => $service->duration_minutes,
                'bufferMinutes' => $service->buffer_minutes,
                'isActive' => $service->is_active,
                'doctorNames' => $service->doctors
                    ->map(fn (Doctor $doctor): string => $doctor->user->name)
                    ->sort()
                    ->values()
                    ->all(),
                'appointmentsCount' => $service->appointments_count,
                // The button is hidden when the Action would refuse. The Policy and the Action still check on submit.
                'can' => [
                    'update' => Gate::allows('update', $service),
                    'delete' => Gate::allows('delete', $service) && $service->appointments_count === 0,
                ],
            ]);

        return Inertia::render('admin/services/index', ['services' => $services]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Service::class);

        return Inertia::render('admin/services/create');
    }

    public function store(StoreServiceRequest $request, CreateService $create): RedirectResponse
    {
        Gate::authorize('create', Service::class);

        $data = $request->validated();

        $service = $create->handle(
            $data['name'],
            $data['description'] ?? null,
            (int) $data['duration_minutes'],
            (int) $data['buffer_minutes'],
            (bool) $data['is_active'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name has been added.', ['name' => $service->name])]);

        return to_route('admin.services.index');
    }

    public function edit(Service $service): Response
    {
        Gate::authorize('update', $service);

        return Inertia::render('admin/services/edit', [
            'service' => [
                'id' => $service->id,
                'name' => $service->name,
                'description' => $service->description,
                'durationMinutes' => $service->duration_minutes,
                'bufferMinutes' => $service->buffer_minutes,
                'isActive' => $service->is_active,
            ],
        ]);
    }

    public function update(UpdateServiceRequest $request, Service $service, UpdateService $update): RedirectResponse
    {
        Gate::authorize('update', $service);

        $data = $request->validated();

        $update->handle(
            $service,
            $data['name'],
            $data['description'] ?? null,
            (int) $data['duration_minutes'],
            (int) $data['buffer_minutes'],
            (bool) $data['is_active'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name has been saved.', ['name' => $service->name])]);

        return to_route('admin.services.index');
    }

    public function destroy(Service $service, DeleteService $delete): RedirectResponse
    {
        Gate::authorize('delete', $service);

        $delete->handle($service);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name has been deleted.', ['name' => $service->name])]);

        return to_route('admin.services.index');
    }
}
