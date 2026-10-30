<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\CountBookingsInTimeOff;
use App\Actions\Admin\CreateTimeOff;
use App\Actions\Admin\DeleteTimeOff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTimeOffRequest;
use App\Models\Doctor;
use App\Models\TimeOff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class TimeOffController extends Controller
{
    public function index(Doctor $doctor, CountBookingsInTimeOff $countBookings): Response
    {
        Gate::authorize('update', $doctor);

        $doctor->loadMissing('user');

        // Upcoming and running time off only. Past time off is history and would only make the list longer.
        $timeOffs = $doctor->timeOffs()
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate(10);

        $bookingsInside = $countBookings->handle($doctor, $timeOffs->getCollection());

        return Inertia::render('admin/doctors/time-offs', [
            'doctor' => [
                'id' => $doctor->id,
                'name' => $doctor->user->name,
            ],
            // Times are ISO 8601 UTC. The page shows them in clinic time.
            'timeOffs' => $timeOffs->through(fn (TimeOff $timeOff): array => [
                'id' => $timeOff->id,
                'startsAt' => $timeOff->starts_at->toIso8601ZuluString(),
                'endsAt' => $timeOff->ends_at->toIso8601ZuluString(),
                'reason' => $timeOff->reason,
                'bookingsInside' => $bookingsInside[$timeOff->id] ?? 0,
            ]),
        ]);
    }

    public function store(StoreTimeOffRequest $request, Doctor $doctor, CreateTimeOff $create, CountBookingsInTimeOff $countBookings): RedirectResponse
    {
        Gate::authorize('update', $doctor);

        $doctor->loadMissing('user');

        $data = $request->validated();

        $timeOff = $create->handle($doctor, $request->startsAt(), $request->endsAt(), $data['reason'] ?? null);

        $inside = $countBookings->handle($doctor, collect([$timeOff]))[$timeOff->id] ?? 0;

        // The time off is saved either way. The warning tells the admin that staff still have bookings to move.
        Inertia::flash('toast', $inside > 0
            ? ['type' => 'warning', 'message' => trans_choice(
                'Time off added for :name. :count booking is inside it and needs to be moved or cancelled.|Time off added for :name. :count bookings are inside it and need to be moved or cancelled.',
                $inside,
                ['name' => $doctor->user->name],
            )]
            : ['type' => 'success', 'message' => __('Time off added for :name.', ['name' => $doctor->user->name])]);

        return to_route('admin.doctors.time-offs.index', $doctor);
    }

    public function destroy(Doctor $doctor, TimeOff $timeOff, DeleteTimeOff $delete): RedirectResponse
    {
        Gate::authorize('update', $doctor);

        $doctor->loadMissing('user');

        // The route binds `{timeOff}` through this doctor (scoped), so another doctor's time off is a 404 here.
        $delete->handle($timeOff);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Time off removed for :name.', ['name' => $doctor->user->name])]);

        return to_route('admin.doctors.time-offs.index', $doctor);
    }
}
