<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Intake;
use App\Models\User;
use App\Models\VisitNote;
use Illuminate\Support\Facades\Gate;

/** A booked appointment with an intake, plus the patient and the doctor user who own it. */
function appointmentWithIntake(): array
{
    $appointment = Appointment::factory()->booked()->create();
    $intake = Intake::factory()->create(['appointment_id' => $appointment->id]);

    return [
        $intake,
        $appointment,
        User::query()->findOrFail($appointment->patient_id),
        User::query()->findOrFail(Doctor::query()->findOrFail($appointment->doctor_id)->user_id),
    ];
}

describe('intake answers', function () {
    it('can be read by the owning patient and the appointment\'s doctor only', function () {
        [$intake, , $patient, $doctor] = appointmentWithIntake();

        expect(Gate::forUser($patient)->allows('view', $intake))->toBeTrue()
            ->and(Gate::forUser($doctor)->allows('view', $intake))->toBeTrue()
            ->and(Gate::forUser(userWithRole(Role::Patient))->allows('view', $intake))->toBeFalse()
            ->and(Gate::forUser(userWithRole(Role::Doctor))->allows('view', $intake))->toBeFalse();
    });

    it('can never be read by staff or admins (least privilege)', function (Role $role) {
        [$intake] = appointmentWithIntake();

        $user = userWithRole($role);

        expect(Gate::forUser($user)->denies('view', $intake))->toBeTrue()
            ->and(Gate::forUser($user)->denies('viewSummary', $intake))->toBeTrue();
    })->with([Role::Staff, Role::Admin]);

    it('can be edited by the owning patient only until check-in', function () {
        [$intake, $appointment, $patient] = appointmentWithIntake();

        expect(Gate::forUser($patient)->allows('update', $intake))->toBeTrue();

        $appointment->update(['status' => 'checked_in']);

        expect(Gate::forUser($patient)->denies('update', $intake->fresh()))->toBeTrue();
    });

    it('can be started by the owning patient for a booked appointment only', function () {
        $booked = Appointment::factory()->booked()->create();
        $checkedIn = Appointment::factory()->checkedIn()->create();

        expect(Gate::forUser(User::query()->findOrFail($booked->patient_id))->allows('create', [Intake::class, $booked]))->toBeTrue()
            ->and(Gate::forUser(User::query()->findOrFail($checkedIn->patient_id))->denies('create', [Intake::class, $checkedIn]))->toBeTrue()
            ->and(Gate::forUser(userWithRole(Role::Patient))->denies('create', [Intake::class, $booked]))->toBeTrue();
    });
});

describe('AI summary', function () {
    it('can be read and reviewed by the appointment\'s doctor only', function () {
        [$intake, , $patient, $doctor] = appointmentWithIntake();

        foreach (['viewSummary', 'reviewSummary'] as $ability) {
            expect(Gate::forUser($doctor)->allows($ability, $intake))->toBeTrue()
                ->and(Gate::forUser($patient)->denies($ability, $intake))->toBeTrue()
                ->and(Gate::forUser(userWithRole(Role::Doctor))->denies($ability, $intake))->toBeTrue();
        }
    });
});

describe('visit notes', function () {
    it('can be read and written by the author only', function () {
        $note = VisitNote::factory()->create();
        $author = User::query()->findOrFail(Doctor::query()->findOrFail($note->doctor_id)->user_id);

        expect(Gate::forUser($author)->allows('view', $note))->toBeTrue()
            ->and(Gate::forUser($author)->allows('update', $note))->toBeTrue()
            ->and(Gate::forUser(userWithRole(Role::Doctor))->denies('view', $note))->toBeTrue()
            ->and(Gate::forUser(userWithRole(Role::Doctor))->denies('update', $note))->toBeTrue();
    });

    it('can not be read by patients, staff or admins', function (Role $role) {
        $note = VisitNote::factory()->create();

        expect(Gate::forUser(userWithRole($role))->denies('view', $note))->toBeTrue();
    })->with([Role::Patient, Role::Staff, Role::Admin]);

    it('can be started for an appointment by its own doctor only', function () {
        $appointment = Appointment::factory()->checkedIn()->create();
        $ownDoctor = User::query()->findOrFail(Doctor::query()->findOrFail($appointment->doctor_id)->user_id);

        expect(Gate::forUser($ownDoctor)->allows('create', [VisitNote::class, $appointment]))->toBeTrue()
            ->and(Gate::forUser(userWithRole(Role::Doctor))->denies('create', [VisitNote::class, $appointment]))->toBeTrue()
            ->and(Gate::forUser(userWithRole(Role::Admin))->denies('create', [VisitNote::class, $appointment]))->toBeTrue();
    });
});
