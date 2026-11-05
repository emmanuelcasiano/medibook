<?php

declare(strict_types=1);

use App\Actions\Doctor\MarkSummaryReviewed;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Intake;
use App\Models\IntakeSummary;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

// "Mark reviewed" (step 10.3). The request has no body: the doctor and the time come from the server, so there is no
// input to validate. The "validation" group here is the state rule instead (only a ready summary can be reviewed).
// The `summary.reviewed` audit write is tested in Audit/ActionAuditTest. "Now" is fixed at Monday 2 November 2026, 09:00 in Manila.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'Asia/Manila'));

    $this->doctorUser = userWithRole(Role::Doctor);
    $this->doctor = Doctor::query()->where('user_id', $this->doctorUser->id)->firstOrFail();

    // A booking for the signed-in doctor, or for $doctor.
    $this->booking = fn (?Doctor $doctor = null): Appointment => Appointment::factory()
        ->for($doctor ?? $this->doctor)
        ->for(User::factory()->patient()->create(), 'patient')
        ->booked()
        ->create(['starts_at' => CarbonImmutable::parse('2026-11-02 10:00', 'Asia/Manila')->utc()]);

    // An appointment with a summary in the given factory state ('ready' is the factory default).
    $this->withSummary = function (string $state = 'ready', ?Doctor $doctor = null): array {
        $appointment = ($this->booking)($doctor);
        $factory = IntakeSummary::factory()->for(Intake::factory()->for($appointment));
        $summary = ($state === 'ready' ? $factory : $factory->{$state}())->create();

        return [$appointment, $summary];
    };

    // What the page sends back: the token of the draft it showed. By default that is the draft in the database now.
    $this->versionOf = fn (Appointment $appointment): string => IntakeSummary::query()
        ->whereHas('intake', fn ($query) => $query->where('appointment_id', $appointment->id))
        ->first()?->version() ?? 'no-summary';

    $this->review = fn (Appointment $appointment, ?User $as = null, ?string $version = null) => $this->actingAs($as ?? $this->doctorUser)
        ->post(route('doctor.appointments.summary.review', $appointment), ['version' => $version ?? ($this->versionOf)($appointment)]);
});

describe('marking a summary reviewed', function () {
    it('saves who reviewed it and when, and goes back to the appointment', function () {
        [$appointment, $summary] = ($this->withSummary)();

        ($this->review)($appointment)->assertRedirect(route('doctor.appointments.show', $appointment));

        $summary->refresh();
        expect($summary->reviewed_by)->toBe($this->doctorUser->id)
            ->and($summary->reviewed_at?->toIso8601ZuluString())->toBe('2026-11-02T01:00:00Z');
    });

    it('takes the reviewer and the time from the server, whatever the request says', function () {
        [$appointment, $summary] = ($this->withSummary)();

        $this->actingAs($this->doctorUser)
            ->post(route('doctor.appointments.summary.review', $appointment), [
                'version' => ($this->versionOf)($appointment),
                'reviewed_by' => User::factory()->create()->id,
                'reviewed_at' => '2020-01-01 00:00:00',
            ])
            ->assertRedirect();

        $summary->refresh();
        expect($summary->reviewed_by)->toBe($this->doctorUser->id)
            ->and($summary->reviewed_at?->toIso8601ZuluString())->toBe('2026-11-02T01:00:00Z');
    });

    it('keeps the first who and when when it is marked again', function () {
        [$appointment, $summary] = ($this->withSummary)();
        ($this->review)($appointment);

        $this->travelTo(CarbonImmutable::parse('2026-11-02 11:00', 'Asia/Manila'));
        ($this->review)($appointment)->assertRedirect(route('doctor.appointments.show', $appointment));

        expect($summary->refresh()->reviewed_at?->toIso8601ZuluString())->toBe('2026-11-02T01:00:00Z');
    });

    it('shows the button for an unreviewed ready summary and hides it after the review', function () {
        [$appointment] = ($this->withSummary)();
        $show = fn () => $this->actingAs($this->doctorUser)->get(route('doctor.appointments.show', $appointment));

        $show()->assertInertia(fn (Assert $page) => $page
            ->where('summary.can.review', true)
            ->where('summary.reviewedAt', null));

        ($this->review)($appointment);

        $show()->assertInertia(fn (Assert $page) => $page
            ->where('summary.can.review', false)
            ->where('summary.reviewedAt', '2026-11-02T01:00:00Z'));
    });

    it('does not show the button for a summary that is not ready', function (string $state) {
        [$appointment] = ($this->withSummary)($state);

        $this->actingAs($this->doctorUser)
            ->get(route('doctor.appointments.show', $appointment))
            ->assertInertia(fn (Assert $page) => $page->where('summary.can.review', false));
    })->with(['pending', 'failed', 'skipped']);
});

describe('the draft the doctor read', function () {
    it('keeps the same token after the review, so a double click is harmless', function () {
        [$appointment, $summary] = ($this->withSummary)();
        $before = $summary->version();

        ($this->review)($appointment);

        expect($summary->refresh()->version())->toBe($before);
    });

    it('gives another token when the draft text or the row changes', function () {
        [$appointment, $summary] = ($this->withSummary)();
        $before = $summary->version();

        $summary->update(['summary' => ['chief_complaint' => 'Changed', 'key_points' => [], 'allergies_and_meds' => 'Not given', 'questions_for_doctor' => []]]);
        expect($summary->refresh()->version())->not->toBe($before);

        $other = IntakeSummary::factory()->for(Intake::factory()->for(($this->booking)(Doctor::factory()->create())))->create(['summary' => $summary->summary]);
        expect($other->version())->not->toBe($summary->version());
    });

    it('refuses a review of a draft that changed after the page was opened, and leaves the row unchanged', function () {
        [$appointment, $summary] = ($this->withSummary)();
        $seen = $summary->version();

        // The patient edits the intake and a new draft takes the old one's place (new row, new text).
        $summary->delete();
        $new = IntakeSummary::factory()->for($appointment->intake()->firstOrFail())->create();

        ($this->review)($appointment, version: $seen)->assertSessionHasErrors('summary');

        expect($new->refresh()->reviewed_by)->toBeNull()
            ->and($new->reviewed_at)->toBeNull();
    });

    it('refuses a review when the same row was rewritten after the page was opened', function () {
        [$appointment, $summary] = ($this->withSummary)();
        $seen = $summary->version();
        $summary->update(['summary' => ['chief_complaint' => 'Rewritten', 'key_points' => [], 'allergies_and_meds' => 'Not given', 'questions_for_doctor' => []]]);

        ($this->review)($appointment, version: $seen)->assertSessionHasErrors('summary');

        expect($summary->refresh()->reviewed_at)->toBeNull();
    });

    it('accepts the review of the new draft once the page has the new token', function () {
        [$appointment, $summary] = ($this->withSummary)();
        $summary->delete();
        $new = IntakeSummary::factory()->for($appointment->intake()->firstOrFail())->create();

        ($this->review)($appointment)->assertSessionHasNoErrors();

        expect($new->refresh()->reviewed_by)->toBe($this->doctorUser->id);
    });

    it('sends the token with the page', function () {
        [$appointment, $summary] = ($this->withSummary)();

        $this->actingAs($this->doctorUser)
            ->get(route('doctor.appointments.show', $appointment))
            ->assertInertia(fn (Assert $page) => $page->where('summary.version', $summary->version()));
    });
});

describe('validation', function () {
    it('requires the token', function (array $body) {
        [$appointment, $summary] = ($this->withSummary)();

        $this->actingAs($this->doctorUser)
            ->post(route('doctor.appointments.summary.review', $appointment), $body)
            ->assertSessionHasErrors('version');

        expect($summary->refresh()->reviewed_at)->toBeNull();
    })->with([
        'missing' => [[]],
        'empty' => [['version' => '']],
        'too long' => [['version' => str_repeat('a', 65)]],
        'not a string' => [['version' => ['a']]],
    ]);
});

describe('rate limit', function () {
    it('allows 30 clicks a minute per doctor and then answers 429', function () {
        RateLimiter::clear('doctor-actions');
        [$appointment, $summary] = ($this->withSummary)('pending');

        foreach (range(1, 30) as $click) {
            ($this->review)($appointment);
        }

        ($this->review)($appointment)->assertStatus(429);

        // The count is per person, so another doctor is not held up.
        $other = userWithRole(Role::Doctor);
        ($this->review)(($this->booking)(Doctor::query()->where('user_id', $other->id)->firstOrFail()), $other)->assertSessionHasErrors('summary');
    });
});

describe('the state rule', function () {
    it('refuses a summary that is not ready, and leaves the row unchanged', function (string $state) {
        [$appointment, $summary] = ($this->withSummary)($state);

        ($this->review)($appointment)->assertSessionHasErrors('summary');

        expect($summary->refresh()->reviewed_by)->toBeNull()
            ->and($summary->reviewed_at)->toBeNull();
    })->with(['pending', 'failed', 'skipped']);

    it('refuses an appointment that has no intake', function () {
        ($this->review)(($this->booking)())->assertSessionHasErrors('summary');
    });

    it('refuses an intake that has no summary row yet', function () {
        $appointment = ($this->booking)();
        Intake::factory()->for($appointment)->create();

        ($this->review)($appointment)->assertSessionHasErrors('summary');
    });

    it('lands on the summary that exists now, after an edit replaced the old one', function () {
        [$appointment, $old] = ($this->withSummary)();
        $intake = $appointment->intake()->firstOrFail();

        // What SaveIntake does on an edit: the old row goes, and a pending one takes its place.
        $old->delete();
        $new = IntakeSummary::factory()->pending()->for($intake)->create();

        ($this->review)($appointment)->assertSessionHasErrors('summary');
        expect($new->refresh()->reviewed_at)->toBeNull();
    });
});

describe('denied access', function () {
    it('answers 403 when a doctor reviews another doctor\'s summary, and changes nothing', function () {
        [$theirs, $summary] = ($this->withSummary)('ready', Doctor::factory()->create());

        ($this->review)($theirs)->assertForbidden();

        expect($summary->refresh()->reviewed_at)->toBeNull();
    });

    it('answers 403 to staff, admins and patients, and changes nothing', function (Role $role) {
        [$appointment, $summary] = ($this->withSummary)();

        ($this->review)($appointment, userWithRole($role))->assertForbidden();

        expect($summary->refresh()->reviewed_by)->toBeNull()
            ->and($summary->reviewed_at)->toBeNull();
    })->with([
        'staff' => Role::Staff,
        'admin' => Role::Admin,
        'patient' => Role::Patient,
    ]);

    it('answers 403 and not a validation message to a user who may not open the appointment, even with no token', function (Role $role) {
        [$appointment, $summary] = ($this->withSummary)();

        $this->actingAs(userWithRole($role))
            ->post(route('doctor.appointments.summary.review', $appointment))
            ->assertForbidden()
            ->assertSessionHasNoErrors();

        expect($summary->refresh()->reviewed_at)->toBeNull();
    })->with([
        'staff' => Role::Staff,
        'admin' => Role::Admin,
        'patient' => Role::Patient,
    ]);

    it('answers 403 to the patient who owns the appointment', function () {
        [$appointment, $summary] = ($this->withSummary)();

        ($this->review)($appointment, $appointment->patient)->assertForbidden();

        expect($summary->refresh()->reviewed_at)->toBeNull();
    });

    it('answers 403 to a doctor user who has no doctor profile', function () {
        [$appointment, $summary] = ($this->withSummary)();

        ($this->review)($appointment, User::factory()->doctor()->withTwoFactor()->create())->assertForbidden();

        expect($summary->refresh()->reviewed_at)->toBeNull();
    });

    it('sends a guest to the login page', function () {
        [$appointment, $summary] = ($this->withSummary)();

        $this->post(route('doctor.appointments.summary.review', $appointment))->assertRedirect(route('login'));

        expect($summary->refresh()->reviewed_at)->toBeNull();
    });

    it('answers 404 for an appointment that does not exist', function () {
        $this->actingAs($this->doctorUser)
            ->post(route('doctor.appointments.summary.review', ['appointment' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']))
            ->assertNotFound();
    });

    it('refuses a caller that skips the controller (the Action checks the Policies itself)', function (Role $role) {
        [$appointment, $summary] = ($this->withSummary)();

        expect(fn () => app(MarkSummaryReviewed::class)->handle(userWithRole($role), $appointment, $summary->version()))
            ->toThrow(AuthorizationException::class);
        expect($summary->refresh()->reviewed_at)->toBeNull();
    })->with([
        'staff' => Role::Staff,
        'admin' => Role::Admin,
        'patient' => Role::Patient,
    ]);

    it('tells a user who may not open the appointment nothing about its summary state', function () {
        // A pending summary would give a validation error to the owner. A stranger gets the authorization error first.
        [$appointment, $summary] = ($this->withSummary)('pending');

        expect(fn () => app(MarkSummaryReviewed::class)->handle(userWithRole(Role::Staff), $appointment, $summary->version()))
            ->toThrow(AuthorizationException::class);
    });
});
