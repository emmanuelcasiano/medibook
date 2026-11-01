<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Actions\Schedule\FindMoveOptions;
use App\Actions\Schedule\GetDaySchedule;
use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ShowScheduleRequest;
use App\Http\Resources\Schedule\DayScheduleResource;
use App\Http\Resources\Schedule\MoveOptionsResource;
use App\Http\Resources\Schedule\SelectedAppointmentResource;
use App\Models\Appointment;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class ScheduleController extends Controller
{
    /**
     * The day grid. Staff and admins open it; the `role:` group and the Policy both refuse everyone else. The day
     * is not paginated: one clinic day is a bounded list, and the grid needs all of it at once.
     */
    public function index(ShowScheduleRequest $request, GetDaySchedule $getDaySchedule, FindMoveOptions $findMoveOptions): Response
    {
        Gate::authorize('viewSchedule', Appointment::class);

        $schedule = $getDaySchedule->handle($request->day());

        // The side panel opens only for a booking that is on the day being shown, so a ULID from another day (or
        // one that does not exist) opens nothing and leaks nothing.
        $selected = $request->selectedUlid() === null ? null : $schedule->find($request->selectedUlid());

        return Inertia::render('staff/schedule', [
            'schedule' => (new DayScheduleResource($schedule))->resolve(),
            'selected' => $selected === null ? null : (new SelectedAppointmentResource($selected))->resolve(),
            // Only sent when the panel's Move section asks for it (a partial reload of `move`), so opening a panel
            // costs no extra query. The Policy and the status are checked again here: an admin, or a booking that is
            // no longer `booked`, gets null and no list of doctors or times.
            'move' => Inertia::optional(fn (): ?array => $selected !== null
                && $selected->status === AppointmentStatus::Booked
                && Gate::allows('move', $selected)
                    ? (new MoveOptionsResource($findMoveOptions->handle($selected, $request->moveDoctorId(), $request->moveDay())))->resolve()
                    : null),
        ]);
    }
}
