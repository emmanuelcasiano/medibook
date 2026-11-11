<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\SummaryGap;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\Intake;
use App\Models\IntakeSummary;
use App\Models\User;
use App\Models\VitalsRecord;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

// The doctor's appointment page (step 10.2): first name and age, the raw intake, and the AI draft next to it. The page
// takes no input, so there is no validation test: the appointment comes from the URL and the Policy decides. Who may
// open it is in the "denied access" group. The full role matrix of IntakePolicy is in HealthDataPoliciesTest; here
// each role is tried once through the route, to prove the controller calls the Policy. "Mark reviewed" is tested in
// MarkSummaryReviewedTest (step 10.3). Visit notes and "Finish visit" are steps 10.4 and 10.5, and the view audit rows
// (`intake.viewed` and the others) are tested in tests/Feature/Audit/ViewAuditEventsTest.php (step 11.3).
// The Vitals card (nurse vitals, part 3) is read-only too, so it has no validation test either. The vitals Policy matrix
// is in VitalsPolicyTest, and the `vitals.viewed` rows are in ViewAuditEventsTest.
// The AI label ("AI draft. The doctor must review.") is React, so it is checked in the browser, not here.
// "Now" is fixed at Monday 2 November 2026, 09:00 in Manila.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'Asia/Manila'));

    $this->doctorUser = userWithRole(Role::Doctor);
    $this->doctor = Doctor::query()->where('user_id', $this->doctorUser->id)->firstOrFail();

    // A booking for the signed-in doctor, or for $doctor. $patient defaults to a new patient with a birth date.
    $this->booking = fn (?User $patient = null, ?Doctor $doctor = null, array $attributes = []): Appointment => Appointment::factory()
        ->for($doctor ?? $this->doctor)
        ->for($patient ?? User::factory()->patient()->create(), 'patient')
        ->booked()
        ->create($attributes + ['starts_at' => CarbonImmutable::parse('2026-11-02 10:00', 'Asia/Manila')->utc()]);

    $this->open = fn (Appointment $appointment) => $this->actingAs($this->doctorUser)
        ->get(route('doctor.appointments.show', $appointment));
});

describe('the page', function () {
    it('shows the doctor the patient, the raw intake and the AI draft', function () {
        $patient = User::factory()->patient()->create(['name' => 'Maria Santos', 'birth_date' => '1990-05-20']);
        $appointment = ($this->booking)($patient);
        $appointment->service->update(['name' => 'Skin check']);
        $intake = Intake::factory()->for($appointment)->create([
            'answers' => [
                'reason' => 'Skin rash on my arm',
                'symptoms' => 'Itchy since Friday',
                'duration' => 'few_days',
                'allergies' => 'Penicillin',
                'medicines' => 'None',
                'fever' => true,
                'pregnant' => false,
                'recent_surgery' => false,
            ],
            // The column holds UTC and the model writes a date as it is, so convert a clinic time first.
            'submitted_at' => CarbonImmutable::parse('2026-11-01 18:00', 'Asia/Manila')->utc(),
        ]);
        $summary = IntakeSummary::factory()->for($intake)->create([
            'summary' => [
                'headline' => 'Itchy rash on the arm since Friday.',
                'timeline' => ['Started on Friday'],
                'in_english' => 'Itchy skin on the arm: "makati ang braso ko".',
                'gaps' => ['severity', 'earlier_episodes'],
                'check_with_patient' => ["Fever is ticked 'yes', but the symptoms do not mention a fever."],
            ],
            'model' => 'claude-haiku-5-5',
        ]);

        // Strict mode is on, so a lazy load anywhere on this page would throw instead of rendering.
        ($this->open)($appointment)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('doctor/appointments/show')
                ->where('appointment', [
                    'ulid' => $appointment->ulid,
                    'startsAt' => '2026-11-02T02:00:00Z',
                    'endsAt' => $appointment->ends_at->toIso8601ZuluString(),
                    'status' => 'booked',
                    'statusLabel' => 'Booked',
                    'patientFirstName' => 'Maria',
                    'patientAge' => 36,
                    'serviceName' => 'Skin check',
                    'emergency' => false,
                    // A booking that has not been checked in can not be finished yet.
                    'can' => ['complete' => false],
                    // No follow-up was asked for. The follow-up cases are in RequestFollowUpTest.
                    'followUp' => null,
                ])
                ->where('intake', [
                    'answers' => [
                        'reason' => 'Skin rash on my arm',
                        'symptoms' => 'Itchy since Friday',
                        'duration' => 'few_days',
                        'allergies' => 'Penicillin',
                        'medicines' => 'None',
                        'fever' => true,
                        'pregnant' => false,
                        'recent_surgery' => false,
                    ],
                    'durationLabel' => 'A few days',
                    'submittedAt' => '2026-11-01T10:00:00Z',
                ])
                ->where('summary', [
                    'status' => 'ready',
                    'statusLabel' => 'Ready',
                    'summary' => [
                        'headline' => 'Itchy rash on the arm since Friday.',
                        'timeline' => ['Started on Friday'],
                        'inEnglish' => 'Itchy skin on the arm: "makati ang braso ko".',
                        // The page gets a fixed label per key, never the model's own words.
                        'gaps' => [
                            ['key' => 'severity', 'label' => 'How bad it is'],
                            ['key' => 'earlier_episodes', 'label' => 'Earlier episodes'],
                        ],
                        'checkWithPatient' => ["Fever is ticked 'yes', but the symptoms do not mention a fever."],
                    ],
                    'model' => 'claude-haiku-5-5',
                    'reviewedAt' => null,
                    'version' => $summary->version(),
                    'can' => ['review' => true],
                ]));
    });

    it('never sends the birth date, the surname or the email, not even in the page source', function () {
        $patient = User::factory()->patient()->create([
            'name' => 'Maria Santos',
            'email' => 'maria.santos@example.test',
            'birth_date' => '1990-05-20',
        ]);
        $appointment = ($this->booking)($patient);
        Intake::factory()->for($appointment)->create();

        ($this->open)($appointment)
            ->assertOk()
            ->assertDontSee('1990-05-20')
            ->assertDontSee('Santos')
            ->assertDontSee('maria.santos@example.test');
    });

    it('works out the age on the clinic calendar', function (?string $birthDate, string $now, ?int $age) {
        $this->travelTo(CarbonImmutable::parse($now, 'Asia/Manila'));
        $appointment = ($this->booking)(User::factory()->patient()->create(['birth_date' => $birthDate]));

        ($this->open)($appointment)
            ->assertInertia(fn (Assert $page) => $page->where('appointment.patientAge', $age));
    })->with([
        'the day before the birthday' => ['1990-11-03', '2026-11-02 09:00', 35],
        'on the birthday' => ['1990-11-02', '2026-11-02 09:00', 36],
        // 00:30 on 2 November in Manila is still 1 November in UTC. The Manila date decides.
        'on the birthday, just after midnight in Manila' => ['1990-11-02', '2026-11-02 00:30', 36],
        'the last minute before the birthday in Manila' => ['1990-11-02', '2026-11-01 23:59', 35],
        'a baby under one year' => ['2026-01-01', '2026-11-02 09:00', 0],
        'a birth date in the future (bad data)' => ['2027-01-01', '2026-11-02 09:00', 0],
        'no birth date' => [null, '2026-11-02 09:00', null],
    ]);

    it('says "how long" in words, and shows a value it does not know as not given', function (?string $duration, ?string $label) {
        $appointment = ($this->booking)();
        Intake::factory()->for($appointment)->create([
            'answers' => ['reason' => 'Cough', 'symptoms' => null, 'duration' => $duration, 'allergies' => null, 'medicines' => null,
                'fever' => false, 'pregnant' => false, 'recent_surgery' => false],
        ]);

        ($this->open)($appointment)
            ->assertInertia(fn (Assert $page) => $page->where('intake.durationLabel', $label));
    })->with([
        'a known choice' => ['month_or_more', 'A month or more'],
        'left blank' => [null, null],
        'a choice that was removed since' => ['two_years', null],
    ]);

    it('sends only the listed answer fields, so a stray stored key never reaches the page', function () {
        $appointment = ($this->booking)();
        $intake = Intake::factory()->for($appointment)->create([
            'answers' => ['reason' => 'Cough', 'internal_note' => 'STRAY-ANSWER-KEY'],
        ]);
        IntakeSummary::factory()->for($intake)->create([
            'summary' => ['headline' => 'Cough', 'timeline' => [], 'in_english' => null, 'gaps' => [],
                'check_with_patient' => [], 'diagnosis' => 'STRAY-SUMMARY-KEY'],
        ]);

        ($this->open)($appointment)
            ->assertOk()
            // An older or odd row still opens: the missing answers become empty, not an error.
            ->assertInertia(fn (Assert $page) => $page
                ->where('intake.answers.reason', 'Cough')
                ->where('intake.answers.symptoms', null)
                ->where('intake.answers.fever', false))
            ->assertDontSee('STRAY-ANSWER-KEY')
            ->assertDontSee('STRAY-SUMMARY-KEY');
    });

    it('sends one fixed label for each gap key, and leaves out a stored key that is no longer a gap', function () {
        $appointment = ($this->booking)();
        $intake = Intake::factory()->for($appointment)->create();
        IntakeSummary::factory()->for($intake)->create([
            'summary' => ['headline' => 'Cough', 'timeline' => [], 'in_english' => null, 'check_with_patient' => [],
                'gaps' => [...array_column(SummaryGap::cases(), 'value'), 'a_gap_that_was_removed']],
        ]);

        ($this->open)($appointment)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('summary.summary.gaps', function ($gaps): bool {
                $gaps = collect($gaps);

                return $gaps->pluck('key')->all() === array_column(SummaryGap::cases(), 'value')
                    && $gaps->pluck('label')->every(fn ($label): bool => is_string($label) && $label !== '')
                    && $gaps->pluck('label')->unique()->count() === count(SummaryGap::cases());
            }))
            ->assertDontSee('a_gap_that_was_removed');
    });

    it('sends the emergency flag as a yes or no', function () {
        $appointment = ($this->booking)(attributes: ['emergency_flag' => true]);

        ($this->open)($appointment)
            ->assertInertia(fn (Assert $page) => $page->where('appointment.emergency', true));
    });
});

describe('the intake and the summary states', function () {
    it('has no intake and no summary before the patient fills in the form', function () {
        ($this->open)(($this->booking)())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('intake', null)->where('summary', null));
    });

    it('still shows the raw answers when the summary is not ready', function (string $state, ?string $status) {
        $appointment = ($this->booking)();
        $intake = Intake::factory()->for($appointment)->create(['answers' => [
            'reason' => 'Headache for several days', 'symptoms' => null, 'duration' => null, 'allergies' => null,
            'medicines' => null, 'fever' => false, 'pregnant' => false, 'recent_surgery' => false,
        ]]);

        if ($state !== 'none') {
            IntakeSummary::factory()->for($intake)->{$state}()->create();
        }

        ($this->open)($appointment)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('intake.answers.reason', 'Headache for several days')
                ->where('summary', $status === null ? null : fn ($summary) => $summary['status'] === $status && $summary['summary'] === null));
    })->with([
        'an intake with no summary row' => ['none', null],
        'a pending summary' => ['pending', 'pending'],
        'a failed summary' => ['failed', 'failed'],
        'a skipped summary' => ['skipped', 'skipped'],
    ]);

    it('sends the review time of a reviewed summary', function () {
        $appointment = ($this->booking)();
        $summary = IntakeSummary::factory()->for(Intake::factory()->for($appointment))->create();
        // The review columns are guarded (only MarkSummaryReviewed sets them), so the test forces them.
        $summary->forceFill(['reviewed_by' => $this->doctorUser->id, 'reviewed_at' => now()])->save();

        ($this->open)($appointment)
            ->assertInertia(fn (Assert $page) => $page->where('summary.reviewedAt', '2026-11-02T01:00:00Z'));
    });

    it('never sends the tokens or the error of the summary', function () {
        $appointment = ($this->booking)();
        IntakeSummary::factory()->for(Intake::factory()->for($appointment))->failed()->create(['error' => 'App\\Exceptions\\SecretFailure']);

        ($this->open)($appointment)
            ->assertOk()
            ->assertDontSee('SecretFailure')
            ->assertInertia(fn (Assert $page) => $page
                ->missing('summary.error')
                ->missing('summary.input_tokens')
                ->missing('summary.output_tokens'));
    });
});

describe('the vitals card', function () {
    // Readings that can't turn up by chance in a page: three decimals-or-large values, and key names only vitals have.
    $readings = ['systolic' => 187, 'diastolic' => 121, 'pulse' => 143, 'temperature' => 39.4, 'oxygen_saturation' => 91, 'weight' => 83.7];

    it('gives the treating doctor the readings, the nurse who took them and when', function () use ($readings) {
        $appointment = ($this->booking)(attributes: ['status' => 'checked_in']);
        VitalsRecord::factory()->forAppointment($appointment)
            ->for(User::factory()->nurse()->create(['name' => 'Nora Reyes']), 'recordedBy')
            ->create(['readings' => $readings, 'recorded_at' => CarbonImmutable::parse('2026-11-02 08:40', 'Asia/Manila')->utc()]);

        // Exactly these three keys: no nurse id or email, no record id, no appointment id.
        ($this->open)($appointment)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('vitals', [
                'readings' => $readings,
                'recordedByName' => 'Nora Reyes',
                'recordedAt' => '2026-11-02T00:40:00Z',
            ]));
    });

    it('sends null when the nurse has not recorded vitals yet', function () {
        ($this->open)(($this->booking)(attributes: ['status' => 'checked_in']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('vitals', null));
    });

    it('still shows the readings after the visit is finished', function () use ($readings) {
        $appointment = ($this->booking)(attributes: ['status' => 'completed']);
        VitalsRecord::factory()->forAppointment($appointment)->create(['readings' => $readings]);

        ($this->open)($appointment)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('vitals.readings', $readings));
    });

    it('never shows the doctor another appointment\'s readings', function () use ($readings) {
        $mine = ($this->booking)(attributes: ['status' => 'checked_in']);
        // Another doctor's patient, so it can't take the same slot.
        VitalsRecord::factory()->forAppointment(($this->booking)(null, Doctor::factory()->create(), ['status' => 'checked_in']))->create(['readings' => $readings]);

        ($this->open)($mine)
            ->assertInertia(fn (Assert $page) => $page->where('vitals', null))
            ->assertDontSee('39.4');
    });

    it('shows the corrected readings after the nurse fixes a typo', function () use ($readings) {
        $appointment = ($this->booking)(attributes: ['status' => 'checked_in']);
        $record = VitalsRecord::factory()->forAppointment($appointment)->create(['readings' => $readings]);
        $record->update(['readings' => [...$readings, 'pulse' => 88]]);

        ($this->open)($appointment)
            ->assertInertia(fn (Assert $page) => $page->where('vitals.readings.pulse', 88));
    });
});

describe('denied access', function () {
    it('answers 403 to every role but the treating doctor, and sends no reading', function (string $who) {
        $appointment = ($this->booking)(attributes: ['status' => 'checked_in']);
        // A nurse who can sign in (the role routes need 2FA), who is the one that recorded the readings.
        $nurse = userWithRole(Role::Nurse);
        VitalsRecord::factory()->forAppointment($appointment)->for($nurse, 'recordedBy')->create([
            'readings' => ['systolic' => 187, 'diastolic' => 121, 'pulse' => 143, 'temperature' => 39.4, 'oxygen_saturation' => 91, 'weight' => 83.7],
        ]);

        $user = match ($who) {
            'the nurse who took them' => $nurse,
            'another nurse' => userWithRole(Role::Nurse),
            'another doctor' => userWithRole(Role::Doctor),
            'the patient' => $appointment->patient,
            'staff' => userWithRole(Role::Staff),
            'admin' => userWithRole(Role::Admin),
        };

        // A refused request answers with the error page, which has no page props. So the check is that nothing of the
        // readings (the numbers or the key names) is anywhere in the body, and that no view row was written.
        $this->actingAs($user)
            ->get(route('doctor.appointments.show', $appointment))
            ->assertForbidden()
            ->assertDontSee('39.4')
            ->assertDontSee('83.7')
            ->assertDontSee('systolic')
            ->assertDontSee('oxygen_saturation');

        expect(AuditLog::query()->where('action', 'vitals.viewed')->count())->toBe(0);
    })->with([
        'the nurse who took them',
        'another nurse',
        'another doctor',
        'the patient',
        'staff',
        'admin',
    ]);

    it('answers 403 when a doctor opens another doctor\'s appointment, and sends none of its content', function () {
        $theirs = ($this->booking)(User::factory()->patient()->create(['name' => 'Rosa Dimaculangan']), Doctor::factory()->create());
        Intake::factory()->for($theirs)->create(['answers' => ['reason' => 'OTHER-DOCTORS-SECRET']]);

        ($this->open)($theirs)
            ->assertForbidden()
            ->assertDontSee('OTHER-DOCTORS-SECRET')
            ->assertDontSee('Rosa');
    });

    it('answers 403 to staff, admins and patients, and sends none of the intake content', function (Role $role) {
        $appointment = ($this->booking)();
        $intake = Intake::factory()->for($appointment)->create(['answers' => ['reason' => 'INTAKE-SECRET']]);
        IntakeSummary::factory()->for($intake)->create([
            'summary' => ['headline' => 'SUMMARY-SECRET', 'timeline' => [], 'in_english' => null, 'gaps' => [], 'check_with_patient' => []],
        ]);

        $this->actingAs(userWithRole($role))
            ->get(route('doctor.appointments.show', $appointment))
            ->assertForbidden()
            ->assertDontSee('INTAKE-SECRET')
            ->assertDontSee('SUMMARY-SECRET');
    })->with([
        'staff' => Role::Staff,
        'admin' => Role::Admin,
        'nurse' => Role::Nurse,
        'patient' => Role::Patient,
    ]);

    it('answers 403 to a doctor user who has no doctor profile', function () {
        // "No doctor" must not match an appointment, so the check compares ids and never null to anything.
        $appointment = ($this->booking)();
        Intake::factory()->for($appointment)->create(['answers' => ['reason' => 'INTAKE-SECRET']]);

        $this->actingAs(User::factory()->doctor()->withTwoFactor()->create())
            ->get(route('doctor.appointments.show', $appointment))
            ->assertForbidden()
            ->assertDontSee('INTAKE-SECRET');
    });

    it('answers 403 to the patient who owns the appointment', function () {
        $patient = User::factory()->patient()->create();
        $appointment = ($this->booking)($patient);
        Intake::factory()->for($appointment)->create();

        $this->actingAs($patient)
            ->get(route('doctor.appointments.show', $appointment))
            ->assertForbidden();
    });

    it('sends a guest to the login page', function () {
        $this->get(route('doctor.appointments.show', ($this->booking)()))->assertRedirect(route('login'));
    });

    it('answers 404 for an appointment that does not exist', function () {
        $this->actingAs($this->doctorUser)
            ->get(route('doctor.appointments.show', ['appointment' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']))
            ->assertNotFound();
    });
});
