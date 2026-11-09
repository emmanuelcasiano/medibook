<?php

declare(strict_types=1);

namespace App\Http\Resources\Staff;

use App\Enums\FollowUpEventType;
use App\Models\FollowUp;
use App\Models\FollowUpEvent;
use Illuminate\Http\Request;

/**
 * The follow-up whose side panel is open: the list row, plus the patient's email, the visit it came from and the
 * history of calls and reminders. The caller must load what the row needs, and also `sourceAppointment` and
 * `events.actor` (strict mode throws on a lazy load). Call `->resolve()` to get the plain array for an Inertia prop.
 *
 * Still no `note`. The history is built from fixed outcomes, so it holds no free text either.
 *
 * @mixin FollowUp
 */
final class FollowUpPanelResource extends FollowUpRowResource
{
    /**
     * @param  bool  $canManage  Whether the signed-in user may use the buttons (staff only, and only while `open`).
     */
    public function __construct(FollowUp $resource, private readonly bool $canManage)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'patientEmail' => $this->patient->email,
            'durationMinutes' => $this->service->duration_minutes,
            'sourceVisitAt' => $this->sourceAppointment->starts_at->toIso8601ZuluString(),
            'canManage' => $this->canManage,
            'history' => $this->events
                ->map(fn (FollowUpEvent $event): array => [
                    'id' => $event->id,
                    'what' => $event->type === FollowUpEventType::Called
                        ? sprintf('Called by %s: %s', $event->actor->name, $event->outcome?->label())
                        : sprintf('Reminder email sent by %s', $event->actor->name),
                    'at' => $event->created_at->toIso8601ZuluString(),
                ])
                ->values()
                ->all(),
        ];
    }
}
