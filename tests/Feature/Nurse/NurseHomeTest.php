<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Support\DemoMode;
use Inertia\Testing\AssertableInertia as Assert;

describe('the nurse home page', function () {
    it('opens for a nurse and says nobody is waiting yet', function () {
        $this->actingAs(userWithRole(Role::Nurse))
            ->get(route('nurse.home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('nurse/home'));
    });

    it('is where /dashboard sends a nurse', function () {
        $this->actingAs(userWithRole(Role::Nurse))->get(route('dashboard'))->assertRedirect(route('nurse.home'));
    });

    it('sends a guest to the login page', function () {
        $this->get(route('nurse.home'))->assertRedirect(route('login'));
    });

    it('sends a nurse without 2FA to the 2FA setup', function () {
        $this->actingAs(userWithRole(Role::Nurse, twoFactor: false))
            ->get(route('nurse.home'))
            ->assertRedirect(route('security.edit'));
    });

    it('is closed to every role but the nurse', function (Role $role) {
        $this->actingAs(userWithRole($role))->get(route('nurse.home'))->assertForbidden();
    })->with([Role::Patient, Role::Staff, Role::Doctor, Role::Admin]);
});

describe('what a nurse can not open', function () {
    it('is closed to the nurse in every other area', function (string $routeName) {
        $this->actingAs(userWithRole(Role::Nurse))->get(route($routeName))->assertForbidden();
    })->with([
        'admin users' => 'admin.users.index',
        'audit log' => 'admin.audit-logs.index',
        'AI usage' => 'admin.ai-usage.index',
        'staff schedule' => 'staff.home',
        'follow-ups' => 'staff.follow-ups.index',
        'doctor today' => 'doctor.home',
        'my appointments' => 'my.home',
    ]);

    it('is closed to the nurse on the pages of one appointment', function () {
        $appointment = Appointment::factory()->checkedIn()->create();
        $nurse = userWithRole(Role::Nurse);

        $this->actingAs($nurse)->get(route('doctor.appointments.show', $appointment))->assertForbidden();
        $this->actingAs($nurse)->get(route('my.appointments.intake', $appointment))->assertForbidden();
        $this->actingAs($nurse)->post(route('staff.appointments.check-in', $appointment))->assertForbidden();

        expect($appointment->fresh()->status->value)->toBe('checked_in');
    });

    it('is closed to the nurse on the booking confirm step', function () {
        $service = Service::factory()->create();

        $this->actingAs(userWithRole(Role::Nurse))
            ->get(route('booking.confirm', ['service' => $service->slug]))
            ->assertForbidden();
    });
});

describe('the demo nurse', function () {
    beforeEach(function () {
        User::factory()->nurse()->create(['email' => DemoMode::ACCOUNTS['nurse']]);
    });

    it('logs in with one click in demo mode and lands on the nurse home, past the 2FA gate', function () {
        config()->set('medibook.demo.enabled', true);

        $this->post(route('demo.login', 'nurse'))->assertRedirect(route('nurse.home'));
        $this->get(route('nurse.home'))->assertOk();
    });

    it('is blocked when demo mode is off', function () {
        config()->set('medibook.demo.enabled', false);

        $this->post(route('demo.login', 'nurse'))->assertNotFound();
        $this->assertGuest();
    });
});
