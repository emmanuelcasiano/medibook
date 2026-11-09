<?php

declare(strict_types=1);

namespace App\Http\Controllers\Patient;

use App\Actions\Booking\BookFollowUp;
use App\Actions\Booking\CancelAppointment;
use App\Actions\Booking\FindFollowUpDays;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\ConfirmFollowUpBookingRequest;
use App\Http\Requests\Booking\ShowSlotsRequest;
use App\Http\Resources\Booking\BookableDoctorResource;
use App\Http\Resources\Booking\BookableServiceResource;
use App\Models\FollowUp;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Booking from a follow-up the doctor asked for (PROJECT.md section 9). It is the same two steps as the public flow,
 * pick a time and confirm, on the same pages. The service and the doctor come from the follow-up and the days are
 * the days of its window, so there is no step for choosing either.
 */
final class FollowUpBookingController extends Controller
{
    /**
     * Pick a day and a time. `{followUp}` is a ULID and the Policy lets only its patient in, while it is `open`.
     * A visit type switched off is a 404. A doctor who can't take bookings any more sends the patient back to the
     * list with a note, because there is nothing to pick from.
     */
    public function slots(ShowSlotsRequest $request, FollowUp $followUp, FindFollowUpDays $findDays): Response|RedirectResponse
    {
        Gate::authorize('book', $followUp);

        $followUp->loadMissing('service');

        abort_unless($followUp->service->is_active, 404);

        $doctor = $followUp->bookableDoctor();

        if ($followUp->same_doctor && $doctor === null) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('That doctor is not taking bookings now. Please call the clinic to book your follow-up.')]);

            return to_route('my.home');
        }

        $days = $findDays->handle($followUp, $doctor);

        // The asked-for day only counts when it is a day of the window. Otherwise open on the first day with a free
        // time, or on the first day of the window when nothing is free.
        $selectedDate = $request->selectedDate()?->format('Y-m-d');

        if ($selectedDate === null || ! array_key_exists($selectedDate, $days)) {
            $selectedDate = collect($days)->filter()->keys()->first()
                ?? array_key_first($days)
                ?? $followUp->window_starts_on->toDateString();
        }

        return Inertia::render('booking/slots', [
            'service' => (new BookableServiceResource($followUp->service))->resolve(),
            'doctor' => $doctor === null ? null : (new BookableDoctorResource($doctor))->resolve(),
            'days' => collect($days)
                ->map(fn (array $slots, string $date): array => ['date' => $date, 'slotsCount' => count($slots)])
                ->values()
                ->all(),
            'selectedDate' => $selectedDate,
            'slots' => array_map(
                fn (CarbonImmutable $slot): array => ['startsAt' => $slot->toIso8601ZuluString()],
                $days[$selectedDate] ?? [],
            ),
            // Tells the page to link to this flow and not to the public one. Dates only: the note stays on the list.
            'followUp' => [
                'ulid' => $followUp->ulid,
                'windowStartsOn' => $followUp->window_starts_on->toDateString(),
                'windowEndsOn' => $followUp->window_ends_on->toDateString(),
            ],
        ]);
    }

    /**
     * Check and confirm. The Form Request has already checked the owner, the window and the slot finders, so the
     * time shown here is one that can be booked. Nothing is held for the patient: it is checked again on confirm.
     */
    public function confirm(ConfirmFollowUpBookingRequest $request, FollowUp $followUp): Response
    {
        Gate::authorize('book', $followUp);

        $doctor = $request->doctor();
        $startsAt = $request->startsAt();
        $service = $followUp->service;

        return Inertia::render('booking/confirm', [
            'service' => (new BookableServiceResource($service))->resolve(),
            // null means "Any doctor": the doctor is picked when the booking is made.
            'doctor' => $doctor === null ? null : (new BookableDoctorResource($doctor))->resolve(),
            'startsAt' => $startsAt->toIso8601ZuluString(),
            'endsAt' => $startsAt->addMinutes($service->duration_minutes)->toIso8601ZuluString(),
            'cancelUntil' => $startsAt->subHours(CancelAppointment::PATIENT_CANCEL_HOURS)->toIso8601ZuluString(),
            'followUp' => ['ulid' => $followUp->ulid],
        ]);
    }

    /**
     * Book the visit. The patient is the signed-in user and must own the follow-up; the service and doctor are the
     * follow-up's own. A clash, a patient limit or a follow-up that was just booked comes back as a validation error.
     */
    public function store(ConfirmFollowUpBookingRequest $request, #[CurrentUser] User $patient, FollowUp $followUp, BookFollowUp $book): RedirectResponse
    {
        Gate::authorize('book', $followUp);

        $appointment = $book->handle($patient, $followUp, $request->startsAt());

        return to_route('my.appointments.confirmation', $appointment);
    }
}
