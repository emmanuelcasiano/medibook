<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Actions\Booking\FindFollowUpDays;
use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Models\Service;

final class FindFollowUpBookingOptions
{
    public function __construct(private readonly FindFollowUpDays $findDays) {}

    /**
     * The days and times staff can pick when they book $followUp for the patient. It is the patient's own list
     * (FindFollowUpDays), so every slot rule still applies: working hours, time off, buffers, the lead time and the
     * 30 day window, cut down to the days the doctor asked for.
     *
     * $date is the clinic day staff looked at. It only counts when it is a day of the list. Otherwise the first day
     * with a free time is shown, or the first day of the list when nothing is free. A follow-up that is not `open`,
     * a visit type that is switched off, or a named doctor who can't take bookings gets no days and a reason: there is
     * nothing to pick, and booking would be refused anyway.
     *
     * The caller asks for this only when the panel's Book section is open, so a normal page load pays for none of it.
     * The free times are the ones the public booking pages already show to anyone, so the controller's Policy check
     * is enough here.
     */
    public function handle(FollowUp $followUp, ?string $date): FollowUpBookingOptions
    {
        // The list loads only a few columns of the service. The slot finders need the rest (buffer, `is_active`), so
        // read it whole, like FindMoveOptions does.
        $followUp->setRelation('service', Service::query()->findOrFail($followUp->service_id));

        if ($followUp->status !== FollowUpStatus::Open) {
            return new FollowUpBookingOptions([], null, [], 'This follow-up is not open any more.');
        }

        if (! $followUp->service->is_active) {
            return new FollowUpBookingOptions([], null, [], 'This visit type is switched off.');
        }

        $doctor = $followUp->bookableDoctor();

        if ($followUp->same_doctor && $doctor === null) {
            return new FollowUpBookingOptions([], null, [], 'The doctor is not taking bookings now.');
        }

        $days = $this->findDays->handle($followUp, $doctor);

        $selected = $date !== null && array_key_exists($date, $days)
            ? $date
            : (collect($days)->filter()->keys()->first() ?? array_key_first($days));

        return new FollowUpBookingOptions(
            array_map('count', $days),
            $selected,
            $selected === null ? [] : $days[$selected],
        );
    }
}
