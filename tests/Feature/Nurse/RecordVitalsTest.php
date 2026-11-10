<?php

declare(strict_types=1);

use App\Actions\Triage\RecordVitals;
use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\User;
use App\Models\VitalsRecord;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Recording and correcting vitals: the form (`nurse.appointments.vitals.edit`) and the save (`...vitals.update`). The
// nurse takes the readings of a checked-in patient, they are stored encrypted, and a correction edits the same row until
// the visit is finished. "Now" is Friday 30 October 2026, 09:00 in Manila.

/**
 * A full set of readings, as the form posts them. Override one to test it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function vitalsPayload(array $overrides = []): array
{
    return [
        'systolic' => 120,
        'diastolic' => 80,
        'pulse' => 72,
        'temperature' => 36.8,
        'oxygen_saturation' => 98,
        'weight' => 70.5,
        ...$overrides,
    ];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));

    $this->nurse = userWithRole(Role::Nurse);
    $this->appointment = Appointment::factory()->checkedIn()->create();

    $this->formUrl = route('nurse.appointments.vitals.edit', $this->appointment);
    $this->saveUrl = route('nurse.appointments.vitals.update', $this->appointment);

    $this->save = fn (array $payload, ?User $as = null) => $this->actingAs($as ?? $this->nurse)->put($this->saveUrl, $payload);

    // Nothing was written: no record, no mark on the appointment, no audit row.
    $this->expectNothingWritten = function (): void {
        expect(VitalsRecord::count())->toBe(0)
            ->and($this->appointment->fresh()->vitals_taken_at)->toBeNull()
            ->and(AuditLog::count())->toBe(0);
    };
});

describe('the happy path', function () {
    it('opens an empty form for a checked-in patient, and writes no view row for it', function () {
        $this->actingAs($this->nurse)
            ->get($this->formUrl)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('nurse/vitals')
                ->where('readings', null)
                ->where('appointment.ulid', $this->appointment->ulid)
                ->where('appointment.patientName', $this->appointment->patient->name));

        // There is nothing to view yet.
        expect(AuditLog::count())->toBe(0);
    });

    it('saves the readings encrypted, marks the appointment and writes the audit row', function () {
        ($this->save)(vitalsPayload())->assertRedirect(route('nurse.home'))->assertSessionHasNoErrors();

        $record = VitalsRecord::query()->sole();
        $raw = (string) DB::table('vitals_records')->where('id', $record->id)->value('readings');

        // The column holds ciphertext: no key name and no value is readable in it.
        expect($raw)->not->toContain('systolic')->not->toContain('36.8')->not->toContain('70.5')
            ->and($record->readings)->toBe(vitalsPayload())
            ->and($record->appointment_id)->toBe($this->appointment->id)
            ->and($record->recorded_by)->toBe($this->nurse->id)
            ->and($record->recorded_at->equalTo(now()))->toBeTrue()
            ->and($this->appointment->fresh()->vitals_taken_at?->equalTo(now()))->toBeTrue()
            ->and(AuditLog::query()->where('action', 'vitals.saved')->sole()->metadata)->toBe(['mode' => 'created']);
    });

    it('stores the readings as numbers when the browser posts them as text', function () {
        ($this->save)(['systolic' => '120', 'diastolic' => '80', 'pulse' => '72', 'temperature' => '37', 'oxygen_saturation' => '98', 'weight' => '70.5'])
            ->assertSessionHasNoErrors();

        $readings = VitalsRecord::query()->sole()->readings;

        // The text became numbers, not strings. A whole temperature such as 37.0 comes back as 37 (JSON drops the ".0"),
        // which is the same number, so a page that shows it must format it to one decimal.
        expect($readings)->toEqual(vitalsPayload(['temperature' => 37.0]))
            ->and($readings['systolic'])->toBeInt()
            ->and($readings['temperature'])->toBeNumeric()
            ->and($readings['weight'])->toBeFloat();
    });

    it('corrects the same row in place, and keeps the time the vitals were first taken', function () {
        ($this->save)(vitalsPayload())->assertSessionHasNoErrors();
        $first = VitalsRecord::query()->sole();
        $takenAt = $this->appointment->fresh()->vitals_taken_at;

        $this->travel(10)->minutes();
        $other = userWithRole(Role::Nurse);
        ($this->save)(vitalsPayload(['pulse' => 90]), $other)->assertRedirect(route('nurse.home'))->assertSessionHasNoErrors();

        $record = VitalsRecord::query()->sole();

        expect($record->id)->toBe($first->id)
            ->and($record->readings['pulse'])->toBe(90)
            // The latest save is the one on record, and the nurse who made it.
            ->and($record->recorded_by)->toBe($other->id)
            ->and($record->recorded_at->equalTo(now()))->toBeTrue()
            // The mark says "vitals were taken", so it keeps its first time.
            ->and($this->appointment->fresh()->vitals_taken_at?->equalTo($takenAt))->toBeTrue()
            ->and(AuditLog::query()->where('action', 'vitals.saved')->orderBy('id')->pluck('metadata')->all())
            ->toBe([['mode' => 'created'], ['mode' => 'updated']]);
    });

    it('opens the form with the saved readings, and logs that the nurse looked', function () {
        $record = VitalsRecord::factory()->forAppointment($this->appointment)->create([
            'readings' => vitalsPayload(['systolic' => 187, 'weight' => 83.7]),
        ]);

        $this->actingAs($this->nurse)
            ->get($this->formUrl)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('readings.systolic', 187)
                ->where('readings.weight', 83.7));

        $viewed = AuditLog::query()->where('action', 'vitals.viewed')->sole();
        expect($viewed->subject_id)->toBe($record->id)->and($viewed->actor_id)->toBe($this->nurse->id);
    });

    it('ignores any field that is not one of the six readings', function () {
        $stranger = User::factory()->patient()->create();

        ($this->save)(vitalsPayload([
            'recorded_by' => $stranger->id,
            'appointment_id' => Appointment::factory()->checkedIn()->create()->id,
            'vitals_taken_at' => '2020-01-01 00:00:00',
        ]))->assertSessionHasNoErrors();

        $record = VitalsRecord::query()->sole();

        expect($record->recorded_by)->toBe($this->nurse->id)
            ->and($record->appointment_id)->toBe($this->appointment->id)
            ->and($record->readings)->toBe(vitalsPayload())
            ->and($this->appointment->fresh()->vitals_taken_at?->equalTo(now()))->toBeTrue();
    });
});

describe('denied access', function () {
    it('is closed to every role but the nurse, on the form and on the save', function (Role $role) {
        $user = userWithRole($role);

        $this->actingAs($user)->get($this->formUrl)->assertForbidden();
        ($this->save)(vitalsPayload(), $user)->assertForbidden();

        ($this->expectNothingWritten)();
    })->with([Role::Patient, Role::Staff, Role::Doctor, Role::Admin]);

    it('is closed to the appointment\'s own doctor and own patient too', function () {
        $doctor = userWithRole(Role::Doctor);
        $this->appointment->forceFill(['doctor_id' => Doctor::query()->where('user_id', $doctor->id)->value('id')])->save();

        $this->actingAs($doctor)->get($this->formUrl)->assertForbidden();
        ($this->save)(vitalsPayload(), $doctor)->assertForbidden();
        ($this->save)(vitalsPayload(), $this->appointment->patient)->assertForbidden();

        ($this->expectNothingWritten)();
    });

    it('sends a guest to the login page', function () {
        $this->get($this->formUrl)->assertRedirect(route('login'));
        $this->put($this->saveUrl, vitalsPayload())->assertRedirect(route('login'));

        ($this->expectNothingWritten)();
    });

    it('is closed to the nurse unless the patient is checked in', function (string $state) {
        $appointment = Appointment::factory()->{$state}()->create();

        $this->actingAs($this->nurse)->get(route('nurse.appointments.vitals.edit', $appointment))->assertForbidden();
        $this->actingAs($this->nurse)->put(route('nurse.appointments.vitals.update', $appointment), vitalsPayload())->assertForbidden();

        expect(VitalsRecord::count())->toBe(0)
            ->and($appointment->fresh()->vitals_taken_at)->toBeNull()
            ->and(AuditLog::count())->toBe(0);
    })->with(['booked', 'completed', 'cancelled', 'noShow']);

    it('closes the form once the doctor finishes the visit, and changes nothing', function () {
        VitalsRecord::factory()->forAppointment($this->appointment)->create(['readings' => vitalsPayload()]);
        Appointment::query()->whereKey($this->appointment->id)->update(['status' => AppointmentStatus::Completed->value]);

        $this->actingAs($this->nurse)->get($this->formUrl)->assertForbidden();
        ($this->save)(vitalsPayload(['pulse' => 150]))->assertForbidden();

        expect(VitalsRecord::query()->sole()->readings)->toBe(vitalsPayload())
            // No view row either: a refused form leaves no trace of a reading that was never shown.
            ->and(AuditLog::count())->toBe(0);
    });

    it('refuses a save that lost the race to the doctor finishing the visit', function () {
        // The nurse's page loaded the appointment while it was checked in, and the doctor finished it a moment later.
        $stale = $this->appointment;
        Appointment::query()->whereKey($stale->id)->update(['status' => AppointmentStatus::Completed->value]);

        expect($stale->status)->toBe(AppointmentStatus::CheckedIn)
            ->and(fn () => app(RecordVitals::class)->handle($this->nurse, $stale, vitalsPayload()))->toThrow(AuthorizationException::class);

        expect(VitalsRecord::count())->toBe(0)
            ->and($this->appointment->fresh()->vitals_taken_at)->toBeNull()
            ->and(AuditLog::count())->toBe(0);
    });

    it('refuses the Action to anyone but the nurse', function (Role $role) {
        expect(fn () => app(RecordVitals::class)->handle(userWithRole($role), $this->appointment, vitalsPayload()))
            ->toThrow(AuthorizationException::class);

        ($this->expectNothingWritten)();
    })->with([Role::Patient, Role::Staff, Role::Doctor, Role::Admin]);
});

describe('validation', function () {
    it('asks for every reading', function () {
        ($this->save)([])->assertInvalid([
            'systolic' => 'Enter the systolic pressure.',
            'diastolic' => 'Enter the diastolic pressure.',
            'pulse' => 'Enter the pulse.',
            'temperature' => 'Enter the temperature.',
            'oxygen_saturation' => 'Enter the oxygen saturation.',
            'weight' => 'Enter the weight.',
        ]);

        ($this->expectNothingWritten)();
    });

    it('asks for a reading that is left empty', function (string $field) {
        ($this->save)(vitalsPayload([$field => '']))->assertInvalid([$field]);

        ($this->expectNothingWritten)();
    })->with(['systolic', 'diastolic', 'pulse', 'temperature', 'oxygen_saturation', 'weight']);

    // Just inside and just outside every range. A systolic or diastolic case moves the other number out of the way, so
    // the only rule that can fail is the one under test.
    it('accepts the ends of every range and refuses a value just outside', function (string $field, int|float|string $value, array $with, bool $valid) {
        $response = ($this->save)(vitalsPayload([...$with, $field => $value]));

        if ($valid) {
            $response->assertSessionHasNoErrors();
            expect(VitalsRecord::count())->toBe(1);
        } else {
            $response->assertInvalid([$field => 'Check the reading.']);
            ($this->expectNothingWritten)();
        }
    })->with([
        'systolic 50' => ['systolic', 50, ['diastolic' => 40], true],
        'systolic 260' => ['systolic', 260, [], true],
        'systolic 49' => ['systolic', 49, ['diastolic' => 40], false],
        'systolic 261' => ['systolic', 261, [], false],
        'diastolic 30' => ['diastolic', 30, [], true],
        'diastolic 160' => ['diastolic', 160, ['systolic' => 200], true],
        'diastolic 29' => ['diastolic', 29, [], false],
        'diastolic 161' => ['diastolic', 161, ['systolic' => 260], false],
        'pulse 20' => ['pulse', 20, [], true],
        'pulse 250' => ['pulse', 250, [], true],
        'pulse 19' => ['pulse', 19, [], false],
        'pulse 251' => ['pulse', 251, [], false],
        'temperature 30.0' => ['temperature', '30.0', [], true],
        'temperature 45' => ['temperature', 45, [], true],
        'temperature 29.9' => ['temperature', '29.9', [], false],
        'temperature 45.1' => ['temperature', '45.1', [], false],
        'oxygen saturation 50' => ['oxygen_saturation', 50, [], true],
        'oxygen saturation 100' => ['oxygen_saturation', 100, [], true],
        'oxygen saturation 49' => ['oxygen_saturation', 49, [], false],
        'oxygen saturation 101' => ['oxygen_saturation', 101, [], false],
        'weight 1' => ['weight', 1, [], true],
        'weight 400' => ['weight', 400, [], true],
        'weight 0.9' => ['weight', '0.9', [], false],
        'weight 400.1' => ['weight', '400.1', [], false],
    ]);

    it('refuses a diastolic pressure that is not lower than the systolic one', function (int $diastolic) {
        ($this->save)(vitalsPayload(['systolic' => 120, 'diastolic' => $diastolic]))
            ->assertInvalid(['diastolic' => 'Check the reading. The diastolic pressure must be less than the systolic pressure.']);

        ($this->expectNothingWritten)();
    })->with(['equal' => 120, 'higher' => 130]);

    it('accepts a diastolic pressure one lower than the systolic one', function () {
        ($this->save)(vitalsPayload(['systolic' => 120, 'diastolic' => 119]))->assertSessionHasNoErrors();

        expect(VitalsRecord::count())->toBe(1);
    });

    it('takes whole numbers only for pressure, pulse and oxygen saturation', function (string $field) {
        ($this->save)(vitalsPayload([$field => 70.5]))->assertInvalid([$field => 'Use a whole number.']);

        ($this->expectNothingWritten)();
    })->with(['systolic', 'diastolic', 'pulse', 'oxygen_saturation']);

    it('takes at most one decimal place for temperature and weight', function (string $field) {
        ($this->save)(vitalsPayload([$field => '37.55']))->assertInvalid([$field => 'Use at most one decimal place.']);

        ($this->expectNothingWritten)();
    })->with(['temperature', 'weight']);

    it('refuses text where a number is needed', function (string $field, string $message) {
        ($this->save)(vitalsPayload([$field => 'abc']))->assertInvalid([$field => $message]);

        ($this->expectNothingWritten)();
    })->with([
        'pulse' => ['pulse', 'Use a whole number.'],
        'temperature' => ['temperature', 'Enter a number.'],
        'weight' => ['weight', 'Enter a number.'],
    ]);

    it('never says a reading is high, low or dangerous', function (array $overrides) {
        ($this->save)(vitalsPayload($overrides))->assertSessionHasErrors();

        $messages = strtolower(collect(app('session.store')->get('errors')->getBag('default')->all())->implode(' '));

        // The app checks that a number is plausible. Whether it is a worry is for the doctor, not for an error message.
        expect($messages)->toContain('check the reading')
            ->not->toContain('high')->not->toContain('low')->not->toContain('danger')->not->toContain('critical');
    })->with([
        'every reading out of range' => [['systolic' => 261, 'diastolic' => 20, 'pulse' => 5, 'temperature' => '50.0', 'oxygen_saturation' => 20, 'weight' => 0]],
        'diastolic not below systolic' => [['systolic' => 120, 'diastolic' => 130]],
    ]);
});

describe('one record per appointment', function () {
    it('corrects the row when the same appointment is saved twice, even from an old copy of it', function () {
        $stale = $this->appointment;

        app(RecordVitals::class)->handle($this->nurse, $stale, vitalsPayload());
        // The same model again, which never loaded the first record: the Action looks under the lock, not at the copy.
        app(RecordVitals::class)->handle($this->nurse, $stale, vitalsPayload(['pulse' => 88]));

        expect(VitalsRecord::count())->toBe(1)
            ->and(VitalsRecord::query()->sole()->readings['pulse'])->toBe(88);
    });

    it('is held to one row by the database itself, even if the code were to forget', function () {
        VitalsRecord::factory()->forAppointment($this->appointment)->create();

        expect(fn () => DB::table('vitals_records')->insert([
            'appointment_id' => $this->appointment->id,
            'recorded_by' => $this->nurse->id,
            'readings' => 'not real ciphertext',
            'recorded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(UniqueConstraintViolationException::class);

        expect(VitalsRecord::count())->toBe(1);
    });
});
