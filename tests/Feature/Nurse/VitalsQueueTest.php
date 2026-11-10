<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Intake;
use App\Models\Service;
use App\Models\User;
use App\Models\VitalsRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// The nurse's triage queue (`nurse.home`): today's checked-in patients in two groups, oldest check-in first. It lists
// names, times, doctor and service and whether vitals were taken, and never a reading or any other health data. "Now" is
// Friday 30 October 2026, 09:00 in Manila.

/** A checked-in booking at a clinic time, for a patient with the given name. */
function queueBooking(string $startsAt, string $checkedInAt, string $patientName = 'Ana Cruz', string $state = 'checkedIn'): Appointment
{
    return Appointment::factory()
        ->for(User::factory()->patient()->create(['name' => $patientName]), 'patient')
        ->{$state}()
        ->create([
            'starts_at' => CarbonImmutable::parse($startsAt, 'Asia/Manila')->utc(),
            'checked_in_at' => CarbonImmutable::parse($checkedInAt, 'Asia/Manila')->utc(),
        ]);
}

/** The queue prop of the nurse home page: the patient names of each group, in order. */
function queueNames(array $queue): array
{
    return [
        'needsVitals' => array_column($queue['needsVitals'], 'patientName'),
        'vitalsTaken' => array_column($queue['vitalsTaken'], 'patientName'),
    ];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));
    $this->nurse = userWithRole(Role::Nurse);
    $this->queue = fn (): array => queueNames($this->actingAs($this->nurse)->get(route('nurse.home'))->assertOk()->inertiaProps('queue'));
});

describe('which patients are in the queue', function () {
    it('lists the checked-in patients of today, and nobody else', function () {
        queueBooking('2026-10-30 09:00', '2026-10-30 08:50', 'In line');
        queueBooking('2026-10-30 09:30', '2026-10-30 08:55', 'Also in line');

        foreach (['booked' => 'Not here yet', 'completed' => 'Finished', 'cancelled' => 'Cancelled', 'noShow' => 'Did not come'] as $state => $name) {
            queueBooking('2026-10-30 10:00', '2026-10-30 08:40', $name, $state);
        }

        expect(($this->queue)()['needsVitals'])->toBe(['In line', 'Also in line']);
    });

    it('reads the day in the clinic time zone, at both edges', function () {
        // 07:30 Manila is 23:30 UTC the day before, and 23:30 Manila is 15:30 UTC: both are still today in Manila.
        queueBooking('2026-10-30 00:15', '2026-10-30 00:10', 'Just after midnight');
        queueBooking('2026-10-30 23:30', '2026-10-30 23:20', 'Late evening');
        // One minute outside each edge.
        queueBooking('2026-10-29 23:59', '2026-10-29 23:50', 'Yesterday');
        queueBooking('2026-10-31 00:00', '2026-10-30 23:59', 'Tomorrow');

        expect(($this->queue)()['needsVitals'])->toBe(['Just after midnight', 'Late evening']);
    });

    it('puts the oldest check-in first, whatever the appointment time', function () {
        queueBooking('2026-10-30 09:00', '2026-10-30 08:58', 'Third');
        queueBooking('2026-10-30 11:00', '2026-10-30 08:30', 'First');
        queueBooking('2026-10-30 10:00', '2026-10-30 08:45', 'Second');

        expect(($this->queue)()['needsVitals'])->toBe(['First', 'Second', 'Third']);
    });

    it('splits the patients who still need vitals from those whose vitals are taken', function () {
        queueBooking('2026-10-30 09:00', '2026-10-30 08:50', 'Needs');
        $done = queueBooking('2026-10-30 09:15', '2026-10-30 08:40', 'Done');
        $alsoDone = queueBooking('2026-10-30 09:30', '2026-10-30 08:45', 'Also done');
        VitalsRecord::factory()->forAppointment($done)->create();
        VitalsRecord::factory()->forAppointment($alsoDone)->create();

        expect(($this->queue)())->toBe([
            'needsVitals' => ['Needs'],
            'vitalsTaken' => ['Done', 'Also done'],
        ]);
    });

    it('shows an empty queue as two empty groups', function () {
        expect(($this->queue)())->toBe(['needsVitals' => [], 'vitalsTaken' => []]);
    });
});

describe('what a row tells the nurse', function () {
    it('sends the name, times, doctor and service of each patient, and nothing more', function () {
        $service = Service::factory()->create(['name' => 'General check-up']);
        $doctor = Doctor::factory()->create();
        $doctor->user->forceFill(['name' => 'Dr. Reyes'])->save();
        $booking = queueBooking('2026-10-30 09:00', '2026-10-30 08:50');
        $booking->forceFill(['doctor_id' => $doctor->id, 'service_id' => $service->id])->save();

        $this->actingAs($this->nurse)
            ->get(route('nurse.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('queue.needsVitals.0.ulid', $booking->ulid)
                ->where('queue.needsVitals.0.patientName', 'Ana Cruz')
                ->where('queue.needsVitals.0.doctorName', 'Dr. Reyes')
                ->where('queue.needsVitals.0.serviceName', 'General check-up')
                ->where('queue.needsVitals.0.startsAt', '2026-10-30T01:00:00Z')
                ->where('queue.needsVitals.0.checkedInAt', '2026-10-30T00:50:00Z'));

        $row = $this->actingAs($this->nurse)->get(route('nurse.home'))->inertiaProps('queue.needsVitals.0');

        expect(collect($row)->keys()->sort()->values()->all())
            ->toBe(['checkedInAt', 'doctorName', 'patientName', 'serviceName', 'startsAt', 'ulid']);
    });

    it('sends no intake, emergency flag, contact detail, birth date or reading', function () {
        $booking = queueBooking('2026-10-30 09:00', '2026-10-30 08:50');
        $booking->forceFill(['emergency_flag' => true])->save();
        $booking->patient->forceFill(['email' => 'ana.private@example.test', 'birth_date' => '1990-04-12'])->save();
        Intake::factory()->for($booking)->create(['answers' => ['reason' => 'Private reason XYZ-7731', 'symptoms' => 'Private symptoms XYZ-7731']]);
        VitalsRecord::factory()->forAppointment($booking)->create([
            'readings' => ['systolic' => 187, 'diastolic' => 121, 'pulse' => 143, 'temperature' => 39.4, 'oxygen_saturation' => 91, 'weight' => 83.7],
        ]);

        $this->actingAs($this->nurse)
            ->get(route('nurse.home'))
            ->assertOk()
            ->assertDontSee('XYZ-7731')
            ->assertDontSee('ana.private@example.test')
            ->assertDontSee('1990-04-12')
            ->assertDontSee('emergencyFlag')
            ->assertDontSee('emergency_flag')
            ->assertDontSee('birth_date')
            // The decimals can't turn up by chance in an id or a time, unlike a whole number.
            ->assertDontSee('39.4')
            ->assertDontSee('83.7')
            ->assertDontSee('systolic')
            ->assertDontSee('readings');
    });

    it('never reads the readings to draw the queue', function () {
        $booking = queueBooking('2026-10-30 09:00', '2026-10-30 08:50');
        VitalsRecord::factory()->forAppointment($booking)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->nurse)->get(route('nurse.home'))->assertOk();

        $tables = collect(DB::getQueryLog())->pluck('query')->implode("\n");

        // The group comes from the `vitals_taken_at` mark, so no encrypted column is read or decrypted for a list.
        expect($tables)->not->toContain('vitals_records');
    });

    it('runs the same number of queries however many patients are waiting', function () {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->nurse)->get(route('nurse.home'))->assertOk();

            return count(DB::getQueryLog());
        };

        queueBooking('2026-10-30 09:00', '2026-10-30 08:50', 'One');
        $few = $queries();

        foreach (['Two', 'Three', 'Four', 'Five'] as $name) {
            VitalsRecord::factory()->forAppointment(queueBooking('2026-10-30 09:00', '2026-10-30 08:50', $name))->create();
        }

        expect($queries())->toBe($few);
    });
});

describe('who may open the queue', function () {
    it('is closed to every role but the nurse', function (Role $role) {
        queueBooking('2026-10-30 09:00', '2026-10-30 08:50');

        $this->actingAs(userWithRole($role))->get(route('nurse.home'))->assertForbidden();
    })->with([Role::Patient, Role::Staff, Role::Doctor, Role::Admin]);

    it('sends a guest to the login page', function () {
        $this->get(route('nurse.home'))->assertRedirect(route('login'));
    });
});
