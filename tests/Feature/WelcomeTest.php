<?php

declare(strict_types=1);

use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// The landing page is public and read-only, so there is no Policy and no "denied" role. It has no input either, so
// there is nothing to validate. The refusals are the doctors it must not advertise, and the fields it must not send.

/** A doctor with a name who offers one service, so the landing page may show them. */
function listedDoctor(string $name, ?Service $service = null): Doctor
{
    $doctor = Doctor::factory()->create();
    $doctor->user->forceFill(['name' => $name])->save();
    $doctor->services()->attach($service ?? Service::factory()->create());

    return $doctor;
}

it('shows the bookable doctors to a guest, by name, with public fields only', function () {
    listedDoctor('Dr. Zed Zamora');
    $alpha = listedDoctor('Dr. Ana Alonzo');

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->has('doctors', 2)
            ->where('doctors.0', [
                'id' => $alpha->id,
                'name' => 'Dr. Ana Alonzo',
                'specialty' => $alpha->specialty,
                'bio' => $alpha->bio,
            ])
            ->where('doctors.1.name', 'Dr. Zed Zamora')
            ->missing('doctors.0.email')
            ->missing('doctors.0.userId'));
});

it('leaves out a deactivated doctor and a doctor who offers no active service', function () {
    listedDoctor('Dr. Visible');

    $deactivated = listedDoctor('Dr. Deactivated');
    $deactivated->user->forceFill(['is_active' => false])->save();

    listedDoctor('Dr. Switched Off', Service::factory()->create(['is_active' => false]));

    $noService = Doctor::factory()->create();
    $noService->user->forceFill(['name' => 'Dr. No Service'])->save();

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('doctors', 1)
            ->where('doctors.0.name', 'Dr. Visible'));
});

it('lists a doctor once, even when they offer several active services', function () {
    $doctor = listedDoctor('Dr. Many Services');
    $doctor->services()->attach(Service::factory()->count(2)->create());

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('doctors', 1)
            ->where('doctors.0.name', 'Dr. Many Services'));
});

// The header branches on the signed-in role, so every role must still reach the page and get the same doctors.
it('is the same public page for a signed-in user of any role', function (string $role) {
    listedDoctor('Dr. Visible');

    $this->actingAs(User::factory()->{$role}()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->has('doctors', 1)
            ->where('doctors.0.name', 'Dr. Visible'));
})->with(['patient', 'staff', 'doctor', 'admin']);

it('shows at most eight doctors, in name order', function () {
    foreach (range(1, 10) as $number) {
        listedDoctor(sprintf('Dr. Doctor %02d', $number));
    }

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('doctors', 8)
            ->where('doctors.0.name', 'Dr. Doctor 01')
            ->where('doctors.7.name', 'Dr. Doctor 08'));
});

it('still renders when there are no doctors', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('welcome')->has('doctors', 0));
});
