<?php

declare(strict_types=1);

namespace App\Http\Resources\Schedule;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * What the side panel needs beyond the schedule itself: which booking is open and what the signed-in user may do
 * with it. The booking's own fields (name, time, doctor, service, status) are already in the `schedule` prop, so
 * they are not sent twice, and that prop stays the same for staff and admin. Call `->resolve()` to get the plain
 * array for an Inertia prop.
 *
 * @mixin Appointment
 */
final class SelectedAppointmentResource extends JsonResource
{
    /**
     * @return array{
     *     ulid: string,
     *     hasStarted: bool,
     *     can: array{checkIn: bool, markNoShow: bool, cancel: bool},
     * }
     */
    public function toArray(Request $request): array
    {
        $booked = $this->status === AppointmentStatus::Booked;

        return [
            'ulid' => $this->ulid,
            // The server's clock decides, not the browser's. The page also re-checks it against the start time.
            'hasStarted' => $this->starts_at <= CarbonImmutable::now(),
            // A button is hidden when the Action would refuse. The Policy and the Action still check on submit,
            // and an admin gets `false` for both because the Policy methods are staff only.
            'can' => [
                'checkIn' => $booked && Gate::allows('checkIn', $this->resource),
                'markNoShow' => $booked && Gate::allows('markNoShow', $this->resource),
                'cancel' => $booked && Gate::allows('cancel', $this->resource),
            ],
        ];
    }
}
