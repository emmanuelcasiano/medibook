<?php

declare(strict_types=1);

use App\Actions\Booking\BookAppointment;
use App\Actions\Booking\BookFollowUp;
use App\Actions\Booking\CancelAppointment;
use App\Actions\Booking\MoveAppointment;
use App\Enums\AppointmentStatus;
use App\Enums\FollowUpStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\FollowUp;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppointmentBooked;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

// Booking from a follow-up (step L.3): the list on "My appointments", the two booking pages and the booking itself.
// The booking rules (three guards, patient limits) are tested in BookAppointmentTest and ConfirmBookingTest. These
// tests cover what a follow-up adds: who may use it, that the service and doctor are its own, the window, and that the
// follow-up is linked on a booking and reopened on a cancel. The staff screens are L.4 and the audit events are L.5.
// "Now" is Friday 30 October 2026, 09:00 in Manila. The doctor works Mondays 08:00 to 12:00 and Fridays 08:00 to 17:00,
// and the service is 60 minutes with no buffer. The follow-up window is Monday 2 to Friday 6 November, so Monday 9
// November is a day the doctor works that the window does not cover.

/** A clinic time as the slot pages send it: ISO 8601 UTC with a Z. */
function fuStartsAt(string $clinicTime = '2026-11-02 08:00'): string
{
    return CarbonImmutable::parse($clinicTime, 'Asia/Manila')->utc()->format('Y-m-d\TH:i:s\Z');
}

/** The confirm address a slot page links to, for the follow-up and a clinic time. */
function fuConfirmUrl(FollowUp $followUp, string $clinicTime = '2026-11-02 08:00'): string
{
    return route('my.follow-ups.confirm', ['followUp' => $followUp, 'starts_at' => fuStartsAt($clinicTime)]);
}

/**
 * The validation errors of a call that must fail.
 *
 * @return array<string, list<string>>
 */
function fuErrors(Closure $attempt): array
{
    try {
        $attempt();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The call was expected to fail, but it went through.');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));

    $this->service = Service::factory()->lasting(60)->create();
    $this->doctor = doctorWorking($this->service, [[1, '08:00', '12:00'], [5, '08:00', '17:00']]);
    $this->patient = User::factory()->patient()->create();

    // A follow-up for $patient from a finished visit with $doctor. $state is a FollowUpFactory state
    // (open, booked, dismissed, windowEnded, anyDoctor). $overrides replaces single columns.
    $madeSoFar = 0;
    $this->makeFollowUp = function (string $state = 'open', array $overrides = [], ?User $patient = null, ?Doctor $doctor = null) use (&$madeSoFar): FollowUp {
        // Each finished visit starts an hour earlier than the last one, so two of them never share the doctor's slot.
        $source = Appointment::factory()
            ->completed()
            ->for($doctor ?? $this->doctor)
            ->for($patient ?? $this->patient, 'patient')
            ->startingIn(hours: -72 - $madeSoFar++)
            ->create();

        // The defaults go in as a state first, so a later state (windowEnded) or an override still wins. Values passed
        // to create() would win over every state.
        return FollowUp::factory()
            ->state([
                'source_appointment_id' => $source->id,
                'service_id' => $this->service->id,
                'window_starts_on' => '2026-11-02',
                'window_ends_on' => '2026-11-06',
            ])
            ->{$state}()
            ->create($overrides);
    };

    // Book the follow-up through the Action, the way the confirm step does.
    $this->bookIt = fn (FollowUp $followUp, string $clinicTime = '2026-11-02 08:00'): Appointment => app(BookFollowUp::class)->handle(
        $this->patient,
        $followUp,
        CarbonImmutable::parse($clinicTime, 'Asia/Manila')->utc(),
    );

    // The visits the patient has booked (the finished source visits are not counted).
    $this->bookedVisits = fn () => Appointment::query()->where('patient_id', $this->patient->id)->where('status', AppointmentStatus::Booked);
});

describe('the list on My appointments', function () {
    it('shows the patient their open follow-ups, the one that ends first on top', function () {
        $later = ($this->makeFollowUp)(overrides: ['window_ends_on' => '2026-11-12', 'note' => 'Bring your lab results.']);
        $sooner = ($this->makeFollowUp)(overrides: ['window_ends_on' => '2026-11-06']);
        $anyDoctor = ($this->makeFollowUp)('anyDoctor', ['window_ends_on' => '2026-11-09', 'note' => null]);

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('my/appointments/index')
                ->where('followUps', fn ($rows): bool => collect($rows)->pluck('ulid')->all() === [$sooner->ulid, $anyDoctor->ulid, $later->ulid])
                ->where('followUps.0.service.name', $this->service->name)
                ->where('followUps.0.service.durationMinutes', 60)
                ->where('followUps.0.doctor.name', $this->doctor->user->name)
                ->where('followUps.0.windowStartsOn', '2026-11-02')
                ->where('followUps.0.windowEndsOn', '2026-11-06')
                // "Any doctor" does not name the asking doctor, and a follow-up may have no note.
                ->where('followUps.1.doctor', null)
                ->where('followUps.1.note', null)
                ->where('followUps.2.note', 'Bring your lab results.')
                // The sidebar badge counts the same follow-ups.
                ->where('openFollowUps', 3));
    });

    it('leaves out everything the patient can not book', function () {
        $mine = ($this->makeFollowUp)();
        ($this->makeFollowUp)(patient: User::factory()->patient()->create());
        ($this->makeFollowUp)('booked');
        ($this->makeFollowUp)('dismissed');
        ($this->makeFollowUp)('windowEnded');
        ($this->makeFollowUp)(overrides: ['service_id' => Service::factory()->create(['is_active' => false])->id]);

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('followUps', fn ($rows): bool => collect($rows)->pluck('ulid')->all() === [$mine->ulid])
                ->where('openFollowUps', 1));
    });

    it('counts no follow-ups for a role that has none', function () {
        ($this->makeFollowUp)();

        $this->actingAs(userWithRole(Role::Staff))
            ->get(route('staff.home'))
            ->assertInertia(fn (Assert $page) => $page->where('openFollowUps', 0));
    });
});

describe('the policy', function () {
    it('lets only the patient it was written for book an open follow-up', function () {
        $followUp = ($this->makeFollowUp)();

        expect(Gate::forUser($this->patient)->allows('book', $followUp))->toBeTrue()
            ->and(Gate::forUser(User::factory()->patient()->create())->allows('book', $followUp))->toBeFalse()
            ->and(Gate::forUser(userWithRole(Role::Staff))->allows('book', $followUp))->toBeFalse()
            ->and(Gate::forUser(userWithRole(Role::Doctor))->allows('book', $followUp))->toBeFalse()
            ->and(Gate::forUser($this->doctor->user)->allows('book', $followUp))->toBeFalse()
            ->and(Gate::forUser(userWithRole(Role::Admin))->allows('book', $followUp))->toBeFalse();
    });

    it('refuses its own patient once it is booked or dismissed', function (string $state) {
        $followUp = ($this->makeFollowUp)($state);

        expect(Gate::forUser($this->patient)->allows('book', $followUp))->toBeFalse();
    })->with(['booked', 'dismissed']);

    it('gives a follow-up list to patients only', function () {
        expect(Gate::forUser($this->patient)->allows('viewAny', FollowUp::class))->toBeTrue()
            ->and(Gate::forUser(userWithRole(Role::Staff))->allows('viewAny', FollowUp::class))->toBeFalse()
            ->and(Gate::forUser(userWithRole(Role::Doctor))->allows('viewAny', FollowUp::class))->toBeFalse()
            ->and(Gate::forUser(userWithRole(Role::Admin))->allows('viewAny', FollowUp::class))->toBeFalse();
    });
});

describe('the time picker', function () {
    it('shows the days of the window with the service and doctor of the follow-up', function () {
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->patient)
            ->get(route('my.follow-ups.book', $followUp))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('booking/slots')
                ->where('followUp.ulid', $followUp->ulid)
                ->where('followUp.windowStartsOn', '2026-11-02')
                ->where('followUp.windowEndsOn', '2026-11-06')
                ->where('service.slug', $this->service->slug)
                ->where('doctor.id', $this->doctor->id)
                // Only the five days of the window, not the 30 day booking window.
                ->where('days', fn ($days): bool => collect($days)->pluck('date')->all() === ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'])
                // Monday 2 November is the first day with a free time: 08:00 Manila is 00:00 UTC.
                ->where('selectedDate', '2026-11-02')
                ->where('slots.0.startsAt', '2026-11-02T00:00:00Z'));
    });

    it('opens a day of the window when the address names one, and falls back when it names a day outside it', function () {
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->patient)
            ->get(route('my.follow-ups.book', ['followUp' => $followUp, 'date' => '2026-11-06']))
            ->assertInertia(fn (Assert $page) => $page->where('selectedDate', '2026-11-06'));

        // Monday 9 November is inside the 30 day booking window but outside this follow-up's window.
        $this->actingAs($this->patient)
            ->get(route('my.follow-ups.book', ['followUp' => $followUp, 'date' => '2026-11-09']))
            ->assertInertia(fn (Assert $page) => $page->where('selectedDate', '2026-11-02'));
    });

    it('shows no doctor for "Any doctor"', function () {
        $followUp = ($this->makeFollowUp)('anyDoctor');

        $this->actingAs($this->patient)
            ->get(route('my.follow-ups.book', $followUp))
            ->assertInertia(fn (Assert $page) => $page
                ->where('doctor', null)
                ->where('selectedDate', '2026-11-02')
                ->has('slots'));
    });

    it('sends the patient back to the list when the doctor is not taking bookings', function () {
        $followUp = ($this->makeFollowUp)();
        $this->doctor->user->forceFill(['is_active' => false])->save();

        $this->actingAs($this->patient)
            ->get(route('my.follow-ups.book', $followUp))
            ->assertRedirect(route('my.home'));
    });

    it('is a 404 when the visit type is switched off', function () {
        $followUp = ($this->makeFollowUp)();
        $this->service->update(['is_active' => false]);

        $this->actingAs($this->patient)->get(route('my.follow-ups.book', $followUp))->assertNotFound();
    });

    it('asks a guest to log in', function () {
        $this->get(route('my.follow-ups.book', ($this->makeFollowUp)()))->assertRedirect(route('login'));
    });

    it('refuses another patient, and a follow-up that is booked or dismissed', function () {
        $followUp = ($this->makeFollowUp)();

        $this->actingAs(User::factory()->patient()->create())->get(route('my.follow-ups.book', $followUp))->assertForbidden();

        foreach (['booked', 'dismissed'] as $state) {
            $this->actingAs($this->patient)->get(route('my.follow-ups.book', ($this->makeFollowUp)($state)))->assertForbidden();
        }
    });

    it('refuses staff, doctors and admins', function (Role $role) {
        $this->actingAs(userWithRole($role))->get(route('my.follow-ups.book', ($this->makeFollowUp)()))->assertForbidden();
    })->with([Role::Staff, Role::Doctor, Role::Admin]);
});

describe('the confirm page', function () {
    it('shows the visit, with the service and doctor of the follow-up', function () {
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->patient)
            ->get(fuConfirmUrl($followUp))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('booking/confirm')
                ->where('followUp.ulid', $followUp->ulid)
                ->where('service.slug', $this->service->slug)
                ->where('doctor.name', $this->doctor->user->name)
                ->where('startsAt', '2026-11-02T00:00:00Z')
                ->where('endsAt', '2026-11-02T01:00:00Z')
                // 2 hours before the start, the patient's own cancel limit.
                ->where('cancelUntil', '2026-11-01T22:00:00Z'));
    });

    it('shows no doctor for "Any doctor"', function () {
        $this->actingAs($this->patient)
            ->get(fuConfirmUrl(($this->makeFollowUp)('anyDoctor')))
            ->assertInertia(fn (Assert $page) => $page->where('doctor', null));
    });

    it('sends a time outside the window back to the time picker', function () {
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->patient)
            ->get(fuConfirmUrl($followUp, '2026-11-09 08:00'))
            ->assertRedirect(route('my.follow-ups.book', $followUp))
            ->assertSessionHasErrors(['starts_at' => 'Pick a time between 2 November and 6 November.']);
    });

    it('sends a time the slot finder does not offer back to the time picker', function () {
        $followUp = ($this->makeFollowUp)();

        // 03:00 is before the doctor's working hours.
        $this->actingAs($this->patient)
            ->get(fuConfirmUrl($followUp, '2026-11-02 03:00'))
            ->assertRedirect(route('my.follow-ups.book', $followUp))
            ->assertSessionHasErrors(['starts_at' => BookAppointment::TIME_TAKEN_MESSAGE]);
    });

    it('refuses another patient before it validates anything, so the window dates stay private', function () {
        $followUp = ($this->makeFollowUp)();

        // A time outside the window would give a message with the window's dates, if validation ran first.
        $this->actingAs(User::factory()->patient()->create())
            ->get(fuConfirmUrl($followUp, '2026-11-09 08:00'))
            ->assertForbidden()
            ->assertSessionHasNoErrors();
    });
});

describe('booking from a follow-up', function () {
    it('books the visit with the follow-up service and doctor, and links the follow-up', function () {
        Notification::fake();
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->patient)
            ->post(route('my.follow-ups.store', $followUp), ['starts_at' => fuStartsAt()])
            ->assertSessionHasNoErrors();

        $appointment = ($this->bookedVisits)()->sole();

        expect($appointment->doctor_id)->toBe($this->doctor->id)
            ->and($appointment->service_id)->toBe($this->service->id)
            ->and($appointment->starts_at->toIso8601ZuluString())->toBe('2026-11-02T00:00:00Z')
            ->and($followUp->fresh()->status)->toBe(FollowUpStatus::Booked)
            ->and($followUp->fresh()->booked_appointment_id)->toBe($appointment->id);

        // The booking is an ordinary one: it is audited and the patient gets the confirmation email.
        expect(AuditLog::query()->where('action', 'appointment.booked')->count())->toBe(1);
        Notification::assertSentTo($this->patient, AppointmentBooked::class);
    });

    it('goes to the "You\'re booked" page', function () {
        $followUp = ($this->makeFollowUp)();

        $response = $this->actingAs($this->patient)->post(route('my.follow-ups.store', $followUp), ['starts_at' => fuStartsAt()]);

        $response->assertRedirect(route('my.appointments.confirmation', ($this->bookedVisits)()->sole()));
    });

    it('books "Any doctor" with a doctor who is free then', function () {
        $followUp = ($this->makeFollowUp)('anyDoctor');
        $otherDoctor = doctorWorking($this->service, [[1, '08:00', '12:00']]);
        // The asking doctor is busy at 08:00 on Monday, so the other one takes it.
        bookingAt($this->doctor, $this->service, '2026-11-02 08:00');

        $this->actingAs($this->patient)
            ->post(route('my.follow-ups.store', $followUp), ['starts_at' => fuStartsAt()])
            ->assertSessionHasNoErrors();

        expect(($this->bookedVisits)()->sole()->doctor_id)->toBe($otherDoctor->id)
            ->and($followUp->fresh()->status)->toBe(FollowUpStatus::Booked);
    });

    it('takes the service and doctor from the follow-up, not from the request', function () {
        $followUp = ($this->makeFollowUp)();
        $otherService = Service::factory()->lasting(30)->create();
        $otherDoctor = doctorWorking($otherService, [[1, '08:00', '12:00']]);

        $this->actingAs($this->patient)->post(route('my.follow-ups.store', $followUp), [
            'starts_at' => fuStartsAt(),
            'doctor_id' => $otherDoctor->id,
            'service_id' => $otherService->id,
            'patient_id' => User::factory()->patient()->create()->id,
        ])->assertSessionHasNoErrors();

        $appointment = ($this->bookedVisits)()->sole();

        expect($appointment->doctor_id)->toBe($this->doctor->id)
            ->and($appointment->service_id)->toBe($this->service->id);
    });

    it('refuses a second submit of the same follow-up', function () {
        $followUp = ($this->makeFollowUp)();
        $this->actingAs($this->patient)->post(route('my.follow-ups.store', $followUp), ['starts_at' => fuStartsAt()]);

        $this->actingAs($this->patient)
            ->post(route('my.follow-ups.store', $followUp), ['starts_at' => fuStartsAt('2026-11-02 09:00')])
            ->assertForbidden();

        expect(($this->bookedVisits)()->count())->toBe(1);
    });

    it('refuses another patient and writes nothing', function () {
        $followUp = ($this->makeFollowUp)();

        $this->actingAs(User::factory()->patient()->create())
            ->post(route('my.follow-ups.store', $followUp), ['starts_at' => fuStartsAt()])
            ->assertForbidden();

        expect(Appointment::query()->where('status', AppointmentStatus::Booked)->count())->toBe(0)
            ->and($followUp->fresh()->status)->toBe(FollowUpStatus::Open);
    });

    it('refuses a follow-up that is already booked or dismissed', function (string $state) {
        $followUp = ($this->makeFollowUp)($state);
        $before = Appointment::query()->count();

        $this->actingAs($this->patient)
            ->post(route('my.follow-ups.store', $followUp), ['starts_at' => fuStartsAt()])
            ->assertForbidden();

        expect(Appointment::query()->count())->toBe($before);
    })->with(['booked', 'dismissed']);

    it('refuses staff, doctors and admins', function (Role $role) {
        $followUp = ($this->makeFollowUp)();

        $this->actingAs(userWithRole($role))
            ->post(route('my.follow-ups.store', $followUp), ['starts_at' => fuStartsAt()])
            ->assertForbidden();

        expect($followUp->fresh()->status)->toBe(FollowUpStatus::Open);
    })->with([Role::Staff, Role::Doctor, Role::Admin]);
});

describe('what the follow-up booking refuses', function () {
    it('needs a time in the right format', function (array $input) {
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->patient)
            ->post(route('my.follow-ups.store', $followUp), $input)
            ->assertRedirect(route('my.follow-ups.book', $followUp))
            ->assertSessionHasErrors('starts_at');

        expect($followUp->fresh()->status)->toBe(FollowUpStatus::Open);
    })->with([
        'missing' => [[]],
        'not a date' => [['starts_at' => 'tomorrow morning']],
        'with a time zone offset' => [['starts_at' => '2026-11-02T08:00:00+08:00']],
    ]);

    it('refuses a day outside the window, even one the slot finder offers', function () {
        $followUp = ($this->makeFollowUp)();

        // Monday 9 November is a working day inside the 30 day booking window.
        $this->actingAs($this->patient)
            ->post(route('my.follow-ups.store', $followUp), ['starts_at' => fuStartsAt('2026-11-09 08:00')])
            ->assertSessionHasErrors(['starts_at' => 'Pick a time between 2 November and 6 November.']);

        expect(($this->bookedVisits)()->count())->toBe(0)
            ->and($followUp->fresh()->status)->toBe(FollowUpStatus::Open);
    });

    it('refuses a time that was taken, and the follow-up stays open', function () {
        $followUp = ($this->makeFollowUp)();
        bookingAt($this->doctor, $this->service, '2026-11-02 08:00');

        $this->actingAs($this->patient)
            ->post(route('my.follow-ups.store', $followUp), ['starts_at' => fuStartsAt()])
            ->assertSessionHasErrors(['starts_at' => BookAppointment::TIME_TAKEN_MESSAGE]);

        expect(($this->bookedVisits)()->count())->toBe(0)
            ->and($followUp->fresh()->status)->toBe(FollowUpStatus::Open)
            ->and($followUp->fresh()->booked_appointment_id)->toBeNull();
    });

    it('refuses a fourth upcoming appointment, and the follow-up stays open', function () {
        $followUp = ($this->makeFollowUp)();
        Appointment::factory()->count(3)->for($this->patient, 'patient')->booked()->sequence(
            ['starts_at' => CarbonImmutable::parse('2026-11-10 09:00', 'Asia/Manila')->utc()],
            ['starts_at' => CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Manila')->utc()],
            ['starts_at' => CarbonImmutable::parse('2026-11-12 09:00', 'Asia/Manila')->utc()],
        )->create();

        $this->actingAs($this->patient)
            ->post(route('my.follow-ups.store', $followUp), ['starts_at' => fuStartsAt()])
            ->assertSessionHasErrors(['starts_at' => 'You can have at most 3 upcoming appointments. Cancel one before booking another.']);

        expect(($this->bookedVisits)()->count())->toBe(3)
            ->and($followUp->fresh()->status)->toBe(FollowUpStatus::Open)
            ->and($followUp->fresh()->booked_appointment_id)->toBeNull();
    });

    it('refuses a follow-up that was booked in another tab after this one was loaded', function () {
        $followUp = ($this->makeFollowUp)();
        // The copy a second request would have read before the first one booked.
        $stale = FollowUp::query()->findOrFail($followUp->id);
        ($this->bookIt)($followUp);

        $errors = fuErrors(fn () => ($this->bookIt)($stale, '2026-11-02 09:00'));

        expect($errors)->toBe(['follow_up' => ['This follow-up can no longer be booked.']])
            ->and(($this->bookedVisits)()->count())->toBe(1);
    });

    it('refuses a day outside the window when the Action is called without the Form Request', function () {
        $followUp = ($this->makeFollowUp)();

        $errors = fuErrors(fn () => ($this->bookIt)($followUp, '2026-11-09 08:00'));

        expect($errors)->toBe(['starts_at' => ['Pick a time between 2 November and 6 November.']])
            ->and($followUp->fresh()->status)->toBe(FollowUpStatus::Open);
    });
});

describe('when the follow-up booking is cancelled', function () {
    it('puts the follow-up back to open when the patient cancels it', function () {
        $followUp = ($this->makeFollowUp)();
        $appointment = ($this->bookIt)($followUp);

        $this->actingAs($this->patient)->post(route('my.appointments.cancel', $appointment))->assertRedirect(route('my.home'));

        expect($appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled)
            ->and($followUp->fresh()->status)->toBe(FollowUpStatus::Open)
            ->and($followUp->fresh()->booked_appointment_id)->toBeNull();
    });

    it('puts the follow-up back to open when staff cancel it', function () {
        Notification::fake();
        $followUp = ($this->makeFollowUp)();
        $appointment = ($this->bookIt)($followUp);

        app(CancelAppointment::class)->handle(User::factory()->staff()->create(), $appointment, 'The doctor is away.');

        expect($followUp->fresh()->status)->toBe(FollowUpStatus::Open)
            ->and($followUp->fresh()->booked_appointment_id)->toBeNull();
    });

    it('lets the patient book the reopened follow-up again', function () {
        $followUp = ($this->makeFollowUp)();
        $first = ($this->bookIt)($followUp);
        $this->actingAs($this->patient)->post(route('my.appointments.cancel', $first));

        $this->actingAs($this->patient)
            ->post(route('my.follow-ups.store', $followUp), ['starts_at' => fuStartsAt('2026-11-06 08:00')])
            ->assertSessionHasNoErrors();

        $second = ($this->bookedVisits)()->sole();

        expect($second->id)->not->toBe($first->id)
            ->and($followUp->fresh()->status)->toBe(FollowUpStatus::Booked)
            ->and($followUp->fresh()->booked_appointment_id)->toBe($second->id);
    });

    it('leaves a follow-up booked when a different appointment is cancelled', function () {
        $followUp = ($this->makeFollowUp)();
        ($this->bookIt)($followUp);
        $other = Appointment::factory()->for($this->patient, 'patient')->booked()->startingIn(hours: 72)->create();

        $this->actingAs($this->patient)->post(route('my.appointments.cancel', $other));

        expect($followUp->fresh()->status)->toBe(FollowUpStatus::Booked);
    });
});

describe('the edges of the follow-up window', function () {
    // The window is in clinic days (Asia/Manila). Manila is 8 hours ahead of UTC, so the first and last minute of a day
    // are on the other UTC date. A check on the UTC date would get exactly these four wrong.
    it('counts a time by its clinic day', function (string $clinicTime, bool $inside) {
        $followUp = FollowUp::make(['window_starts_on' => '2026-11-02', 'window_ends_on' => '2026-11-06']);

        $instant = CarbonImmutable::parse($clinicTime, 'Asia/Manila')->utc();

        expect($followUp->windowIncludes($instant))->toBe($inside);
    })->with([
        'last minute before the first day' => ['2026-11-01 23:59', false],
        'first minute of the first day' => ['2026-11-02 00:00', true],
        'last minute of the last day' => ['2026-11-06 23:59', true],
        'first minute after the last day' => ['2026-11-07 00:00', false],
    ]);

    it('lists a follow-up through the last day of its window, by the clinic date and not the UTC date', function () {
        // 23:30 on Friday 6 November in Manila is 15:30 UTC, still the 6th everywhere.
        $this->travelTo(CarbonImmutable::parse('2026-11-06 23:30', 'Asia/Manila'));
        $followUp = ($this->makeFollowUp)();

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page->where('followUps', fn ($rows): bool => collect($rows)->pluck('ulid')->all() === [$followUp->ulid]));

        // 06:00 on Saturday 7 November in Manila is 22:00 UTC on the 6th. The clinic day is the 7th, so the window is
        // over, even though the UTC date still says the 6th.
        $this->travelTo(CarbonImmutable::parse('2026-11-07 06:00', 'Asia/Manila'));

        $this->actingAs($this->patient)
            ->get(route('my.home'))
            ->assertInertia(fn (Assert $page) => $page->has('followUps', 0)->where('openFollowUps', 0));
    });

    it('offers only the days that are inside both the window and the 30 day booking window', function () {
        // The booking window ends on Sunday 29 November (30 days from Friday 30 October). The follow-up window runs past it.
        $followUp = ($this->makeFollowUp)(overrides: ['window_starts_on' => '2026-11-27', 'window_ends_on' => '2026-12-03']);

        $this->actingAs($this->patient)
            ->get(route('my.follow-ups.book', $followUp))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('days', fn ($days): bool => collect($days)->pluck('date')->all() === ['2026-11-27', '2026-11-28', '2026-11-29'])
                ->where('selectedDate', '2026-11-27'));
    });

    it('shows an empty picker, opened on the first day of the window, when the window has not reached the booking window yet', function () {
        $followUp = ($this->makeFollowUp)(overrides: ['window_starts_on' => '2026-12-20', 'window_ends_on' => '2026-12-24']);

        $this->actingAs($this->patient)
            ->get(route('my.follow-ups.book', $followUp))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('days', 0)
                ->where('selectedDate', '2026-12-20')
                ->has('slots', 0));
    });
});

describe('what stops a follow-up booking before the Action', function () {
    it('refuses an Action call by someone who may not book the follow-up, and writes nothing', function () {
        $followUp = ($this->makeFollowUp)();
        $stranger = User::factory()->patient()->create();

        expect(fn () => app(BookFollowUp::class)->handle($stranger, $followUp, CarbonImmutable::parse('2026-11-02 08:00', 'Asia/Manila')->utc()))
            ->toThrow(AuthorizationException::class);

        expect(Appointment::query()->where('status', AppointmentStatus::Booked)->count())->toBe(0)
            ->and($followUp->fresh()->status)->toBe(FollowUpStatus::Open);
    });

    it('refuses a booking with the asking doctor once that doctor is not taking bookings', function () {
        $followUp = ($this->makeFollowUp)();
        $this->doctor->user->forceFill(['is_active' => false])->save();

        $this->actingAs($this->patient)
            ->post(route('my.follow-ups.store', $followUp), ['starts_at' => fuStartsAt()])
            ->assertSessionHasErrors(['doctor_id' => 'This doctor is not taking bookings. Please call the clinic.']);

        expect(($this->bookedVisits)()->count())->toBe(0)
            ->and($followUp->fresh()->status)->toBe(FollowUpStatus::Open);
    });

    it('is a 404 on the booking too when the visit type is switched off, and writes nothing', function () {
        $followUp = ($this->makeFollowUp)();
        $this->service->update(['is_active' => false]);

        $this->actingAs($this->patient)
            ->post(route('my.follow-ups.store', $followUp), ['starts_at' => fuStartsAt()])
            ->assertNotFound();

        expect(($this->bookedVisits)()->count())->toBe(0)
            ->and($followUp->fresh()->status)->toBe(FollowUpStatus::Open);
    });
});

describe('the 2 hour cancel rule on a follow-up booking', function () {
    it('keeps the follow-up booked when the patient is too late to cancel', function () {
        $followUp = ($this->makeFollowUp)();
        $appointment = ($this->bookIt)($followUp);
        // One hour before the 08:00 visit.
        $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00', 'Asia/Manila'));

        $this->actingAs($this->patient)
            ->post(route('my.appointments.cancel', $appointment))
            ->assertSessionHasErrors('appointment');

        expect($appointment->fresh()->status)->toBe(AppointmentStatus::Booked)
            ->and($followUp->fresh()->status)->toBe(FollowUpStatus::Booked)
            ->and($followUp->fresh()->booked_appointment_id)->toBe($appointment->id);
    });

    it('reopens the follow-up when the patient cancels exactly 2 hours before', function () {
        $followUp = ($this->makeFollowUp)();
        $appointment = ($this->bookIt)($followUp);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00', 'Asia/Manila'));

        $this->actingAs($this->patient)->post(route('my.appointments.cancel', $appointment))->assertSessionHasNoErrors();

        expect($followUp->fresh()->status)->toBe(FollowUpStatus::Open)
            ->and($followUp->fresh()->booked_appointment_id)->toBeNull();
    });
});

describe('when staff move the follow-up booking', function () {
    it('keeps the follow-up booked and points it at the new appointment', function () {
        Notification::fake();
        $followUp = ($this->makeFollowUp)();
        $old = ($this->bookIt)($followUp);

        $new = app(MoveAppointment::class)->handle(
            User::factory()->staff()->create(),
            $old,
            $this->doctor,
            CarbonImmutable::parse('2026-11-02 09:00', 'Asia/Manila')->utc(),
        );

        expect($old->fresh()->status)->toBe(AppointmentStatus::Cancelled)
            ->and($followUp->fresh()->status)->toBe(FollowUpStatus::Booked)
            ->and($followUp->fresh()->booked_appointment_id)->toBe($new->id);
    });

    it('leaves the follow-up on the old appointment when the move is refused', function () {
        Notification::fake();
        $followUp = ($this->makeFollowUp)();
        $old = ($this->bookIt)($followUp);

        // 03:00 is not a time the doctor works, so the move is refused and rolled back.
        $errors = fuErrors(fn () => app(MoveAppointment::class)->handle(
            User::factory()->staff()->create(),
            $old,
            $this->doctor,
            CarbonImmutable::parse('2026-11-02 03:00', 'Asia/Manila')->utc(),
        ));

        expect($errors)->toHaveKey('starts_at')
            ->and($old->fresh()->status)->toBe(AppointmentStatus::Booked)
            ->and($followUp->fresh()->status)->toBe(FollowUpStatus::Booked)
            ->and($followUp->fresh()->booked_appointment_id)->toBe($old->id);
    });
});
