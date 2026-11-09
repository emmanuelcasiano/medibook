<?php

declare(strict_types=1);

namespace App\Http\Resources\Booking;

use App\Models\FollowUp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One follow-up on "My appointments", as the page receives it. The caller must load `service` and `doctor.user` first
 * (strict mode throws on a lazy load). Call `->resolve()` to get the plain array for an Inertia prop.
 *
 * `note` is the doctor's message to this patient and may hold health detail. It is sent here because the patient is
 * who it was written for, and the caller scopes the list to that patient. It must never be added to a staff resource
 * or an email (PROJECT.md section 5). The doctor is `null` when any doctor will do, so the page says "Any doctor"
 * and does not name the asking doctor as the only choice.
 *
 * @mixin FollowUp
 */
final class MyFollowUpResource extends JsonResource
{
    /**
     * @return array{
     *     ulid: string,
     *     service: array{name: string, durationMinutes: int},
     *     doctor: array{name: string, specialty: string}|null,
     *     windowStartsOn: string,
     *     windowEndsOn: string,
     *     note: string|null,
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'service' => [
                'name' => $this->service->name,
                'durationMinutes' => $this->service->duration_minutes,
            ],
            'doctor' => $this->same_doctor
                ? ['name' => $this->doctor->user->name, 'specialty' => $this->doctor->specialty]
                : null,
            // Clinic days with no time part, as 'Y-m-d'. The page formats them as dates, not as instants.
            'windowStartsOn' => $this->window_starts_on->toDateString(),
            'windowEndsOn' => $this->window_ends_on->toDateString(),
            'note' => $this->note,
        ];
    }
}
