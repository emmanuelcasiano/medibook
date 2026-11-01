<?php

declare(strict_types=1);

use App\Actions\Intake\SaveIntake;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Intake;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

// These tests call the Action directly. The form page and the route (HTTP status, redirect, validation messages) are in
// IntakeFormTest. The Action checks IntakePolicy itself, and a denied call must write nothing. "Now" is Friday
// 30 October 2026, 09:00 in Manila. The emergency check (step 8.3) and the AI summary (Day 9) are not part of it yet.

/**
 * A full set of valid answers, as the Form Request hands them to the Action.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{reason: string, symptoms: string|null, duration: string|null, allergies: string|null, medicines: string|null, fever: bool, pregnant: bool, recent_surgery: bool}
 */
function intakeAnswers(array $overrides = []): array
{
    return [
        'reason' => 'Cough that does not go away',
        'symptoms' => 'Dry cough at night',
        'duration' => 'few_days',
        'allergies' => 'None',
        'medicines' => 'Paracetamol when needed',
        'fever' => false,
        'pregnant' => false,
        'recent_surgery' => false,
        ...$overrides,
    ];
}

function saveIntakeAs(User $actor, Appointment $appointment, array $answers): Intake
{
    return app(SaveIntake::class)->handle($actor, $appointment, $answers);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));
    $this->patient = User::factory()->patient()->create();
    $this->appointment = Appointment::factory()->for($this->patient, 'patient')->booked()->startingIn(days: 2)->create();
});

// Happy path

it('creates the intake on the first save', function () {
    $intake = saveIntakeAs($this->patient, $this->appointment, intakeAnswers());

    expect(Intake::query()->count())->toBe(1)
        ->and($intake->appointment_id)->toBe($this->appointment->id)
        ->and($intake->submitted_at)->toEqual(now())
        ->and($intake->fresh()->answers)->toBe(intakeAnswers());
});

it('replaces the answers on the next save and keeps one row', function () {
    $first = saveIntakeAs($this->patient, $this->appointment, intakeAnswers());

    $this->travel(1)->hours();
    $second = saveIntakeAs($this->patient, $this->appointment, intakeAnswers([
        'reason' => 'Fever since last night',
        'symptoms' => null,
        'fever' => true,
    ]));

    expect(Intake::query()->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->fresh()->answers['reason'])->toBe('Fever since last night')
        ->and($second->fresh()->answers['symptoms'])->toBeNull()
        ->and($second->fresh()->answers['fever'])->toBeTrue()
        ->and($second->fresh()->submitted_at)->toEqual(now());
});

it('keeps each appointment\'s intake apart', function () {
    $other = Appointment::factory()->for($this->patient, 'patient')->booked()->startingIn(days: 3)->create();

    saveIntakeAs($this->patient, $this->appointment, intakeAnswers(['reason' => 'First visit']));
    saveIntakeAs($this->patient, $other, intakeAnswers(['reason' => 'Second visit']));

    expect(Intake::query()->count())->toBe(2)
        ->and(Intake::query()->where('appointment_id', $this->appointment->id)->firstOrFail()->answers['reason'])->toBe('First visit')
        ->and(Intake::query()->where('appointment_id', $other->id)->firstOrFail()->answers['reason'])->toBe('Second visit');
});

it('stores only the form\'s own answers, not any extra key', function () {
    $intake = saveIntakeAs($this->patient, $this->appointment, intakeAnswers() + ['role' => 'admin', 'appointment_id' => 999]);

    expect(array_keys($intake->fresh()->answers))->toBe([
        'reason', 'symptoms', 'duration', 'allergies', 'medicines', 'fever', 'pregnant', 'recent_surgery',
    ]);
});

// The raw column is read with the query builder, which skips the model casts. If it were plain text, the secret
// would show up here.
it('encrypts the answers at rest and decrypts them on read', function () {
    $intake = saveIntakeAs($this->patient, $this->appointment, intakeAnswers([
        'reason' => 'Secret chest rash',
        'allergies' => 'Secret penicillin',
    ]));

    $raw = DB::table('intakes')->where('id', $intake->id)->value('answers');

    expect($raw)->not->toContain('Secret chest rash')
        ->and($raw)->not->toContain('Secret penicillin')
        ->and($raw)->not->toContain('reason')
        ->and($intake->fresh()->answers['reason'])->toBe('Secret chest rash');
});

it('encrypts the answers again on an edit', function () {
    saveIntakeAs($this->patient, $this->appointment, intakeAnswers());
    $intake = saveIntakeAs($this->patient, $this->appointment, intakeAnswers(['reason' => 'Secret second reason']));

    expect(DB::table('intakes')->where('id', $intake->id)->value('answers'))->not->toContain('Secret second reason');
});

// Denied access

it('refuses another patient and writes nothing', function () {
    $stranger = User::factory()->patient()->create();

    expect(fn () => saveIntakeAs($stranger, $this->appointment, intakeAnswers()))->toThrow(AuthorizationException::class);

    expect(Intake::query()->count())->toBe(0);
});

it('refuses another patient who tries to edit an existing intake, and the answers stay as they were', function () {
    $intake = saveIntakeAs($this->patient, $this->appointment, intakeAnswers(['reason' => 'My own reason']));
    $stranger = User::factory()->patient()->create();

    expect(fn () => saveIntakeAs($stranger, $this->appointment, intakeAnswers(['reason' => 'Overwritten'])))
        ->toThrow(AuthorizationException::class);

    expect($intake->fresh()->answers['reason'])->toBe('My own reason');
});

it('refuses staff, an admin and a doctor, even the appointment\'s own doctor', function (string $who) {
    $actor = match ($who) {
        'staff' => userWithRole(Role::Staff),
        'admin' => userWithRole(Role::Admin),
        'another doctor' => userWithRole(Role::Doctor),
        'own doctor' => User::query()->findOrFail(Doctor::query()->findOrFail($this->appointment->doctor_id)->user_id),
    };

    expect(fn () => saveIntakeAs($actor, $this->appointment, intakeAnswers()))->toThrow(AuthorizationException::class);

    expect(Intake::query()->count())->toBe(0);
})->with(['staff', 'admin', 'another doctor', 'own doctor']);

it('closes the form once the appointment is no longer booked', function (string $state) {
    $appointment = Appointment::factory()->for($this->patient, 'patient')->{$state}()->startingIn(days: 2)->create();

    expect(fn () => saveIntakeAs($this->patient, $appointment, intakeAnswers()))->toThrow(AuthorizationException::class);

    expect(Intake::query()->count())->toBe(0);
})->with(['checkedIn', 'completed', 'cancelled', 'noShow']);

it('keeps the saved answers when the patient tries to edit after check-in', function () {
    $intake = saveIntakeAs($this->patient, $this->appointment, intakeAnswers(['reason' => 'Before check-in']));
    $this->appointment->update(['status' => 'checked_in', 'checked_in_at' => now()]);

    expect(fn () => saveIntakeAs($this->patient, $this->appointment->fresh(), intakeAnswers(['reason' => 'After check-in'])))
        ->toThrow(AuthorizationException::class);

    expect($intake->fresh()->answers['reason'])->toBe('Before check-in');
});

// The model in the caller's hand may be old. The check must use the row as it is in the database.
it('reads the status again, so an old copy of a booked appointment cannot save after check-in', function () {
    Appointment::query()->whereKey($this->appointment->id)->update(['status' => 'checked_in']);

    expect($this->appointment->status->value)->toBe('booked')
        ->and(fn () => saveIntakeAs($this->patient, $this->appointment, intakeAnswers()))->toThrow(AuthorizationException::class);

    expect(Intake::query()->count())->toBe(0);
});

// One PHP process can't truly race itself, so this checks the row is locked inside the transaction, before the
// status is read. Check-in locks the same row, so a save and a check-in can't both pass.
it('locks the appointment row inside the transaction', function () {
    $locks = [];
    DB::listen(function ($query) use (&$locks): void {
        if (str_contains($query->sql, 'from `appointments`') && str_contains($query->sql, 'for update')) {
            $locks[] = DB::transactionLevel();
        }
    });

    saveIntakeAs($this->patient, $this->appointment, intakeAnswers());

    // The test itself runs in one transaction (RefreshDatabase), so the Action's transaction is a level deeper.
    expect($locks)->toHaveCount(1)
        ->and($locks[0])->toBeGreaterThan(1);
});
