<?php

declare(strict_types=1);

namespace App\Http\Controllers\Doctor;

use App\Actions\Doctor\ListTodaysAppointments;
use App\Http\Controllers\Controller;
use App\Http\Resources\Doctor\TodayAppointmentResource;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class TodayController extends Controller
{
    /**
     * The doctor's own appointments for today. The `role:doctor` group closes the route to other roles, and the
     * Action scopes the list to the signed-in doctor. The list is not paginated: one clinic day is a bounded list.
     */
    public function index(Request $request, ListTodaysAppointments $list): Response
    {
        Gate::authorize('viewAny', Appointment::class);

        // One "now" for the whole response: the date in the header and the day of the list can't disagree.
        $today = CarbonImmutable::now((string) config('app.clinic_timezone'));

        return Inertia::render('doctor/today', [
            'date' => $today->format('Y-m-d'),
            'appointments' => $list->handle($request->user(), $today)
                ->map(fn (Appointment $appointment): array => (new TodayAppointmentResource($appointment))->resolve())
                ->values()
                ->all(),
        ]);
    }
}
