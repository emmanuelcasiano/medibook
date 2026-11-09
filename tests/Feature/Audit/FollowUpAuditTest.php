<?php

declare(strict_types=1);

use App\Actions\Booking\BookFollowUp;
use App\Actions\Doctor\CompleteAppointment;
use App\Actions\Doctor\FollowUpData;
use App\Actions\Doctor\RequestFollowUp;
use App\Actions\Staff\LogFollowUpCall;
use App\Actions\Staff\SendFollowUpReminder;
use App\Enums\AppointmentStatus;
use App\Enums\FollowUpCallOutcome;
use App\Enums\FollowUpStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\FollowUp;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

// Step L.5: the four follow-up audit events. Each one is written in the same transaction as the change it records, names
// the person who pressed the button, and holds ids, dates and states only, never the doctor's note. A refused call writes
// no row. Who may call each Action is also tested in FollowUpsTest, BookFollowUpTest and RequestFollowUpTest; here the
// point is that a refusal leaves the log untouched. "Now" is Friday 30 October 2026, 09:00 in Manila. The doctor works
// Mondays 08:00 to 12:00 and Fridays 08:00 to 17:00, the service lasts 60 minutes, and the window is 2 to 6 November.

const AUDIT_FU_NOTE = 'Your biopsy result was abnormal, please come in.';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));

    $this->service = Service::factory()->lasting(60)->create();
    $this->doctor = doctorWorking($this->service, [[1, '08:00', '12:00'], [5, '08:00', '17:00']]);
    // Doctors need 2FA to reach their screens, like every staff-side role.
    $this->doctorUser = $this->doctor->user;
    $this->doctorUser->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $this->patient = User::factory()->patient()->create();
    $this->staff = userWithRole(Role::Staff);
    $this->admin = userWithRole(Role::Admin);

    // The audit rows of one event, oldest first.
    $this->rows = fn (string $action) => AuditLog::query()->where('action', $action)->orderBy('id')->get();

    // Every follow-up row, to look for the note in.
    $this->followUpRows = fn () => AuditLog::query()->where('action', 'like', 'follow_up.%')->get();

    $madeSoFar = 0;
    $this->makeFollowUp = function (string $state = 'open', array $overrides = []) use (&$madeSoFar): FollowUp {
        // Each finished visit starts an hour earlier than the last one, so two never share the doctor's slot.
        $source = Appointment::factory()
            ->completed()
            ->for($this->doctor)
            ->for($this->patient, 'patient')
            ->startingIn(hours: -72 - $madeSoFar++)
            ->create();

        return FollowUp::factory()
            ->state([
                'source_appointment_id' => $source->id,
                'service_id' => $this->service->id,
                'window_starts_on' => '2026-11-02',
                'window_ends_on' => '2026-11-06',
                'note' => AUDIT_FU_NOTE,
            ])
            ->{$state}()
            ->create($overrides);
    };

    $this->monday = CarbonImmutable::parse('2026-11-02 08:00', 'Asia/Manila');
});

describe('follow_up.requested', function () {
    beforeEach(function () {
        $this->visit = fn (): Appointment => Appointment::factory()
            ->checkedIn()
            ->for($this->doctor)
            ->for($this->patient, 'patient')
            ->create(['starts_at' => CarbonImmutable::parse('2026-10-30 08:00', 'Asia/Manila')->utc()]);
        $this->data = fn (array $overrides = []): FollowUpData => new FollowUpData(
            serviceId: $overrides['serviceId'] ?? $this->service->id,
            startsOn: $overrides['startsOn'] ?? CarbonImmutable::parse('2026-11-02', 'Asia/Manila'),
            endsOn: $overrides['endsOn'] ?? CarbonImmutable::parse('2026-11-06', 'Asia/Manila'),
            sameDoctor: true,
            note: AUDIT_FU_NOTE,
        );
    });

    it('writes one row naming the doctor, with ids and dates and no note', function () {
        $visit = ($this->visit)();

        $this->actingAs($this->doctorUser)
            ->post(route('doctor.appointments.complete', $visit), ['follow_up' => [
                'service_id' => $this->service->id,
                'starts_on' => '2026-11-02',
                'ends_on' => '2026-11-06',
                'same_doctor' => '1',
                'note' => AUDIT_FU_NOTE,
            ]])
            ->assertSessionHasNoErrors();

        $followUp = FollowUp::query()->sole();
        $log = ($this->rows)('follow_up.requested')->sole();

        expect($log->actor_id)->toBe($this->doctorUser->id)
            ->and($log->subject_type)->toBe(FollowUp::class)
            ->and($log->subject_id)->toBe($followUp->id)
            ->and($log->metadata)->toEqual([
                'source_appointment_id' => $visit->id,
                'service_id' => $this->service->id,
                'window_starts_on' => '2026-11-02',
                'window_ends_on' => '2026-11-06',
                'same_doctor' => true,
            ])
            ->and($log->ip_hash)->not->toBeNull();
    });

    it('writes none when the visit is finished without a follow-up', function () {
        $this->actingAs($this->doctorUser)
            ->post(route('doctor.appointments.complete', ($this->visit)()))
            ->assertSessionHasNoErrors();

        expect(($this->rows)('follow_up.requested'))->toHaveCount(0)
            ->and(($this->rows)('appointment.completed'))->toHaveCount(1);
    });

    it('writes none, and undoes the finish, when the request is refused', function (array $overrides) {
        $visit = ($this->visit)();

        expect(fn () => app(CompleteAppointment::class)->handle($this->doctorUser, $visit, ($this->data)($overrides)))
            ->toThrow(ValidationException::class);

        expect(($this->rows)('follow_up.requested'))->toHaveCount(0)
            ->and(($this->rows)('appointment.completed'))->toHaveCount(0)
            ->and(FollowUp::query()->count())->toBe(0);
    })->with([
        'a first day in the past' => [['startsOn' => CarbonImmutable::parse('2026-10-01', 'Asia/Manila')]],
        'a last day before the first' => [['startsOn' => CarbonImmutable::parse('2026-11-06', 'Asia/Manila'), 'endsOn' => CarbonImmutable::parse('2026-11-02', 'Asia/Manila')]],
        'a service the doctor does not offer' => [['serviceId' => 999999]],
    ]);

    it('writes none for a doctor who does not own the visit, or for another role', function (Role $role) {
        $visit = ($this->visit)();
        $visit->update(['status' => 'completed']);
        $actor = userWithRole($role);

        expect(fn () => app(RequestFollowUp::class)->handle($actor, $visit, ($this->data)()))->toThrow(AuthorizationException::class);

        expect(AuditLog::query()->count())->toBe(0)
            ->and(FollowUp::query()->count())->toBe(0);
    })->with([Role::Doctor, Role::Staff, Role::Nurse, Role::Admin, Role::Patient]);

    it('writes none when a follow-up already exists for the visit', function () {
        $visit = ($this->visit)();
        $visit->update(['status' => 'completed']);

        app(RequestFollowUp::class)->handle($this->doctorUser, $visit, ($this->data)());

        expect(fn () => app(RequestFollowUp::class)->handle($this->doctorUser, $visit, ($this->data)()))->toThrow(ValidationException::class);

        expect(($this->rows)('follow_up.requested'))->toHaveCount(1);
    });
});

describe('follow_up.booked', function () {
    it('writes one row naming the patient and the appointment, next to appointment.booked', function () {
        $followUp = ($this->makeFollowUp)();

        $appointment = app(BookFollowUp::class)->handle($this->patient, $followUp, $this->monday);

        $log = ($this->rows)('follow_up.booked')->sole();

        expect($log->actor_id)->toBe($this->patient->id)
            ->and($log->subject_type)->toBe(FollowUp::class)
            ->and($log->subject_id)->toBe($followUp->id)
            ->and($log->metadata)->toEqual([
                'status_from' => 'open',
                'status_to' => 'booked',
                'appointment_id' => $appointment->id,
            ])
            ->and(($this->rows)('appointment.booked'))->toHaveCount(1);
    });

    it('names the staff member when staff book for the patient', function () {
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->staff)
            ->post(route('staff.follow-ups.book', $followUp), ['starts_at' => $this->monday->utc()->format('Y-m-d\TH:i:s\Z')])
            ->assertSessionHasNoErrors();

        expect(($this->rows)('follow_up.booked')->sole()->actor_id)->toBe($this->staff->id);
    });

    it('writes none when the follow-up is already booked or the time is outside the window', function () {
        $booked = ($this->makeFollowUp)('booked');
        $open = ($this->makeFollowUp)();

        expect(fn () => app(BookFollowUp::class)->handle($this->patient, $booked, $this->monday))->toThrow(AuthorizationException::class)
            ->and(fn () => app(BookFollowUp::class)->handle($this->patient, $open, CarbonImmutable::parse('2026-11-09 08:00', 'Asia/Manila')))->toThrow(ValidationException::class);

        expect(($this->rows)('follow_up.booked'))->toHaveCount(0)
            ->and($open->refresh()->status)->toBe(FollowUpStatus::Open);
    });

    it('writes none, and leaves the follow-up open, when the booking itself is refused', function () {
        $followUp = ($this->makeFollowUp)();
        // The doctor's 08:00 on Monday is taken by someone else.
        Appointment::factory()->for($this->doctor)->create(['starts_at' => $this->monday->utc()]);

        expect(fn () => app(BookFollowUp::class)->handle($this->patient, $followUp, $this->monday))->toThrow(ValidationException::class);

        expect(($this->rows)('follow_up.booked'))->toHaveCount(0)
            ->and(($this->rows)('appointment.booked'))->toHaveCount(0)
            ->and($followUp->refresh()->status)->toBe(FollowUpStatus::Open);
    });

    it('writes none for another patient, a doctor or an admin', function (Role $role) {
        $followUp = ($this->makeFollowUp)();
        $actor = userWithRole($role);

        expect(fn () => app(BookFollowUp::class)->handle($actor, $followUp, $this->monday))->toThrow(AuthorizationException::class);

        expect(AuditLog::query()->count())->toBe(0);
    })->with([Role::Patient, Role::Doctor, Role::Nurse, Role::Admin]);
});

describe('follow_up.reminded', function () {
    it('writes one row naming the staff member, with no metadata', function () {
        Notification::fake();
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->staff)
            ->post(route('staff.follow-ups.remind', $followUp))
            ->assertSessionHasNoErrors();

        $log = ($this->rows)('follow_up.reminded')->sole();

        expect($log->actor_id)->toBe($this->staff->id)
            ->and($log->subject_type)->toBe(FollowUp::class)
            ->and($log->subject_id)->toBe($followUp->id)
            ->and($log->metadata)->toBeNull();
    });

    it('writes none for a second reminder the same day, a booked follow-up or a past window', function () {
        Notification::fake();
        $open = ($this->makeFollowUp)();
        $booked = ($this->makeFollowUp)('booked');
        $past = ($this->makeFollowUp)('windowEnded');

        app(SendFollowUpReminder::class)->handle($this->staff, $open);

        expect(fn () => app(SendFollowUpReminder::class)->handle($this->staff, $open))->toThrow(ValidationException::class)
            ->and(fn () => app(SendFollowUpReminder::class)->handle($this->staff, $booked))->toThrow(ValidationException::class)
            ->and(fn () => app(SendFollowUpReminder::class)->handle($this->staff, $past))->toThrow(ValidationException::class);

        expect(($this->rows)('follow_up.reminded'))->toHaveCount(1);
    });

    it('writes none for an admin, a doctor or a patient', function (Role $role) {
        Notification::fake();
        $followUp = ($this->makeFollowUp)();

        expect(fn () => app(SendFollowUpReminder::class)->handle(userWithRole($role), $followUp))->toThrow(AuthorizationException::class);

        expect(AuditLog::query()->count())->toBe(0);
    })->with([Role::Admin, Role::Doctor, Role::Nurse, Role::Patient]);
});

describe('follow_up.dismissed', function () {
    it('writes one row when staff log that the patient does not need the visit', function () {
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->staff)
            ->post(route('staff.follow-ups.call', $followUp), ['outcome' => 'not_needed'])
            ->assertSessionHasNoErrors();

        $log = ($this->rows)('follow_up.dismissed')->sole();

        expect($log->actor_id)->toBe($this->staff->id)
            ->and($log->subject_type)->toBe(FollowUp::class)
            ->and($log->subject_id)->toBe($followUp->id)
            ->and($log->metadata)->toEqual([
                'status_from' => 'open',
                'status_to' => 'dismissed',
                'outcome' => 'not_needed',
            ]);
    });

    it('writes none for a call that closes nothing', function (FollowUpCallOutcome $outcome) {
        app(LogFollowUpCall::class)->handle($this->staff, ($this->makeFollowUp)(), $outcome);

        expect(($this->rows)('follow_up.dismissed'))->toHaveCount(0);
    })->with([FollowUpCallOutcome::NoAnswer, FollowUpCallOutcome::WillBookOnline, FollowUpCallOutcome::BookedByPhone]);

    it('writes none when the follow-up is no longer open', function (string $state) {
        $followUp = ($this->makeFollowUp)($state);

        expect(fn () => app(LogFollowUpCall::class)->handle($this->staff, $followUp, FollowUpCallOutcome::NotNeeded))->toThrow(ValidationException::class);

        expect(($this->rows)('follow_up.dismissed'))->toHaveCount(0);
    })->with(['booked', 'dismissed']);

    it('writes none for an admin, a doctor or a patient', function (Role $role) {
        $followUp = ($this->makeFollowUp)();

        expect(fn () => app(LogFollowUpCall::class)->handle(userWithRole($role), $followUp, FollowUpCallOutcome::NotNeeded))->toThrow(AuthorizationException::class);

        expect(AuditLog::query()->count())->toBe(0)
            ->and($followUp->refresh()->status)->toBe(FollowUpStatus::Open);
    })->with([Role::Admin, Role::Doctor, Role::Nurse, Role::Patient]);
});

describe('a guest', function () {
    it('is sent to log in on every follow-up button, and nothing is changed or logged', function (string $route, array $input) {
        $followUp = ($this->makeFollowUp)();
        $visit = Appointment::factory()->checkedIn()->for($this->doctor)->for($this->patient, 'patient')->create();

        $url = $route === 'doctor.appointments.complete' ? route($route, $visit) : route($route, $followUp);

        $this->post($url, $input)->assertRedirect(route('login'));

        expect(AuditLog::query()->count())->toBe(0)
            ->and($followUp->refresh()->status)->toBe(FollowUpStatus::Open)
            ->and($visit->refresh()->status)->toBe(AppointmentStatus::CheckedIn);
    })->with([
        'finish a visit and ask for a follow-up' => ['doctor.appointments.complete', ['follow_up' => ['service_id' => 1]]],
        'staff book' => ['staff.follow-ups.book', ['starts_at' => '2026-11-02T00:00:00Z']],
        'staff log a call' => ['staff.follow-ups.call', ['outcome' => 'not_needed']],
        'staff remind' => ['staff.follow-ups.remind', []],
        'patient book' => ['my.follow-ups.store', ['starts_at' => '2026-11-02T00:00:00Z']],
    ]);
});

describe('what the log holds', function () {
    it('never carries the doctors note or the patients address, in any follow-up row', function () {
        Notification::fake();
        $doctorUser = $this->doctorUser;
        $visit = Appointment::factory()
            ->checkedIn()
            ->for($this->doctor)
            ->for($this->patient, 'patient')
            ->create(['starts_at' => CarbonImmutable::parse('2026-10-30 08:00', 'Asia/Manila')->utc()]);

        $this->actingAs($doctorUser)->post(route('doctor.appointments.complete', $visit), ['follow_up' => [
            'service_id' => $this->service->id,
            'starts_on' => '2026-11-02',
            'ends_on' => '2026-11-06',
            'same_doctor' => '1',
            'note' => AUDIT_FU_NOTE,
        ]]);

        $requested = FollowUp::query()->sole();
        app(SendFollowUpReminder::class)->handle($this->staff, $requested);
        app(BookFollowUp::class)->handle($this->patient, $requested, $this->monday);

        $dismissed = ($this->makeFollowUp)();
        app(LogFollowUpCall::class)->handle($this->staff, $dismissed, FollowUpCallOutcome::NotNeeded);

        $everything = ($this->followUpRows)();

        expect($everything->pluck('action')->sort()->values()->all())->toBe([
            'follow_up.booked',
            'follow_up.dismissed',
            'follow_up.reminded',
            'follow_up.requested',
        ]);

        $text = $everything->toJson();
        expect($text)->not->toContain('biopsy')
            ->and($text)->not->toContain(AUDIT_FU_NOTE)
            ->and($text)->not->toContain($this->patient->email);
    });

    it('can not be changed or removed', function () {
        app(LogFollowUpCall::class)->handle($this->staff, ($this->makeFollowUp)(), FollowUpCallOutcome::NotNeeded);
        $log = ($this->rows)('follow_up.dismissed')->sole();

        expect(fn () => $log->update(['action' => 'follow_up.booked']))->toThrow(LogicException::class)
            ->and(fn () => $log->delete())->toThrow(LogicException::class);
    });

    it('shows on the admin audit screen, with the follow-up as the subject', function () {
        $followUp = ($this->makeFollowUp)();
        app(LogFollowUpCall::class)->handle($this->staff, $followUp, FollowUpCallOutcome::NotNeeded);

        $this->actingAs($this->admin)
            ->get(route('admin.audit-logs.index', ['action' => 'follow_up.dismissed']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.data.0.action', 'follow_up.dismissed')
                ->where('logs.data.0.actorName', $this->staff->name)
                ->where('logs.data.0.subject', 'FollowUp #'.$followUp->id)
                ->where('logs.data.0.details', fn ($lines) => collect($lines)->sort()->values()->all() === ['outcome: not_needed', 'status from: open', 'status to: dismissed'])
                ->where('options.actions', fn ($actions) => in_array('follow_up.dismissed', collect($actions)->all(), true)));
    });
});
