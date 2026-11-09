<?php

declare(strict_types=1);

namespace App\Http\Resources\Staff;

use App\Enums\FollowUpState;
use App\Models\FollowUp;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * One row of the staff Follow-ups list, as the page receives it. The caller must load `patient`, `doctor.user`,
 * `service` and `bookedAppointment` first (strict mode throws on a lazy load). Call `->resolve()` to get the plain
 * array for an Inertia prop.
 *
 * There is no `note` here, and the list query does not select it: it is the doctor's message to the patient and can
 * hold health detail, so staff never get it (PROJECT.md sections 3 and 5). The state and its words are worked out here,
 * on the server, in clinic days, so the page does no date math of its own.
 *
 * @mixin FollowUp
 */
class FollowUpRowResource extends JsonResource
{
    /**
     * @return array{
     *     ulid: string,
     *     patientName: string,
     *     serviceName: string,
     *     doctorName: string,
     *     sameDoctor: bool,
     *     windowStartsOn: string,
     *     windowEndsOn: string,
     *     state: string,
     *     stateLabel: string,
     * }
     */
    public function toArray(Request $request): array
    {
        $now = CarbonImmutable::now();
        $state = $this->state($now);

        return [
            'ulid' => $this->ulid,
            'patientName' => $this->patient->name,
            'serviceName' => $this->service->name,
            // The doctor who asked, even when any doctor will do: the list says "Asked by", the panel says who it is with.
            'doctorName' => $this->doctor->user->name,
            'sameDoctor' => $this->same_doctor,
            // Clinic days with no time part, as 'Y-m-d'. The page formats them as dates, not as instants.
            'windowStartsOn' => $this->window_starts_on->toDateString(),
            'windowEndsOn' => $this->window_ends_on->toDateString(),
            'state' => $state->value,
            'stateLabel' => $this->stateLabel($state, $now),
        ];
    }

    private function stateLabel(FollowUpState $state, CarbonImmutable $now): string
    {
        $today = $now->setTimezone((string) config('app.clinic_timezone'))->toDateString();

        return match ($state) {
            FollowUpState::Booked => $this->bookedAppointment === null
                ? 'Booked'
                : 'Booked · '.$this->bookedAppointment->starts_at->setTimezone((string) config('app.clinic_timezone'))->format('j M, g:i A'),
            FollowUpState::Dismissed => 'Not needed',
            FollowUpState::Waiting => 'Waiting · opens in '.self::days($this->daysBetween($today, $this->window_starts_on->toDateString())),
            FollowUpState::OpenNow => 'Open now · '.self::days($this->daysBetween($today, $this->window_ends_on->toDateString())).' left',
            FollowUpState::NeedsCall => match (true) {
                $this->window_ends_on->toDateString() < $today => 'Not booked · ended '.self::days($this->daysBetween($this->window_ends_on->toDateString(), $today)).' ago',
                $this->window_ends_on->toDateString() === $today => 'Not booked · ends today',
                default => 'Not booked · ends tomorrow',
            },
        };
    }

    /** Whole days from one 'Y-m-d' day to another. Both are read as UTC midnights, so no timezone shifts a day. */
    private function daysBetween(string $from, string $to): int
    {
        return (int) CarbonImmutable::parse($from, 'UTC')->startOfDay()->diffInDays(CarbonImmutable::parse($to, 'UTC')->startOfDay(), false);
    }

    private static function days(int $count): string
    {
        return $count.' '.Str::plural('day', $count);
    }
}
