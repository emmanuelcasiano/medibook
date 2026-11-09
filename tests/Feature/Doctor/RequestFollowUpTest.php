<?php

declare(strict_types=1);

use App\Actions\Doctor\FollowUpData;
use App\Actions\Doctor\RequestFollowUp;
use App\Enums\AppointmentStatus;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

// "Ask the patient to come back" on "Finish visit" (step L.2): the doctor's finish request can carry a `follow_up`
// group, and the visit and the follow-up are saved together or not at all. The `follow_up.requested` audit event is tested in
// FollowUpAuditTest (step L.5), and the patient and staff screens are L.3 and L.4. "Now" is fixed at Monday 2 November 2026, 09:00 in
// Manila, so "today" for the clinic is 2026-11-02.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'Asia/Manila'));

    $this->doctorUser = userWithRole(Role::Doctor);
    $this->doctor = Doctor::query()->where('user_id', $this->doctorUser->id)->firstOrFail();

    // A service this doctor offers. The appointment's own service is a different one: the follow-up may be for another visit type.
    $this->service = Service::factory()->create();
    $this->doctor->services()->attach($this->service);

    $this->booking = fn (?Doctor $doctor = null, string $state = 'checkedIn'): Appointment => Appointment::factory()
        ->for($doctor ?? $this->doctor)
        ->for(User::factory()->patient()->create(), 'patient')
        ->{$state}()
        ->create(['starts_at' => CarbonImmutable::parse('2026-11-02 10:00', 'Asia/Manila')->utc()]);

    // A valid follow-up group. $overrides replaces single keys.
    $this->followUp = fn (array $overrides = []): array => $overrides + [
        'service_id' => $this->service->id,
        'starts_on' => '2026-11-14',
        'ends_on' => '2026-11-18',
        'same_doctor' => '1',
        'note' => 'Come back to check the rash.',
    ];

    $this->finish = fn (Appointment $appointment, array $payload = [], ?User $as = null) => $this->actingAs($as ?? $this->doctorUser)
        ->post(route('doctor.appointments.complete', $appointment), $payload);

    $this->finishWithFollowUp = fn (Appointment $appointment, array $overrides = [], ?User $as = null) => ($this->finish)(
        $appointment,
        ['follow_up' => ($this->followUp)($overrides)],
        $as,
    );
});

describe('asking the patient to come back', function () {
    it('finishes the visit and saves an open follow-up for the same patient and doctor', function () {
        $appointment = ($this->booking)();

        ($this->finishWithFollowUp)($appointment)->assertRedirect(route('doctor.appointments.show', $appointment));

        $followUp = FollowUp::query()->sole();

        expect($appointment->fresh()->status)->toBe(AppointmentStatus::Completed)
            ->and($followUp->patient_id)->toBe($appointment->patient_id)
            ->and($followUp->doctor_id)->toBe($this->doctor->id)
            ->and($followUp->service_id)->toBe($this->service->id)
            ->and($followUp->source_appointment_id)->toBe($appointment->id)
            ->and($followUp->booked_appointment_id)->toBeNull()
            ->and($followUp->window_starts_on->toDateString())->toBe('2026-11-14')
            ->and($followUp->window_ends_on->toDateString())->toBe('2026-11-18')
            ->and($followUp->same_doctor)->toBeTrue()
            ->and($followUp->status)->toBe(FollowUpStatus::Open)
            ->and($followUp->note)->toBe('Come back to check the rash.');
    });

    it('keeps the note encrypted in the database', function () {
        ($this->finishWithFollowUp)(($this->booking)());

        $stored = DB::table('follow_ups')->value('note');

        expect($stored)->not->toBe('Come back to check the rash.')
            ->and($stored)->not->toContain('rash');
    });

    it('saves "any doctor" as same_doctor false, and an empty note as null', function () {
        ($this->finishWithFollowUp)(($this->booking)(), ['same_doctor' => '0', 'note' => '   ']);

        $followUp = FollowUp::query()->sole();

        expect($followUp->same_doctor)->toBeFalse()
            ->and($followUp->note)->toBeNull();
    });

    it('accepts a window that starts today and ends on the last allowed day', function () {
        ($this->finishWithFollowUp)(($this->booking)(), ['starts_on' => '2026-11-02', 'ends_on' => '2027-01-01'])
            ->assertSessionHasNoErrors();

        expect(FollowUp::query()->count())->toBe(1);
    });

    it('still finishes a visit with no follow-up and saves none', function () {
        $appointment = ($this->booking)();

        ($this->finish)($appointment)->assertSessionHasNoErrors();

        expect($appointment->fresh()->status)->toBe(AppointmentStatus::Completed)
            ->and(FollowUp::query()->count())->toBe(0);
    });

    it('takes the patient, doctor and status from the visit and ignores what the request says', function () {
        $appointment = ($this->booking)();
        $stranger = User::factory()->patient()->create();
        $other = Appointment::factory()->booked()->create();

        ($this->finish)($appointment, [
            'status' => 'booked',
            'patient_id' => $stranger->id,
            'follow_up' => ($this->followUp)([
                'patient_id' => $stranger->id,
                'doctor_id' => Doctor::factory()->create()->id,
                'source_appointment_id' => $other->id,
                'booked_appointment_id' => $other->id,
                'status' => 'dismissed',
            ]),
        ])->assertSessionHasNoErrors();

        $followUp = FollowUp::query()->sole();

        expect($appointment->fresh()->status)->toBe(AppointmentStatus::Completed)
            ->and($followUp->patient_id)->toBe($appointment->patient_id)
            ->and($followUp->doctor_id)->toBe($this->doctor->id)
            ->and($followUp->source_appointment_id)->toBe($appointment->id)
            ->and($followUp->booked_appointment_id)->toBeNull()
            ->and($followUp->status)->toBe(FollowUpStatus::Open);
    });
});

describe('the appointment page', function () {
    it('offers only this doctor\'s active services, and only while the button is shown', function () {
        // One service this doctor does not offer and one they offer but is switched off: neither is listed.
        Service::factory()->create();
        $this->doctor->services()->attach(Service::factory()->inactive()->create());
        $appointment = ($this->booking)();

        $this->actingAs($this->doctorUser)
            ->get(route('doctor.appointments.show', $appointment))
            ->assertInertia(fn (Assert $page) => $page
                ->has('followUpServices', 1)
                ->where('followUpServices.0.id', $this->service->id)
                ->where('followUpServices.0.name', $this->service->name)
                ->where('followUpServices.0.durationMinutes', 30)
                ->where('appointment.followUp', null)
                ->etc());

        ($this->finish)($appointment);

        $this->actingAs($this->doctorUser)
            ->get(route('doctor.appointments.show', $appointment))
            ->assertInertia(fn (Assert $page) => $page->where('followUpServices', [])->etc());
    });

    it('says a follow-up was requested, without the note', function () {
        $appointment = ($this->booking)();
        ($this->finishWithFollowUp)($appointment);

        $this->actingAs($this->doctorUser)
            ->get(route('doctor.appointments.show', $appointment))
            ->assertInertia(fn (Assert $page) => $page
                ->where('appointment.followUp', [
                    'statusLabel' => 'Open',
                    'startsOn' => '2026-11-14',
                    'endsOn' => '2026-11-18',
                    'sameDoctor' => true,
                ])
                ->etc())
            ->assertDontSee('check the rash');
    });
});

describe('a follow-up that is refused', function () {
    it('answers with the error on the field, finishes nothing and saves nothing', function (array $overrides, string $field) {
        $appointment = ($this->booking)();

        ($this->finishWithFollowUp)($appointment, $overrides)->assertSessionHasErrors($field);

        expect($appointment->fresh()->status)->toBe(AppointmentStatus::CheckedIn)
            ->and(FollowUp::query()->count())->toBe(0);
    })->with([
        'no service' => [['service_id' => ''], 'follow_up.service_id'],
        'service is not a number' => [['service_id' => 'abc'], 'follow_up.service_id'],
        'no first day' => [['starts_on' => ''], 'follow_up.starts_on'],
        'first day is not a date' => [['starts_on' => '14/11/2026'], 'follow_up.starts_on'],
        'first day does not exist' => [['starts_on' => '2026-02-30'], 'follow_up.starts_on'],
        'no last day' => [['ends_on' => ''], 'follow_up.ends_on'],
        'last day before the first day' => [['starts_on' => '2026-11-18', 'ends_on' => '2026-11-14'], 'follow_up.ends_on'],
        'no choice of doctor' => [['same_doctor' => ''], 'follow_up.same_doctor'],
        'choice of doctor is not a yes or no' => [['same_doctor' => 'maybe'], 'follow_up.same_doctor'],
        'note over 200 characters' => [['note' => str_repeat('a', 201)], 'follow_up.note'],
        'first day in the past' => [['starts_on' => '2026-11-01', 'ends_on' => '2026-11-05'], 'follow_up.starts_on'],
        'last day more than 60 days away' => [['starts_on' => '2026-11-14', 'ends_on' => '2027-01-02'], 'follow_up.ends_on'],
    ]);

    // A refused form is sent back with the input flashed to the session ("old input"). The sessions table is not
    // encrypted and the note is health text, so none of it may go there. The dialog does not need it: the typed values
    // stay in the form.
    it('does not keep the typed follow-up in the session', function (string $key) {
        ($this->finishWithFollowUp)(($this->booking)(), ['ends_on' => 'not-a-date', 'note' => 'Secret health note'])
            ->assertSessionHasErrors('follow_up.ends_on')
            ->assertSessionMissing("_old_input.$key");
    })->with(['follow_up', 'follow_up.note']);

    it('refuses a service this doctor does not offer, or one that is switched off', function (string $kind) {
        $serviceId = match ($kind) {
            'not offered' => Service::factory()->create()->id,
            'switched off' => tap(Service::factory()->inactive()->create(), fn (Service $service) => $this->doctor->services()->attach($service))->id,
            'missing' => 999_999,
        };
        $appointment = ($this->booking)();

        ($this->finishWithFollowUp)($appointment, ['service_id' => $serviceId])->assertSessionHasErrors([
            'follow_up.service_id' => 'Pick a visit type from the list.',
        ]);

        expect($appointment->fresh()->status)->toBe(AppointmentStatus::CheckedIn)
            ->and(FollowUp::query()->count())->toBe(0);
    })->with(['not offered', 'switched off', 'missing']);

    it('says nothing about the follow-up when the group is missing, and does not ask for its fields', function () {
        $appointment = ($this->booking)();

        ($this->finish)($appointment, ['follow_up' => []])->assertSessionHasNoErrors();

        expect($appointment->fresh()->status)->toBe(AppointmentStatus::Completed)
            ->and(FollowUp::query()->count())->toBe(0);
    });

    it('asks for every field when the group is sent empty', function () {
        $appointment = ($this->booking)();

        ($this->finish)($appointment, ['follow_up' => ['note' => '']])->assertSessionHasErrors([
            'follow_up.service_id' => 'Pick a visit type.',
            'follow_up.starts_on' => 'Pick the first day.',
            'follow_up.ends_on' => 'Pick the last day.',
            'follow_up.same_doctor' => 'Choose who the patient should see.',
        ]);

        expect($appointment->fresh()->status)->toBe(AppointmentStatus::CheckedIn);
    });

    it('refuses a second follow-up for the same visit', function () {
        $appointment = ($this->booking)(state: 'completed');
        FollowUp::factory()->create(['source_appointment_id' => $appointment->id, 'service_id' => $this->service->id]);

        expect(fn () => app(RequestFollowUp::class)->handle($this->doctorUser, $appointment, new FollowUpData(
            serviceId: $this->service->id,
            startsOn: CarbonImmutable::parse('2026-11-14', 'Asia/Manila'),
            endsOn: CarbonImmutable::parse('2026-11-18', 'Asia/Manila'),
            sameDoctor: true,
            note: null,
        )))->toThrow(ValidationException::class, 'A follow-up was already requested for this visit.')
            ->and(FollowUp::query()->count())->toBe(1);
    });

    it('refuses a visit that is not finished, reading the status again under the lock', function (string $state) {
        $appointment = ($this->booking)(state: $state);

        expect(fn () => app(RequestFollowUp::class)->handle($this->doctorUser, $appointment, new FollowUpData(
            serviceId: $this->service->id,
            startsOn: CarbonImmutable::parse('2026-11-14', 'Asia/Manila'),
            endsOn: CarbonImmutable::parse('2026-11-18', 'Asia/Manila'),
            sameDoctor: true,
            note: null,
        )))->toThrow(ValidationException::class, 'A follow-up can be requested once the visit is finished.')
            ->and(FollowUp::query()->count())->toBe(0);
    })->with(['booked', 'checkedIn', 'cancelled', 'noShow']);
});

describe('the window at the edges of the clinic day', function () {
    // 00:30 on Tuesday 3 November in Manila is still 2 November in UTC. "Today" is the clinic's day, not UTC's.
    it('counts today in clinic time, not UTC, for the first and the last allowed day', function (string $startsOn, string $endsOn, bool $accepted) {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 00:30', 'Asia/Manila'));
        $appointment = ($this->booking)();

        $response = ($this->finishWithFollowUp)($appointment, ['starts_on' => $startsOn, 'ends_on' => $endsOn]);

        if ($accepted) {
            $response->assertSessionHasNoErrors();
        } else {
            $response->assertSessionHasErrors();
        }

        expect(FollowUp::query()->count())->toBe($accepted ? 1 : 0);
    })->with([
        'UTC today is already past in Manila' => ['2026-11-02', '2026-11-05', false],
        'Manila today' => ['2026-11-03', '2026-11-05', true],
        'last day is 60 days from Manila today' => ['2026-11-10', '2027-01-02', true],
        'one day past it' => ['2026-11-10', '2027-01-03', false],
    ]);
});

describe('the finish and the follow-up as one unit', function () {
    it('leaves no completed audit row behind when the follow-up is refused', function () {
        $appointment = ($this->booking)();

        ($this->finishWithFollowUp)($appointment, ['service_id' => Service::factory()->create()->id])->assertSessionHasErrors('follow_up.service_id');

        expect(AuditLog::query()->where('action', 'appointment.completed')->count())->toBe(0);

        ($this->finishWithFollowUp)($appointment)->assertSessionHasNoErrors();

        expect(AuditLog::query()->where('action', 'appointment.completed')->count())->toBe(1);
    });

    it('reads the status again, so a stale model can not ask for a follow-up on a visit that was cancelled', function () {
        $appointment = ($this->booking)(state: 'completed');
        $stale = Appointment::query()->findOrFail($appointment->id);

        $appointment->update(['status' => AppointmentStatus::Cancelled]);

        expect(fn () => app(RequestFollowUp::class)->handle($this->doctorUser, $stale, new FollowUpData(
            serviceId: $this->service->id,
            startsOn: CarbonImmutable::parse('2026-11-14', 'Asia/Manila'),
            endsOn: CarbonImmutable::parse('2026-11-18', 'Asia/Manila'),
            sameDoctor: true,
            note: null,
        )))->toThrow(ValidationException::class)
            ->and(FollowUp::query()->count())->toBe(0);
    });

    // One PHP process can't truly race itself, so this checks the row is locked inside the transaction, before the
    // "one follow-up per visit" check. A lock outside the transaction would be released at once and protect nothing.
    it('locks the appointment row inside the transaction', function () {
        $appointment = ($this->booking)(state: 'completed');

        $locks = [];
        DB::listen(function ($query) use (&$locks): void {
            if (str_contains($query->sql, 'from `appointments`') && str_contains($query->sql, 'for update')) {
                $locks[] = DB::transactionLevel();
            }
        });

        app(RequestFollowUp::class)->handle($this->doctorUser, $appointment, new FollowUpData(
            serviceId: $this->service->id,
            startsOn: CarbonImmutable::parse('2026-11-14', 'Asia/Manila'),
            endsOn: CarbonImmutable::parse('2026-11-18', 'Asia/Manila'),
            sameDoctor: true,
            note: null,
        ));

        // The test itself runs in one transaction (RefreshDatabase), so the Action's transaction is a level deeper.
        expect($locks)->toHaveCount(1)
            ->and($locks[0])->toBeGreaterThan(1);
    });
});

describe('denied access', function () {
    it('answers 403 when another doctor sends the follow-up, and saves nothing', function () {
        $theirs = ($this->booking)(Doctor::factory()->create());

        ($this->finishWithFollowUp)($theirs)->assertForbidden();

        expect($theirs->fresh()->status)->toBe(AppointmentStatus::CheckedIn)
            ->and(FollowUp::query()->count())->toBe(0);
    });

    it('answers 403 to staff, admins and patients, and saves nothing', function (Role $role) {
        $appointment = ($this->booking)();

        ($this->finishWithFollowUp)($appointment, as: userWithRole($role))->assertForbidden();

        expect($appointment->fresh()->status)->toBe(AppointmentStatus::CheckedIn)
            ->and(FollowUp::query()->count())->toBe(0);
    })->with([
        'staff' => Role::Staff,
        'admin' => Role::Admin,
        'nurse' => Role::Nurse,
        'patient' => Role::Patient,
    ]);

    it('answers 403 to the patient of the visit', function () {
        $appointment = ($this->booking)();

        ($this->finishWithFollowUp)($appointment, as: $appointment->patient)->assertForbidden();

        expect(FollowUp::query()->count())->toBe(0);
    });

    it('lets only the visit\'s own doctor ask, in the Policy and in the Action', function () {
        $appointment = ($this->booking)(state: 'completed');
        $data = new FollowUpData(
            serviceId: $this->service->id,
            startsOn: CarbonImmutable::parse('2026-11-14', 'Asia/Manila'),
            endsOn: CarbonImmutable::parse('2026-11-18', 'Asia/Manila'),
            sameDoctor: true,
            note: null,
        );

        foreach ([userWithRole(Role::Doctor), userWithRole(Role::Staff), userWithRole(Role::Admin), userWithRole(Role::Patient)] as $stranger) {
            expect(Gate::forUser($stranger)->allows('requestFollowUp', $appointment))->toBeFalse()
                ->and(fn () => app(RequestFollowUp::class)->handle($stranger, $appointment, $data))->toThrow(AuthorizationException::class);
        }

        expect(Gate::forUser($this->doctorUser)->allows('requestFollowUp', $appointment))->toBeTrue()
            ->and(FollowUp::query()->count())->toBe(0);
    });
});
