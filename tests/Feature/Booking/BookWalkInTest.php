<?php

declare(strict_types=1);

use App\Actions\Booking\BookAppointment;
use App\Actions\Booking\BookWalkIn;
use App\Actions\Staff\NewPatient;
use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use App\Notifications\AppointmentBooked;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

// The staff walk-in Action (PROJECT.md F3, F7): a patient at the desk with no booking, seen today. The booking rules
// themselves are tested in BookAppointmentTest; these tests cover what BookWalkIn adds: who may call it, the lead-time
// skip and the limits on it (today only, only a time the finders offer from now), a new patient account made in the same
// transaction, the optional check-in, and the audit rows. "Now" is Friday 30 October 2026, 09:00 in Manila. The service
// lasts 30 minutes with a 15 minute buffer, and both doctors work Friday 08:00 to 17:00.

/** A clinic time on that Friday as the UTC instant the Action takes. */
function walkInAt(string $time, string $date = '2026-10-30'): CarbonImmutable
{
    return CarbonImmutable::parse("$date $time", 'Asia/Manila')->utc();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));
    Notification::fake();

    $this->staff = userWithRole(Role::Staff);
    $this->service = Service::factory()->lasting(30, 15)->create();
    $this->doctor = doctorWorking($this->service, [[5, '08:00', '17:00']]);
    $this->otherDoctor = doctorWorking($this->service, [[5, '08:00', '17:00']]);
    $this->patient = User::factory()->patient()->create();
    $this->book = fn (...$arguments) => app(BookWalkIn::class)->handle(...$arguments);
});

describe('an existing patient', function () {
    it('is booked now with the picked doctor and checked in, and the lead time is skipped', function () {
        $appointment = ($this->book)($this->staff, $this->patient, $this->service, $this->doctor, walkInAt('09:15'), true);

        expect($appointment->patient_id)->toBe($this->patient->id)
            ->and($appointment->doctor_id)->toBe($this->doctor->id)
            ->and($appointment->starts_at->equalTo(walkInAt('09:15')))->toBeTrue()
            ->and($appointment->status)->toBe(AppointmentStatus::CheckedIn)
            ->and($appointment->checked_in_at)->not->toBeNull()
            ->and(Appointment::query()->count())->toBe(1);

        // The patient is told, as for any booking.
        Notification::assertSentToTimes($this->patient, AppointmentBooked::class, 1);
    });

    it('stays booked when the check-in is off', function () {
        $appointment = ($this->book)($this->staff, $this->patient, $this->service, $this->doctor, walkInAt('09:15'), false);

        expect($appointment->status)->toBe(AppointmentStatus::Booked)
            ->and($appointment->checked_in_at)->toBeNull()
            ->and(AuditLog::query()->where('action', 'appointment.checked_in')->count())->toBe(0);
    });

    it('takes the first free doctor for "Any doctor", and the next one when the first is busy then', function () {
        $first = ($this->book)($this->staff, $this->patient, $this->service, null, walkInAt('09:15'), false);

        // The same time for a second walk-in: the first doctor is busy, so the other one is booked.
        $second = ($this->book)($this->staff, User::factory()->patient()->create(), $this->service, null, walkInAt('09:15'), false);

        expect($first->doctor_id)->toBe(min($this->doctor->id, $this->otherDoctor->id))
            ->and($second->doctor_id)->toBe(max($this->doctor->id, $this->otherDoctor->id));
    });

    it('writes who did it: booked and walk-in rows name the staff member, and checked-in too', function () {
        $appointment = ($this->book)($this->staff, $this->patient, $this->service, $this->doctor, walkInAt('09:15'), true);

        foreach (['appointment.booked', 'appointment.checked_in', 'appointment.walk_in'] as $action) {
            $log = AuditLog::query()->where('action', $action)->sole();

            expect($log->actor_id)->toBe($this->staff->id)
                ->and($log->subject_id)->toBe($appointment->id);
        }

        expect(AuditLog::query()->where('action', 'appointment.walk_in')->sole()->metadata)->toEqual([
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'service_id' => $this->service->id,
            'patient_created' => false,
            'checked_in' => true,
        ]);
    });
});

describe('a new patient', function () {
    beforeEach(function () {
        $this->newPatient = new NewPatient('Ana Reyes', 'ana.reyes@example.test', '1991-04-12');
    });

    it('gets an account nobody knows the password of, is booked, and is emailed the links', function () {
        $appointment = ($this->book)($this->staff, $this->newPatient, $this->service, $this->doctor, walkInAt('09:15'), true);

        $account = User::query()->where('email', 'ana.reyes@example.test')->sole();

        expect($account->role)->toBe(Role::Patient)
            ->and($account->is_active)->toBeTrue()
            ->and($account->name)->toBe('Ana Reyes')
            ->and($account->birth_date)->toBe('1991-04-12')
            ->and($account->email_verified_at)->toBeNull()
            ->and($account->hasVerifiedEmail())->toBeFalse()
            ->and($appointment->patient_id)->toBe($account->id);

        Notification::assertSentTo($account, VerifyEmail::class);
        Notification::assertSentTo($account, ResetPassword::class);
        Notification::assertSentToTimes($account, AppointmentBooked::class, 1);
    });

    it('is audited without the email or the birth date', function () {
        ($this->book)($this->staff, $this->newPatient, $this->service, $this->doctor, walkInAt('09:15'), false);

        $account = User::query()->where('email', 'ana.reyes@example.test')->sole();
        $created = AuditLog::query()->where('action', 'user.created')->sole();

        expect($created->actor_id)->toBe($this->staff->id)
            ->and($created->subject_id)->toBe($account->id)
            ->and($created->metadata)->toBe(['role' => 'patient'])
            ->and(AuditLog::query()->where('action', 'appointment.walk_in')->sole()->metadata['patient_created'])->toBeTrue();
    });

    it('leaves no account and sends no email when the booking is refused', function () {
        // The finder offers the time (the doctor offers the service), and BookAppointment then refuses the switched-off
        // service inside the transaction, after the account was made. Everything must roll back.
        $this->service->update(['is_active' => false]);

        expect(fn () => ($this->book)($this->staff, $this->newPatient, $this->service, $this->doctor, walkInAt('09:15'), true))
            ->toThrow(ValidationException::class);

        expect(User::query()->where('email', 'ana.reyes@example.test')->exists())->toBeFalse()
            ->and(Appointment::query()->count())->toBe(0)
            ->and(AuditLog::query()->whereIn('action', ['user.created', 'appointment.booked', 'appointment.walk_in'])->count())->toBe(0);

        Notification::assertNothingSent();
    });
});

describe('the time', function () {
    it('must be one the finders offer from now: not past, not outside the hours, not taken', function (string $time, string $message) {
        bookingAt($this->doctor, $this->service, '2026-10-30 10:00');

        try {
            ($this->book)($this->staff, $this->patient, $this->service, $this->doctor, walkInAt($time), false);
            $failed = false;
        } catch (ValidationException $e) {
            $failed = true;
            expect($e->errors()['starts_at'])->toBe([$message]);
        }

        expect($failed)->toBeTrue()
            ->and(Appointment::query()->count())->toBe(1);
    })->with([
        'already started' => ['08:45', BookAppointment::TIME_TAKEN_MESSAGE],
        'before the hours' => ['07:00', BookAppointment::TIME_TAKEN_MESSAGE],
        'taken by another booking' => ['10:00', BookAppointment::TIME_TAKEN_MESSAGE],
        'in the buffer of another booking' => ['09:45', BookAppointment::TIME_TAKEN_MESSAGE],
    ]);

    it('must be today: tomorrow is refused even though the lead time is skipped', function () {
        // Saturday has hours too, so only the "today" rule can refuse this.
        WorkingHour::factory()->for($this->doctor)->onWeekday(6)->between('08:00', '12:00')->create();

        expect(fn () => ($this->book)($this->staff, $this->patient, $this->service, $this->doctor, walkInAt('09:00', '2026-10-31'), false))
            ->toThrow(ValidationException::class, 'A walk-in can only be booked for today.');

        expect(Appointment::query()->count())->toBe(0);
    });
});

describe('the limits that still apply', function () {
    it('refuses a patient who already has 3 upcoming appointments', function () {
        foreach (['2026-10-31 09:00', '2026-11-02 09:00', '2026-11-03 09:00'] as $start) {
            Appointment::factory()->for($this->doctor)->for($this->service)->for($this->patient, 'patient')->booked()
                ->create(['starts_at' => CarbonImmutable::parse($start, 'Asia/Manila')->utc()]);
        }

        expect(fn () => ($this->book)($this->staff, $this->patient, $this->service, $this->doctor, walkInAt('09:15'), true))
            ->toThrow(ValidationException::class, 'at most 3 upcoming');

        expect(Appointment::query()->count())->toBe(3)
            ->and(AuditLog::query()->where('action', 'appointment.walk_in')->count())->toBe(0);
    });

    it('refuses a patient who is already booked at that time with another doctor', function () {
        Appointment::factory()->for($this->otherDoctor)->for($this->service)->for($this->patient, 'patient')->booked()
            ->create(['starts_at' => walkInAt('09:15')]);

        expect(fn () => ($this->book)($this->staff, $this->patient, $this->service, $this->doctor, walkInAt('09:15'), true))
            ->toThrow(ValidationException::class, 'You already have an appointment at this time');

        expect(Appointment::query()->count())->toBe(1);
    });
});

describe('who may call it', function () {
    it('refuses everyone but staff, before anything is read or written', function (Role $role) {
        $actor = userWithRole($role);

        expect(fn () => ($this->book)($actor, $this->patient, $this->service, $this->doctor, walkInAt('09:15'), true))
            ->toThrow(AuthorizationException::class);

        expect(Appointment::query()->count())->toBe(0)
            ->and(AuditLog::query()->count())->toBe(0);
    })->with([
        'patient' => [Role::Patient],
        'doctor' => [Role::Doctor],
        'admin' => [Role::Admin],
    ]);

    it('refuses an account that is not an active patient, with the same answer for each', function (string $kind) {
        $account = match ($kind) {
            'staff' => userWithRole(Role::Staff),
            'doctor' => userWithRole(Role::Doctor),
            'switched-off patient' => User::factory()->patient()->create(['is_active' => false]),
        };

        expect(fn () => ($this->book)($this->staff, $account, $this->service, $this->doctor, walkInAt('09:15'), true))
            ->toThrow(ValidationException::class, 'Pick a patient from the list.');

        expect(Appointment::query()->count())->toBe(0);
    })->with(['staff', 'doctor', 'switched-off patient']);
});

describe('the clinic day', function () {
    it('is today by the clinic calendar, not by the UTC one', function () {
        // 07:00 in Manila is 23:00 UTC the day before. A walk-in at 08:15 Manila is 00:15 UTC on the next UTC day, but it
        // is still the same clinic day as "now", so it must be accepted.
        $this->travelTo(CarbonImmutable::parse('2026-10-30 07:00', 'Asia/Manila'));
        $earlyDoctor = doctorWorking($this->service, [[5, '07:00', '12:00']]);

        $appointment = ($this->book)($this->staff, $this->patient, $this->service, $earlyDoctor, walkInAt('08:15'), false);

        expect($appointment->starts_at->utc()->toDateString())->toBe('2026-10-30')
            ->and(CarbonImmutable::now('UTC')->toDateString())->toBe('2026-10-29');
    });

    it('stops at the clinic midnight: a time a minute into tomorrow is refused though its UTC day is still today', function () {
        // 23:30 in Manila is 15:30 UTC. 00:15 tomorrow in Manila is 16:15 UTC, the same UTC day as "now".
        $this->travelTo(CarbonImmutable::parse('2026-10-30 23:30', 'Asia/Manila'));
        $nightDoctor = doctorWorking($this->service, [[5, '08:00', '23:59'], [6, '00:00', '12:00']]);

        expect(fn () => ($this->book)($this->staff, $this->patient, $this->service, $nightDoctor, walkInAt('00:15', '2026-10-31'), false))
            ->toThrow(ValidationException::class, 'A walk-in can only be booked for today.');

        expect(Appointment::query()->count())->toBe(0);
    });
});
