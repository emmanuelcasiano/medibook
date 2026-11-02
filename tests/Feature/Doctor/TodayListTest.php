<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Intake;
use App\Models\IntakeSummary;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// The doctor's "Today" list (step 10.1) and the door to one appointment. The page takes no input, so there is no
// validation test: the doctor comes from the signed-in user, never from the request. The role gate on the whole
// /doctor group (staff, admin, patient and guests) is also tested in RoleRouteGroupsTest. What the detail page shows
// is step 10.2.
// "Now" is fixed at Monday 2 November 2026, 09:00 in Manila.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'Asia/Manila'));

    $this->doctorUser = userWithRole(Role::Doctor);
    $this->doctor = Doctor::query()->where('user_id', $this->doctorUser->id)->firstOrFail();

    // A booking at a clinic time ('2026-11-02 14:30') for $doctor, or for the signed-in doctor by default.
    // $state is an AppointmentFactory state.
    $this->bookingAt = fn (string $startsAt, string $state = 'booked', ?Doctor $doctor = null): Appointment => Appointment::factory()
        ->for($doctor ?? $this->doctor)
        ->{$state}()
        ->create(['starts_at' => CarbonImmutable::parse($startsAt, 'Asia/Manila')->utc()]);

    $this->ulids = fn ($rows): array => collect($rows)->pluck('ulid')->all();
});

describe('the list', function () {
    it('shows the doctor their own appointments for today, earliest first', function () {
        $afternoon = ($this->bookingAt)('2026-11-02 14:30');
        $morning = ($this->bookingAt)('2026-11-02 08:00');
        $noon = ($this->bookingAt)('2026-11-02 12:15');

        $this->actingAs($this->doctorUser)
            ->get(route('doctor.home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('doctor/today')
                ->where('date', '2026-11-02')
                ->where('appointments', fn ($rows) => ($this->ulids)($rows) === [$morning->ulid, $noon->ulid, $afternoon->ulid]));
    });

    it('shows the time, the first name, the service and the status, and nothing else about the patient', function () {
        $patient = User::factory()->patient()->create(['name' => 'Maria Santos', 'email' => 'maria.santos@example.test']);
        $appointment = Appointment::factory()->for($this->doctor)->for($patient, 'patient')->checkedIn()->create([
            'starts_at' => CarbonImmutable::parse('2026-11-02 10:00', 'Asia/Manila')->utc(),
        ]);
        $appointment->service->update(['name' => 'Skin check', 'duration_minutes' => 30]);

        $this->actingAs($this->doctorUser)
            ->get(route('doctor.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('appointments.0', [
                    'ulid' => $appointment->ulid,
                    'startsAt' => '2026-11-02T02:00:00Z',
                    'endsAt' => '2026-11-02T02:30:00Z',
                    'status' => 'checked_in',
                    'statusLabel' => 'Checked in',
                    'patientFirstName' => 'Maria',
                    'serviceName' => 'Skin check',
                    'intakeFilled' => false,
                    'summaryStatus' => null,
                    'summaryLabel' => 'No intake yet',
                ]))
            // The surname, email and birth date are not anywhere in the page, not even in the page source.
            ->assertDontSee('Santos')
            ->assertDontSee('maria.santos@example.test')
            ->assertDontSee((string) $patient->birth_date);
    });

    it('sends the first name only, whatever the whitespace between the names', function (string $name, string $firstName) {
        $patient = User::factory()->patient()->create(['name' => $name]);
        Appointment::factory()->for($this->doctor)->for($patient, 'patient')->create([
            'starts_at' => CarbonImmutable::parse('2026-11-02 10:00', 'Asia/Manila')->utc(),
        ]);

        $this->actingAs($this->doctorUser)
            ->get(route('doctor.home'))
            ->assertInertia(fn (Assert $page) => $page->where('appointments.0.patientFirstName', $firstName));
    })->with([
        'a first and last name' => ['Maria Santos', 'Maria'],
        'one name only' => ['Cher', 'Cher'],
        'several names' => ['Ana Marie dela Cruz', 'Ana'],
        'extra spaces around and between' => ['  Maria   Santos ', 'Maria'],
        'a tab between' => ["Maria\tSantos", 'Maria'],
        'a non-breaking space between' => ["Maria\u{00A0}Santos", 'Maria'],
    ]);

    it('keeps two appointments with the same start time in the order they were made', function () {
        // A no-show frees the slot (no active key), so a booked appointment can start at the same minute.
        $first = ($this->bookingAt)('2026-11-02 09:00', 'noShow');
        $second = ($this->bookingAt)('2026-11-02 09:00');

        $this->actingAs($this->doctorUser)
            ->get(route('doctor.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('appointments', fn ($rows) => ($this->ulids)($rows) === [$first->ulid, $second->ulid]));
    });

    it('shows an empty list on a day with no appointments', function () {
        $this->actingAs($this->doctorUser)
            ->get(route('doctor.home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('appointments', []));
    });

    it('never lists another doctor\'s appointments', function () {
        $mine = ($this->bookingAt)('2026-11-02 09:00');
        $other = Doctor::factory()->create();
        ($this->bookingAt)('2026-11-02 09:00', doctor: $other);
        ($this->bookingAt)('2026-11-02 11:00', doctor: $other);

        $this->actingAs($this->doctorUser)
            ->get(route('doctor.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('appointments', fn ($rows) => ($this->ulids)($rows) === [$mine->ulid]));
    });

    it('shows nothing to a doctor user who has no doctor profile', function () {
        // Another doctor has a booking today. A user without a profile must not match "no doctor" to anyone's rows.
        ($this->bookingAt)('2026-11-02 09:00', doctor: Doctor::factory()->create());
        $orphan = User::factory()->doctor()->withTwoFactor()->create();

        $this->actingAs($orphan)
            ->get(route('doctor.home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('appointments', []));
    });

    it('leaves out cancelled appointments and keeps no-shows', function () {
        ($this->bookingAt)('2026-11-02 09:00', 'cancelled');
        $noShow = ($this->bookingAt)('2026-11-02 10:00', 'noShow');
        $completed = ($this->bookingAt)('2026-11-02 11:00', 'completed');

        $this->actingAs($this->doctorUser)
            ->get(route('doctor.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('appointments', fn ($rows) => ($this->ulids)($rows) === [$noShow->ulid, $completed->ulid]));
    });

    it('cuts the day at midnight Manila time, not midnight UTC', function (string $startsAt, bool $today) {
        $appointment = ($this->bookingAt)($startsAt);

        $this->actingAs($this->doctorUser)
            ->get(route('doctor.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('appointments', fn ($rows) => ($this->ulids)($rows) === ($today ? [$appointment->ulid] : [])));
    })->with([
        // 00:00 Manila is 16:00 UTC the day before, so a UTC-based day would put this one on yesterday.
        'first minute of the day' => ['2026-11-02 00:00', true],
        'last minute of the day' => ['2026-11-02 23:59', true],
        'one minute before the day' => ['2026-11-01 23:59', false],
        'first minute of tomorrow' => ['2026-11-03 00:00', false],
    ]);
});

describe('the summary column', function () {
    it('says in words where the intake and its AI summary stand', function (?string $summaryState, bool $reviewed, ?string $summaryStatus, string $label) {
        $appointment = ($this->bookingAt)('2026-11-02 10:00');

        if ($summaryState !== null) {
            $intake = Intake::factory()->for($appointment)->create();

            if ($summaryState !== 'none') {
                $summary = IntakeSummary::factory()->for($intake);
                $summary = $summaryState === 'ready' ? $summary : $summary->{$summaryState}();
                $summary->create($reviewed ? ['reviewed_by' => $this->doctorUser->id, 'reviewed_at' => now()] : []);
            }
        }

        $this->actingAs($this->doctorUser)
            ->get(route('doctor.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('appointments.0.intakeFilled', $summaryState !== null)
                ->where('appointments.0.summaryStatus', $summaryStatus)
                ->where('appointments.0.summaryLabel', $label));
    })->with([
        'no intake' => [null, false, null, 'No intake yet'],
        'an intake with no summary row' => ['none', false, null, 'No AI summary'],
        'a pending summary' => ['pending', false, 'pending', 'Summary pending'],
        'a ready summary' => ['ready', false, 'ready', 'AI draft, not reviewed'],
        'a reviewed summary' => ['ready', true, 'ready', 'Reviewed'],
        'a failed summary' => ['failed', false, 'failed', 'Summary failed'],
        'a skipped summary' => ['skipped', false, 'skipped', 'No AI summary'],
    ]);

    it('never puts the answers or the summary text on the list', function () {
        $intake = Intake::factory()->for(($this->bookingAt)('2026-11-02 10:00'))->create(['answers' => ['reason' => 'Secret chest rash']]);
        IntakeSummary::factory()->for($intake)->create([
            'summary' => ['chief_complaint' => 'Secret summary line', 'key_points' => [], 'allergies_and_meds' => 'Not given', 'questions_for_doctor' => []],
        ]);

        $this->actingAs($this->doctorUser)
            ->get(route('doctor.home'))
            ->assertOk()
            ->assertDontSee('Secret chest rash')
            ->assertDontSee('Secret summary line');
    });
});

describe('queries', function () {
    it('does not run more queries for a longer day', function () {
        // Strict mode already throws on a lazy load; this also proves the count does not grow with the day.
        $queriesFor = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->doctorUser)->get(route('doctor.home'))->assertOk();

            return count(DB::getQueryLog());
        };

        $intake = Intake::factory()->for(($this->bookingAt)('2026-11-02 08:00'))->create();
        IntakeSummary::factory()->for($intake)->create();
        // The first request also looks up the doctor profile, which the user model remembers for the next ones.
        $queriesFor();
        $withOne = $queriesFor();

        foreach (['09:00', '10:00', '11:00', '13:00'] as $time) {
            IntakeSummary::factory()->for(Intake::factory()->for(($this->bookingAt)("2026-11-02 {$time}")))->create();
        }

        expect($queriesFor())->toBe($withOne);
    });
});

describe('opening an appointment', function () {
    it('lets a doctor open their own patient\'s appointment', function () {
        $appointment = ($this->bookingAt)('2026-11-02 09:00');

        $this->actingAs($this->doctorUser)
            ->get(route('doctor.appointments.show', $appointment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('role-home'));
    });

    it('answers 403 when a doctor opens another doctor\'s appointment', function () {
        $theirs = ($this->bookingAt)('2026-11-02 09:00', doctor: Doctor::factory()->create());

        $this->actingAs($this->doctorUser)
            ->get(route('doctor.appointments.show', $theirs))
            ->assertForbidden();
    });

    it('answers 403 to staff, admins and patients, even for an appointment of their own', function (Role $role) {
        $appointment = ($this->bookingAt)('2026-11-02 09:00');

        $this->actingAs(userWithRole($role))
            ->get(route('doctor.appointments.show', $appointment))
            ->assertForbidden();
    })->with([
        'staff' => Role::Staff,
        'admin' => Role::Admin,
        'patient' => Role::Patient,
    ]);

    it('sends a guest to the login page', function () {
        $appointment = ($this->bookingAt)('2026-11-02 09:00');

        $this->get(route('doctor.appointments.show', $appointment))->assertRedirect(route('login'));
    });

    it('answers 404 for an appointment that does not exist', function () {
        $this->actingAs($this->doctorUser)
            ->get(route('doctor.appointments.show', ['appointment' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']))
            ->assertNotFound();
    });
});
