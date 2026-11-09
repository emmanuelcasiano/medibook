<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Actions\Booking\BookFollowUp;
use App\Actions\Staff\FindFollowUpBookingOptions;
use App\Actions\Staff\ListFollowUps;
use App\Actions\Staff\LogFollowUpCall;
use App\Actions\Staff\SendFollowUpReminder;
use App\Enums\FollowUpCallOutcome;
use App\Enums\FollowUpState;
use App\Enums\FollowUpStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\BookFollowUpForPatientRequest;
use App\Http\Requests\Staff\LogFollowUpCallRequest;
use App\Http\Requests\Staff\ShowFollowUpsRequest;
use App\Http\Resources\Staff\FollowUpBookingOptionsResource;
use App\Http\Resources\Staff\FollowUpPanelResource;
use App\Http\Resources\Staff\FollowUpRowResource;
use App\Models\FollowUp;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The staff Follow-ups page (PROJECT.md section 9): the list, and the panel's three buttons. Staff only for the
 * buttons: the `staff` route group also lets an admin in to look at the list, so it is the Policy here (and again in
 * each Action) that turns an admin away with a 403.
 *
 * Book takes a time and Log a call takes an outcome, so those have a Form Request. Send reminder takes no input. The
 * follow-up comes from the URL (by ULID) and each Action reads its state from the database under a lock, never from the
 * browser. The rules (still open, window, one reminder a day) are in the Actions. A refusal there comes back as a
 * validation error on `follow_up` or `starts_at`. The doctor's note is never loaded on this page.
 */
final class FollowUpController extends Controller
{
    /** Panel history: the newest events only, so a follow-up chased for months can't make a long page. */
    private const int HISTORY_LIMIT = 20;

    /**
     * The list, the state tiles, and the side panel of one follow-up. The panel opens only for a follow-up that is on
     * the list, so a ULID that is not there (or does not exist) opens nothing and leaks nothing.
     */
    public function index(ShowFollowUpsRequest $request, ListFollowUps $list, FindFollowUpBookingOptions $findOptions): Response
    {
        Gate::authorize('viewStaffList', FollowUp::class);

        $now = CarbonImmutable::now();
        $followUps = $list->handle($now);

        $filter = $request->filter();
        $shown = $filter === null ? $followUps : $followUps->filter(fn (FollowUp $followUp): bool => $followUp->state($now) === $filter);

        // Counts of the whole list, not of the filter, so a tile never changes its own number when it is chosen.
        $byState = $followUps->countBy(fn (FollowUp $followUp): string => $followUp->state($now)->value);

        $selected = $request->selectedUlid() === null ? null : $followUps->firstWhere('ulid', $request->selectedUlid());
        $selected?->load([
            'sourceAppointment:id,starts_at',
            'events' => fn (Relation $events) => $events->with('actor:id,name')->latest('created_at')->latest('id')->limit(self::HISTORY_LIMIT),
        ]);

        $canManage = $selected !== null && Gate::allows('manage', $selected);

        return Inertia::render('staff/follow-ups', [
            'followUps' => $shown->map(fn (FollowUp $followUp): array => (new FollowUpRowResource($followUp))->resolve())->values()->all(),
            'counts' => collect(FollowUpState::cases())->mapWithKeys(fn (FollowUpState $state): array => [$state->value => $byState->get($state->value, 0)])->all(),
            'filter' => $filter?->value,
            'today' => $now->setTimezone((string) config('app.clinic_timezone'))->toDateString(),
            'selected' => $selected === null ? null : (new FollowUpPanelResource($selected, $canManage && $selected->status === FollowUpStatus::Open))->resolve(),
            // Only sent when the panel's Book section asks for it (a partial reload of `book`), so opening a panel
            // costs no slot queries. The Policy is asked again: an admin gets null and no list of times.
            'book' => Inertia::optional(fn (): ?array => $selected !== null && $canManage
                ? (new FollowUpBookingOptionsResource($findOptions->handle($selected, $request->bookDate())))->resolve()
                : null),
        ]);
    }

    /**
     * Book the visit for the patient. The patient, the visit type and the doctor are the follow-up's own; staff pick
     * only the time. A clash, a patient limit or a follow-up that was just booked comes back as a validation error.
     */
    public function book(BookFollowUpForPatientRequest $request, #[CurrentUser] User $user, FollowUp $followUp, BookFollowUp $book): RedirectResponse
    {
        Gate::authorize('manage', $followUp);

        $appointment = $book->handle($user, $followUp, $request->startsAt());

        $followUp->loadMissing('patient:id,name');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Booked :name for :time.', [
            'name' => $followUp->patient->name,
            'time' => $appointment->starts_at->setTimezone((string) config('app.clinic_timezone'))->format('j M Y, g:i A'),
        ])]);

        return $this->backToPanel($followUp);
    }

    public function call(LogFollowUpCallRequest $request, #[CurrentUser] User $user, FollowUp $followUp, LogFollowUpCall $logCall): RedirectResponse
    {
        Gate::authorize('manage', $followUp);

        $event = $logCall->handle($user, $followUp, $request->outcome());

        Inertia::flash('toast', ['type' => 'success', 'message' => $event->outcome === FollowUpCallOutcome::NotNeeded
            ? __('Call logged. The follow-up was closed.')
            : __('Call logged.')]);

        return $this->backToPanel($followUp);
    }

    public function remind(#[CurrentUser] User $user, FollowUp $followUp, SendFollowUpReminder $remind): RedirectResponse
    {
        Gate::authorize('manage', $followUp);

        $remind->handle($user, $followUp);

        $followUp->loadMissing('patient:id,name');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reminder sent to :name.', ['name' => $followUp->patient->firstName()])]);

        return $this->backToPanel($followUp);
    }

    /** The same list with the same panel open, so staff see the new state and history at once. */
    private function backToPanel(FollowUp $followUp): RedirectResponse
    {
        return to_route('staff.follow-ups.index', ['selected' => $followUp->ulid]);
    }
}
