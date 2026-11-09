<?php

declare(strict_types=1);

use App\Actions\Doctor\CompleteAppointment;
use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

// "Finish visit" (step 10.5): the appointment's own doctor turns a checked-in visit into a completed one. The
// `appointment.completed` audit write is step 11.2. "Now" is fixed at Monday 2 November 2026, 09:00 in Manila.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'Asia/Manila'));

    $this->doctorUser = userWithRole(Role::Doctor);
    $this->doctor = Doctor::query()->where('user_id', $this->doctorUser->id)->firstOrFail();

    // An appointment for the signed-in doctor, or for $doctor. It is checked in by default, the only state that can
    // be finished ($state is any AppointmentFactory state).
    $this->booking = fn (?Doctor $doctor = null, string $state = 'checkedIn'): Appointment => Appointment::factory()
        ->for($doctor ?? $this->doctor)
        ->for(User::factory()->patient()->create(), 'patient')
        ->{$state}()
        ->create(['starts_at' => CarbonImmutable::parse('2026-11-02 10:00', 'Asia/Manila')->utc()]);

    $this->finish = fn (Appointment $appointment, ?User $as = null) => $this->actingAs($as ?? $this->doctorUser)
        ->post(route('doctor.appointments.complete', $appointment));
});

describe('finishing a visit', function () {
    it('completes a checked-in visit for its own doctor and goes back to the appointment', function () {
        $appointment = ($this->booking)();

        ($this->finish)($appointment)->assertRedirect(route('doctor.appointments.show', $appointment));

        expect($appointment->fresh()->status)->toBe(AppointmentStatus::Completed);
    });

    it('still holds the slot and leaves the other columns alone', function () {
        $appointment = ($this->booking)();
        $before = $appointment->only(['patient_id', 'doctor_id', 'service_id', 'starts_at', 'ends_at', 'checked_in_at', 'cancelled_at']);

        ($this->finish)($appointment);

        expect(Appointment::query()->active()->whereKey($appointment->id)->exists())->toBeTrue()
            ->and($appointment->fresh()->only(array_keys($before)))->toEqual($before);
    });

    it('lets the doctor keep writing the visit note after the visit is finished', function () {
        $appointment = ($this->booking)();

        ($this->finish)($appointment);

        $this->actingAs($this->doctorUser)
            ->put(route('doctor.appointments.visit-note.update', $appointment), ['body' => 'Follow up in two weeks.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    });

    it('shows the button only while the visit is checked in', function (string $state, bool $shown) {
        $appointment = ($this->booking)(state: $state);

        $this->actingAs($this->doctorUser)
            ->get(route('doctor.appointments.show', $appointment))
            ->assertInertia(fn (Assert $page) => $page
                ->component('doctor/appointments/show')
                ->where('appointment.can.complete', $shown)
                ->etc());
    })->with([
        'checked in' => ['checkedIn', true],
        'booked' => ['booked', false],
        'completed' => ['completed', false],
        'cancelled' => ['cancelled', false],
        'no-show' => ['noShow', false],
    ]);
});

describe('a visit that can not be finished', function () {
    it('refuses a visit that is not checked in, says why, and changes nothing', function (string $state, AppointmentStatus $status) {
        $appointment = ($this->booking)(state: $state);

        ($this->finish)($appointment)->assertSessionHasErrors(['appointment' => 'Only a checked-in visit can be finished.']);

        expect($appointment->fresh()->status)->toBe($status);
    })->with([
        'booked' => ['booked', AppointmentStatus::Booked],
        'completed' => ['completed', AppointmentStatus::Completed],
        'cancelled' => ['cancelled', AppointmentStatus::Cancelled],
        'no-show' => ['noShow', AppointmentStatus::NoShow],
    ]);

    it('reads the status again, so a stale model can not finish a visit that was cancelled meanwhile', function () {
        $appointment = ($this->booking)();
        $stale = Appointment::query()->findOrFail($appointment->id);

        $appointment->update(['status' => AppointmentStatus::Cancelled]);

        expect(fn () => app(CompleteAppointment::class)->handle($this->doctorUser, $stale))->toThrow(ValidationException::class)
            ->and($appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled);
    });
});

describe('rate limit', function () {
    it('allows 30 requests a minute per doctor and then answers 429', function () {
        RateLimiter::clear('doctor-actions');
        $appointment = ($this->booking)();

        foreach (range(1, 30) as $request) {
            ($this->finish)($appointment);
        }

        ($this->finish)($appointment)->assertStatus(429);
    });
});

describe('denied access', function () {
    it('answers 403 when a doctor finishes another doctor\'s visit, and changes nothing', function () {
        $theirs = ($this->booking)(Doctor::factory()->create());

        ($this->finish)($theirs)->assertForbidden();

        expect($theirs->fresh()->status)->toBe(AppointmentStatus::CheckedIn);
    });

    it('answers 403 to staff, admins and patients, and changes nothing', function (Role $role) {
        $appointment = ($this->booking)();

        ($this->finish)($appointment, userWithRole($role))->assertForbidden();

        expect($appointment->fresh()->status)->toBe(AppointmentStatus::CheckedIn);
    })->with([
        'staff' => Role::Staff,
        'admin' => Role::Admin,
        'nurse' => Role::Nurse,
        'patient' => Role::Patient,
    ]);

    it('answers 403 to the patient who owns the appointment', function () {
        $appointment = ($this->booking)();

        ($this->finish)($appointment, $appointment->patient)->assertForbidden();

        expect($appointment->fresh()->status)->toBe(AppointmentStatus::CheckedIn);
    });

    it('answers 403 and not a state message to a user who may not finish, even on a booking that is not checked in', function () {
        $theirs = ($this->booking)(Doctor::factory()->create(), 'booked');

        ($this->finish)($theirs)->assertForbidden()->assertSessionHasNoErrors();
    });

    it('sends a guest to the login page', function () {
        $appointment = ($this->booking)();

        $this->post(route('doctor.appointments.complete', $appointment))->assertRedirectToRoute('login');

        expect($appointment->fresh()->status)->toBe(AppointmentStatus::CheckedIn);
    });

    it('checks the Policy inside the Action too, for a caller that skips the controller', function (Role $role) {
        $appointment = ($this->booking)();

        expect(fn () => app(CompleteAppointment::class)->handle(userWithRole($role), $appointment))->toThrow(AuthorizationException::class)
            ->and(fn () => app(CompleteAppointment::class)->handle(userWithRole(Role::Doctor), $appointment))->toThrow(AuthorizationException::class)
            ->and($appointment->fresh()->status)->toBe(AppointmentStatus::CheckedIn);
    })->with([
        'staff' => Role::Staff,
        'admin' => Role::Admin,
        'nurse' => Role::Nurse,
        'patient' => Role::Patient,
    ]);
});
