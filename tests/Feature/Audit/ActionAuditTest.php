<?php

declare(strict_types=1);

use App\Actions\Admin\CreateService;
use App\Actions\Admin\CreateStaffUser;
use App\Actions\Admin\CreateTimeOff;
use App\Actions\Admin\DeactivateUser;
use App\Actions\Admin\DeleteService;
use App\Actions\Admin\DeleteTimeOff;
use App\Actions\Admin\UpdateDoctor;
use App\Actions\Admin\UpdateService;
use App\Actions\Admin\UpdateWorkingHours;
use App\Actions\Booking\BookAnyDoctor;
use App\Actions\Booking\BookAppointment;
use App\Actions\Booking\CancelAppointment;
use App\Actions\Booking\CheckInAppointment;
use App\Actions\Booking\MarkNoShow;
use App\Actions\Booking\MoveAppointment;
use App\Actions\Doctor\CompleteAppointment;
use App\Actions\Doctor\MarkSummaryReviewed;
use App\Actions\Doctor\SaveVisitNote;
use App\Actions\Intake\SaveIntake;
use App\Actions\Triage\RecordVitals;
use App\Ai\Agents\IntakeSummarizer;
use App\Enums\Role;
use App\Jobs\SummarizeIntake;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\Intake;
use App\Models\IntakeSummary;
use App\Models\Service;
use App\Models\TimeOff;
use App\Models\User;
use App\Models\VisitNote;
use App\Models\VitalsRecord;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

// Step 11.2: every Action in PROJECT.md F10 writes one audit row, in its own transaction. Each group below proves three
// things: the happy path writes exactly one row with the right actor, subject and safe metadata; a refused or failed
// call writes none; and no health text, note body, reason or email reaches the metadata. "Now" is Friday 30 October
// 2026, 09:00 in Manila. The service lasts 30 minutes with a 15 minute buffer.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));

    $this->service = Service::factory()->lasting(30, 15)->create();
    // Friday is weekday 5.
    $this->doctor = doctorWorking($this->service, [[5, '08:00', '12:00'], [5, '13:00', '17:00']]);
    $this->patient = User::factory()->patient()->create();
    $this->staff = User::factory()->staff()->create();
    $this->admin = userWithRole(Role::Admin);

    $this->utc = fn (string $clinicTime): CarbonImmutable => CarbonImmutable::parse($clinicTime, 'Asia/Manila')->utc();

    // The audit rows of one event, oldest first.
    $this->rows = fn (string $action) => AuditLog::query()->where('action', $action)->orderBy('id')->get();

    // Everything the log holds as one string, to prove a word never reached it.
    $this->logText = fn (): string => AuditLog::query()->get()->map(fn (AuditLog $log): string => json_encode($log->metadata, JSON_THROW_ON_ERROR))->implode(' ');

    $this->answers = fn (array $overrides = []): array => [
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
});

describe('BookAppointment and BookAnyDoctor', function () {
    it('writes appointment.booked for the patient', function () {
        $startsAt = ($this->utc)('2026-10-30 14:00');

        $appointment = app(BookAppointment::class)->handle($this->patient, $this->doctor, $this->service, $startsAt);

        $log = ($this->rows)('appointment.booked')->sole();
        expect(AuditLog::count())->toBe(1)
            ->and($log->actor_id)->toBe($this->patient->id)
            ->and($log->subject_type)->toBe(Appointment::class)
            ->and($log->subject_id)->toBe($appointment->id)
            ->and($log->metadata)->toEqual([
                'doctor_id' => $this->doctor->id,
                'service_id' => $this->service->id,
                'starts_at' => $startsAt->toIso8601ZuluString(),
            ]);
    });

    it('writes nothing when the time is taken', function () {
        bookingAt($this->doctor, $this->service, '2026-10-30 14:00');

        expect(fn () => app(BookAppointment::class)->handle($this->patient, $this->doctor, $this->service, ($this->utc)('2026-10-30 14:00')))
            ->toThrow(ValidationException::class);

        expect(AuditLog::count())->toBe(0);
    });

    it('writes nothing when Any doctor is refused for a patient limit', function () {
        foreach ([1, 2, 3] as $days) {
            Appointment::factory()->for($this->patient, 'patient')->booked()->startingIn(days: $days)->create();
        }

        expect(fn () => app(BookAnyDoctor::class)->handle($this->patient, $this->service, ($this->utc)('2026-10-30 14:00')))
            ->toThrow(ValidationException::class);

        expect(AuditLog::count())->toBe(0);
    });

    it('writes one appointment.booked when Any doctor skips a busy doctor', function () {
        $second = doctorWorking($this->service, [[5, '08:00', '12:00'], [5, '13:00', '17:00']]);
        bookingAt($this->doctor, $this->service, '2026-10-30 14:00');

        $appointment = app(BookAnyDoctor::class)->handle($this->patient, $this->service, ($this->utc)('2026-10-30 14:00'));

        expect($appointment->doctor_id)->toBe($second->id)
            ->and(AuditLog::count())->toBe(1)
            ->and(($this->rows)('appointment.booked')->sole()->subject_id)->toBe($appointment->id);
    });
});

describe('CancelAppointment', function () {
    it('writes appointment.cancelled with the status change and without the reason', function () {
        $appointment = bookingAt($this->doctor, $this->service, '2026-10-30 14:00');
        $appointment->update(['patient_id' => $this->patient->id]);

        app(CancelAppointment::class)->handle($this->patient, $appointment, 'I have a fever and cannot come');

        $log = ($this->rows)('appointment.cancelled')->sole();
        expect(AuditLog::count())->toBe(1)
            ->and($log->actor_id)->toBe($this->patient->id)
            ->and($log->subject_id)->toBe($appointment->id)
            ->and($log->metadata)->toEqual(['status_from' => 'booked', 'status_to' => 'cancelled'])
            ->and(($this->logText)())->not->toContain('fever');
    });

    it('logs staff as the actor', function () {
        $appointment = bookingAt($this->doctor, $this->service, '2026-10-30 14:00');

        app(CancelAppointment::class)->handle($this->staff, $appointment, 'Doctor is unwell');

        expect(($this->rows)('appointment.cancelled')->sole()->actor_id)->toBe($this->staff->id);
    });

    it('writes nothing when it is refused', function (string $case) {
        $appointment = bookingAt($this->doctor, $this->service, $case === 'too late' ? '2026-10-30 10:00' : '2026-10-30 14:00');
        $appointment->update(['patient_id' => $this->patient->id]);

        $attempt = match ($case) {
            'someone else' => fn () => app(CancelAppointment::class)->handle(User::factory()->patient()->create(), $appointment),
            'too late' => fn () => app(CancelAppointment::class)->handle($this->patient, $appointment),
            'staff without a reason' => fn () => app(CancelAppointment::class)->handle($this->staff, $appointment),
        };

        expect($attempt)->toThrow($case === 'someone else' ? AuthorizationException::class : ValidationException::class);
        expect(AuditLog::count())->toBe(0)
            ->and($appointment->fresh()->status->value)->toBe('booked');
    })->with(['someone else', 'too late', 'staff without a reason']);
});

describe('CheckInAppointment and MarkNoShow', function () {
    it('writes appointment.checked_in', function () {
        $appointment = bookingAt($this->doctor, $this->service, '2026-10-30 14:00');

        app(CheckInAppointment::class)->handle($this->staff, $appointment);

        $log = ($this->rows)('appointment.checked_in')->sole();
        expect(AuditLog::count())->toBe(1)
            ->and($log->actor_id)->toBe($this->staff->id)
            ->and($log->subject_id)->toBe($appointment->id)
            ->and($log->metadata)->toEqual(['status_from' => 'booked', 'status_to' => 'checked_in']);
    });

    it('writes appointment.no_show once the visit has started', function () {
        $appointment = bookingAt($this->doctor, $this->service, '2026-10-30 08:00');

        app(MarkNoShow::class)->handle($this->staff, $appointment);

        $log = ($this->rows)('appointment.no_show')->sole();
        expect(AuditLog::count())->toBe(1)
            ->and($log->actor_id)->toBe($this->staff->id)
            ->and($log->metadata)->toEqual(['status_from' => 'booked', 'status_to' => 'no_show']);
    });

    it('writes nothing when a patient tries, or the state is wrong', function () {
        $appointment = bookingAt($this->doctor, $this->service, '2026-10-30 14:00');

        expect(fn () => app(CheckInAppointment::class)->handle($this->patient, $appointment))->toThrow(AuthorizationException::class);
        expect(fn () => app(MarkNoShow::class)->handle($this->patient, $appointment))->toThrow(AuthorizationException::class);
        // Not started yet, so a no-show is refused even for staff.
        expect(fn () => app(MarkNoShow::class)->handle($this->staff, $appointment))->toThrow(ValidationException::class);

        app(CheckInAppointment::class)->handle($this->staff, $appointment);
        // Already checked in: the second call fails and adds no second row.
        expect(fn () => app(CheckInAppointment::class)->handle($this->staff, $appointment))->toThrow(ValidationException::class);

        expect(AuditLog::count())->toBe(1);
    });
});

describe('MoveAppointment', function () {
    it('writes one appointment.moved on the new booking, and no cancelled or booked row', function () {
        $other = doctorWorking($this->service, [[5, '08:00', '12:00'], [5, '13:00', '17:00']]);
        $old = bookingAt($this->doctor, $this->service, '2026-10-30 14:00');

        $new = app(MoveAppointment::class)->handle($this->staff, $old, $other, ($this->utc)('2026-10-30 15:00'));

        $log = ($this->rows)('appointment.moved')->sole();
        expect(AuditLog::count())->toBe(1)
            ->and($log->actor_id)->toBe($this->staff->id)
            ->and($log->subject_id)->toBe($new->id)
            ->and($log->metadata)->toEqual([
                'from_appointment_id' => $old->id,
                'from_doctor_id' => $this->doctor->id,
                'to_doctor_id' => $other->id,
                'from_starts_at' => ($this->utc)('2026-10-30 14:00')->toIso8601ZuluString(),
                'to_starts_at' => ($this->utc)('2026-10-30 15:00')->toIso8601ZuluString(),
            ])
            ->and(($this->rows)('appointment.cancelled'))->toHaveCount(0)
            ->and(($this->rows)('appointment.booked'))->toHaveCount(0);
    });

    it('writes nothing when the move is refused, and the old booking stays booked', function () {
        $old = bookingAt($this->doctor, $this->service, '2026-10-30 14:00');

        // 07:00 is before the doctor's hours, so the slot finder does not offer it.
        expect(fn () => app(MoveAppointment::class)->handle($this->staff, $old, $this->doctor, ($this->utc)('2026-10-30 07:00')))
            ->toThrow(ValidationException::class);
        expect(fn () => app(MoveAppointment::class)->handle($this->patient, $old, $this->doctor, ($this->utc)('2026-10-30 15:00')))
            ->toThrow(AuthorizationException::class);

        expect(AuditLog::count())->toBe(0)
            ->and($old->fresh()->status->value)->toBe('booked');
    });
});

describe('SaveIntake', function () {
    beforeEach(function () {
        Queue::fake();
        $this->appointment = Appointment::factory()->for($this->patient, 'patient')->booked()->startingIn(days: 2)->create();
    });

    it('writes intake.saved with the emergency flag change and none of the answers', function () {
        $intake = app(SaveIntake::class)->handle($this->patient, $this->appointment, ($this->answers)());

        $first = ($this->rows)('intake.saved')->sole();
        expect($first->actor_id)->toBe($this->patient->id)
            ->and($first->subject_type)->toBe(Intake::class)
            ->and($first->subject_id)->toBe($intake->id)
            ->and($first->metadata)->toEqual([
                'appointment_id' => $this->appointment->id,
                'created' => true,
                'emergency_flag_from' => false,
                'emergency_flag_to' => false,
            ]);

        app(SaveIntake::class)->handle($this->patient, $this->appointment, ($this->answers)(['reason' => 'Chest pain since this morning']));

        $second = ($this->rows)('intake.saved')->last();
        expect(AuditLog::count())->toBe(2)
            ->and($second->metadata)->toEqual([
                'appointment_id' => $this->appointment->id,
                'created' => false,
                'emergency_flag_from' => false,
                'emergency_flag_to' => true,
            ]);

        // An edit that removes the phrase clears the flag, and the log is where that shows.
        app(SaveIntake::class)->handle($this->patient, $this->appointment, ($this->answers)());

        expect(($this->rows)('intake.saved')->last()->metadata)->toMatchArray(['emergency_flag_from' => true, 'emergency_flag_to' => false])
            ->and(($this->logText)())->not->toContain('Chest')->not->toContain('Cough')->not->toContain('Paracetamol');
    });

    it('writes nothing when someone else tries to save', function () {
        expect(fn () => app(SaveIntake::class)->handle(User::factory()->patient()->create(), $this->appointment, ($this->answers)()))
            ->toThrow(AuthorizationException::class);

        expect(AuditLog::count())->toBe(0)
            ->and(Intake::count())->toBe(0);
    });
});

describe('the queue jobs', function () {
    // The queue is `sync` in tests, so SummarizeIntake has already run once inside the save. Jobs act for the system,
    // not for a user, and they are not in the F10 list, so a run or a retry must not add a row.
    it('add no audit row when the summary job runs, even a second time', function () {
        IntakeSummarizer::fake();
        $appointment = Appointment::factory()->for($this->patient, 'patient')->booked()->startingIn(days: 2)->create();

        $intake = app(SaveIntake::class)->handle($this->patient, $appointment, ($this->answers)());
        app()->call((new SummarizeIntake($intake->id))->handle(...));

        expect(AuditLog::count())->toBe(1)
            ->and(($this->rows)('intake.saved'))->toHaveCount(1);
        IntakeSummarizer::assertNeverPrompted();
    });
});

describe('the doctor Actions', function () {
    beforeEach(function () {
        $this->doctorUser = userWithRole(Role::Doctor);
        $this->ownDoctor = Doctor::query()->where('user_id', $this->doctorUser->id)->firstOrFail();

        // A checked-in visit for the signed-in doctor, or for $doctor.
        $this->visit = fn (?Doctor $doctor = null, string $at = '10:00'): Appointment => Appointment::factory()
            ->for($doctor ?? $this->ownDoctor)
            ->for($this->patient, 'patient')
            ->checkedIn()
            ->create(['starts_at' => ($this->utc)("2026-10-30 {$at}")]);
    });

    it('writes appointment.completed', function () {
        $appointment = ($this->visit)();

        app(CompleteAppointment::class)->handle($this->doctorUser, $appointment);

        $log = ($this->rows)('appointment.completed')->sole();
        expect(AuditLog::count())->toBe(1)
            ->and($log->actor_id)->toBe($this->doctorUser->id)
            ->and($log->subject_id)->toBe($appointment->id)
            ->and($log->metadata)->toEqual(['status_from' => 'checked_in', 'status_to' => 'completed']);
    });

    it('writes nothing when another doctor, or a booked visit, is finished', function () {
        $otherDoctorUser = userWithRole(Role::Doctor);
        $appointment = ($this->visit)();
        $booked = bookingAt($this->ownDoctor, $this->service, '2026-10-30 14:00');

        expect(fn () => app(CompleteAppointment::class)->handle($otherDoctorUser, $appointment))->toThrow(AuthorizationException::class);
        expect(fn () => app(CompleteAppointment::class)->handle($this->doctorUser, $booked))->toThrow(ValidationException::class);

        expect(AuditLog::count())->toBe(0);
    });

    it('writes summary.reviewed once, not on a second click', function () {
        $appointment = ($this->visit)();
        $summary = IntakeSummary::factory()->for(Intake::factory()->for($appointment))->create();

        app(MarkSummaryReviewed::class)->handle($this->doctorUser, $appointment->fresh(), $summary->version());
        app(MarkSummaryReviewed::class)->handle($this->doctorUser, $appointment->fresh(), $summary->version());

        $log = ($this->rows)('summary.reviewed')->sole();
        expect(AuditLog::count())->toBe(1)
            ->and($log->actor_id)->toBe($this->doctorUser->id)
            ->and($log->subject_type)->toBe(IntakeSummary::class)
            ->and($log->subject_id)->toBe($summary->id)
            ->and($log->metadata)->toEqual(['appointment_id' => $appointment->id]);
    });

    it('writes nothing when the review is refused', function () {
        $appointment = ($this->visit)();
        $summary = IntakeSummary::factory()->for(Intake::factory()->for($appointment))->create();

        expect(fn () => app(MarkSummaryReviewed::class)->handle($this->staff, $appointment->fresh(), $summary->version()))
            ->toThrow(AuthorizationException::class);
        expect(fn () => app(MarkSummaryReviewed::class)->handle($this->doctorUser, $appointment->fresh(), 'an-old-version'))
            ->toThrow(ValidationException::class);

        expect(AuditLog::count())->toBe(0)
            ->and($summary->fresh()->reviewed_at)->toBeNull();
    });

    it('writes notes.saved for a new note and an edit, without the text', function () {
        $appointment = ($this->visit)();

        $note = app(SaveVisitNote::class)->handle($this->doctorUser, $appointment, 'Rash on the left arm.');
        app(SaveVisitNote::class)->handle($this->doctorUser, $appointment, 'Rash on the left arm, now smaller.');

        $logs = ($this->rows)('notes.saved');
        expect($logs)->toHaveCount(2)
            ->and($logs->first()->actor_id)->toBe($this->doctorUser->id)
            ->and($logs->first()->subject_type)->toBe(VisitNote::class)
            ->and($logs->first()->subject_id)->toBe($note->id)
            ->and($logs->first()->metadata)->toEqual(['appointment_id' => $appointment->id, 'created' => true])
            ->and($logs->last()->metadata)->toEqual(['appointment_id' => $appointment->id, 'created' => false])
            ->and(($this->logText)())->not->toContain('Rash');
    });

    it('writes nothing when another doctor tries to write a note', function () {
        $appointment = ($this->visit)();

        expect(fn () => app(SaveVisitNote::class)->handle(userWithRole(Role::Doctor), $appointment, 'Not mine'))
            ->toThrow(AuthorizationException::class);

        expect(AuditLog::count())->toBe(0)
            ->and(VisitNote::count())->toBe(0);
    });
});

describe('the admin Actions', function () {
    it('writes user.created with the role and without the email or password', function () {
        $user = app(CreateStaffUser::class)->handle($this->admin, 'Sam Staff', 'sam@example.test', 'a-long-demo-password-1', Role::Staff);

        $log = ($this->rows)('user.created')->sole();
        expect(AuditLog::count())->toBe(1)
            ->and($log->actor_id)->toBe($this->admin->id)
            ->and($log->subject_type)->toBe(User::class)
            ->and($log->subject_id)->toBe($user->id)
            ->and($log->metadata)->toEqual(['role' => 'staff'])
            ->and(($this->logText)())->not->toContain('example.test')->not->toContain('password');
    });

    it('writes no user.created when the doctor profile fails and the user is rolled back', function () {
        expect(fn () => app(CreateStaffUser::class)->handle($this->admin, 'Dr. Long', 'long@example.test', 'a-long-demo-password-1', Role::Doctor, str_repeat('x', 300)))
            ->toThrow(QueryException::class);

        expect(AuditLog::count())->toBe(0);
    });

    it('writes user.deactivated', function () {
        $user = User::factory()->staff()->create();

        app(DeactivateUser::class)->handle($this->admin, $user);

        $log = ($this->rows)('user.deactivated')->sole();
        expect($log->actor_id)->toBe($this->admin->id)
            ->and($log->subject_id)->toBe($user->id)
            ->and($log->metadata)->toBeNull();
    });

    it('writes user.deactivated once, not again for an account that is already off', function () {
        $user = User::factory()->staff()->create();

        app(DeactivateUser::class)->handle($this->admin, $user);
        app(DeactivateUser::class)->handle($this->admin, $user->fresh());
        app(DeactivateUser::class)->handle($this->admin, User::factory()->staff()->inactive()->create());

        expect(AuditLog::count())->toBe(1)
            ->and(($this->rows)('user.deactivated')->sole()->subject_id)->toBe($user->id);
    });

    it('writes service.created, service.updated with the field names, and service.deleted', function () {
        $service = app(CreateService::class)->handle($this->admin, 'Eye Check', 'A quick check', 30, 10, true);

        expect(($this->rows)('service.created')->sole())
            ->actor_id->toBe($this->admin->id)
            ->subject_id->toBe($service->id)
            ->metadata->toEqual(['slug' => 'eye-check']);

        app(UpdateService::class)->handle($this->admin, $service, 'Eye Checkup', 'A quick check', 45, 10, true);

        expect(($this->rows)('service.updated')->sole()->metadata)->toEqual(['changed' => ['name', 'duration_minutes']]);

        app(DeleteService::class)->handle($this->admin, $service);

        $deleted = ($this->rows)('service.deleted')->sole();
        expect($deleted->subject_id)->toBe($service->id)
            ->and($deleted->metadata)->toEqual(['slug' => 'eye-check'])
            ->and(Service::query()->whereKey($service->id)->exists())->toBeFalse();
    });

    it('writes no service.deleted when the service has appointments', function () {
        $service = Service::factory()->create();
        Appointment::factory()->completed()->create(['service_id' => $service->id]);

        expect(fn () => app(DeleteService::class)->handle($this->admin, $service))->toThrow(ValidationException::class);

        expect(AuditLog::count())->toBe(0);
    });

    it('writes doctor.updated with the changed fields and the service ids', function () {
        $doctor = Doctor::factory()->create(['specialty' => 'Dermatology', 'bio' => null]);

        app(UpdateDoctor::class)->handle($this->admin, $doctor, 'Pediatrics', null, [$this->service->id]);

        $log = ($this->rows)('doctor.updated')->sole();
        expect($log->actor_id)->toBe($this->admin->id)
            ->and($log->subject_type)->toBe(Doctor::class)
            ->and($log->subject_id)->toBe($doctor->id)
            ->and($log->metadata)->toEqual(['changed' => ['specialty'], 'service_ids' => [$this->service->id]]);
    });

    it('writes no doctor.updated when the services can not be saved', function () {
        $doctor = Doctor::factory()->create();

        expect(fn () => app(UpdateDoctor::class)->handle($this->admin, $doctor, 'Pediatrics', null, [999999]))->toThrow(Exception::class);

        expect(AuditLog::count())->toBe(0);
    });

    it('writes working_hours.updated with the number of ranges, and none when the save fails', function () {
        $doctor = Doctor::factory()->create();

        expect(fn () => app(UpdateWorkingHours::class)->handle($this->admin, $doctor, [
            ['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '10:00'],
            ['weekday' => 999, 'starts_at' => '09:00', 'ends_at' => '10:00'],
        ]))->toThrow(Exception::class);
        expect(AuditLog::count())->toBe(0);

        app(UpdateWorkingHours::class)->handle($this->admin, $doctor, [
            ['weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00'],
            ['weekday' => 1, 'starts_at' => '13:00', 'ends_at' => '17:00'],
        ]);

        $log = ($this->rows)('working_hours.updated')->sole();
        expect($log->actor_id)->toBe($this->admin->id)
            ->and($log->subject_id)->toBe($doctor->id)
            ->and($log->metadata)->toEqual(['range_count' => 2]);
    });

    it('writes time_off.created and time_off.deleted without the reason', function () {
        $doctor = Doctor::factory()->create();
        $startsAt = ($this->utc)('2026-11-10 08:00');
        $endsAt = ($this->utc)('2026-11-10 17:00');

        $timeOff = app(CreateTimeOff::class)->handle($this->admin, $doctor, $startsAt, $endsAt, 'Sick leave');

        $expected = [
            'doctor_id' => $doctor->id,
            'starts_at' => $startsAt->toIso8601ZuluString(),
            'ends_at' => $endsAt->toIso8601ZuluString(),
        ];
        $created = ($this->rows)('time_off.created')->sole();
        expect($created->actor_id)->toBe($this->admin->id)
            ->and($created->subject_type)->toBe(TimeOff::class)
            ->and($created->subject_id)->toBe($timeOff->id)
            ->and($created->metadata)->toEqual($expected);

        app(DeleteTimeOff::class)->handle($this->admin, $timeOff);

        $deleted = ($this->rows)('time_off.deleted')->sole();
        expect($deleted->subject_id)->toBe($timeOff->id)
            ->and($deleted->metadata)->toEqual($expected)
            ->and(TimeOff::query()->whereKey($timeOff->id)->exists())->toBeFalse()
            ->and(($this->logText)())->not->toContain('Sick');
    });
});

describe('the admin routes pass the signed-in user as the actor', function () {
    it('logs the admin who adds a service and deactivates a user', function () {
        $this->actingAs($this->admin)->post(route('admin.services.store'), [
            'name' => 'Eye Check',
            'description' => 'A quick check',
            'duration_minutes' => 30,
            'buffer_minutes' => 10,
            'is_active' => true,
        ])->assertRedirect(route('admin.services.index'));

        $user = User::factory()->staff()->create();
        $this->actingAs($this->admin)->patch(route('admin.users.deactivate', $user))->assertRedirect(route('admin.users.index'));

        expect(($this->rows)('service.created')->sole()->actor_id)->toBe($this->admin->id)
            ->and(($this->rows)('user.deactivated')->sole()->actor_id)->toBe($this->admin->id);
    });

    it('logs nothing when a non-admin is refused', function () {
        // Staff with 2FA on, so the 403 comes from the role group and not from the 2FA setup redirect.
        $this->actingAs(userWithRole(Role::Staff))->post(route('admin.services.store'), [
            'name' => 'Eye Check',
            'duration_minutes' => 30,
            'buffer_minutes' => 10,
            'is_active' => true,
        ])->assertForbidden();

        expect(AuditLog::count())->toBe(0);
    });
});

describe('the nurse vitals Actions', function () {
    beforeEach(function () {
        $this->nurse = userWithRole(Role::Nurse);
        $this->checkedIn = Appointment::factory()->checkedIn()->create();
        $this->readings = ['systolic' => 187, 'diastolic' => 121, 'pulse' => 143, 'temperature' => 39.4, 'oxygen_saturation' => 91, 'weight' => 83.7];
    });

    it('writes vitals.saved with the mode only, for a first save and for a correction', function () {
        $record = app(RecordVitals::class)->handle($this->nurse, $this->checkedIn, $this->readings);

        $created = ($this->rows)('vitals.saved')->sole();
        expect($created->actor_id)->toBe($this->nurse->id)
            ->and($created->subject_type)->toBe(VitalsRecord::class)
            ->and($created->subject_id)->toBe($record->id)
            ->and($created->metadata)->toBe(['mode' => 'created']);

        app(RecordVitals::class)->handle($this->nurse, $this->checkedIn, [...$this->readings, 'pulse' => 90]);

        $rows = ($this->rows)('vitals.saved');
        expect($rows)->toHaveCount(2)
            ->and($rows->last()->subject_id)->toBe($record->id)
            ->and($rows->last()->metadata)->toBe(['mode' => 'updated']);
    });

    it('keeps every reading out of the audit log', function () {
        app(RecordVitals::class)->handle($this->nurse, $this->checkedIn, $this->readings);

        // The decimals can't turn up by chance in an id or a time, unlike a whole number.
        expect(($this->logText)())->not->toContain('39.4')
            ->not->toContain('83.7')
            ->not->toContain('systolic')
            ->not->toContain('readings');
    });

    it('writes vitals.viewed with no metadata when the nurse opens a form that holds readings', function () {
        $record = VitalsRecord::factory()->forAppointment($this->checkedIn)->create(['readings' => $this->readings]);

        $this->actingAs($this->nurse)->get(route('nurse.appointments.vitals.edit', $this->checkedIn))->assertOk();

        $viewed = ($this->rows)('vitals.viewed')->sole();
        expect($viewed->actor_id)->toBe($this->nurse->id)
            ->and($viewed->subject_type)->toBe(VitalsRecord::class)
            ->and($viewed->subject_id)->toBe($record->id)
            ->and($viewed->metadata)->toBeNull();
    });

    it('writes nothing when the save is refused', function () {
        $completed = Appointment::factory()->completed()->create();

        expect(fn () => app(RecordVitals::class)->handle($this->nurse, $completed, $this->readings))
            ->toThrow(AuthorizationException::class)
            ->and(AuditLog::count())->toBe(0);
    });
});
