<?php

declare(strict_types=1);

use App\Actions\Doctor\SaveVisitNote;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\User;
use App\Models\VisitNote;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

// Visit notes (step 10.4): one private, encrypted note per appointment, written and read by its own doctor only. The
// full Policy matrix is in HealthDataPoliciesTest; here each refused role is tried once through the route, to prove
// the controller and the Action call the Policy. The `notes.saved` and `notes.viewed` audit writes are steps 11.2 and
// 11.3. "Now" is fixed at Monday 2 November 2026, 09:00 in Manila.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'Asia/Manila'));

    $this->doctorUser = userWithRole(Role::Doctor);
    $this->doctor = Doctor::query()->where('user_id', $this->doctorUser->id)->firstOrFail();

    // An appointment for the signed-in doctor, or for $doctor. It is checked in, because only a visit that took place
    // takes a note ($state is any AppointmentFactory state). A doctor can't have two bookings at the same time, so
    // pass another $at when a test needs a second one.
    $this->booking = fn (?Doctor $doctor = null, string $at = '10:00', string $state = 'checkedIn'): Appointment => Appointment::factory()
        ->for($doctor ?? $this->doctor)
        ->for(User::factory()->patient()->create(), 'patient')
        ->{$state}()
        ->create(['starts_at' => CarbonImmutable::parse("2026-11-02 {$at}", 'Asia/Manila')->utc()]);

    $this->save = fn (Appointment $appointment, array $body = ['body' => 'Rash on the left arm.'], ?User $as = null) => $this->actingAs($as ?? $this->doctorUser)
        ->put(route('doctor.appointments.visit-note.update', $appointment), $body);

    $this->open = fn (Appointment $appointment, ?User $as = null) => $this->actingAs($as ?? $this->doctorUser)
        ->get(route('doctor.appointments.show', $appointment));
});

describe('saving a note', function () {
    it('saves the first note for the doctor and goes back to the appointment', function () {
        $appointment = ($this->booking)();

        ($this->save)($appointment, ['body' => 'Rash on the left arm.'])
            ->assertRedirect(route('doctor.appointments.show', $appointment));

        $note = VisitNote::query()->sole();
        expect($note->appointment_id)->toBe($appointment->id)
            ->and($note->doctor_id)->toBe($this->doctor->id)
            ->and($note->body)->toBe('Rash on the left arm.');
    });

    it('stores only ciphertext in the database', function () {
        ($this->save)(($this->booking)(), ['body' => 'Suspected eczema, no fever.']);

        $raw = (string) DB::table('visit_notes')->value('body');

        expect($raw)->not->toBe('Suspected eczema, no fever.')
            ->and($raw)->not->toContain('eczema');
    });

    it('edits the same note on a second save, so there is still one', function () {
        $appointment = ($this->booking)();

        ($this->save)($appointment, ['body' => 'First draft.']);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 11:00', 'Asia/Manila'));
        ($this->save)($appointment, ['body' => 'Final note.'])->assertRedirect();

        $note = VisitNote::query()->sole();
        expect($note->body)->toBe('Final note.')
            ->and($note->updated_at?->toIso8601ZuluString())->toBe('2026-11-02T03:00:00Z');
    });

    it('keeps each appointment\'s note apart', function () {
        $first = ($this->booking)();
        $second = ($this->booking)(at: '14:00');

        ($this->save)($first, ['body' => 'Note for the first.']);
        ($this->save)($second, ['body' => 'Note for the second.']);

        expect(VisitNote::query()->where('appointment_id', $first->id)->sole()->body)->toBe('Note for the first.')
            ->and(VisitNote::query()->where('appointment_id', $second->id)->sole()->body)->toBe('Note for the second.');
    });

    it('takes the doctor and the appointment from the server, whatever the request says', function () {
        $appointment = ($this->booking)();
        $other = ($this->booking)(Doctor::factory()->create());

        ($this->save)($appointment, [
            'body' => 'Mine.',
            'doctor_id' => $other->doctor_id,
            'appointment_id' => $other->id,
        ])->assertRedirect();

        $note = VisitNote::query()->sole();
        expect($note->appointment_id)->toBe($appointment->id)
            ->and($note->doctor_id)->toBe($this->doctor->id);
    });
});

describe('which appointments take a note', function () {
    it('takes a note once the patient is checked in, and after the visit is finished', function (string $state) {
        ($this->save)(($this->booking)(state: $state))->assertRedirect()->assertSessionHasNoErrors();

        expect(VisitNote::query()->count())->toBe(1);
    })->with(['checked in' => 'checkedIn', 'completed' => 'completed']);

    it('refuses a booking that has not started, a cancellation and a no-show, says why, and saves nothing', function (string $state, string $message) {
        ($this->save)(($this->booking)(state: $state))->assertSessionHasErrors(['appointment' => $message]);

        expect(VisitNote::query()->count())->toBe(0);
    })->with([
        // A booking will take a note after check-in. A cancellation or a no-show never will, so it is not told to wait.
        'booked' => ['booked', 'A note can be written once the patient is checked in.'],
        'cancelled' => ['cancelled', "A note can't be written on a cancelled appointment."],
        'no-show' => ['noShow', "A note can't be written on a no-show appointment."],
    ]);

    it('refuses an edit once the appointment no longer takes a note, and keeps the old text', function () {
        $appointment = ($this->booking)();
        ($this->save)($appointment, ['body' => 'Written during the visit.']);
        $appointment->update(['status' => 'no_show']);

        ($this->save)($appointment, ['body' => 'Changed later.'])->assertSessionHasErrors('appointment');

        expect(VisitNote::query()->sole()->body)->toBe('Written during the visit.');
    });

    it('reads the status again when the model in hand is old (a cancel at the same moment)', function () {
        $appointment = ($this->booking)();
        // Staff cancel the booking after the doctor's page loaded: the model the caller holds still says "checked in".
        Appointment::query()->whereKey($appointment->id)->update(['status' => 'cancelled']);

        expect(fn () => app(SaveVisitNote::class)->handle($this->doctorUser, $appointment, 'Too late.'))
            ->toThrow(ValidationException::class);
        expect(VisitNote::query()->count())->toBe(0);
    });

    it('tells the page whether the form is open, and still sends a note saved earlier', function () {
        $open = ($this->booking)();
        $closed = ($this->booking)(at: '14:00', state: 'booked');
        VisitNote::factory()->for($closed)->create(['body' => 'Prepared earlier.']);

        ($this->open)($open)->assertInertia(fn (Assert $page) => $page->where('canSaveVisitNote', true)->etc());
        ($this->open)($closed)->assertInertia(fn (Assert $page) => $page
            ->where('canSaveVisitNote', false)
            ->where('visitNote.body', 'Prepared earlier.')
            ->etc());
    });
});

describe('the unique key', function () {
    it('lets the database refuse a second note for the same appointment, even past the model', function () {
        $appointment = ($this->booking)();
        VisitNote::factory()->for($appointment)->create();

        // A raw insert skips the Action, the model and its cast: this is the last line of defense on its own.
        expect(fn () => DB::table('visit_notes')->insert([
            'appointment_id' => $appointment->id,
            'doctor_id' => $appointment->doctor_id,
            'body' => 'A second note.',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(UniqueConstraintViolationException::class);

        expect(VisitNote::query()->count())->toBe(1);
    });

    it('still lets each appointment have its own note', function () {
        VisitNote::factory()->for(($this->booking)())->create();
        VisitNote::factory()->for(($this->booking)(at: '14:00'))->create();

        expect(VisitNote::query()->count())->toBe(2);
    });
});

describe('the page', function () {
    it('sends the doctor their own note, with no other column', function () {
        $appointment = ($this->booking)();
        ($this->save)($appointment, ['body' => 'Rash on the left arm.']);

        ($this->open)($appointment)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('doctor/appointments/show')
                ->where('visitNote', [
                    'body' => 'Rash on the left arm.',
                    'updatedAt' => '2026-11-02T01:00:00Z',
                ])
                ->etc());
    });

    it('sends null when no note is saved yet', function () {
        ($this->open)(($this->booking)())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('visitNote', null)->etc());
    });

    it('never sends a note to a role that may not open the page', function (Role $role) {
        $appointment = ($this->booking)();
        VisitNote::factory()->for($appointment)->create(['body' => 'Hidden findings text']);

        ($this->open)($appointment, userWithRole($role))
            ->assertForbidden()
            ->assertDontSee('Hidden findings text');
    })->with([
        'staff' => Role::Staff,
        'admin' => Role::Admin,
        'patient' => Role::Patient,
    ]);

    it('never sends the note to the patient who owns the appointment', function () {
        $appointment = ($this->booking)();
        VisitNote::factory()->for($appointment)->create(['body' => 'Hidden findings text']);

        ($this->open)($appointment, $appointment->patient)
            ->assertForbidden()
            ->assertDontSee('Hidden findings text');
    });

    it('answers 403 when another doctor opens the page, and shows no note', function () {
        $appointment = ($this->booking)();
        VisitNote::factory()->for($appointment)->create(['body' => 'Hidden findings text']);

        ($this->open)($appointment, userWithRole(Role::Doctor))
            ->assertForbidden()
            ->assertDontSee('Hidden findings text');
    });
});

describe('validation', function () {
    it('requires the note text', function (array $body) {
        $appointment = ($this->booking)();

        ($this->save)($appointment, $body)->assertSessionHasErrors(['body' => 'Write a note before you save.']);

        expect(VisitNote::query()->count())->toBe(0);
    })->with([
        'missing' => [[]],
        'empty' => [['body' => '']],
        'only spaces' => [['body' => '    ']],
    ]);

    it('refuses a text that is not a string', function () {
        ($this->save)(($this->booking)(), ['body' => ['a']])->assertSessionHasErrors('body');

        expect(VisitNote::query()->count())->toBe(0);
    });

    it('refuses a text over 10,000 characters and keeps the old note', function () {
        $appointment = ($this->booking)();
        ($this->save)($appointment, ['body' => 'Kept.']);

        ($this->save)($appointment, ['body' => str_repeat('a', 10001)])
            ->assertSessionHasErrors(['body' => 'The note can be up to 10000 characters.']);

        expect(VisitNote::query()->sole()->body)->toBe('Kept.');
    });

    it('accepts a text of exactly 10,000 characters', function () {
        ($this->save)(($this->booking)(), ['body' => str_repeat('a', 10000)])->assertSessionHasNoErrors();

        expect(VisitNote::query()->sole()->body)->toHaveLength(10000);
    });
});

describe('rate limit', function () {
    it('allows 30 saves a minute per doctor and then answers 429', function () {
        RateLimiter::clear('doctor-actions');
        $appointment = ($this->booking)();

        foreach (range(1, 30) as $save) {
            ($this->save)($appointment);
        }

        ($this->save)($appointment)->assertStatus(429);

        // The count is per person, so another doctor is not held up.
        $other = userWithRole(Role::Doctor);
        ($this->save)(($this->booking)(Doctor::query()->where('user_id', $other->id)->firstOrFail()), as: $other)->assertRedirect();
    });
});

describe('denied access', function () {
    it('answers 403 when a doctor writes on another doctor\'s appointment, and saves nothing', function () {
        $theirs = ($this->booking)(Doctor::factory()->create());

        ($this->save)($theirs)->assertForbidden();

        expect(VisitNote::query()->count())->toBe(0);
    });

    it('answers 403 and leaves the note alone when another doctor tries to overwrite it', function () {
        $appointment = ($this->booking)();
        ($this->save)($appointment, ['body' => 'Original.']);

        ($this->save)($appointment, ['body' => 'Overwritten.'], userWithRole(Role::Doctor))->assertForbidden();

        expect(VisitNote::query()->sole()->body)->toBe('Original.');
    });

    it('answers 403 to staff, admins and patients, and saves nothing', function (Role $role) {
        ($this->save)(($this->booking)(), as: userWithRole($role))->assertForbidden();

        expect(VisitNote::query()->count())->toBe(0);
    })->with([
        'staff' => Role::Staff,
        'admin' => Role::Admin,
        'patient' => Role::Patient,
    ]);

    it('answers 403 and not a validation message to a user who may not write, even with no text', function (Role $role) {
        $this->actingAs(userWithRole($role))
            ->put(route('doctor.appointments.visit-note.update', ($this->booking)()))
            ->assertForbidden()
            ->assertSessionHasNoErrors();
    })->with([
        'staff' => Role::Staff,
        'admin' => Role::Admin,
        'patient' => Role::Patient,
    ]);

    it('answers 403 to the patient who owns the appointment', function () {
        $appointment = ($this->booking)();

        ($this->save)($appointment, as: $appointment->patient)->assertForbidden();

        expect(VisitNote::query()->count())->toBe(0);
    });

    it('answers 403 to a doctor user who has no doctor profile', function () {
        ($this->save)(($this->booking)(), as: User::factory()->doctor()->withTwoFactor()->create())->assertForbidden();

        expect(VisitNote::query()->count())->toBe(0);
    });

    it('sends a guest to the login page', function () {
        $this->put(route('doctor.appointments.visit-note.update', ($this->booking)()), ['body' => 'x'])
            ->assertRedirect(route('login'));

        expect(VisitNote::query()->count())->toBe(0);
    });

    it('answers 404 for an appointment that does not exist', function () {
        $this->actingAs($this->doctorUser)
            ->put(route('doctor.appointments.visit-note.update', ['appointment' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']), ['body' => 'x'])
            ->assertNotFound();
    });

    it('refuses a caller that skips the controller (the Action checks the Policy itself)', function (Role $role) {
        $appointment = ($this->booking)();

        expect(fn () => app(SaveVisitNote::class)->handle(userWithRole($role), $appointment, 'Sneaky note.'))
            ->toThrow(AuthorizationException::class);
        expect(VisitNote::query()->count())->toBe(0);
    })->with([
        'staff' => Role::Staff,
        'admin' => Role::Admin,
        'patient' => Role::Patient,
        // A doctor, but not this appointment's doctor.
        'another doctor' => Role::Doctor,
    ]);
});
