<?php

declare(strict_types=1);

namespace App\Http\Controllers\Booking;

use App\Actions\Booking\FindBookableDays;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\ShowSlotsRequest;
use App\Http\Resources\Booking\BookableDoctorResource;
use App\Http\Resources\Booking\BookableServiceResource;
use App\Models\Doctor;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

final class SlotController extends Controller
{
    /**
     * Step 3 of booking, "Any doctor": the days of the window and the free times of the chosen day, merged
     * over every doctor who offers the service. Public and read-only, so no Policy. The page gets start times
     * only, never which doctor is free: the doctor is picked inside the lock when the booking is made.
     */
    public function any(ShowSlotsRequest $request, Service $service, FindBookableDays $findDays): Response
    {
        abort_unless($service->is_active, 404);

        return $this->render($request, $service, null, $findDays);
    }

    /**
     * Step 3 of booking, one doctor. The route binds `{doctor}` through the service, so a doctor who does not
     * offer it is a 404. A deactivated doctor is a 404 too.
     */
    public function doctor(ShowSlotsRequest $request, Service $service, Doctor $doctor, FindBookableDays $findDays): Response
    {
        $doctor->loadMissing('user');

        abort_unless($service->is_active && $doctor->user->is_active, 404);

        return $this->render($request, $service, $doctor, $findDays);
    }

    private function render(ShowSlotsRequest $request, Service $service, ?Doctor $doctor, FindBookableDays $findDays): Response
    {
        $days = $findDays->handle($service, $doctor);

        // Without a chosen day, open on the first day that has a free time, or on today when nothing is free.
        $selectedDate = $request->selectedDate()?->format('Y-m-d')
            ?? collect($days)->filter()->keys()->first()
            ?? array_key_first($days);

        return Inertia::render('booking/slots', [
            'service' => (new BookableServiceResource($service))->resolve(),
            // The `user` is loaded in doctor(), which is the only way a doctor gets here.
            'doctor' => $doctor === null ? null : (new BookableDoctorResource($doctor))->resolve(),
            // Every day of the window, so the page can draw the whole calendar and grey out the empty days.
            'days' => collect($days)
                ->map(fn (array $slots, string $date): array => ['date' => $date, 'slotsCount' => count($slots)])
                ->values()
                ->all(),
            'selectedDate' => $selectedDate,
            // ISO 8601 UTC. The page shows them in clinic time.
            'slots' => array_map(
                fn (CarbonImmutable $slot): array => ['startsAt' => $slot->toIso8601ZuluString()],
                $days[$selectedDate] ?? [],
            ),
        ]);
    }
}
