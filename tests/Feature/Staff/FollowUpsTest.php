<?php

declare(strict_types=1);

use App\Actions\Booking\BookFollowUp;
use App\Actions\Staff\LogFollowUpCall;
use App\Actions\Staff\SendFollowUpReminder;
use App\Enums\AppointmentStatus;
use App\Enums\FollowUpCallOutcome;
use App\Enums\FollowUpEventType;
use App\Enums\FollowUpState;
use App\Enums\FollowUpStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\FollowUp;
use App\Models\FollowUpEvent;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppointmentBooked;
use App\Notifications\FollowUpReminder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

// The staff Follow-ups page (step L.4): the list and its states, the side panel, and the three buttons (book for the
// patient, log a call, send a reminder). The booking rules (three guards, patient limits) are tested in
// BookAppointmentTest and BookFollowUpTest; here we test who may press each button, what staff are (and are not) told,
// and the rules the new Actions add. The audit events for follow-ups are tested in FollowUpAuditTest (step L.5).
// "Now" is Friday 30 October 2026, 09:00 in Manila. The doctor works Mondays 08:00 to 12:00 and Fridays 08:00 to 17:00,
// and the service is 60 minutes with no buffer. The follow-up window is Monday 2 to Friday 6 November.

const FU_NOTE = 'Your biopsy result was abnormal, please come in.';

/** A clinic time as the slot lists send it: ISO 8601 UTC with a Z. */
function staffFuStartsAt(string $clinicTime = '2026-11-02 08:00'): string
{
    return CarbonImmutable::parse($clinicTime, 'Asia/Manila')->utc()->format('Y-m-d\TH:i:s\Z');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));

    $this->service = Service::factory()->lasting(60)->create();
    $this->doctor = doctorWorking($this->service, [[1, '08:00', '12:00'], [5, '08:00', '17:00']]);
    $this->patient = User::factory()->patient()->create();
    $this->staff = userWithRole(Role::Staff);
    $this->admin = userWithRole(Role::Admin);

    // A follow-up for $patient from a finished visit with the doctor. $state is a FollowUpFactory state
    // (open, booked, dismissed, windowEnded, anyDoctor). $overrides replaces single columns.
    $madeSoFar = 0;
    $this->makeFollowUp = function (string $state = 'open', array $overrides = [], ?User $patient = null) use (&$madeSoFar): FollowUp {
        // Each finished visit starts an hour earlier than the last one, so two of them never share the doctor's slot.
        $source = Appointment::factory()
            ->completed()
            ->for($this->doctor)
            ->for($patient ?? $this->patient, 'patient')
            ->startingIn(hours: -72 - $madeSoFar++)
            ->create();

        return FollowUp::factory()
            ->state([
                'source_appointment_id' => $source->id,
                'service_id' => $this->service->id,
                'window_starts_on' => '2026-11-02',
                'window_ends_on' => '2026-11-06',
                'note' => FU_NOTE,
            ])
            ->{$state}()
            ->create($overrides);
    };
});

describe('the list', function () {
    it('shows staff the follow-ups by state, with the counts of the whole list', function () {
        $waiting = ($this->makeFollowUp)(overrides: ['window_starts_on' => '2026-11-03', 'window_ends_on' => '2026-11-08']);
        $open = ($this->makeFollowUp)(overrides: ['window_starts_on' => '2026-10-28', 'window_ends_on' => '2026-11-10']);
        $call = ($this->makeFollowUp)('windowEnded');
        $booked = ($this->makeFollowUp)('booked');
        $dismissed = ($this->makeFollowUp)('dismissed');

        $this->actingAs($this->staff)
            ->get(route('staff.follow-ups.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/follow-ups')
                ->where('followUps', fn ($rows): bool => collect($rows)->pluck('ulid')->all() === [$call->ulid, $open->ulid, $waiting->ulid, $booked->ulid, $dismissed->ulid])
                ->where('followUps.0.state', 'call')
                ->where('followUps.0.patientName', $this->patient->name)
                ->where('followUps.0.serviceName', $this->service->name)
                ->where('followUps.0.doctorName', $this->doctor->user->name)
                ->where('counts', ['call' => 1, 'open' => 1, 'waiting' => 1, 'booked' => 1, 'dismissed' => 1])
                ->where('filter', null)
                ->where('today', '2026-10-30')
                ->where('selected', null));
    });

    it('never sends the doctors note, in the list or in the panel', function () {
        $followUp = ($this->makeFollowUp)();

        $response = $this->actingAs($this->staff)
            ->get(route('staff.follow-ups.index', ['selected' => $followUp->ulid]))
            ->assertOk();

        expect($response->getContent())->not->toContain('biopsy')->not->toContain('"note"');
    });

    it('filters by state and keeps the counts of the whole list', function () {
        ($this->makeFollowUp)('windowEnded');
        ($this->makeFollowUp)();

        $this->actingAs($this->staff)
            ->get(route('staff.follow-ups.index', ['filter' => 'call']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('followUps', 1)
                ->where('followUps.0.state', 'call')
                ->where('filter', 'call')
                ->where('counts.waiting', 1));
    });

    it('drops a booked or dismissed follow-up 30 days after it last changed', function () {
        $recent = ($this->makeFollowUp)('dismissed');
        $old = ($this->makeFollowUp)('dismissed');
        $old->forceFill(['updated_at' => now()->subDays(31)])->saveQuietly();

        $this->actingAs($this->staff)
            ->get(route('staff.follow-ups.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('followUps', fn ($rows): bool => collect($rows)->pluck('ulid')->all() === [$recent->ulid]));
    });

    it('opens the panel for a follow-up on the list and nothing for one that is not', function () {
        $followUp = ($this->makeFollowUp)();
        $event = FollowUpEvent::factory()->create(['follow_up_id' => $followUp->id, 'actor_id' => $this->staff->id]);

        $this->actingAs($this->staff)
            ->get(route('staff.follow-ups.index', ['selected' => $followUp->ulid]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.ulid', $followUp->ulid)
                ->where('selected.patientEmail', $this->patient->email)
                ->where('selected.canManage', true)
                ->where('selected.history.0.id', $event->id)
                ->where('selected.history.0.what', 'Called by '.$this->staff->name.': No answer'));

        $this->actingAs($this->staff)
            ->get(route('staff.follow-ups.index', ['selected' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('selected', null));
    });

    it('lets an admin look but not act', function () {
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->admin)
            ->get(route('staff.follow-ups.index', ['selected' => $followUp->ulid]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.canManage', false)
                ->missing('book'));
    });

    it('turns every other role away', function (?Role $role) {
        $request = $role === null ? $this : $this->actingAs(userWithRole($role));

        $request->get(route('staff.follow-ups.index'))
            ->assertStatus($role === null ? 302 : 403);
    })->with([
        'guest' => null,
        'patient' => Role::Patient,
        'doctor' => Role::Doctor,
        'nurse' => Role::Nurse,
    ]);

    it('rejects a state that does not exist', function () {
        $this->actingAs($this->staff)
            ->from(route('staff.follow-ups.index'))
            ->get(route('staff.follow-ups.index', ['filter' => 'urgent']))
            ->assertSessionHasErrors('filter');
    });

    it('offers the booking days only when the panel asks, and only to staff', function () {
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->staff)
            ->get(route('staff.follow-ups.index', ['selected' => $followUp->ulid]))
            ->assertInertia(fn (Assert $page) => $page->missing('book'));

        // A partial reload for `book`, with the day asked for. Not an arrow function: it would capture `$book` by
        // value, and the assignment would never reach this scope.
        $book = null;
        $this->actingAs($this->staff)
            ->get(route('staff.follow-ups.index', ['selected' => $followUp->ulid, 'book_date' => '2026-11-06']))
            ->assertInertia(function (Assert $page) use (&$book): void {
                $page->reloadOnly('book', function (Assert $reload) use (&$book): void {
                    $book = $reload->toArray()['props']['book'];
                });
            });

        expect($book['selectedDate'])->toBe('2026-11-06')
            ->and($book['unavailable'])->toBeNull()
            ->and($book['days'][0]['date'])->toBe('2026-11-02')
            ->and($book['slots'][0]['startsAt'])->toBe(staffFuStartsAt('2026-11-06 08:00'));
    });
});

describe('the state', function () {
    it('is worked out in clinic days', function (string $startsOn, string $endsOn, FollowUpState $expected) {
        $followUp = ($this->makeFollowUp)(overrides: ['window_starts_on' => $startsOn, 'window_ends_on' => $endsOn]);

        expect($followUp->state(CarbonImmutable::now()))->toBe($expected);
    })->with([
        'starts tomorrow' => ['2026-10-31', '2026-11-05', FollowUpState::Waiting],
        'started today, a week left' => ['2026-10-30', '2026-11-06', FollowUpState::OpenNow],
        'two days left' => ['2026-10-28', '2026-11-01', FollowUpState::OpenNow],
        'ends tomorrow' => ['2026-10-28', '2026-10-31', FollowUpState::NeedsCall],
        'ends today' => ['2026-10-28', '2026-10-30', FollowUpState::NeedsCall],
        'ended yesterday' => ['2026-10-20', '2026-10-29', FollowUpState::NeedsCall],
    ]);

    it('uses the clinic day, not the UTC day', function () {
        // 07:00 on Saturday 31 October in Manila is still Friday 30 October in UTC. The window ends on Sunday 1 November,
        // so by the clinic's calendar it ends tomorrow (needs a call). By the UTC date it would still have two days.
        $this->travelTo(CarbonImmutable::parse('2026-10-31 07:00', 'Asia/Manila'));
        $followUp = ($this->makeFollowUp)(overrides: ['window_starts_on' => '2026-10-28', 'window_ends_on' => '2026-11-01']);

        expect($followUp->state(CarbonImmutable::now()))->toBe(FollowUpState::NeedsCall);
    });

    it('is Booked or Dismissed whatever the dates say', function () {
        expect(($this->makeFollowUp)('windowEnded', ['status' => FollowUpStatus::Booked])->state(CarbonImmutable::now()))->toBe(FollowUpState::Booked)
            ->and(($this->makeFollowUp)('windowEnded', ['status' => FollowUpStatus::Dismissed])->state(CarbonImmutable::now()))->toBe(FollowUpState::Dismissed);
    });

    it('is counted by the needingCall scope exactly as state() says', function () {
        $all = collect([
            ($this->makeFollowUp)(overrides: ['window_starts_on' => '2026-10-31', 'window_ends_on' => '2026-11-05']),
            ($this->makeFollowUp)(overrides: ['window_starts_on' => '2026-10-28', 'window_ends_on' => '2026-11-06']),
            ($this->makeFollowUp)(overrides: ['window_starts_on' => '2026-10-28', 'window_ends_on' => '2026-10-31']),
            ($this->makeFollowUp)(overrides: ['window_starts_on' => '2026-10-28', 'window_ends_on' => '2026-10-30']),
            ($this->makeFollowUp)('windowEnded'),
            // The window has not started but ends tomorrow: still "Coming up", not "Needs a call".
            ($this->makeFollowUp)(overrides: ['window_starts_on' => '2026-10-31', 'window_ends_on' => '2026-10-31']),
            ($this->makeFollowUp)('booked'),
            ($this->makeFollowUp)('dismissed'),
        ]);

        $now = CarbonImmutable::now();
        $fromState = $all->filter(fn (FollowUp $f): bool => $f->state($now) === FollowUpState::NeedsCall)->pluck('id')->sort()->values()->all();
        $fromScope = FollowUp::query()->needingCall($now)->pluck('id')->sort()->values()->all();

        // Ends tomorrow, ends today, and the one that ended. The two that have not started and the closed ones are out.
        expect($fromScope)->toBe($fromState)->and($fromScope)->toHaveCount(3);
    });

    it('feeds the sidebar badge for staff only', function () {
        ($this->makeFollowUp)('windowEnded');

        $this->actingAs($this->staff)->get(route('staff.home'))
            ->assertInertia(fn (Assert $page) => $page->where('followUpsToCall', 1));

        $this->actingAs($this->patient)->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page->where('followUpsToCall', 0));
    });
});

describe('book for the patient', function () {
    it('books the visit for the patient and says who did it', function () {
        Notification::fake();
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->staff)
            ->from(route('staff.follow-ups.index', ['selected' => $followUp->ulid]))
            ->post(route('staff.follow-ups.book', $followUp), ['starts_at' => staffFuStartsAt()])
            ->assertRedirect(route('staff.follow-ups.index', ['selected' => $followUp->ulid]))
            ->assertSessionHasNoErrors();

        $followUp->refresh();
        $appointment = Appointment::query()->findOrFail($followUp->booked_appointment_id);

        expect($followUp->status)->toBe(FollowUpStatus::Booked)
            ->and($appointment->patient_id)->toBe($this->patient->id)
            ->and($appointment->doctor_id)->toBe($this->doctor->id)
            ->and($appointment->service_id)->toBe($this->service->id)
            ->and($appointment->status)->toBe(AppointmentStatus::Booked);

        // The audit row names the staff member, not the patient, and the patient gets the confirmation email.
        $audit = AuditLog::query()->where('action', 'appointment.booked')->latest('id')->firstOrFail();
        expect($audit->actor_id)->toBe($this->staff->id);
        Notification::assertSentTo($this->patient, AppointmentBooked::class);
    });

    it('books with any doctor for an any-doctor follow-up', function () {
        $followUp = ($this->makeFollowUp)('anyDoctor');

        $this->actingAs($this->staff)
            ->post(route('staff.follow-ups.book', $followUp), ['starts_at' => staffFuStartsAt()])
            ->assertSessionHasNoErrors();

        expect($followUp->refresh()->status)->toBe(FollowUpStatus::Booked)
            ->and($followUp->bookedAppointment->doctor_id)->toBe($this->doctor->id);
    });

    it('turns away an admin, a patient and a doctor, and books nothing', function (Role $role) {
        $followUp = ($this->makeFollowUp)();

        $this->actingAs(userWithRole($role))
            ->post(route('staff.follow-ups.book', $followUp), ['starts_at' => staffFuStartsAt()])
            ->assertForbidden();

        expect($followUp->refresh()->status)->toBe(FollowUpStatus::Open)
            ->and(Appointment::query()->where('status', AppointmentStatus::Booked)->count())->toBe(0);
    })->with([Role::Admin, Role::Patient, Role::Doctor, Role::Nurse]);

    it('needs a time from the list', function (array $input) {
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->staff)
            ->from(route('staff.follow-ups.index', ['selected' => $followUp->ulid]))
            ->post(route('staff.follow-ups.book', $followUp), $input)
            ->assertRedirect(route('staff.follow-ups.index', ['selected' => $followUp->ulid]))
            ->assertSessionHasErrors('starts_at');

        expect($followUp->refresh()->status)->toBe(FollowUpStatus::Open);
    })->with([
        'no time' => [[]],
        'not a time' => [['starts_at' => 'tomorrow morning']],
        'a day outside the window' => [['starts_at' => staffFuStartsAt('2026-11-09 08:00')]],
        'a time the doctor does not work' => [['starts_at' => staffFuStartsAt('2026-11-02 14:00')]],
    ]);

    it('refuses a follow-up that is already booked, and keeps the first booking', function () {
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->staff)->post(route('staff.follow-ups.book', $followUp), ['starts_at' => staffFuStartsAt()]);

        $this->actingAs($this->staff)
            ->from(route('staff.follow-ups.index', ['selected' => $followUp->ulid]))
            ->post(route('staff.follow-ups.book', $followUp), ['starts_at' => staffFuStartsAt('2026-11-02 09:00')])
            ->assertSessionHasErrors('follow_up');

        expect(Appointment::query()->where('patient_id', $this->patient->id)->where('status', AppointmentStatus::Booked)->count())->toBe(1);
    });

    it('still applies the limit of three upcoming visits for the patient', function () {
        foreach (['2026-11-02 10:00', '2026-11-02 11:00', '2026-11-06 08:00'] as $clinicTime) {
            Appointment::factory()->booked()->for($this->doctor)->for($this->service)->for($this->patient, 'patient')
                ->create(['starts_at' => CarbonImmutable::parse($clinicTime, 'Asia/Manila')->utc()]);
        }

        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->staff)
            ->from(route('staff.follow-ups.index', ['selected' => $followUp->ulid]))
            ->post(route('staff.follow-ups.book', $followUp), ['starts_at' => staffFuStartsAt()])
            ->assertSessionHasErrors('starts_at');

        expect($followUp->refresh()->status)->toBe(FollowUpStatus::Open);
    });

    it('reopens the follow-up when staff cancel the booking they made', function () {
        $followUp = ($this->makeFollowUp)();
        $this->actingAs($this->staff)->post(route('staff.follow-ups.book', $followUp), ['starts_at' => staffFuStartsAt()]);

        $appointment = $followUp->refresh()->bookedAppointment;
        $this->actingAs($this->staff)->post(route('staff.appointments.cancel', $appointment), ['reason' => 'Patient called to change.']);

        expect($followUp->refresh()->status)->toBe(FollowUpStatus::Open)
            ->and($followUp->booked_appointment_id)->toBeNull();
    });
});

describe('log a call', function () {
    it('records the outcome and who logged it, and leaves the follow-up open', function () {
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->staff)
            ->from(route('staff.follow-ups.index', ['selected' => $followUp->ulid]))
            ->post(route('staff.follow-ups.call', $followUp), ['outcome' => 'no_answer'])
            ->assertRedirect(route('staff.follow-ups.index', ['selected' => $followUp->ulid]))
            ->assertSessionHasNoErrors();

        $event = FollowUpEvent::query()->sole();

        expect($event->type)->toBe(FollowUpEventType::Called)
            ->and($event->outcome)->toBe(FollowUpCallOutcome::NoAnswer)
            ->and($event->actor_id)->toBe($this->staff->id)
            ->and($event->follow_up_id)->toBe($followUp->id)
            ->and($followUp->refresh()->status)->toBe(FollowUpStatus::Open);
    });

    it('closes the follow-up when the patient does not need the visit', function () {
        $followUp = ($this->makeFollowUp)('windowEnded');

        $this->actingAs($this->staff)
            ->post(route('staff.follow-ups.call', $followUp), ['outcome' => 'not_needed'])
            ->assertSessionHasNoErrors();

        expect($followUp->refresh()->status)->toBe(FollowUpStatus::Dismissed)
            ->and(FollowUp::query()->needingCall(CarbonImmutable::now())->count())->toBe(0);
    });

    it('turns away an admin, a patient and a doctor, and logs nothing', function (Role $role) {
        $followUp = ($this->makeFollowUp)();

        $this->actingAs(userWithRole($role))
            ->post(route('staff.follow-ups.call', $followUp), ['outcome' => 'not_needed'])
            ->assertForbidden();

        expect(FollowUpEvent::query()->count())->toBe(0)
            ->and($followUp->refresh()->status)->toBe(FollowUpStatus::Open);
    })->with([Role::Admin, Role::Patient, Role::Doctor, Role::Nurse]);

    it('takes one outcome from the fixed list and no free text', function (array $input) {
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->staff)
            ->from(route('staff.follow-ups.index', ['selected' => $followUp->ulid]))
            ->post(route('staff.follow-ups.call', $followUp), $input)
            ->assertSessionHasErrors('outcome');

        expect(FollowUpEvent::query()->count())->toBe(0);
    })->with([
        'no outcome' => [[]],
        'free text' => [['outcome' => 'Patient says her chest still hurts']],
        'an empty outcome' => [['outcome' => '']],
    ]);

    it('ignores any extra text sent with the outcome', function () {
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->staff)
            ->post(route('staff.follow-ups.call', $followUp), ['outcome' => 'no_answer', 'note' => 'chest pain', 'status' => 'booked'])
            ->assertSessionHasNoErrors();

        expect(FollowUpEvent::query()->sole()->getAttributes())->not->toHaveKey('note')
            ->and($followUp->refresh()->status)->toBe(FollowUpStatus::Open);
    });

    it('refuses a follow-up that is not open any more', function (string $state) {
        $followUp = ($this->makeFollowUp)($state);

        $this->actingAs($this->staff)
            ->from(route('staff.follow-ups.index', ['selected' => $followUp->ulid]))
            ->post(route('staff.follow-ups.call', $followUp), ['outcome' => 'no_answer'])
            ->assertSessionHasErrors('follow_up');

        expect(FollowUpEvent::query()->count())->toBe(0);
    })->with(['booked', 'dismissed']);
});

describe('send a reminder', function () {
    it('emails the patient, marks the day and writes the history', function () {
        Notification::fake();
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->staff)
            ->from(route('staff.follow-ups.index', ['selected' => $followUp->ulid]))
            ->post(route('staff.follow-ups.remind', $followUp))
            ->assertRedirect(route('staff.follow-ups.index', ['selected' => $followUp->ulid]))
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($this->patient, FollowUpReminder::class);
        Notification::assertCount(1);

        $event = FollowUpEvent::query()->sole();
        expect($event->type)->toBe(FollowUpEventType::Reminded)
            ->and($event->outcome)->toBeNull()
            ->and($event->actor_id)->toBe($this->staff->id)
            ->and($followUp->refresh()->last_reminded_at?->equalTo(CarbonImmutable::now()))->toBeTrue();
    });

    it('is queued, so staff do not wait for the mail server', function () {
        Queue::fake();
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->staff)->post(route('staff.follow-ups.remind', $followUp));

        Queue::assertPushed(SendQueuedNotifications::class, fn ($job): bool => $job->notification instanceof FollowUpReminder);
    });

    it('holds no health information: the doctor, the visit type and the dates only', function () {
        $followUp = ($this->makeFollowUp)(overrides: ['note' => FU_NOTE]);

        $mail = (new FollowUpReminder($followUp))->toMail($this->patient);
        $text = implode("\n", [$mail->subject, ...array_map(fn ($line): string => (string) $line, $mail->introLines), (string) $mail->render()]);

        expect($text)->toContain($this->doctor->user->name)
            ->toContain($this->service->name)
            ->toContain('2 November 2026')
            ->toContain('6 November 2026')
            ->not->toContain('biopsy')
            ->not->toContain('abnormal');
    });

    it('does not name the doctor when any doctor will do', function () {
        $followUp = ($this->makeFollowUp)('anyDoctor');

        $mail = (new FollowUpReminder($followUp))->toMail($this->patient);

        expect(implode("\n", array_map(fn ($line): string => (string) $line, $mail->introLines)))->not->toContain($this->doctor->user->name);
    });

    it('does not send to a patient who booked while the email waited in the queue', function () {
        $followUp = ($this->makeFollowUp)();

        expect((new FollowUpReminder($followUp))->shouldSend($this->patient, 'mail'))->toBeTrue();

        // The worker loads the follow-up again when it runs, so it sees the booking made in the meantime.
        $followUp->update(['status' => FollowUpStatus::Booked]);

        expect((new FollowUpReminder($followUp->fresh()))->shouldSend($this->patient, 'mail'))->toBeFalse();
    });

    it('sends one a day: a second the same day is refused, the next day is fine', function () {
        Notification::fake();
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->staff)->post(route('staff.follow-ups.remind', $followUp))->assertSessionHasNoErrors();

        $this->actingAs($this->staff)
            ->from(route('staff.follow-ups.index', ['selected' => $followUp->ulid]))
            ->post(route('staff.follow-ups.remind', $followUp))
            ->assertSessionHasErrors('follow_up');

        Notification::assertCount(1);

        $this->travelTo(CarbonImmutable::parse('2026-10-31 09:00', 'Asia/Manila'));
        $this->actingAs($this->staff)->post(route('staff.follow-ups.remind', $followUp))->assertSessionHasNoErrors();

        Notification::assertCount(2);
        expect(FollowUpEvent::query()->count())->toBe(2);
    });

    it('turns away an admin, a patient and a doctor, and sends nothing', function (Role $role) {
        Notification::fake();
        $followUp = ($this->makeFollowUp)();

        $this->actingAs(userWithRole($role))
            ->post(route('staff.follow-ups.remind', $followUp))
            ->assertForbidden();

        Notification::assertNothingSent();
        expect(FollowUpEvent::query()->count())->toBe(0)
            ->and($followUp->refresh()->last_reminded_at)->toBeNull();
    })->with([Role::Admin, Role::Patient, Role::Doctor, Role::Nurse]);

    it('refuses a follow-up that is booked, dismissed or past its dates', function (string $state) {
        Notification::fake();
        $followUp = ($this->makeFollowUp)($state);

        $this->actingAs($this->staff)
            ->from(route('staff.follow-ups.index', ['selected' => $followUp->ulid]))
            ->post(route('staff.follow-ups.remind', $followUp))
            ->assertSessionHasErrors('follow_up');

        Notification::assertNothingSent();
        expect(FollowUpEvent::query()->count())->toBe(0);
    })->with(['booked', 'dismissed', 'windowEnded']);
});

describe('the actions on their own', function () {
    // The controller asks the Policy, and so do the Actions: a job or an AI tool that calls one directly must not skip it.
    it('refuse an actor who may not manage the follow-up', function (Role $role) {
        $followUp = ($this->makeFollowUp)();
        $actor = userWithRole($role);

        expect(fn () => app(LogFollowUpCall::class)->handle($actor, $followUp, FollowUpCallOutcome::NoAnswer))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SendFollowUpReminder::class)->handle($actor, $followUp))->toThrow(AuthorizationException::class)
            ->and(fn () => app(BookFollowUp::class)->handle($actor, $followUp, CarbonImmutable::parse('2026-11-02 08:00', 'Asia/Manila')))->toThrow(AuthorizationException::class);

        expect(FollowUpEvent::query()->count())->toBe(0)
            ->and($followUp->refresh()->status)->toBe(FollowUpStatus::Open);
    })->with([Role::Admin, Role::Doctor, Role::Nurse]);

    it('read the follow-up again under the lock, so a stale copy cannot be acted on', function () {
        $followUp = ($this->makeFollowUp)();
        $stale = FollowUp::query()->findOrFail($followUp->id);

        // Another tab closes it, while this copy still says "open".
        $followUp->update(['status' => FollowUpStatus::Dismissed]);
        expect($stale->status)->toBe(FollowUpStatus::Open);

        expect(fn () => app(LogFollowUpCall::class)->handle($this->staff, $stale, FollowUpCallOutcome::NoAnswer))->toThrow(ValidationException::class)
            ->and(fn () => app(SendFollowUpReminder::class)->handle($this->staff, $stale))->toThrow(ValidationException::class);

        expect(FollowUpEvent::query()->count())->toBe(0);
    });

    it('write no event and send no email when the reminder is refused', function () {
        Notification::fake();
        $followUp = ($this->makeFollowUp)('windowEnded');

        expect(fn () => app(SendFollowUpReminder::class)->handle($this->staff, $followUp))->toThrow(ValidationException::class);

        Notification::assertNothingSent();
        expect(FollowUpEvent::query()->count())->toBe(0)
            ->and($followUp->refresh()->last_reminded_at)->toBeNull();
    });
});

describe('the one reminder a day', function () {
    it('counts the clinic day, not the UTC day', function () {
        Notification::fake();
        $followUp = ($this->makeFollowUp)(overrides: ['window_starts_on' => '2026-10-28', 'window_ends_on' => '2026-11-10']);

        // 00:30 on Saturday 31 October in Manila is still Friday 30 October in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-10-31 00:30', 'Asia/Manila'));
        app(SendFollowUpReminder::class)->handle($this->staff, $followUp);

        // 07:00 the same Manila day: the UTC date is still 30 October, so only a Manila-day check refuses this one
        // for the right reason, and only a UTC check would let the next one through too early.
        $this->travelTo(CarbonImmutable::parse('2026-10-31 07:00', 'Asia/Manila'));
        expect(fn () => app(SendFollowUpReminder::class)->handle($this->staff, $followUp->refresh()))->toThrow(ValidationException::class);

        // The next Manila day starts at 00:00 on 1 November, which is 16:00 on 31 October in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-11-01 00:05', 'Asia/Manila'));
        app(SendFollowUpReminder::class)->handle($this->staff, $followUp->refresh());

        Notification::assertCount(2);
    });

    it('still reminds on the last day of the window, and not the day after', function () {
        Notification::fake();
        $followUp = ($this->makeFollowUp)(overrides: ['window_starts_on' => '2026-10-26', 'window_ends_on' => '2026-10-30']);

        app(SendFollowUpReminder::class)->handle($this->staff, $followUp);

        $this->travelTo(CarbonImmutable::parse('2026-10-31 09:00', 'Asia/Manila'));
        expect(fn () => app(SendFollowUpReminder::class)->handle($this->staff, $followUp->refresh()))->toThrow(ValidationException::class);

        Notification::assertCount(1);
    });
});

describe('the booking picker', function () {
    /** The `book` prop of a partial reload for the follow-up. */
    function staffFuBookOptions($test, FollowUp $followUp): array
    {
        $book = null;
        $test->actingAs($test->staff)
            ->get(route('staff.follow-ups.index', ['selected' => $followUp->ulid]))
            ->assertInertia(function (Assert $page) use (&$book): void {
                $page->reloadOnly('book', function (Assert $reload) use (&$book): void {
                    $book = $reload->toArray()['props']['book'];
                });
            });

        return $book;
    }

    it('says why there is nothing to pick instead of listing days', function (Closure $setUp, string $reason) {
        $followUp = ($this->makeFollowUp)();
        $setUp($this, $followUp);

        $book = staffFuBookOptions($this, $followUp->refresh());

        expect($book['days'])->toBe([])
            ->and($book['slots'])->toBe([])
            ->and($book['selectedDate'])->toBeNull()
            ->and($book['unavailable'])->toBe($reason);
    })->with([
        'the visit type is switched off' => [fn ($test, FollowUp $followUp) => $test->service->update(['is_active' => false]), 'This visit type is switched off.'],
        'the doctor is switched off' => [fn ($test, FollowUp $followUp) => $test->doctor->user->forceFill(['is_active' => false])->save(), 'The doctor is not taking bookings now.'],
        'the follow-up was dismissed' => [fn ($test, FollowUp $followUp) => $followUp->update(['status' => FollowUpStatus::Dismissed]), 'This follow-up is not open any more.'],
    ]);

    it('lists only the days of the window, with how many free times each has', function () {
        $followUp = ($this->makeFollowUp)();

        $book = staffFuBookOptions($this, $followUp);

        // Every day of the window is listed (a day with nothing free stays in, empty, like on the public pages). The
        // doctor works Monday 2 and Friday 6 November, and not on the days between. Start times are every 15 minutes, so
        // a 60 minute visit has 13 starts in 08:00 to 12:00 (the last at 11:00) and 33 in 08:00 to 17:00.
        expect(collect($book['days'])->pluck('date')->all())->toBe(['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'])
            ->and(collect($book['days'])->pluck('slotsCount')->all())->toBe([13, 0, 0, 0, 33])
            ->and($book['selectedDate'])->toBe('2026-11-02');
    });
});

describe('the history record', function () {
    it('can not be changed or removed', function () {
        $event = FollowUpEvent::factory()->create();

        expect(fn () => $event->update(['outcome' => FollowUpCallOutcome::NotNeeded]))->toThrow(LogicException::class)
            ->and(fn () => $event->delete())->toThrow(LogicException::class);

        expect($event->refresh()->outcome)->toBe(FollowUpCallOutcome::NoAnswer);
    });

    it('lists the newest events first and only the last twenty', function () {
        $followUp = ($this->makeFollowUp)();

        foreach (range(1, 22) as $minute) {
            FollowUpEvent::factory()->create([
                'follow_up_id' => $followUp->id,
                'actor_id' => $this->staff->id,
                'created_at' => now()->subHours(30)->addMinutes($minute),
            ]);
        }

        $this->actingAs($this->staff)
            ->get(route('staff.follow-ups.index', ['selected' => $followUp->ulid]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('selected.history', 20)
                ->where('selected.history.0.id', FollowUpEvent::query()->max('id')));
    });
});

describe('the words of each state', function () {
    it('say what staff need to know, in clinic days', function (string $state, array $overrides, string $label) {
        $followUp = ($this->makeFollowUp)($state, $overrides);

        $this->actingAs($this->staff)
            ->get(route('staff.follow-ups.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('followUps.0.ulid', $followUp->ulid)
                ->where('followUps.0.stateLabel', $label));
    })->with([
        'ended days ago' => ['open', ['window_starts_on' => '2026-10-20', 'window_ends_on' => '2026-10-27'], 'Not booked · ended 3 days ago'],
        'ended yesterday' => ['open', ['window_starts_on' => '2026-10-20', 'window_ends_on' => '2026-10-29'], 'Not booked · ended 1 day ago'],
        'ends today' => ['open', ['window_starts_on' => '2026-10-26', 'window_ends_on' => '2026-10-30'], 'Not booked · ends today'],
        'ends tomorrow' => ['open', ['window_starts_on' => '2026-10-26', 'window_ends_on' => '2026-10-31'], 'Not booked · ends tomorrow'],
        'open with days left' => ['open', ['window_starts_on' => '2026-10-26', 'window_ends_on' => '2026-11-04'], 'Open now · 5 days left'],
        'opens in a day' => ['open', ['window_starts_on' => '2026-10-31', 'window_ends_on' => '2026-11-04'], 'Waiting · opens in 1 day'],
        // The factory's booked visit is 09:00 UTC on 7 November, which is 5:00 PM in Manila.
        'booked' => ['booked', [], 'Booked · 7 Nov, 5:00 PM'],
        'dismissed' => ['dismissed', [], 'Not needed'],
    ]);
});

describe('the routes', function () {
    it('answers 404 for a follow-up that does not exist', function () {
        // Route binding runs before the role check (as for appointments), so this is a 404 for every role. The
        // address is a ULID, so there is nothing to count up, and an existing follow-up is a 403 for a patient above.
        $this->actingAs($this->staff)->post(route('staff.follow-ups.remind', '01ARZ3NDEKTSV4RRFFQ69G5FAV'))->assertNotFound();
    });

    it('caps the buttons at 60 a minute per staff member and then answers 429', function () {
        RateLimiter::clear('staff-actions');
        $followUp = ($this->makeFollowUp)();

        // A refused click (no outcome) counts too: the limit is on clicks, not on successes.
        foreach (range(1, 60) as $click) {
            $this->actingAs($this->staff)->post(route('staff.follow-ups.call', $followUp));
        }

        $this->actingAs($this->staff)
            ->post(route('staff.follow-ups.call', $followUp), ['outcome' => 'no_answer'])
            ->assertStatus(429);

        expect(FollowUpEvent::query()->count())->toBe(0);
    });

    it('names the staff member in the audit row for an any-doctor booking too', function () {
        $followUp = ($this->makeFollowUp)('anyDoctor');

        $this->actingAs($this->staff)->post(route('staff.follow-ups.book', $followUp), ['starts_at' => staffFuStartsAt()]);

        expect(AuditLog::query()->where('action', 'appointment.booked')->latest('id')->firstOrFail()->actor_id)->toBe($this->staff->id);
    });
});
