<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\Intake;
use App\Models\IntakeSummary;
use App\Models\User;
use App\Models\VisitNote;
use Illuminate\Support\Facades\Event;

// Step 11.3: opening the doctor's appointment page writes a view row for each piece of health data on it
// (PROJECT.md F10): `intake.viewed`, `summary.viewed` (a ready AI draft only) and `notes.viewed`. The page takes no
// input, so there is no validation test: the edge cases (a missing part, a draft that is not ready, text in the row)
// stand in for it. Who may open the page is in AppointmentDetailTest; here each refused role is tried once to prove
// that a refused request leaves no row. The change events (`intake.saved`, `notes.saved`...) are step 11.2.

beforeEach(function () {
    $this->doctorUser = userWithRole(Role::Doctor);
    $this->doctor = Doctor::query()->where('user_id', $this->doctorUser->id)->firstOrFail();

    $this->booking = fn (?Doctor $doctor = null): Appointment => Appointment::factory()
        ->for($doctor ?? $this->doctor)
        ->for(User::factory()->patient()->create(), 'patient')
        ->checkedIn()
        ->create();

    $this->open = fn (Appointment $appointment, ?User $as = null) => $this->actingAs($as ?? $this->doctorUser)
        ->get(route('doctor.appointments.show', $appointment));

    // The view rows only, so a row from setup can't pass for a view.
    $this->views = fn () => AuditLog::query()->where('action', 'like', '%.viewed')->orderBy('id')->get();
});

describe('the doctor opens an appointment', function () {
    it('writes one row each for the intake, the ready summary and the note', function () {
        $appointment = ($this->booking)();
        $intake = Intake::factory()->for($appointment)->create();
        $summary = IntakeSummary::factory()->for($intake)->create();
        $note = VisitNote::factory()->for($appointment)->create();

        ($this->open)($appointment)->assertOk();

        $rows = ($this->views)();

        expect($rows->pluck('action')->all())->toBe(['intake.viewed', 'summary.viewed', 'notes.viewed'])
            ->and($rows->pluck('actor_id')->unique()->all())->toBe([$this->doctorUser->id])
            ->and($rows->map(fn (AuditLog $row): array => [$row->subject_type, $row->subject_id])->all())->toBe([
                [$intake->getMorphClass(), $intake->id],
                [$summary->getMorphClass(), $summary->id],
                [$note->getMorphClass(), $note->id],
            ])
            ->and($rows->pluck('metadata')->every(fn (array $meta): bool => $meta === ['appointment_id' => $appointment->id]))->toBeTrue()
            ->and($rows->pluck('ip_hash')->every(fn (?string $hash): bool => $hash !== null && strlen($hash) === 64))->toBeTrue();
    });

    it('writes a new row on every open', function () {
        $appointment = ($this->booking)();
        Intake::factory()->for($appointment)->create();

        ($this->open)($appointment);
        ($this->open)($appointment);

        expect(($this->views)()->pluck('action')->all())->toBe(['intake.viewed', 'intake.viewed']);
    });

    it('writes no row for a part that is not on the page', function () {
        ($this->open)(($this->booking)())->assertOk();

        expect(($this->views)())->toHaveCount(0);
    });

    it('writes only the intake row when there is an intake but no summary and no note', function () {
        $appointment = ($this->booking)();
        Intake::factory()->for($appointment)->create();

        ($this->open)($appointment)->assertOk();

        expect(($this->views)()->pluck('action')->all())->toBe(['intake.viewed']);
    });

    it('writes no summary row while the draft has no text to read', function (string $state) {
        $appointment = ($this->booking)();
        IntakeSummary::factory()->for(Intake::factory()->for($appointment))->{$state}()->create();

        ($this->open)($appointment)->assertOk();

        expect(($this->views)()->pluck('action')->all())->toBe(['intake.viewed']);
    })->with(['pending', 'failed', 'skipped']);

    it('keeps the health text out of the rows', function () {
        $appointment = ($this->booking)();
        $intake = Intake::factory()->for($appointment)->create(['answers' => ['reason' => 'INTAKE-SECRET']]);
        IntakeSummary::factory()->for($intake)->create([
            'summary' => ['chief_complaint' => 'SUMMARY-SECRET', 'key_points' => [], 'allergies_and_meds' => '', 'questions_for_doctor' => []],
        ]);
        VisitNote::factory()->for($appointment)->create(['body' => 'NOTE-SECRET']);

        ($this->open)($appointment)->assertOk();

        $stored = json_encode(AuditLog::query()->get()->toArray());

        expect($stored)->not->toContain('INTAKE-SECRET')
            ->not->toContain('SUMMARY-SECRET')
            ->not->toContain('NOTE-SECRET');
    });
});

describe('the page and the log stay together', function () {
    it('shows no health content when the view row can not be written', function () {
        $appointment = ($this->booking)();
        Intake::factory()->for($appointment)->create(['answers' => ['reason' => 'INTAKE-SECRET']]);
        // The real AuditLogger runs and its insert fails: a failing write must stop the page, not be skipped.
        // Each test boots a new app, so this listener does not outlive the test.
        Event::listen('eloquent.creating: '.AuditLog::class, fn () => throw new RuntimeException('audit down'));
        // As in production. The debug page prints the lines of this very test, secret text included.
        config(['app.debug' => false]);

        ($this->open)($appointment)
            ->assertServerError()
            ->assertDontSee('INTAKE-SECRET');
    });

    it('does not count a patient reading their own intake form as a view', function () {
        $patient = User::factory()->patient()->create();
        $appointment = Appointment::factory()->for($this->doctor)->for($patient, 'patient')->booked()->create();
        Intake::factory()->for($appointment)->create();

        $this->actingAs($patient)->get(route('my.appointments.intake', $appointment))->assertOk();

        expect(($this->views)())->toHaveCount(0);
    });
});

describe('denied access', function () {
    it('writes no view row when another doctor opens the appointment', function () {
        $theirs = ($this->booking)(Doctor::factory()->create());
        $intake = Intake::factory()->for($theirs)->create();
        IntakeSummary::factory()->for($intake)->create();
        VisitNote::factory()->for($theirs)->create();

        ($this->open)($theirs)->assertForbidden();

        expect(($this->views)())->toHaveCount(0);
    });

    it('writes no view row when staff, an admin or a patient is refused', function (Role $role) {
        $appointment = ($this->booking)();
        $intake = Intake::factory()->for($appointment)->create();
        IntakeSummary::factory()->for($intake)->create();
        VisitNote::factory()->for($appointment)->create();

        ($this->open)($appointment, userWithRole($role))->assertForbidden();

        expect(($this->views)())->toHaveCount(0);
    })->with([
        'staff' => Role::Staff,
        'admin' => Role::Admin,
        'patient' => Role::Patient,
    ]);

    it('writes no view row for a guest', function () {
        $appointment = ($this->booking)();
        Intake::factory()->for($appointment)->create();

        $this->get(route('doctor.appointments.show', $appointment))->assertRedirect(route('login'));

        expect(($this->views)())->toHaveCount(0);
    });
});
