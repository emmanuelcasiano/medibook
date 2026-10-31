<?php

declare(strict_types=1);

use App\Actions\Booking\BookAnyDoctor;
use App\Actions\Booking\BookAppointment;
use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

// "Now" is Friday 30 October 2026, 09:00 in Manila. The test doctor works Mondays 08:00 to 12:00 and Fridays 08:00
// to 17:00, and the service is 60 minutes with no buffer, so Monday 2 November has start times 08:00 to 11:00.
// The slot rules themselves are tested in FindAvailableSlotsTest. These tests cover the confirm step: who may
// open it, that only a time the finders offer can be booked, and what a booking does.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));
});

/** A 60-minute service with no buffer. */
function confirmService(): Service
{
    return Service::factory()->lasting(60)->create();
}

/** A doctor who offers $service and works Mondays 08:00 to 12:00 and Fridays 08:00 to 17:00. */
function confirmDoctor(Service $service): Doctor
{
    return doctorWorking($service, [[1, '08:00', '12:00'], [5, '08:00', '17:00']]);
}

/** A clinic time as the slot pages send it: ISO 8601 UTC with a Z. */
function startsAtParam(string $clinicTime = '2026-11-02 08:00'): string
{
    return CarbonImmutable::parse($clinicTime, 'Asia/Manila')->utc()->format('Y-m-d\TH:i:s\Z');
}

/**
 * The confirm address a slot page links to. No $doctor means "Any doctor". The query keys are in alphabetical order,
 * which is how Laravel writes a URL it remembers for after the login.
 */
function confirmUrl(Service $service, ?Doctor $doctor = null, string $clinicTime = '2026-11-02 08:00'): string
{
    return route('booking.confirm', array_filter([
        'service' => $service,
        'doctor_id' => $doctor?->id,
        'starts_at' => startsAtParam($clinicTime),
    ]));
}

/** @return array{starts_at: string, doctor_id?: int} */
function bookingInput(?Doctor $doctor = null, string $clinicTime = '2026-11-02 08:00'): array
{
    return array_filter(['starts_at' => startsAtParam($clinicTime), 'doctor_id' => $doctor?->id]);
}

function patientUser(): User
{
    return User::factory()->patient()->create();
}

describe('confirm page', function () {
    it('shows the visit to a signed-in patient', function () {
        $service = confirmService();
        $doctor = confirmDoctor($service);
        $doctor->user->forceFill(['name' => 'Dr. Reyes'])->save();

        $this->actingAs(patientUser())
            ->get(confirmUrl($service, $doctor))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('booking/confirm')
                ->where('service.slug', $service->slug)
                ->where('doctor.name', 'Dr. Reyes')
                ->where('startsAt', '2026-11-02T00:00:00Z')
                ->where('endsAt', '2026-11-02T01:00:00Z')
                // 2 hours before the start, the patient's own cancel limit.
                ->where('cancelUntil', '2026-11-01T22:00:00Z'));
    });

    it('shows no doctor for "Any doctor"', function () {
        $service = confirmService();
        confirmDoctor($service);

        $this->actingAs(patientUser())
            ->get(confirmUrl($service))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('booking/confirm')->where('doctor', null));
    });

    it('sends a guest to log in and remembers the confirm address', function () {
        $service = confirmService();
        $url = confirmUrl($service, confirmDoctor($service));

        $this->get($url)
            ->assertRedirect(route('login'))
            ->assertSessionHas('url.intended', $url);
    });

    it('brings a guest back to the confirm address after logging in', function () {
        $service = confirmService();
        $url = confirmUrl($service, confirmDoctor($service));
        $patient = User::factory()->patient()->create(['email' => 'ana@example.com']);

        $this->get($url);

        $this->post(route('login.store'), ['email' => $patient->email, 'password' => 'password'])
            ->assertRedirect($url);
    });

    it('brings a guest back to the confirm address after registering', function () {
        $service = confirmService();
        $url = confirmUrl($service, confirmDoctor($service));

        $this->get($url);

        $this->post(route('register.store'), [
            'name' => 'New Patient',
            'email' => 'new@example.com',
            'birth_date' => '1990-04-12',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect($url);
    });

    it('sends an unverified patient to verify their email and remembers the confirm address', function () {
        $service = confirmService();
        $url = confirmUrl($service, confirmDoctor($service));

        $this->actingAs(User::factory()->patient()->unverified()->create())
            ->get($url)
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('url.intended', $url);
    });

    it('is closed to staff, doctors and admins', function (Role $role) {
        $service = confirmService();

        $this->actingAs(userWithRole($role))
            ->get(confirmUrl($service, confirmDoctor($service)))
            ->assertForbidden();
    })->with([Role::Staff, Role::Doctor, Role::Admin]);

    it('is a 404 for a switched-off service', function () {
        $service = Service::factory()->lasting(60)->inactive()->create();
        $doctor = confirmDoctor($service);

        $this->actingAs(patientUser())->get(confirmUrl($service, $doctor))->assertNotFound();
    });

    it('sends the patient back to the time picker when the time is not offered', function (string $clinicTime) {
        $service = confirmService();
        $doctor = confirmDoctor($service);

        $this->actingAs(patientUser())
            ->get(confirmUrl($service, $doctor, $clinicTime))
            ->assertRedirect(route('booking.slots.doctor', [$service, $doctor]))
            ->assertSessionHasErrors(['starts_at' => BookAppointment::TIME_TAKEN_MESSAGE]);
    })->with([
        'before the doctor opens' => '2026-11-02 07:45',
        'too late for the visit to end in the working range' => '2026-11-02 11:15',
        'on a day the doctor does not work' => '2026-11-03 09:00',
        'in the past' => '2026-10-26 09:00',
        'inside the 2 hour lead time' => '2026-10-30 10:00',
        'beyond the 30 day window' => '2026-12-07 09:00',
        'off the 15 minute step' => '2026-11-02 08:07',
    ]);

    it('sends the patient back to the "Any doctor" picker when no doctor is free', function () {
        $service = confirmService();
        confirmDoctor($service);

        $this->actingAs(patientUser())
            ->get(confirmUrl($service, null, '2026-11-03 09:00'))
            ->assertRedirect(route('booking.slots.any', $service))
            ->assertSessionHasErrors('starts_at');
    });

    it('refuses a time that someone else has just booked', function () {
        $service = confirmService();
        $doctor = confirmDoctor($service);
        bookingAt($doctor, $service, '2026-11-02 08:00');

        $this->actingAs(patientUser())
            ->get(confirmUrl($service, $doctor))
            ->assertRedirect(route('booking.slots.doctor', [$service, $doctor]))
            ->assertSessionHasErrors(['starts_at' => 'This time is no longer free. Please pick another slot.']);
    });

    it('accepts the first time after the lead time and reads the day in clinic time', function (string $clinicTime) {
        $service = confirmService();
        // Opens at 06:00 Manila on a Monday, which is still Sunday in UTC. A check that read the UTC date would look at the wrong day.
        $doctor = doctorWorking($service, [[1, '06:00', '12:00'], [5, '08:00', '17:00']]);

        $this->actingAs(patientUser())
            ->get(confirmUrl($service, $doctor, $clinicTime))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('booking/confirm'));
    })->with([
        // Now is 09:00, the lead time is 2 hours, so 11:00 is the first offered time today. 10:45 is refused above.
        'exactly at the lead time' => '2026-10-30 11:00',
        'before 08:00 Manila, the day before in UTC' => '2026-11-02 06:30',
    ]);

    it('refuses a missing or malformed time', function (array $query) {
        $service = confirmService();
        $doctor = confirmDoctor($service);

        $this->actingAs(patientUser())
            ->get(route('booking.confirm', ['service' => $service, 'doctor_id' => $doctor->id, ...$query]))
            ->assertRedirect(route('booking.slots.doctor', [$service, $doctor]))
            ->assertSessionHasErrors('starts_at');
    })->with([
        'missing' => [[]],
        'empty' => [['starts_at' => '']],
        'not a date' => [['starts_at' => 'tomorrow']],
        'a local time without the Z' => [['starts_at' => '2026-11-02T08:00:00']],
        'an array' => [['starts_at' => ['2026-11-02T00:00:00Z']]],
    ]);

    it('refuses a doctor who does not offer the service, is switched off, or does not exist', function (string $case) {
        $service = confirmService();
        confirmDoctor($service);

        $doctorId = match ($case) {
            'does not offer it' => doctorWorking(confirmService(), [[1, '08:00', '12:00']])->id,
            'switched off' => tap(confirmDoctor($service), fn (Doctor $doctor) => $doctor->user->forceFill(['is_active' => false])->save())->id,
            'does not exist' => 999999,
            'not a number' => 'abc',
        };

        $this->actingAs(patientUser())
            ->get(route('booking.confirm', ['service' => $service, 'starts_at' => startsAtParam(), 'doctor_id' => $doctorId]))
            ->assertRedirect(route('booking.doctors', $service))
            ->assertSessionHasErrors('doctor_id');
    })->with(['does not offer it', 'switched off', 'does not exist', 'not a number']);
});

describe('booking', function () {
    it('books the visit for the signed-in patient and shows the confirmation', function () {
        $service = confirmService();
        $doctor = confirmDoctor($service);
        $patient = patientUser();

        $response = $this->actingAs($patient)->post(route('booking.store', $service), bookingInput($doctor));

        $appointment = Appointment::query()->sole();

        $response->assertRedirect(route('my.appointments.confirmation', $appointment));

        expect($appointment->patient_id)->toBe($patient->id)
            ->and($appointment->doctor_id)->toBe($doctor->id)
            ->and($appointment->service_id)->toBe($service->id)
            ->and($appointment->status)->toBe(AppointmentStatus::Booked)
            ->and($appointment->starts_at->toIso8601ZuluString())->toBe('2026-11-02T00:00:00Z')
            ->and($appointment->ends_at->toIso8601ZuluString())->toBe('2026-11-02T01:00:00Z');
    });

    it('books the patient who is signed in, whatever the form says', function () {
        $service = confirmService();
        $doctor = confirmDoctor($service);
        $patient = patientUser();
        $someoneElse = patientUser();

        $this->actingAs($patient)->post(route('booking.store', $service), [
            ...bookingInput($doctor),
            'patient_id' => $someoneElse->id,
            'status' => 'completed',
            'ends_at' => '2030-01-01T00:00:00Z',
        ]);

        $appointment = Appointment::query()->sole();

        expect($appointment->patient_id)->toBe($patient->id)
            ->and($appointment->status)->toBe(AppointmentStatus::Booked)
            ->and($appointment->ends_at->toIso8601ZuluString())->toBe('2026-11-02T01:00:00Z');
    });

    it('books "Any doctor" with the first doctor who is free', function () {
        $service = confirmService();
        $first = confirmDoctor($service);
        $second = confirmDoctor($service);

        $this->actingAs(patientUser())->post(route('booking.store', $service), bookingInput())->assertRedirect();

        expect(Appointment::query()->sole()->doctor_id)->toBe($first->id);

        // The next patient asks for the same time. The first doctor is busy now, so the second one gets it.
        $this->actingAs(patientUser())->post(route('booking.store', $service), bookingInput())->assertRedirect();

        expect(Appointment::query()->orderBy('id')->pluck('doctor_id')->all())->toBe([$first->id, $second->id]);
    });

    it('skips a doctor who is switched off, or busy at that time, when booking "Any doctor"', function () {
        $service = confirmService();
        $switchedOff = confirmDoctor($service);
        $switchedOff->user->forceFill(['is_active' => false])->save();
        $busy = confirmDoctor($service);
        bookingAt($busy, $service, '2026-11-02 08:00');
        $free = confirmDoctor($service);

        $this->actingAs(patientUser())->post(route('booking.store', $service), bookingInput())->assertRedirect();

        expect(Appointment::query()->latest('id')->first()->doctor_id)->toBe($free->id);
    });

    it('books once when the form is sent twice', function () {
        $service = confirmService();
        $doctor = confirmDoctor($service);
        $patient = patientUser();

        $this->actingAs($patient)->post(route('booking.store', $service), bookingInput($doctor))->assertRedirect();

        // The second click arrives after the first booking: the time is gone, so it is refused and nothing is added.
        $this->actingAs($patient)->post(route('booking.store', $service), bookingInput($doctor))->assertSessionHasErrors('starts_at');

        expect(Appointment::query()->count())->toBe(1);
    });

    it('frees the time again when the booking is cancelled', function () {
        $service = confirmService();
        $doctor = confirmDoctor($service);
        bookingAt($doctor, $service, '2026-11-02 08:00', 'cancelled');

        $this->actingAs(patientUser())->post(route('booking.store', $service), bookingInput($doctor))->assertRedirect();

        expect(Appointment::query()->active()->count())->toBe(1);
    });

    it('moves on to the next doctor when the first one is taken inside the lock', function () {
        $service = confirmService();
        $first = confirmDoctor($service);
        $second = confirmDoctor($service);
        $patient = patientUser();
        $rival = patientUser();

        // The finders see both doctors free. While BookAppointment holds the first doctor's lock, another patient's
        // booking lands for that doctor: the race the lock and the overlap check exist for.
        $raced = false;
        DB::listen(function ($query) use (&$raced, $first, $service, $rival): void {
            if ($raced || ! str_contains($query->sql, 'from `doctors`') || ! str_contains($query->sql, 'for update')) {
                return;
            }

            $raced = true;
            Appointment::factory()->for($rival, 'patient')->for($first)->for($service)->create([
                'starts_at' => CarbonImmutable::parse('2026-11-02 08:00', 'Asia/Manila')->utc(),
            ]);
        });

        $appointment = app(BookAnyDoctor::class)->handle($patient, $service, CarbonImmutable::parse('2026-11-02 08:00', 'Asia/Manila'));

        expect($raced)->toBeTrue()
            ->and($appointment->doctor_id)->toBe($second->id);
    });

    it('gives the "time taken" error when every doctor is taken inside the lock', function () {
        $service = confirmService();
        $doctor = confirmDoctor($service);
        $patient = patientUser();
        $rival = patientUser();

        $raced = false;
        DB::listen(function ($query) use (&$raced, $doctor, $service, $rival): void {
            if ($raced || ! str_contains($query->sql, 'from `doctors`') || ! str_contains($query->sql, 'for update')) {
                return;
            }

            $raced = true;
            Appointment::factory()->for($rival, 'patient')->for($doctor)->for($service)->create([
                'starts_at' => CarbonImmutable::parse('2026-11-02 08:00', 'Asia/Manila')->utc(),
            ]);
        });

        try {
            app(BookAnyDoctor::class)->handle($patient, $service, CarbonImmutable::parse('2026-11-02 08:00', 'Asia/Manila'));
            $this->fail('A ValidationException was expected.');
        } catch (ValidationException $e) {
            expect($e->errors())->toBe(['starts_at' => [BookAppointment::TIME_TAKEN_MESSAGE]]);
        }

        // The rival's row was written inside the Action's transaction, so it is rolled back with the error.
        // What matters here is that the patient got nothing.
        expect($raced)->toBeTrue()
            ->and(Appointment::query()->where('patient_id', $patient->id)->count())->toBe(0);
    });

    it('does not try the next doctor when the patient is over a limit', function () {
        $service = confirmService();
        confirmDoctor($service);
        confirmDoctor($service);
        $patient = patientUser();

        foreach ([4, 5, 6] as $days) {
            Appointment::factory()->for($patient, 'patient')->for(confirmDoctor($service))->for($service)->startingIn(days: $days)->create();
        }

        $this->actingAs($patient)
            ->from(confirmUrl($service))
            ->post(route('booking.store', $service), bookingInput())
            ->assertRedirect(confirmUrl($service))
            ->assertSessionHasErrors(['starts_at' => 'You can have at most 3 upcoming appointments. Cancel one before booking another.']);

        expect(Appointment::query()->count())->toBe(3);
    });

    it('refuses a fourth upcoming booking and says why on the confirm page', function () {
        $service = confirmService();
        $doctor = confirmDoctor($service);
        $patient = patientUser();

        foreach ([4, 5, 6] as $days) {
            Appointment::factory()->for($patient, 'patient')->for(confirmDoctor($service))->for($service)->startingIn(days: $days)->create();
        }

        $this->actingAs($patient)
            ->from(confirmUrl($service, $doctor))
            ->post(route('booking.store', $service), bookingInput($doctor))
            ->assertRedirect(confirmUrl($service, $doctor))
            ->assertSessionHasErrors(['starts_at' => 'You can have at most 3 upcoming appointments. Cancel one before booking another.']);

        expect(Appointment::query()->count())->toBe(3);
    });

    it('refuses a time that overlaps the patient\'s own booking with another doctor', function () {
        $service = confirmService();
        $doctor = confirmDoctor($service);
        $patient = patientUser();
        Appointment::factory()->for($patient, 'patient')->for(confirmDoctor($service))->for($service)->create([
            'starts_at' => CarbonImmutable::parse('2026-11-02 08:00', 'Asia/Manila')->utc(),
        ]);

        $this->actingAs($patient)
            ->from(confirmUrl($service, $doctor, '2026-11-02 08:30'))
            ->post(route('booking.store', $service), bookingInput($doctor, '2026-11-02 08:30'))
            ->assertSessionHasErrors(['starts_at' => 'You already have an appointment at this time. Please pick another slot.']);

        expect(Appointment::query()->count())->toBe(1);
    });

    it('refuses a time that someone else has just booked, with the clash message', function () {
        $service = confirmService();
        $doctor = confirmDoctor($service);
        bookingAt($doctor, $service, '2026-11-02 08:00');

        $this->actingAs(patientUser())
            ->post(route('booking.store', $service), bookingInput($doctor))
            ->assertRedirect(route('booking.slots.doctor', [$service, $doctor]))
            ->assertSessionHasErrors(['starts_at' => 'This time is no longer free. Please pick another slot.']);

        expect(Appointment::query()->count())->toBe(1);
    });

    it('refuses a time the slot finders do not offer, and books nothing', function (string $clinicTime) {
        $service = confirmService();
        $doctor = confirmDoctor($service);

        $this->actingAs(patientUser())
            ->post(route('booking.store', $service), bookingInput($doctor, $clinicTime))
            ->assertSessionHasErrors('starts_at');

        $this->actingAs(patientUser())
            ->post(route('booking.store', $service), bookingInput(null, $clinicTime))
            ->assertSessionHasErrors('starts_at');

        expect(Appointment::query()->count())->toBe(0);
    })->with([
        'before the doctor opens' => '2026-11-02 07:45',
        'too late for the visit to end in the working range' => '2026-11-02 11:15',
        'on a day the doctor does not work' => '2026-11-03 09:00',
        'in the past' => '2026-10-26 09:00',
        'inside the 2 hour lead time' => '2026-10-30 10:00',
        'beyond the 30 day window' => '2026-12-07 09:00',
        'off the 15 minute step' => '2026-11-02 08:07',
    ]);

    it('refuses bad input', function (array $input) {
        $service = confirmService();
        $doctor = confirmDoctor($service);

        $this->actingAs(patientUser())
            ->post(route('booking.store', $service), [...bookingInput($doctor), ...$input])
            ->assertSessionHasErrors(array_key_first($input));

        expect(Appointment::query()->count())->toBe(0);
    })->with([
        'no time' => [['starts_at' => '']],
        'a time that is not a date' => [['starts_at' => 'tomorrow']],
        'a doctor id that is not a number' => [['doctor_id' => 'abc']],
        'a doctor who does not exist' => [['doctor_id' => 999999]],
    ]);

    it('refuses a doctor who does not offer the service', function () {
        $service = confirmService();
        confirmDoctor($service);
        $otherDoctor = doctorWorking(confirmService(), [[1, '08:00', '12:00']]);

        $this->actingAs(patientUser())
            ->post(route('booking.store', $service), bookingInput($otherDoctor))
            ->assertSessionHasErrors('doctor_id');

        expect(Appointment::query()->count())->toBe(0);
    });

    it('refuses a switched-off service with a 404', function () {
        $service = Service::factory()->lasting(60)->inactive()->create();
        $doctor = confirmDoctor($service);

        $this->actingAs(patientUser())
            ->post(route('booking.store', $service), bookingInput($doctor))
            ->assertNotFound();

        expect(Appointment::query()->count())->toBe(0);
    });

    it('sends a guest to log in and books nothing', function () {
        $service = confirmService();
        $doctor = confirmDoctor($service);

        $this->post(route('booking.store', $service), bookingInput($doctor))->assertRedirect(route('login'));

        expect(Appointment::query()->count())->toBe(0);
    });

    it('sends an unverified patient to verify their email and books nothing', function () {
        $service = confirmService();
        $doctor = confirmDoctor($service);

        $this->actingAs(User::factory()->patient()->unverified()->create())
            ->post(route('booking.store', $service), bookingInput($doctor))
            ->assertRedirect(route('verification.notice'));

        expect(Appointment::query()->count())->toBe(0);
    });

    it('is closed to staff, doctors and admins, and books nothing', function (Role $role) {
        $service = confirmService();
        $doctor = confirmDoctor($service);

        $this->actingAs(userWithRole($role))
            ->post(route('booking.store', $service), bookingInput($doctor))
            ->assertForbidden();

        expect(Appointment::query()->count())->toBe(0);
    })->with([Role::Staff, Role::Doctor, Role::Admin]);

    it('limits how often a patient can send a booking', function () {
        $service = confirmService();
        $doctor = confirmDoctor($service);
        $patient = patientUser();

        // Bad input is refused before anything is written, but each try still counts toward the limit.
        foreach (range(1, 10) as $_) {
            $this->actingAs($patient)->post(route('booking.store', $service), ['starts_at' => ''])->assertSessionHasErrors('starts_at');
        }

        $this->actingAs($patient)->post(route('booking.store', $service), bookingInput($doctor))->assertStatus(429);

        expect(Appointment::query()->count())->toBe(0);

        // The limit is per patient, so another patient is not locked out.
        $this->actingAs(patientUser())->post(route('booking.store', $service), bookingInput($doctor))->assertRedirect();
    });
});

describe('confirmation page', function () {
    it('shows a patient their own booking, with the doctor and the cancel limit', function () {
        $service = confirmService();
        $doctor = confirmDoctor($service);
        $doctor->user->forceFill(['name' => 'Dr. Reyes'])->save();
        $patient = patientUser();
        $appointment = Appointment::factory()->for($patient, 'patient')->for($doctor)->for($service)->create([
            'starts_at' => CarbonImmutable::parse('2026-11-02 08:00', 'Asia/Manila')->utc(),
        ]);

        $this->actingAs($patient)
            ->get(route('my.appointments.confirmation', $appointment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('booking/confirmation')
                ->where('appointment.ulid', $appointment->ulid)
                ->where('appointment.startsAt', '2026-11-02T00:00:00Z')
                ->where('appointment.endsAt', '2026-11-02T01:00:00Z')
                ->where('appointment.cancelUntil', '2026-11-01T22:00:00Z')
                ->where('appointment.service.slug', $service->slug)
                ->where('appointment.doctor.name', 'Dr. Reyes')
                ->missing('appointment.id')
                ->missing('appointment.patient'));
    });

    it('shows the doctor that "Any doctor" picked', function () {
        $service = confirmService();
        $doctor = confirmDoctor($service);
        $doctor->user->forceFill(['name' => 'Dr. Santos'])->save();
        $patient = patientUser();

        $this->actingAs($patient)->post(route('booking.store', $service), bookingInput());

        $this->actingAs($patient)
            ->get(route('my.appointments.confirmation', Appointment::query()->sole()))
            ->assertInertia(fn (Assert $page) => $page->where('appointment.doctor.name', 'Dr. Santos'));
    });

    it('is closed to another patient, and to staff, doctors and admins', function (?Role $role) {
        $appointment = Appointment::factory()->create();
        $viewer = $role === null ? patientUser() : userWithRole($role);

        $this->actingAs($viewer)->get(route('my.appointments.confirmation', $appointment))->assertForbidden();
    })->with([null, Role::Staff, Role::Doctor, Role::Admin]);

    it('sends a guest to log in', function () {
        $this->get(route('my.appointments.confirmation', Appointment::factory()->create()))->assertRedirect(route('login'));
    });

    it('links to an intake page that only the patient can open', function () {
        $appointment = Appointment::factory()->create();

        $this->actingAs($appointment->patient)->get(route('my.appointments.intake', $appointment))->assertOk();
        $this->actingAs(patientUser())->get(route('my.appointments.intake', $appointment))->assertForbidden();
        $this->actingAs(userWithRole(Role::Staff))->get(route('my.appointments.intake', $appointment))->assertForbidden();
    });
});
