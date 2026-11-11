<?php

declare(strict_types=1);

namespace App\Http\Resources\Doctor;

use App\Enums\SummaryStatus;
use App\Models\Appointment;
use App\Models\IntakeSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the doctor's "Today" list, as the page receives it. The caller must load `patient`, `service` and
 * `intake.summary` first (strict mode throws on a lazy load). First name only: no surname, email or birth date, and
 * no intake or summary text, only whether they exist and the summary status. Vitals are a yes/no from the plain
 * `vitals_taken_at` mark, never a reading, and the vitals row is not loaded. Call `->resolve()` to get the plain
 * array for an Inertia prop.
 *
 * @mixin Appointment
 */
final class TodayAppointmentResource extends JsonResource
{
    /**
     * @return array{
     *     ulid: string,
     *     startsAt: string,
     *     endsAt: string,
     *     status: string,
     *     statusLabel: string,
     *     patientFirstName: string,
     *     serviceName: string,
     *     intakeFilled: bool,
     *     summaryStatus: string|null,
     *     summaryLabel: string,
     *     vitalsTaken: bool,
     * }
     */
    public function toArray(Request $request): array
    {
        $summary = $this->intake?->summary;

        return [
            'ulid' => $this->ulid,
            'startsAt' => $this->starts_at->toIso8601ZuluString(),
            'endsAt' => $this->ends_at->toIso8601ZuluString(),
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'patientFirstName' => $this->patient->firstName(),
            'serviceName' => $this->service->name,
            'intakeFilled' => $this->intake !== null,
            'summaryStatus' => $summary?->status->value,
            'summaryLabel' => $this->summaryLabel($summary),
            'vitalsTaken' => $this->vitals_taken_at !== null,
        ];
    }

    /** The words for the summary column. Written out, so the page never has to show it by color alone. */
    private function summaryLabel(?IntakeSummary $summary): string
    {
        if ($this->intake === null) {
            return 'No intake yet';
        }

        return match ($summary?->status) {
            SummaryStatus::Pending => 'Summary pending',
            SummaryStatus::Ready => $summary->reviewed_at === null ? 'AI draft, not reviewed' : 'Reviewed',
            SummaryStatus::Failed => 'Summary failed',
            SummaryStatus::Skipped, null => 'No AI summary',
        };
    }
}
