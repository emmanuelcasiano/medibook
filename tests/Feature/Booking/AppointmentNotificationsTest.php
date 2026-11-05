<?php

declare(strict_types=1);

use App\Actions\Booking\BookAnyDoctor;
use App\Actions\Booking\BookAppointment;
use App\Actions\Booking\MoveAppointment;
use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Intake;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppointmentBooked;
use App\Notifications\AppointmentCancelled;
use App\Notifications\AppointmentReminder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

// The appointment emails (PROJECT.md F9, step 12.1): who gets which one, that a refused or denied request sends none,
// and that the text holds the date, time, doctor and service and no health information. The reminder is only sent by
// the `medibook:send-reminders` command (step 12.2); its content is checked here.
// "Now" is Friday 30 October 2026, 09:00 in Manila.

/** A clinic time as UTC, the way the Actions take it. */
function notifyAt(string $clinicTime): CarbonImmutable
{
    return CarbonImmutable::parse($clinicTime, 'Asia/Manila')->utc();
}

/** A booking for $patient with $doctor at a clinic time. $state is an AppointmentFactory state. */
function notifyBooking(User $patient, Doctor $doctor, Service $service, string $clinicTime, string $state = 'booked'): Appointment
{
    return Appointment::factory()
        ->for($doctor)->for($service)->for($patient, 'patient')
        ->{$state}()
        ->create(['starts_at' => notifyAt($clinicTime)]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));
    $this->service = Service::factory()->lasting(30, 15)->create(['name' => 'General Consultation']);
    // Mondays and Fridays, so both the slot finder (move, "Any doctor") and plain bookings have times to use.
    $this->doctor = doctorWorking($this->service, [[1, '08:00', '12:00'], [5, '08:00', '17:00']]);
    $this->patient = User::factory()->patient()->create(['name' => 'Maria Santos', 'birth_date' => '1990-04-12']);
});

describe('who gets an email', function () {
    it('sends the patient one booked email after booking through the confirm page', function () {
        Notification::fake();

        $this->actingAs($this->patient)
            ->post(route('booking.store', $this->service), [
                'doctor_id' => $this->doctor->id,
                'starts_at' => notifyAt('2026-11-02 08:00')->format('Y-m-d\TH:i:s\Z'),
            ])
            ->assertSessionHasNoErrors();

        $appointment = Appointment::query()->sole();

        Notification::assertSentToTimes($this->patient, AppointmentBooked::class, 1);
        Notification::assertSentTo($this->patient, AppointmentBooked::class, fn (AppointmentBooked $n): bool => $n->appointment->is($appointment));
    });

    it('sends one booked email for "Any doctor", even when the first doctor turns out to be taken', function () {
        // The first doctor in id order is taken at 08:00 by someone else, so BookAnyDoctor moves on to the second.
        $second = doctorWorking($this->service, [[1, '08:00', '12:00']]);
        $taken = notifyBooking(User::factory()->patient()->create(), $this->doctor, $this->service, '2026-11-02 08:00');
        Notification::fake();

        $appointment = app(BookAnyDoctor::class)->handle($this->patient, $this->service, notifyAt('2026-11-02 08:00'));

        expect($appointment->doctor_id)->toBe($second->id)
            ->and($taken->fresh()->status)->toBe(AppointmentStatus::Booked);
        Notification::assertSentToTimes($this->patient, AppointmentBooked::class, 1);
    });

    it('sends the patient a cancelled email when staff cancel, and nothing to the staff member', function () {
        $appointment = notifyBooking($this->patient, $this->doctor, $this->service, '2026-10-30 15:00');
        $staff = userWithRole(Role::Staff);
        Notification::fake();

        $this->actingAs($staff)
            ->post(route('staff.appointments.cancel', $appointment), ['reason' => 'Doctor is out sick.'])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($this->patient, AppointmentCancelled::class, fn (AppointmentCancelled $n): bool => $n->appointment->is($appointment));
        Notification::assertNothingSentTo($staff);
    });

    it('sends nothing when the patient cancels their own booking', function () {
        $appointment = notifyBooking($this->patient, $this->doctor, $this->service, '2026-10-30 15:00');
        Notification::fake();

        $this->actingAs($this->patient)
            ->post(route('my.appointments.cancel', $appointment), ['reason' => 'Changed my mind.'])
            ->assertSessionHasNoErrors();

        expect($appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled);
        Notification::assertNothingSent();
    });

    it('sends one booked email for the new time when staff move a booking, and no cancelled email', function () {
        $old = notifyBooking($this->patient, $this->doctor, $this->service, '2026-10-30 14:00');
        Notification::fake();

        $new = app(MoveAppointment::class)->handle(userWithRole(Role::Staff), $old, $this->doctor, notifyAt('2026-10-30 15:00'));

        Notification::assertSentToTimes($this->patient, AppointmentBooked::class, 1);
        Notification::assertSentTo($this->patient, AppointmentBooked::class, fn (AppointmentBooked $n): bool => $n->appointment->is($new));
        Notification::assertNotSentTo($this->patient, AppointmentCancelled::class);
    });
});

describe('refused and denied requests send nothing', function () {
    it('sends nothing when the time was taken a moment ago', function () {
        notifyBooking(User::factory()->patient()->create(), $this->doctor, $this->service, '2026-11-02 08:00');
        Notification::fake();

        expect(fn () => app(BookAppointment::class)->handle($this->patient, $this->doctor, $this->service, notifyAt('2026-11-02 08:00')))
            ->toThrow(BookAppointment::TIME_TAKEN_MESSAGE);

        Notification::assertNothingSent();
    });

    it('sends nothing when the booking form is sent without a time', function () {
        Notification::fake();

        $this->actingAs($this->patient)
            ->post(route('booking.store', $this->service), ['doctor_id' => $this->doctor->id])
            ->assertSessionHasErrors('starts_at');

        Notification::assertNothingSent();
    });

    it('sends nothing when staff cancel without a reason', function () {
        $appointment = notifyBooking($this->patient, $this->doctor, $this->service, '2026-10-30 15:00');
        Notification::fake();

        $this->actingAs(userWithRole(Role::Staff))
            ->post(route('staff.appointments.cancel', $appointment), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        expect($appointment->fresh()->status)->toBe(AppointmentStatus::Booked);
        Notification::assertNothingSent();
    });

    it('answers 403 and sends nothing when a patient cancels someone else\'s booking', function () {
        $appointment = notifyBooking($this->patient, $this->doctor, $this->service, '2026-10-30 15:00');
        $stranger = User::factory()->patient()->create();
        Notification::fake();

        $this->actingAs($stranger)
            ->post(route('my.appointments.cancel', $appointment), ['reason' => 'Not mine.'])
            ->assertForbidden();

        expect($appointment->fresh()->status)->toBe(AppointmentStatus::Booked);
        Notification::assertNothingSent();
    });

    it('answers 403 and sends nothing when a patient tries the staff cancel route', function () {
        $appointment = notifyBooking($this->patient, $this->doctor, $this->service, '2026-10-30 15:00');
        Notification::fake();

        $this->actingAs($this->patient)
            ->post(route('staff.appointments.cancel', $appointment), ['reason' => 'Let me in.'])
            ->assertForbidden();

        expect($appointment->fresh()->status)->toBe(AppointmentStatus::Booked);
        Notification::assertNothingSent();
    });

    it('sends nothing when a patient tries to move their own booking', function () {
        $appointment = notifyBooking($this->patient, $this->doctor, $this->service, '2026-10-30 14:00');
        Notification::fake();

        expect(fn () => app(MoveAppointment::class)->handle($this->patient, $appointment, $this->doctor, notifyAt('2026-10-30 15:00')))
            ->toThrow(AuthorizationException::class);

        expect(Appointment::query()->sole()->status)->toBe(AppointmentStatus::Booked);
        Notification::assertNothingSent();
    });

    // No fake here: the real sync queue and the array mailer, so this proves the email waits for the commit.
    it('sends no email when the booking is rolled back, and one when it commits', function () {
        $sent = fn (): int => Mail::mailer()->getSymfonyTransport()->messages()->count();

        try {
            DB::transaction(function (): void {
                app(BookAppointment::class)->handle($this->patient, $this->doctor, $this->service, notifyAt('2026-11-02 08:00'));

                throw new RuntimeException('Something later in the request failed.');
            });
        } catch (RuntimeException) {
        }

        expect(Appointment::query()->count())->toBe(0)
            ->and($sent())->toBe(0);

        app(BookAppointment::class)->handle($this->patient, $this->doctor, $this->service, notifyAt('2026-11-02 08:00'));

        expect($sent())->toBe(1);
    });
});

describe('what the email says', function () {
    beforeEach(function () {
        $this->doctor->user()->update(['name' => 'Ana Reyes']);
        // 07:30 in Manila is 23:30 on Sunday in UTC, so a missing timezone conversion shows the wrong day too.
        // Everything health related this booking has. None of it may reach an email.
        $this->appointment = notifyBooking($this->patient, $this->doctor, $this->service, '2026-11-02 07:30');
        $this->appointment->update([
            'emergency_flag' => true,
            'cancel_reason' => 'Patient admitted to hospital with chest pain.',
        ]);
        Intake::factory()->for($this->appointment)->create(['answers' => [
            'reason' => 'Persistent migraine',
            'symptoms' => 'Blurred vision and nausea every morning',
            'duration' => 'one_week',
            'allergies' => 'Penicillin',
            'medicines' => 'Sumatriptan',
            'fever' => false,
            'pregnant' => false,
            'recent_surgery' => false,
        ]]);
        $this->appointment = $this->appointment->fresh();
    });

    it('shows the date, time, doctor and service in clinic time', function (string $class) {
        $html = (string) (new $class($this->appointment))->toMail($this->patient)->render();

        expect($html)->toContain('Hello Maria,')
            ->toContain('Monday, 2 November 2026')
            ->toContain('7:30 AM')
            ->toContain('Ana Reyes')
            ->toContain('General Consultation');
    })->with([AppointmentBooked::class, AppointmentCancelled::class, AppointmentReminder::class]);

    it('holds no intake answers, emergency flag, cancel reason or birth date', function (string $class) {
        $html = (string) (new $class($this->appointment))->toMail($this->patient)->render();

        expect($html)->not->toContain('migraine')
            ->not->toContain('Blurred vision')
            ->not->toContain('Penicillin')
            ->not->toContain('Sumatriptan')
            ->not->toContain('chest pain')
            ->not->toContain('mergency')
            ->not->toContain('1990')
            ->not->toContain('Santos');
    })->with([AppointmentBooked::class, AppointmentCancelled::class, AppointmentReminder::class]);

    it('links the booked email to the patient\'s intake form', function () {
        $html = (string) (new AppointmentBooked($this->appointment))->toMail($this->patient)->render();

        expect($html)->toContain(e(route('my.appointments.intake', $this->appointment)));
    });
});

describe('sending from the queue', function () {
    it('is pushed to the queue as a mail-only job holding the appointment id, not its data', function (string $class) {
        $appointment = notifyBooking($this->patient, $this->doctor, $this->service, '2026-11-02 08:00');
        Queue::fake();

        $this->patient->notify(new $class($appointment));

        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->notification instanceof $class
            && $job->channels === ['mail']
            && ! str_contains(serialize($job), '2026-11-02'));
    })->with([AppointmentBooked::class, AppointmentCancelled::class, AppointmentReminder::class]);

    // The worker can run minutes later. By then the booking may have changed, and the email would be wrong.
    it('drops an email whose appointment changed status before the worker ran', function (string $class, string $state) {
        $appointment = notifyBooking($this->patient, $this->doctor, $this->service, '2026-11-02 08:00', $state);

        expect((new $class($appointment))->shouldSend($this->patient, 'mail'))->toBeFalse();
    })->with([
        'booked email for a cancelled booking' => [AppointmentBooked::class, 'cancelled'],
        'reminder for a cancelled booking' => [AppointmentReminder::class, 'cancelled'],
        'reminder for a checked-in booking' => [AppointmentReminder::class, 'checkedIn'],
    ]);

    it('sends an email whose appointment still has the expected status', function (string $class, string $state) {
        $appointment = notifyBooking($this->patient, $this->doctor, $this->service, '2026-11-02 08:00', $state);

        expect((new $class($appointment))->shouldSend($this->patient, 'mail'))->toBeTrue();
    })->with([
        [AppointmentBooked::class, 'booked'],
        [AppointmentReminder::class, 'booked'],
        [AppointmentCancelled::class, 'cancelled'],
    ]);
});
