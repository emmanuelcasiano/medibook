<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class BookAnyDoctor
{
    public function __construct(
        private readonly FindAvailableSlots $findSlots,
        private readonly BookAppointment $book,
    ) {}

    /**
     * Book $service at $startsAt with the first doctor who is free then ("Any doctor"). The browser never says
     * which doctor: the slot list holds start times only, so the choice is made here, on the server.
     *
     * Doctors are tried in the order of their id. A doctor who looked free a moment ago may have been booked since,
     * so a "time taken" answer from BookAppointment (which checks inside its row lock) moves on to the next one.
     * Any other error (patient limits, a switched-off service) would be the same for every doctor, so it stops here.
     *
     * @throws ValidationException With the "time taken" message when no doctor can take the time, or with the
     *                             error from BookAppointment (for example a patient limit).
     */
    public function handle(User $patient, Service $service, CarbonImmutable $startsAt): Appointment
    {
        $day = $startsAt->setTimezone((string) config('app.clinic_timezone'));

        $candidates = $service->doctors()->bookable()->with('user')->orderBy('doctors.id')->get()
            ->filter(fn ($doctor): bool => $this->findSlots->handle($service, $doctor, $day, $day)
                ->contains(fn (CarbonImmutable $slot): bool => $slot->getTimestamp() === $startsAt->getTimestamp()));

        foreach ($candidates as $doctor) {
            try {
                return $this->book->handle($patient, $doctor, $service, $startsAt);
            } catch (ValidationException $e) {
                if (($e->errors()['starts_at'] ?? null) !== [BookAppointment::TIME_TAKEN_MESSAGE]) {
                    throw $e;
                }
            }
        }

        throw ValidationException::withMessages(['starts_at' => BookAppointment::TIME_TAKEN_MESSAGE]);
    }
}
