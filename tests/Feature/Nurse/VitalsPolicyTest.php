<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\VitalsRecord;
use Illuminate\Support\Facades\Gate;

// The whole matrix of VitalsRecordPolicy, checked on the Policy itself, so a failure names the rule that is wrong. The
// routes have their own denied-access tests in RecordVitalsTest and VitalsQueueTest, which prove the endpoints call it.
// The Policy is deny by default: the nurse may record and correct only while the patient is checked in, and nobody may
// read the values yet (that comes with the doctor's view, so there is no `view` ability to test).

describe('viewing the queue', function () {
    it('is allowed to the nurse only', function (Role $role, bool $allowed) {
        expect(Gate::forUser(userWithRole($role))->allows('viewQueue', VitalsRecord::class))->toBe($allowed);
    })->with([
        'nurse' => [Role::Nurse, true],
        'patient' => [Role::Patient, false],
        'staff' => [Role::Staff, false],
        'doctor' => [Role::Doctor, false],
        'admin' => [Role::Admin, false],
    ]);
});

describe('recording and correcting', function () {
    it('is allowed to the nurse while the patient is checked in, for create and for update', function (string $ability) {
        $appointment = Appointment::factory()->checkedIn()->create();

        expect(Gate::forUser(userWithRole(Role::Nurse))->allows($ability, [VitalsRecord::class, $appointment]))->toBeTrue();
    })->with(['create', 'update']);

    it('is refused to the nurse in every other status', function (string $state, string $ability) {
        $appointment = Appointment::factory()->{$state}()->create();

        expect(Gate::forUser(userWithRole(Role::Nurse))->allows($ability, [VitalsRecord::class, $appointment]))->toBeFalse();
    })->with(['booked', 'completed', 'cancelled', 'noShow'])->with(['create', 'update']);

    it('is refused to every other role, even for a checked-in appointment', function (Role $role, string $ability) {
        $user = userWithRole($role);
        $appointment = Appointment::factory()->checkedIn()->create();

        // The doctor and the patient of this very appointment, so "own" gives them nothing either.
        if ($role === Role::Doctor) {
            $appointment->forceFill(['doctor_id' => Doctor::query()->where('user_id', $user->id)->value('id')])->save();
        }

        if ($role === Role::Patient) {
            $appointment->forceFill(['patient_id' => $user->id])->save();
        }

        expect(Gate::forUser($user)->allows($ability, [VitalsRecord::class, $appointment]))->toBeFalse();
    })->with([Role::Patient, Role::Staff, Role::Doctor, Role::Admin])->with(['create', 'update']);
});

describe('reading the values', function () {
    it('has no ability yet, so nobody is allowed, the nurse included', function (Role $role) {
        $record = VitalsRecord::factory()->create();

        expect(Gate::forUser(userWithRole($role))->allows('view', $record))->toBeFalse();
    })->with(Role::cases());
});
