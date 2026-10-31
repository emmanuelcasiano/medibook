<?php

declare(strict_types=1);

namespace App\Http\Resources\Booking;

use App\Actions\Booking\CancelAppointment;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * One row of "My appointments", as the page receives it. The caller must load `service` and `doctor.user` first
 * (strict mode throws on a lazy load). Names only: no doctor user id, email or bio. Call `->resolve()` to get the
 * plain array for an Inertia prop.
 *
 * @mixin Appointment
 */
final class MyAppointmentResource extends JsonResource
{
    /**
     * @return array{
     *     ulid: string,
     *     startsAt: string,
     *     endsAt: string,
     *     cancelUntil: string,
     *     status: string,
     *     statusLabel: string,
     *     cancelReason: string|null,
     *     cancelledByClinic: bool,
     *     service: array{name: string, durationMinutes: int},
     *     doctor: array{name: string, specialty: string},
     *     can: array{cancel: bool},
     * }
     */
    public function toArray(Request $request): array
    {
        $cancelled = $this->status === AppointmentStatus::Cancelled;

        return [
            'ulid' => $this->ulid,
            'startsAt' => $this->starts_at->toIso8601ZuluString(),
            'endsAt' => $this->ends_at->toIso8601ZuluString(),
            'cancelUntil' => $this->starts_at->subHours(CancelAppointment::PATIENT_CANCEL_HOURS)->toIso8601ZuluString(),
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'cancelReason' => $cancelled ? $this->cancel_reason : null,
            // Someone other than the patient cancelled it: staff, in step 7.3.
            'cancelledByClinic' => $cancelled && $this->cancelled_by !== null && $this->cancelled_by !== $this->patient_id,
            'service' => [
                'name' => $this->service->name,
                'durationMinutes' => $this->service->duration_minutes,
            ],
            'doctor' => [
                'name' => $this->doctor->user->name,
                'specialty' => $this->doctor->specialty,
            ],
            // The button is hidden when the Action would refuse. The Policy and the Action still check on submit.
            'can' => [
                'cancel' => Gate::allows('cancel', $this->resource)
                    && $this->status === AppointmentStatus::Booked
                    && $this->starts_at >= CarbonImmutable::now()->addHours(CancelAppointment::PATIENT_CANCEL_HOURS),
            ],
        ];
    }
}
