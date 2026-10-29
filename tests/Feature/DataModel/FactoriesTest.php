<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\Intake;
use App\Models\IntakeSummary;
use App\Models\Service;
use App\Models\TimeOff;
use App\Models\User;
use App\Models\VisitNote;
use App\Models\WorkingHour;
use Illuminate\Database\Eloquent\MassAssignmentException;

it('has a working factory for every model', function (string $model) {
    $record = $model::factory()->create();

    expect($record->exists)->toBeTrue();
})->with([
    User::class,
    Doctor::class,
    Service::class,
    WorkingHour::class,
    TimeOff::class,
    Appointment::class,
    Intake::class,
    IntakeSummary::class,
    VisitNote::class,
    AuditLog::class,
]);

it('creates a user with the right role', function (string $state, Role $role) {
    expect(User::factory()->{$state}()->create()->role)->toBe($role);
})->with([
    ['patient', Role::Patient],
    ['staff', Role::Staff],
    ['doctor', Role::Doctor],
    ['admin', Role::Admin],
]);

it('gives only patients a birth date', function () {
    expect(User::factory()->patient()->create()->birth_date)->not->toBeNull()
        ->and(User::factory()->staff()->create()->birth_date)->toBeNull()
        ->and(User::factory()->doctor()->create()->birth_date)->toBeNull()
        ->and(User::factory()->admin()->create()->birth_date)->toBeNull();
});

it('creates active users by default and inactive ones on request', function () {
    expect(User::factory()->create()->is_active)->toBeTrue()
        ->and(User::factory()->inactive()->create()->is_active)->toBeFalse();
});

it('does not let mass assignment set the role or the active flag', function () {
    // role and is_active are not fillable, so a form can't promote a user.
    // In strict mode (non-production) this throws instead of dropping the value silently.
    expect(fn () => (new User)->fill(['name' => 'Eve', 'role' => 'admin', 'is_active' => false]))
        ->toThrow(MassAssignmentException::class);
});

it('links a doctor profile to a doctor user', function () {
    $doctor = Doctor::factory()->create();

    expect($doctor->user->role)->toBe(Role::Doctor)
        ->and($doctor->user->doctor->is($doctor))->toBeTrue();
});

it('creates appointments in each status', function (string $state, AppointmentStatus $status) {
    expect(Appointment::factory()->{$state}()->create()->status)->toBe($status);
})->with([
    ['booked', AppointmentStatus::Booked],
    ['cancelled', AppointmentStatus::Cancelled],
    ['checkedIn', AppointmentStatus::CheckedIn],
    ['completed', AppointmentStatus::Completed],
    ['noShow', AppointmentStatus::NoShow],
]);

it('records who cancelled and when on a cancelled appointment', function () {
    $appointment = Appointment::factory()->cancelled()->create();

    expect($appointment->cancelled_at)->not->toBeNull()
        ->and($appointment->cancelled_by)->toBe($appointment->patient_id);
});

it('sets the check-in time on checked-in and completed appointments', function () {
    expect(Appointment::factory()->checkedIn()->create()->checked_in_at)->not->toBeNull()
        ->and(Appointment::factory()->completed()->create()->checked_in_at)->not->toBeNull()
        ->and(Appointment::factory()->booked()->create()->checked_in_at)->toBeNull();
});

it('flags an appointment as an emergency on request', function () {
    expect(Appointment::factory()->create()->emergency_flag)->toBeFalse()
        ->and(Appointment::factory()->withEmergencyFlag()->create()->emergency_flag)->toBeTrue();
});

it('gives a visit note the doctor of its appointment', function () {
    $note = VisitNote::factory()->create();

    expect($note->doctor_id)->toBe($note->appointment->doctor_id);
});

it('stores an audit log with a created_at and no updated_at', function () {
    $log = AuditLog::factory()->create()->fresh();

    expect($log->created_at)->not->toBeNull()
        ->and($log->getAttributes())->not->toHaveKey('updated_at');
});
