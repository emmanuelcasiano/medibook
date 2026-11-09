<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Intake;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// The intake form page and its save route (step 8.2). The rules (who may save, the lock, which keys are stored) are
// tested on the Action in SaveIntakeTest. These tests cover what the HTTP layer adds: who may open the page, what the
// page is told, the redirect, the validation messages and the rate limit. "Now" is Friday 30 October 2026, 09:00 in
// Manila.

/**
 * The form's fields as the browser sends them: strings, with an explicit 1 or 0 for each yes/no box.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function intakePayload(array $overrides = []): array
{
    return [
        'reason' => 'Cough that does not go away',
        'symptoms' => 'Dry cough at night',
        'duration' => 'few_days',
        'allergies' => 'None',
        'medicines' => 'Paracetamol when needed',
        'fever' => '0',
        'pregnant' => '0',
        'recent_surgery' => '0',
        ...$overrides,
    ];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));
    $this->patient = User::factory()->patient()->create();
    $this->appointment = Appointment::factory()->for($this->patient, 'patient')->booked()->startingIn(days: 2)->create();

    $this->formUrl = route('my.appointments.intake', $this->appointment);
    // Makes the next request come from the form page, so a validation error has somewhere to go back to.
    $this->openedForm = fn () => $this->from($this->formUrl);
});

describe('the form page', function () {
    it('shows an empty form to the patient who owns the booking', function () {
        $this->actingAs($this->patient)
            ->get($this->formUrl)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('my/appointments/intake')
                ->where('appointment.ulid', $this->appointment->ulid)
                ->where('intake', null)
                ->where('emergency', false)
                ->where('can.save', true)
                ->has('durations', 5)
                ->where('durations.0', ['value' => 'today', 'label' => 'Since today']));
    });

    it('tells the page about the visit and nothing else about the people', function () {
        $this->actingAs($this->patient)
            ->get($this->formUrl)
            ->assertInertia(fn (Assert $page) => $page
                ->where('appointment.startsAt', $this->appointment->starts_at->toIso8601ZuluString())
                ->where('appointment', fn ($visit) => array_keys($visit->toArray()) === ['ulid', 'startsAt', 'endsAt', 'service', 'doctor'])
                // Names and specialty only: no doctor user id or email.
                ->where('appointment.doctor', fn ($doctor) => array_keys($doctor->toArray()) === ['id', 'name', 'specialty', 'bio']));
    });

    it('shows the saved answers, and only the form\'s own keys', function () {
        Intake::factory()->for($this->appointment)->create([
            'answers' => ['reason' => 'Headache for several days', 'fever' => true, 'role' => 'admin'],
            'submitted_at' => now()->subHour(),
        ]);

        $this->actingAs($this->patient)
            ->get($this->formUrl)
            ->assertInertia(fn (Assert $page) => $page
                ->where('intake.submittedAt', now()->subHour()->toIso8601ZuluString())
                ->where('intake.answers.reason', 'Headache for several days')
                ->where('intake.answers.fever', true)
                ->where('intake.answers.symptoms', null)
                ->where('can.save', true)
                ->where('intake.answers', fn ($answers) => collect($answers)->keys()->all() === [
                    'reason', 'symptoms', 'duration', 'allergies', 'medicines', 'fever', 'pregnant', 'recent_surgery',
                ]));
    });

    it('shows the answers read-only once the appointment is checked in', function () {
        Intake::factory()->for($this->appointment)->create(['answers' => ['reason' => 'Headache for several days']]);
        $this->appointment->update(['status' => AppointmentStatus::CheckedIn, 'checked_in_at' => now()]);

        $this->actingAs($this->patient)
            ->get($this->formUrl)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.save', false)
                ->where('intake.answers.reason', 'Headache for several days'));
    });

    it('closes the form without answers when check-in came before any intake', function () {
        $this->appointment->update(['status' => AppointmentStatus::CheckedIn, 'checked_in_at' => now()]);

        $this->actingAs($this->patient)
            ->get($this->formUrl)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('intake', null)->where('can.save', false));
    });

    it('answers 403 to another patient, with or without a saved intake', function (bool $filled) {
        if ($filled) {
            Intake::factory()->for($this->appointment)->create(['answers' => ['reason' => 'Secret chest rash']]);
        }

        $this->actingAs(User::factory()->patient()->create())
            ->get($this->formUrl)
            ->assertForbidden()
            ->assertDontSee('Secret chest rash');
    })->with(['no intake' => false, 'an intake' => true]);

    it('answers 403 to staff, a doctor and an admin', function (Role $role) {
        Intake::factory()->for($this->appointment)->create(['answers' => ['reason' => 'Secret chest rash']]);

        $this->actingAs(userWithRole($role))
            ->get($this->formUrl)
            ->assertForbidden()
            ->assertDontSee('Secret chest rash');
    })->with([Role::Staff, Role::Doctor, Role::Nurse, Role::Admin]);

    it('sends a guest to log in', function () {
        $this->get($this->formUrl)->assertRedirect(route('login'));
    });
});

describe('saving the form', function () {
    it('creates the intake, goes back to the form and says it was saved', function () {
        $this->actingAs($this->patient)
            ->put(route('my.appointments.intake.update', $this->appointment), intakePayload(['fever' => '1']))
            ->assertRedirect($this->formUrl)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data.toast.type', 'success')
            ->assertSessionHas('inertia.flash_data.toast.message', 'Your intake was saved.');

        $intake = Intake::query()->where('appointment_id', $this->appointment->id)->firstOrFail();

        expect(Intake::query()->count())->toBe(1)
            ->and($intake->answers)->toBe([
                'reason' => 'Cough that does not go away',
                'symptoms' => 'Dry cough at night',
                'duration' => 'few_days',
                'allergies' => 'None',
                'medicines' => 'Paracetamol when needed',
                'fever' => true,
                'pregnant' => false,
                'recent_surgery' => false,
            ]);
    });

    describe('emergency check', function () {
        it('flags the booking, saves the intake and shows the 911 message on the form page', function () {
            $url = route('my.appointments.intake.update', $this->appointment);

            $this->actingAs($this->patient)
                ->put($url, intakePayload(['reason' => 'Chest pain since this morning']))
                ->assertRedirect($this->formUrl)
                ->assertSessionHasNoErrors();

            expect($this->appointment->fresh()->emergency_flag)->toBeTrue()
                ->and(Intake::query()->count())->toBe(1);

            // The message comes from the booking, so it is still there on a plain reload. The page gets a yes/no only.
            $this->actingAs($this->patient)
                ->get($this->formUrl)
                ->assertInertia(fn (Assert $page) => $page
                    ->where('emergency', true)
                    ->where('can.save', true)
                    ->where('intake.answers.reason', 'Chest pain since this morning'));
        });

        it('takes the message away when the patient corrects the answers', function () {
            $url = route('my.appointments.intake.update', $this->appointment);

            $this->actingAs($this->patient)->put($url, intakePayload(['reason' => 'Chest pain']))->assertRedirect();
            $this->actingAs($this->patient)->put($url, intakePayload(['reason' => 'Mild cough']))->assertRedirect();

            $this->actingAs($this->patient)
                ->get($this->formUrl)
                ->assertInertia(fn (Assert $page) => $page->where('emergency', false));

            expect($this->appointment->fresh()->emergency_flag)->toBeFalse();
        });

        // The whole chain, not a flag set by hand: the patient's real save is what staff see on the day grid.
        it('shows staff the red flag after the patient saves, and never shows it to an admin', function () {
            $this->actingAs($this->patient)
                ->put(route('my.appointments.intake.update', $this->appointment), intakePayload(['reason' => 'Chest pain']))
                ->assertRedirect();

            $date = $this->appointment->starts_at->timezone(config('app.clinic_timezone'))->toDateString();
            $rowFor = fn (Role $role): array => collect(
                $this->actingAs(userWithRole($role))->get(route('staff.home', ['date' => $date]))->assertOk()->inertiaProps('schedule.doctors'),
            )->flatMap(fn (array $doctor): array => $doctor['appointments'])->firstWhere('ulid', $this->appointment->ulid);

            expect($rowFor(Role::Staff))->toMatchArray(['emergencyFlag' => true, 'intakeMissing' => false])
                ->and($rowFor(Role::Admin))->not->toHaveKey('emergencyFlag');
        });

        it('does not flag the booking when the form is refused', function () {
            ($this->openedForm)();

            $this->actingAs($this->patient)
                ->put(route('my.appointments.intake.update', $this->appointment), intakePayload([
                    'reason' => '',
                    'symptoms' => 'I have chest pain',
                ]))
                ->assertSessionHasErrors('reason');

            expect($this->appointment->fresh()->emergency_flag)->toBeFalse()
                ->and(Intake::query()->count())->toBe(0);
        });

        it('does not flag the booking when another patient sends an emergency phrase', function () {
            $this->actingAs(User::factory()->patient()->create())
                ->put(route('my.appointments.intake.update', $this->appointment), intakePayload(['reason' => 'Chest pain']))
                ->assertForbidden();

            expect($this->appointment->fresh()->emergency_flag)->toBeFalse();
        });

    });

    it('edits the same intake on the next save', function () {
        $url = route('my.appointments.intake.update', $this->appointment);

        $this->actingAs($this->patient)->put($url, intakePayload())->assertRedirect();
        $this->actingAs($this->patient)->put($url, intakePayload(['reason' => 'Fever since last night', 'pregnant' => '1']))->assertRedirect();

        $intake = Intake::query()->sole();

        expect($intake->answers['reason'])->toBe('Fever since last night')
            ->and($intake->answers['pregnant'])->toBeTrue();
    });

    it('stores the answers encrypted', function () {
        $this->actingAs($this->patient)
            ->put(route('my.appointments.intake.update', $this->appointment), intakePayload(['reason' => 'Secret chest rash']))
            ->assertRedirect();

        $raw = DB::table('intakes')->where('appointment_id', $this->appointment->id)->value('answers');

        expect($raw)->not->toContain('Secret chest rash')
            ->and(Intake::query()->sole()->answers['reason'])->toBe('Secret chest rash');
    });

    it('stores a blank optional field as no answer', function () {
        $this->actingAs($this->patient)
            ->put(route('my.appointments.intake.update', $this->appointment), intakePayload([
                'symptoms' => '', 'duration' => '', 'allergies' => '   ', 'medicines' => null,
            ]))
            ->assertSessionHasNoErrors();

        expect(Intake::query()->sole()->answers)
            ->symptoms->toBeNull()
            ->duration->toBeNull()
            ->allergies->toBeNull()
            ->medicines->toBeNull();
    });

    it('accepts text of exactly the longest length', function () {
        $this->actingAs($this->patient)
            ->put(route('my.appointments.intake.update', $this->appointment), intakePayload([
                'reason' => str_repeat('a', 500),
                'symptoms' => str_repeat('b', 2000),
                'allergies' => str_repeat('c', 500),
                'medicines' => str_repeat('d', 500),
            ]))
            ->assertSessionHasNoErrors();

        expect(Intake::query()->count())->toBe(1);
    });

    it('ignores fields that are not part of the form', function () {
        $other = Appointment::factory()->for($this->patient, 'patient')->booked()->startingIn(days: 3)->create();

        $this->actingAs($this->patient)
            ->put(route('my.appointments.intake.update', $this->appointment), intakePayload([
                'appointment_id' => $other->id,
                'status' => 'completed',
                'emergency_flag' => '1',
                'role' => 'admin',
            ]))
            ->assertSessionHasNoErrors();

        $intake = Intake::query()->sole();

        expect($intake->appointment_id)->toBe($this->appointment->id)
            ->and($intake->answers)->not->toHaveKey('role')
            ->and($this->appointment->fresh()->status)->toBe(AppointmentStatus::Booked)
            ->and($this->appointment->fresh()->emergency_flag)->toBeFalse();
    });

    describe('validation', function () {
        it('asks for the reason and the three yes/no answers when the form is empty', function () {
            ($this->openedForm)();

            $this->actingAs($this->patient)
                ->put(route('my.appointments.intake.update', $this->appointment), [])
                ->assertRedirect($this->formUrl)
                ->assertSessionHasErrors([
                    'reason' => 'The reason for the visit field is required.',
                    'fever' => 'The fever field is required.',
                    'pregnant' => 'The pregnant field is required.',
                    'recent_surgery' => 'The recent surgery field is required.',
                ]);

            expect(Intake::query()->count())->toBe(0);
        });

        it('refuses a value that is not valid and saves nothing', function (string $field, mixed $value) {
            ($this->openedForm)();

            $this->actingAs($this->patient)
                ->put(route('my.appointments.intake.update', $this->appointment), intakePayload([$field => $value]))
                ->assertRedirect($this->formUrl)
                ->assertSessionHasErrors($field);

            expect(Intake::query()->count())->toBe(0);
        })->with([
            'a blank reason' => ['reason', '   '],
            'a reason over 500' => ['reason', str_repeat('a', 501)],
            'symptoms over 2000' => ['symptoms', str_repeat('a', 2001)],
            'allergies over 500' => ['allergies', str_repeat('a', 501)],
            'medicines over 500' => ['medicines', str_repeat('a', 501)],
            'a duration that is not offered' => ['duration', 'forever'],
            'a reason that is a list' => ['reason', ['a', 'b']],
            'a fever that is not yes or no' => ['fever', 'maybe'],
            'a pregnant that is not yes or no' => ['pregnant', 'maybe'],
            'a recent surgery that is not yes or no' => ['recent_surgery', 'maybe'],
        ]);

        // A refused form is sent back with the input flashed to the session ("old input"). The sessions table is not
        // encrypted, so health text must not go there. The page does not need it: the typed values stay in the form.
        it('does not keep the typed answers in the session when the form is refused', function (string $field) {
            ($this->openedForm)();

            $this->actingAs($this->patient)
                ->put(route('my.appointments.intake.update', $this->appointment), intakePayload([
                    'reason' => '',
                    'symptoms' => 'Secret symptoms',
                    'allergies' => 'Secret allergies',
                    'medicines' => 'Secret medicines',
                    'fever' => '1',
                ]))
                ->assertSessionHasErrors('reason')
                ->assertSessionMissing("_old_input.$field");
        })->with(['reason', 'symptoms', 'duration', 'allergies', 'medicines', 'fever', 'pregnant', 'recent_surgery']);

        it('says what is wrong in words the patient can use', function () {
            ($this->openedForm)();

            $this->actingAs($this->patient)
                ->put(route('my.appointments.intake.update', $this->appointment), intakePayload([
                    'reason' => str_repeat('a', 501),
                    'medicines' => str_repeat('a', 501),
                    'duration' => 'forever',
                ]))
                ->assertSessionHasErrors([
                    'reason' => 'The reason for the visit field must not be greater than 500 characters.',
                    'medicines' => 'The current medicines field must not be greater than 500 characters.',
                    'duration' => 'The selected duration is invalid.',
                ]);
        });

        it('keeps what the patient saved before when an edit is refused', function () {
            $url = route('my.appointments.intake.update', $this->appointment);
            $this->actingAs($this->patient)->put($url, intakePayload(['reason' => 'My first reason']));

            ($this->openedForm)();
            $this->actingAs($this->patient)->put($url, intakePayload(['reason' => '']))->assertSessionHasErrors('reason');

            expect(Intake::query()->sole()->answers['reason'])->toBe('My first reason');
        });
    });

    describe('denied access', function () {
        it('answers 403 to another patient and saves nothing', function () {
            $this->actingAs(User::factory()->patient()->create())
                ->put(route('my.appointments.intake.update', $this->appointment), intakePayload())
                ->assertForbidden();

            expect(Intake::query()->count())->toBe(0);
        });

        it('answers 403 to another patient who edits an intake, and the answers stay as they were', function () {
            $intake = Intake::factory()->for($this->appointment)->create(['answers' => ['reason' => 'My own reason']]);

            $this->actingAs(User::factory()->patient()->create())
                ->put(route('my.appointments.intake.update', $this->appointment), intakePayload(['reason' => 'Overwritten']))
                ->assertForbidden();

            expect($intake->fresh()->answers['reason'])->toBe('My own reason');
        });

        it('answers 403 to staff, a doctor and an admin', function (Role $role) {
            $this->actingAs(userWithRole($role))
                ->put(route('my.appointments.intake.update', $this->appointment), intakePayload())
                ->assertForbidden();

            expect(Intake::query()->count())->toBe(0);
        })->with([Role::Staff, Role::Doctor, Role::Nurse, Role::Admin]);

        it('answers 403 once the patient is checked in, and the answers stay as they were', function () {
            $intake = Intake::factory()->for($this->appointment)->create(['answers' => ['reason' => 'Before check-in']]);
            $this->appointment->update(['status' => AppointmentStatus::CheckedIn, 'checked_in_at' => now()]);

            $this->actingAs($this->patient)
                ->put(route('my.appointments.intake.update', $this->appointment), intakePayload(['reason' => 'After check-in']))
                ->assertForbidden();

            expect($intake->fresh()->answers['reason'])->toBe('Before check-in');
        });

        it('sends a guest to log in', function () {
            $this->put(route('my.appointments.intake.update', $this->appointment), intakePayload())
                ->assertRedirect(route('login'));

            expect(Intake::query()->count())->toBe(0);
        });
    });

    describe('rate limit', function () {
        it('lets a patient save 20 times a minute and then answers 429', function () {
            $url = route('my.appointments.intake.update', $this->appointment);

            for ($save = 0; $save < 20; $save++) {
                $this->actingAs($this->patient)->put($url, intakePayload())->assertRedirect();
            }

            $this->actingAs($this->patient)->put($url, intakePayload())->assertStatus(429);

            // The cap is per patient, so another patient is not slowed down.
            $other = User::factory()->patient()->create();
            $otherAppointment = Appointment::factory()->for($other, 'patient')->booked()->startingIn(days: 2)->create();

            $this->actingAs($other)
                ->put(route('my.appointments.intake.update', $otherAppointment), intakePayload())
                ->assertRedirect();
        });
    });
});
