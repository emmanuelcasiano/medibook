<?php

declare(strict_types=1);

use App\Actions\Booking\BookAppointment;
use App\Actions\Schedule\FindWalkInOptions;
use App\Actions\Staff\FindPatientsForWalkIn;
use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

// The "New walk-in" dialog on the staff schedule: the patient search, the options it offers and the booking route. The
// booking rules (the lead-time skip, today only, the limits, the rollback) are tested in BookWalkInTest. These tests
// cover what the HTTP layer adds: who may call the routes, what the lookups answer (and never answer), how the form is
// validated, and where a booking sends the browser. "Now" is Friday 30 October 2026, 09:00 in Manila. The service
// lasts 30 minutes with a 15 minute buffer, and both doctors work Friday 08:00 to 17:00.

/** A clinic time on that Friday as the ISO string the slot lists send. */
function walkInSlot(string $time): string
{
    return CarbonImmutable::parse("2026-10-30 $time", 'Asia/Manila')->utc()->toIso8601ZuluString();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));
    Notification::fake();

    $this->staff = userWithRole(Role::Staff);
    $this->service = Service::factory()->lasting(30, 15)->create(['name' => 'Check-up']);
    $this->doctor = doctorWorking($this->service, [[5, '08:00', '17:00']]);
    $this->otherDoctor = doctorWorking($this->service, [[5, '08:00', '17:00']]);
    $this->doctor->user->update(['name' => 'Dr. Abe']);
    $this->otherDoctor->user->update(['name' => 'Dr. Zed']);
    $this->patient = User::factory()->patient()->create(['name' => 'Maria Santos', 'email' => 'maria.santos@example.test']);

    // The dialog sits on the schedule page, so a refused booking goes back there.
    $this->schedulePage = route('staff.home');

    $this->validBooking = fn (array $overrides = []): array => [
        'patient_id' => $this->patient->id,
        'service_id' => $this->service->id,
        'doctor' => (string) $this->doctor->id,
        'starts_at' => walkInSlot('09:15'),
        'check_in' => true,
        ...$overrides,
    ];
});

describe('the patient search', function () {
    it('finds active patients by a part of the name or the email, and answers id, name and email only', function () {
        User::factory()->patient()->create(['name' => 'Mario Cruz', 'email' => 'mc@example.test']);
        User::factory()->patient()->create(['name' => 'Pedro Lim', 'email' => 'pedro.maria@example.test']);
        User::factory()->patient()->create(['name' => 'Ana Reyes', 'email' => 'ana@example.test']);

        $response = $this->actingAs($this->staff)->postJson(route('staff.walk-ins.patients'), ['q' => 'mari'])->assertOk();

        expect(collect($response->json('patients'))->pluck('name')->all())->toBe(['Maria Santos', 'Mario Cruz', 'Pedro Lim'])
            ->and($response->json('patients.0'))->toBe([
                'id' => $this->patient->id,
                'name' => 'Maria Santos',
                'email' => 'maria.santos@example.test',
            ]);
    });

    it('leaves out staff, doctors and switched-off patients with the same name', function () {
        User::factory()->patient()->create(['name' => 'Maria Off', 'is_active' => false]);
        userWithRole(Role::Staff)->update(['name' => 'Maria Staff']);
        userWithRole(Role::Doctor)->update(['name' => 'Maria Doctor']);

        $names = collect($this->actingAs($this->staff)->postJson(route('staff.walk-ins.patients'), ['q' => 'maria'])->json('patients'))->pluck('name')->all();

        expect($names)->toBe(['Maria Santos']);
    });

    it('treats the term as text, so a wildcard does not list everyone', function () {
        $found = $this->actingAs($this->staff)->postJson(route('staff.walk-ins.patients'), ['q' => '%%'])->assertOk()->json('patients');

        expect($found)->toBe([]);
    });

    it('answers at most 8 patients', function () {
        User::factory()->patient()->count(10)->sequence(fn ($sequence) => ['name' => 'Juan '.$sequence->index])->create();

        $found = $this->actingAs($this->staff)->postJson(route('staff.walk-ins.patients'), ['q' => 'juan'])->assertOk()->json('patients');

        expect($found)->toHaveCount(FindPatientsForWalkIn::LIMIT);
    });

    it('needs a term of 2 characters, and not a list', function (array $payload) {
        $this->actingAs($this->staff)->postJson(route('staff.walk-ins.patients'), $payload)->assertUnprocessable()->assertJsonValidationErrors('q');
    })->with([
        'nothing' => [[]],
        'one character' => [['q' => 'm']],
        'too long' => [['q' => str_repeat('a', 101)]],
        'a list' => [['q' => ['maria']]],
    ]);
});

describe('the options', function () {
    it('lists the visit types that can be booked now, and nothing else until one is picked', function () {
        Service::factory()->create(['name' => 'Switched off', 'is_active' => false]);
        Service::factory()->create(['name' => 'Nobody offers this']);

        $options = $this->actingAs($this->staff)->getJson(route('staff.walk-ins.options'))->assertOk()->json();

        expect($options['services'])->toBe([['id' => $this->service->id, 'name' => 'Check-up', 'durationMinutes' => 30]])
            ->and($options['doctors'])->toBe([])
            ->and($options['slots'])->toBe([]);
    });

    it('lists the service\'s bookable doctors by name, and today\'s nearest times from now for the picked doctor', function () {
        $retired = doctorWorking($this->service, [[5, '08:00', '17:00']]);
        $retired->user->forceFill(['is_active' => false])->save();

        $options = $this->actingAs($this->staff)->getJson(route('staff.walk-ins.options', ['service' => $this->service->id, 'doctor' => $this->doctor->id]))->assertOk()->json();

        expect(collect($options['doctors'])->pluck('name')->all())->toBe(['Dr. Abe', 'Dr. Zed'])
            ->and($options['slots'])->toHaveCount(FindWalkInOptions::SLOT_LIMIT)
            ->and($options['slots'][0])->toBe(['startsAt' => walkInSlot('09:00')])
            ->and($options['slots'][1])->toBe(['startsAt' => walkInSlot('09:15')]);
    });

    it('merges the doctors\' times for "Any doctor", and leaves out a time nobody has', function () {
        // Both doctors are busy from 09:00 to 09:45, so the nearest time anyone has is 09:45.
        bookingAt($this->doctor, $this->service, '2026-10-30 09:00');
        bookingAt($this->otherDoctor, $this->service, '2026-10-30 09:00');

        $options = $this->actingAs($this->staff)->getJson(route('staff.walk-ins.options', ['service' => $this->service->id, 'doctor' => 'any']))->assertOk()->json();

        expect($options['slots'][0])->toBe(['startsAt' => walkInSlot('09:45')]);
    });

    it('gives no times, and no reason, for a doctor who is not in the list', function (?int $offset) {
        $stranger = doctorWorking(Service::factory()->create(), [[5, '08:00', '17:00']]);

        $options = $this->actingAs($this->staff)->getJson(route('staff.walk-ins.options', [
            'service' => $this->service->id,
            'doctor' => $offset === null ? $stranger->id : 999999,
        ]))->assertOk()->json();

        expect($options['slots'])->toBe([])
            ->and($options['doctors'])->not->toBe([]);
    })->with([
        'a doctor who does not offer the service' => [null],
        'an id that does not exist' => [999999],
    ]);

    it('refuses a doctor that is neither an id nor "any"', function () {
        $this->actingAs($this->staff)->getJson(route('staff.walk-ins.options', ['doctor' => 'abe']))->assertUnprocessable()->assertJsonValidationErrors('doctor');
    });
});

describe('booking', function () {
    it('books an existing patient, checks them in and sends the browser to today\'s schedule with a message', function () {
        $this->actingAs($this->staff)
            ->from($this->schedulePage)
            ->post(route('staff.walk-ins.store'), ($this->validBooking)())
            ->assertRedirect(route('staff.home', ['date' => '2026-10-30']))
            ->assertSessionHas('inertia.flash_data.toast.type', 'success')
            ->assertSessionHas('inertia.flash_data.toast.message', 'Walk-in booked: Maria Santos with Dr. Abe at 9:15 AM, checked in.');

        $appointment = Appointment::query()->sole();

        expect($appointment->patient_id)->toBe($this->patient->id)
            ->and($appointment->doctor_id)->toBe($this->doctor->id)
            ->and($appointment->status)->toBe(AppointmentStatus::CheckedIn)
            ->and(AuditLog::query()->where('action', 'appointment.walk_in')->sole()->actor_id)->toBe($this->staff->id);
    });

    it('books without a check-in when the toggle is off', function () {
        $this->actingAs($this->staff)
            ->post(route('staff.walk-ins.store'), ($this->validBooking)(['check_in' => false]))
            ->assertSessionHas('inertia.flash_data.toast.message', 'Walk-in booked: Maria Santos with Dr. Abe at 9:15 AM.');

        expect(Appointment::query()->sole()->status)->toBe(AppointmentStatus::Booked);
    });

    it('makes a new patient account and books "Any doctor"', function () {
        $this->actingAs($this->staff)
            ->post(route('staff.walk-ins.store'), ($this->validBooking)([
                'patient_id' => null,
                'new_patient' => ['name' => 'Ana Reyes', 'email' => 'ana.reyes@example.test', 'birth_date' => '1991-04-12'],
                'doctor' => 'any',
            ]))
            ->assertRedirect(route('staff.home', ['date' => '2026-10-30']));

        $account = User::query()->where('email', 'ana.reyes@example.test')->sole();

        expect($account->role)->toBe(Role::Patient)
            ->and(Appointment::query()->sole()->patient_id)->toBe($account->id);

        Notification::assertSentTo($account, VerifyEmail::class);
        Notification::assertSentTo($account, ResetPassword::class);
    });

    it('sends a refused booking back to the schedule with the reason next to the field', function () {
        bookingAt($this->doctor, $this->service, '2026-10-30 09:15');

        $this->actingAs($this->staff)
            ->from($this->schedulePage)
            ->post(route('staff.walk-ins.store'), ($this->validBooking)())
            ->assertRedirect($this->schedulePage)
            ->assertSessionHasErrors(['starts_at' => BookAppointment::TIME_TAKEN_MESSAGE]);

        expect(Appointment::query()->count())->toBe(1);
    });
});

describe('validation', function () {
    it('names every missing field of an empty form', function () {
        $this->actingAs($this->staff)
            ->from($this->schedulePage)
            ->post(route('staff.walk-ins.store'), [])
            ->assertSessionHasErrors([
                'patient_id' => 'Pick a patient, or add a new one.',
                'new_patient' => 'Pick a patient, or add a new one.',
                'service_id' => 'Pick a visit type.',
                'doctor' => 'Pick a doctor, or Any doctor.',
                'starts_at' => 'Pick a time.',
                'check_in',
            ]);

        expect(Appointment::query()->count())->toBe(0);
    });

    it('refuses a form that has both an existing patient and a new one', function () {
        $this->actingAs($this->staff)
            ->post(route('staff.walk-ins.store'), ($this->validBooking)([
                'new_patient' => ['name' => 'Ana Reyes', 'email' => 'ana.reyes@example.test', 'birth_date' => '1991-04-12'],
            ]))
            ->assertSessionHasErrors(['patient_id' => 'Pick a patient, or add a new one, not both.']);

        expect(User::query()->where('email', 'ana.reyes@example.test')->exists())->toBeFalse();
    });

    it('checks the new patient\'s facts like registration does', function (array $newPatient, string $field, string $message) {
        $this->actingAs($this->staff)
            ->post(route('staff.walk-ins.store'), ($this->validBooking)(['patient_id' => null, 'new_patient' => $newPatient]))
            ->assertSessionHasErrors([$field => $message]);

        expect(Appointment::query()->count())->toBe(0)
            ->and(User::query()->where('role', Role::Patient)->count())->toBe(1);
    })->with([
        'no name' => [['name' => '', 'email' => 'ana@example.test', 'birth_date' => '1991-04-12'], 'new_patient.name', 'Enter the patient\'s name.'],
        'no email' => [['name' => 'Ana', 'email' => '', 'birth_date' => '1991-04-12'], 'new_patient.email', 'Enter the patient\'s email address.'],
        'an email that is taken' => [['name' => 'Ana', 'email' => 'maria.santos@example.test', 'birth_date' => '1991-04-12'], 'new_patient.email', 'This email address already has an account. Search for the patient instead.'],
        'no birth date' => [['name' => 'Ana', 'email' => 'ana@example.test', 'birth_date' => ''], 'new_patient.birth_date', 'Enter the patient\'s birth date.'],
        'a birth date in the future' => [['name' => 'Ana', 'email' => 'ana@example.test', 'birth_date' => '2027-01-01'], 'new_patient.birth_date', 'The birth date must be in the past.'],
        'a birth date that is not a date' => [['name' => 'Ana', 'email' => 'ana@example.test', 'birth_date' => '12/04/1991'], 'new_patient.birth_date', 'Enter a valid birth date.'],
    ]);

    it('refuses a patient id that is not an active patient, with one answer for every case', function (string $kind) {
        $account = match ($kind) {
            'staff' => userWithRole(Role::Staff),
            'doctor' => userWithRole(Role::Doctor),
            'switched-off' => User::factory()->patient()->create(['is_active' => false]),
            'missing' => null,
        };

        $this->actingAs($this->staff)
            ->post(route('staff.walk-ins.store'), ($this->validBooking)(['patient_id' => $account?->id ?? 999999]))
            ->assertSessionHasErrors(['patient_id' => 'Pick a patient from the list.']);

        expect(Appointment::query()->count())->toBe(0);
    })->with(['staff', 'doctor', 'switched-off', 'missing']);

    it('refuses a visit type or doctor the dialog could not have offered', function (string $field, string $kind, string $message) {
        $other = Service::factory()->create();
        $stranger = doctorWorking($other, [[5, '08:00', '17:00']]);
        $retired = doctorWorking($this->service, [[5, '08:00', '17:00']]);
        $retired->user->forceFill(['is_active' => false])->save();

        $overrides = match ($kind) {
            'a service nobody offers' => ['service_id' => Service::factory()->create()->id],
            'a switched-off service' => ['service_id' => Service::factory()->create(['is_active' => false])->id],
            'a missing service' => ['service_id' => 999999],
            'a doctor who does not offer it' => ['doctor' => (string) $stranger->id],
            'a switched-off doctor' => ['doctor' => (string) $retired->id],
            'a doctor that is not a number' => ['doctor' => 'abe'],
        };

        $this->actingAs($this->staff)
            ->post(route('staff.walk-ins.store'), ($this->validBooking)($overrides))
            ->assertSessionHasErrors([$field => $message]);

        expect(Appointment::query()->count())->toBe(0);
    })->with([
        'a service nobody offers' => ['service_id', 'a service nobody offers', 'Pick a visit type from the list.'],
        'a switched-off service' => ['service_id', 'a switched-off service', 'Pick a visit type from the list.'],
        'a missing service' => ['service_id', 'a missing service', 'Pick a visit type from the list.'],
        'a doctor who does not offer it' => ['doctor', 'a doctor who does not offer it', 'Pick a doctor from the list.'],
        'a switched-off doctor' => ['doctor', 'a switched-off doctor', 'Pick a doctor from the list.'],
        'a doctor that is not a number' => ['doctor', 'a doctor that is not a number', 'Pick a doctor from the list.'],
    ]);

    it('refuses a time that is not in the list, even if it looks like a time', function (string $startsAt, string $message) {
        $this->actingAs($this->staff)
            ->post(route('staff.walk-ins.store'), ($this->validBooking)(['starts_at' => $startsAt]))
            ->assertSessionHasErrors(['starts_at' => $message]);

        expect(Appointment::query()->count())->toBe(0);
    })->with([
        'not an ISO time' => ['09:15', 'Pick a time from the list.'],
        'a time that has passed' => ['2026-10-30T00:45:00Z', BookAppointment::TIME_TAKEN_MESSAGE],
        'a time off the 15 minute grid' => ['2026-10-30T01:20:00Z', BookAppointment::TIME_TAKEN_MESSAGE],
        'tomorrow' => ['2026-10-31T01:15:00Z', 'A walk-in can only be booked for today.'],
    ]);

    it('refuses a patient who already has 3 upcoming appointments', function () {
        foreach (['2026-10-31 09:00', '2026-11-02 09:00', '2026-11-03 09:00'] as $start) {
            Appointment::factory()->for($this->doctor)->for($this->service)->for($this->patient, 'patient')->booked()
                ->create(['starts_at' => CarbonImmutable::parse($start, 'Asia/Manila')->utc()]);
        }

        $this->actingAs($this->staff)
            ->post(route('staff.walk-ins.store'), ($this->validBooking)())
            ->assertSessionHasErrors('starts_at');

        expect(Appointment::query()->count())->toBe(3);
    });
});

describe('who may use it', function () {
    it('refuses patients, doctors and admins on every route, and sends a guest to log in', function (?Role $role) {
        $routes = [
            fn () => $this->postJson(route('staff.walk-ins.patients'), ['q' => 'maria']),
            fn () => $this->getJson(route('staff.walk-ins.options')),
            fn () => $this->postJson(route('staff.walk-ins.store'), ($this->validBooking)()),
        ];

        if ($role !== null) {
            $this->actingAs(userWithRole($role));
        }

        foreach ($routes as $call) {
            $role === null ? $call()->assertUnauthorized() : $call()->assertForbidden();
        }

        expect(Appointment::query()->count())->toBe(0);
    })->with([
        'a patient' => [Role::Patient],
        'a doctor' => [Role::Doctor],
        'an admin, who may look at the schedule' => [Role::Admin],
        'a guest' => [null],
    ]);
});

describe('what a refusal leaves behind', function () {
    it('makes no account and sends no email when a patient, doctor or admin posts a new patient', function (Role $role) {
        $accountsBefore = User::query()->count();

        $this->actingAs(userWithRole($role))
            ->postJson(route('staff.walk-ins.store'), ($this->validBooking)([
                'patient_id' => null,
                'new_patient' => ['name' => 'Ana Reyes', 'email' => 'ana.reyes@example.test', 'birth_date' => '1991-04-12'],
            ]))
            ->assertForbidden();

        // `userWithRole` made one more account, so compare with that one counted in.
        expect(User::query()->count())->toBe($accountsBefore + 1)
            ->and(User::query()->where('email', 'ana.reyes@example.test')->exists())->toBeFalse()
            ->and(Appointment::query()->count())->toBe(0)
            ->and(AuditLog::query()->count())->toBe(0);

        Notification::assertNothingSent();
    })->with([
        'a patient' => [Role::Patient],
        'a doctor' => [Role::Doctor],
        'an admin' => [Role::Admin],
    ]);

    it('gives no doctors and no times for a visit type that is switched off', function () {
        $this->service->update(['is_active' => false]);

        $options = $this->actingAs($this->staff)
            ->getJson(route('staff.walk-ins.options', ['service' => $this->service->id, 'doctor' => 'any']))
            ->assertOk()
            ->json();

        expect($options['services'])->toBe([])
            ->and($options['doctors'])->toBe([])
            ->and($options['slots'])->toBe([]);
    });
});

describe('rate limits', function () {
    it('lets 120 lookups a minute through per staff member and then answers 429', function () {
        RateLimiter::clear('staff-lookups');

        foreach (range(1, 120) as $lookup) {
            $this->actingAs($this->staff)->getJson(route('staff.walk-ins.options'))->assertOk();
        }

        $this->actingAs($this->staff)->getJson(route('staff.walk-ins.options'))->assertStatus(429);
        $this->actingAs($this->staff)->postJson(route('staff.walk-ins.patients'), ['q' => 'maria'])->assertStatus(429);

        // The count is per person, so another staff member is not held up.
        $this->actingAs(userWithRole(Role::Staff))->getJson(route('staff.walk-ins.options'))->assertOk();
    });

    it('keeps the lookups apart from the front-desk buttons, so typing never blocks a check-in', function () {
        RateLimiter::clear('staff-lookups');
        RateLimiter::clear('staff-actions');

        foreach (range(1, 121) as $lookup) {
            $this->actingAs($this->staff)->getJson(route('staff.walk-ins.options'));
        }

        // The lookups are used up, and a booking still goes through.
        $this->actingAs($this->staff)
            ->post(route('staff.walk-ins.store'), ($this->validBooking)())
            ->assertRedirect(route('staff.home', ['date' => '2026-10-30']));

        expect(Appointment::query()->count())->toBe(1);
    });

    it('caps the booking route at 60 a minute with the other front-desk buttons', function () {
        RateLimiter::clear('staff-actions');

        // A refused booking (no fields) counts too: the limit is on clicks, not on successes.
        foreach (range(1, 60) as $click) {
            $this->actingAs($this->staff)->post(route('staff.walk-ins.store'), []);
        }

        $this->actingAs($this->staff)->post(route('staff.walk-ins.store'), ($this->validBooking)())->assertStatus(429);

        expect(Appointment::query()->count())->toBe(0);
    });
});
