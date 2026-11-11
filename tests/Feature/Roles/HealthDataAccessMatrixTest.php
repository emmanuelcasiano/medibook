<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Intake;
use App\Models\IntakeSummary;
use App\Models\VisitNote;
use App\Models\VitalsRecord;

// The series check of the nurse vitals feature: every role against every vitals route and every page that carries
// health data, so adding the nurse (and the vitals) opened nothing by accident. One request per test, because the
// nurse's save changes data. The routes are tested one by one elsewhere (RecordVitalsTest, AppointmentDetailTest, the
// intake tests); this is the whole table in one place, so a new role or route that is missing from it is easy to spot.
// The save has its validation tests in RecordVitalsTest. Here it gets a valid payload, so a 403 is the Policy and not a
// validation error.

dataset('health_data_requests', function () {
    // What each request answers to the roles that may use it. Every other role gets 403.
    $allowed = [
        'vitals form' => ['nurse' => 200],
        'vitals save' => ['nurse' => 302],
        'doctor appointment page' => ['doctor' => 200],
        'patient intake form' => ['patient' => 200],
    ];

    foreach ($allowed as $request => $statuses) {
        foreach (Role::cases() as $role) {
            yield "{$role->value} on the {$request}" => [$role, $request, $statuses[$role->value] ?? 403];
        }
    }
});

it('gives each role only the health data it may have', function (Role $role, string $request, int $status) {
    // One checked-in appointment that holds every kind of health data, and a booked one for the intake form (the
    // patient may open an intake form only until check-in).
    // The doctor is made first, with two-factor on (the doctor routes need it), and owns the appointment.
    $doctorUser = userWithRole(Role::Doctor);
    $appointment = Appointment::factory()
        ->for(Doctor::query()->where('user_id', $doctorUser->id)->firstOrFail())
        ->checkedIn()
        ->create();
    VitalsRecord::factory()->forAppointment($appointment)->create([
        'readings' => ['systolic' => 187, 'diastolic' => 121, 'pulse' => 143, 'temperature' => 39.4, 'oxygen_saturation' => 91, 'weight' => 83.7],
    ]);
    Intake::factory()->for($appointment)->create(['answers' => ['reason' => 'INTAKE-SECRET']]);
    VisitNote::factory()->for($appointment)->create(['body' => 'NOTE-SECRET']);
    $booked = Appointment::factory()->booked()->create();
    Intake::factory()->for($booked)->create(['answers' => ['reason' => 'BOOKED-INTAKE-SECRET']]);

    // The "own" users, so ownership alone never opens a door that the role closes.
    $user = match ($role) {
        Role::Doctor => $doctorUser,
        Role::Patient => $request === 'patient intake form' ? $booked->patient : $appointment->patient,
        default => userWithRole($role),
    };

    $response = match ($request) {
        'vitals form' => $this->actingAs($user)->get(route('nurse.appointments.vitals.edit', $appointment)),
        'vitals save' => $this->actingAs($user)->put(route('nurse.appointments.vitals.update', $appointment), [
            'systolic' => 120, 'diastolic' => 80, 'pulse' => 70, 'temperature' => 36.8, 'oxygen_saturation' => 98, 'weight' => 70.5,
        ]),
        'doctor appointment page' => $this->actingAs($user)->get(route('doctor.appointments.show', $appointment)),
        'patient intake form' => $this->actingAs($user)->get(route('my.appointments.intake', $booked)),
    };

    $response->assertStatus($status);

    if ($status === 403) {
        // A refused request carries none of the health data, in any form.
        $response->assertDontSee('39.4')
            ->assertDontSee('83.7')
            ->assertDontSee('systolic')
            ->assertDontSee('INTAKE-SECRET')
            ->assertDontSee('NOTE-SECRET');

        // And a refused save changed nothing.
        expect(VitalsRecord::query()->sole()->readings['pulse'])->toBe(143);
    }
})->with('health_data_requests');

it('keeps the nurse out of every page that holds the intake, the summary or the notes', function () {
    $appointment = Appointment::factory()->checkedIn()->create();
    $intake = Intake::factory()->for($appointment)->create();
    IntakeSummary::factory()->for($intake)->create();
    $nurse = userWithRole(Role::Nurse);

    // The nurse writes vitals and reads nothing else. The queue (no health text) and the form are the nurse's own.
    $this->actingAs($nurse)->get(route('doctor.appointments.show', $appointment))->assertForbidden();
    $this->actingAs($nurse)->get(route('my.appointments.intake', $appointment))->assertForbidden();
    $this->actingAs($nurse)->get(route('staff.home'))->assertForbidden();
    $this->actingAs($nurse)->get(route('admin.audit-logs.index'))->assertForbidden();

    // The doctor's writes on health data are closed to the nurse too, and nothing changed.
    $this->actingAs($nurse)->put(route('doctor.appointments.visit-note.update', $appointment), ['body' => 'NURSE-NOTE'])->assertForbidden();
    $this->actingAs($nurse)->post(route('doctor.appointments.summary.review', $appointment), ['version' => 'x'])->assertForbidden();

    expect(VisitNote::query()->count())->toBe(0)
        ->and(IntakeSummary::query()->sole()->reviewed_at)->toBeNull();
});
