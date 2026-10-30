<?php

declare(strict_types=1);

use App\Actions\Admin\UpdateDoctor;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\TimeOff;
use App\Models\User;
use App\Models\WorkingHour;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array<string, mixed> */
function doctorInput(array $overrides = []): array
{
    return [
        'specialty' => 'Pediatrics',
        'bio' => 'Twelve years caring for children.',
        'service_ids' => [],
        ...$overrides,
    ];
}

describe('guests', function () {
    it('are sent to the login page from every doctors route, and change nothing', function (string $method, string $route) {
        $doctor = Doctor::factory()->create(['specialty' => 'Dermatology']);

        $this->{$method}(route($route, $route === 'admin.doctors.index' ? [] : $doctor), doctorInput())
            ->assertRedirect(route('login'));

        expect($doctor->fresh()->specialty)->toBe('Dermatology');
    })->with([
        'list' => ['get', 'admin.doctors.index'],
        'edit form' => ['get', 'admin.doctors.edit'],
        'update' => ['put', 'admin.doctors.update'],
    ]);
});

describe('doctors list', function () {
    it('shows a paginated list to an admin, in name order, with the number of services', function () {
        $first = Doctor::factory()->for(User::factory()->doctor()->state(['name' => 'Ana Aquino']), 'user')->create(['specialty' => 'Pediatrics']);
        Doctor::factory()->for(User::factory()->doctor()->state(['name' => 'Zed Zamora']), 'user')->create();
        $first->services()->attach(Service::factory()->count(2)->create());
        Doctor::factory()->count(15)->state(fn () => ['user_id' => User::factory()->doctor()->state(['name' => 'Mia Mercado'])])->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.doctors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/doctors/index')
                ->has('doctors.data', 15)
                ->where('doctors.total', 17)
                ->where('doctors.data.0.name', 'Ana Aquino')
                ->where('doctors.data.0.specialty', 'Pediatrics')
                ->where('doctors.data.0.servicesCount', 2)
                ->where('doctors.data.0.isActive', true)
                ->where('doctors.data.0.can.update', true)
                ->where('doctors.data.1.servicesCount', 0)
                ->missing('doctors.data.0.bio')
                ->missing('doctors.data.0.user_id')
                ->missing('doctors.data.0.email'));
    });

    it('marks a doctor whose account is deactivated', function () {
        Doctor::factory()->for(User::factory()->doctor()->inactive(), 'user')->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.doctors.index'))
            ->assertInertia(fn (Assert $page) => $page->where('doctors.data.0.isActive', false));
    });

    it('denies the list to everyone but admins', function (Role $role) {
        $this->actingAs(userWithRole($role))->get(route('admin.doctors.index'))->assertForbidden();
    })->with('non_admins');
});

describe('editing a doctor', function () {
    it('shows the form with the saved values, every service and the ones the doctor offers', function () {
        $doctor = Doctor::factory()->create(['specialty' => 'Dermatology', 'bio' => 'Skin and hair.']);
        $offered = Service::factory()->create(['name' => 'B offered']);
        $off = Service::factory()->inactive()->create(['name' => 'A switched off']);
        $other = Service::factory()->create(['name' => 'C other']);
        $doctor->services()->attach([$offered->id, $off->id]);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.doctors.edit', $doctor))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/doctors/edit')
                ->where('doctor.id', $doctor->id)
                ->where('doctor.name', $doctor->user->name)
                ->where('doctor.specialty', 'Dermatology')
                ->where('doctor.bio', 'Skin and hair.')
                ->where('doctor.serviceIds', fn ($ids) => collect($ids)->sort()->values()->all() === collect([$offered->id, $off->id])->sort()->values()->all())
                ->has('services', 3)
                ->where('services.0.name', 'A switched off')
                ->where('services.0.isActive', false)
                ->where('services.2.id', $other->id)
                ->missing('services.0.slug')
                ->missing('doctor.user_id'));
    });

    it('lets an admin update the specialty and the bio', function () {
        $doctor = Doctor::factory()->create(['specialty' => 'Dermatology', 'bio' => 'Old bio.']);

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.update', $doctor), doctorInput())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.doctors.index'));

        $doctor->refresh();

        expect($doctor->specialty)->toBe('Pediatrics')
            ->and($doctor->bio)->toBe('Twelve years caring for children.');
    });

    it('syncs the services: adds new ones and removes the ones that were left out', function () {
        $doctor = Doctor::factory()->create();
        [$keep, $drop, $add] = Service::factory()->count(3)->create()->all();
        $doctor->services()->attach([$keep->id, $drop->id]);

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.update', $doctor), doctorInput(['service_ids' => [$keep->id, $add->id]]))
            ->assertSessionHasNoErrors();

        expect($doctor->services()->pluck('services.id')->sort()->values()->all())
            ->toBe(collect([$keep->id, $add->id])->sort()->values()->all());
    });

    it('accepts the service ids as a keyed object and still syncs them', function () {
        $doctor = Doctor::factory()->create();
        $service = Service::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.update', $doctor), doctorInput(['service_ids' => ['first' => $service->id]]))
            ->assertSessionHasNoErrors();

        expect($doctor->services()->pluck('services.id')->all())->toBe([$service->id]);
    });

    it('lets an admin remove every service with an empty list', function () {
        $doctor = Doctor::factory()->create();
        $doctor->services()->attach(Service::factory()->count(2)->create());

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.update', $doctor), doctorInput(['service_ids' => []]))
            ->assertSessionHasNoErrors();

        expect($doctor->services()->count())->toBe(0);
    });

    it('keeps a switched-off service the doctor already offers when it stays in the list', function () {
        $doctor = Doctor::factory()->create();
        $off = Service::factory()->inactive()->create();
        $doctor->services()->attach($off);

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.update', $doctor), doctorInput(['service_ids' => [$off->id]]))
            ->assertSessionHasNoErrors();

        expect($doctor->services()->pluck('services.id')->all())->toBe([$off->id]);
    });

    it('lets an admin clear the bio', function () {
        $doctor = Doctor::factory()->create(['bio' => 'Old bio.']);

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.update', $doctor), doctorInput(['bio' => '']))
            ->assertSessionHasNoErrors();

        expect($doctor->fresh()->bio)->toBeNull();
    });

    it('leaves the account, working hours, time off and bookings alone', function () {
        $doctor = Doctor::factory()->create();
        $name = $doctor->user->name;
        $email = $doctor->user->email;
        $service = Service::factory()->create();
        $doctor->services()->attach($service);
        WorkingHour::factory()->for($doctor)->create();
        TimeOff::factory()->for($doctor)->create();
        $appointment = Appointment::factory()->create(['doctor_id' => $doctor->id, 'service_id' => $service->id]);

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.update', $doctor), doctorInput(['service_ids' => []]))
            ->assertSessionHasNoErrors();

        expect($doctor->user->fresh()->name)->toBe($name)
            ->and($doctor->user->fresh()->email)->toBe($email)
            ->and($doctor->workingHours()->count())->toBe(1)
            ->and($doctor->timeOffs()->count())->toBe(1)
            // Removing the service from the doctor does not touch the booking made earlier.
            ->and($appointment->fresh()->service_id)->toBe($service->id)
            ->and($appointment->fresh()->doctor_id)->toBe($doctor->id);
    });

    it('only changes the doctor in the URL', function () {
        $doctor = Doctor::factory()->create(['specialty' => 'Dermatology']);
        $other = Doctor::factory()->create(['specialty' => 'Cardiology']);

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.update', $doctor), doctorInput(['id' => $other->id, 'user_id' => $other->user_id]));

        expect($doctor->fresh()->specialty)->toBe('Pediatrics')
            ->and($doctor->fresh()->user_id)->not->toBe($other->user_id)
            ->and($other->fresh()->specialty)->toBe('Cardiology');
    });

    it('denies editing doctors to everyone but admins, and changes nothing', function (Role $role) {
        // The doctor case is the doctor's own profile: a doctor can't edit it here either.
        $doctor = Doctor::factory()->for(User::factory()->doctor()->withTwoFactor(), 'user')->create(['specialty' => 'Dermatology']);
        $service = Service::factory()->create();
        $actor = $role === Role::Doctor ? $doctor->user : userWithRole($role);

        $this->actingAs($actor)->get(route('admin.doctors.edit', $doctor))->assertForbidden();
        $this->actingAs($actor)->put(route('admin.doctors.update', $doctor), doctorInput(['service_ids' => [$service->id]]))->assertForbidden();

        expect($doctor->fresh()->specialty)->toBe('Dermatology')
            ->and($doctor->services()->count())->toBe(0);
    })->with('non_admins');

    it('validates the input', function (array $overrides, string $invalidField) {
        $doctor = Doctor::factory()->create(['specialty' => 'Dermatology']);
        $doctor->services()->attach(Service::factory()->create());

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.update', $doctor), doctorInput($overrides))
            ->assertSessionHasErrors($invalidField);

        expect($doctor->fresh()->specialty)->toBe('Dermatology')
            ->and($doctor->services()->count())->toBe(1);
    })->with([
        'specialty is required' => [['specialty' => ''], 'specialty'],
        'specialty is too long' => [['specialty' => str_repeat('a', 256)], 'specialty'],
        'bio is too long' => [['bio' => str_repeat('a', 1001)], 'bio'],
        'services must be sent' => [['service_ids' => null], 'service_ids'],
        'services must be a list' => [['service_ids' => 'all'], 'service_ids'],
        'a service must exist' => [['service_ids' => [999999]], 'service_ids.0'],
        'a service id must be a number' => [['service_ids' => ['abc']], 'service_ids.0'],
    ]);

    it('accepts a specialty and a bio at the longest allowed length', function () {
        $doctor = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.update', $doctor), doctorInput(['specialty' => str_repeat('a', 255), 'bio' => str_repeat('b', 1000)]))
            ->assertSessionHasNoErrors();

        expect($doctor->fresh()->specialty)->toHaveLength(255)
            ->and($doctor->fresh()->bio)->toHaveLength(1000);
    });

    it('rejects more than 100 services in one request', function () {
        $doctor = Doctor::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.update', $doctor), doctorInput(['service_ids' => range(1, 101)]))
            ->assertSessionHasErrors('service_ids');
    });

    it('rejects a request that leaves out the services, and keeps them', function () {
        $doctor = Doctor::factory()->create();
        $doctor->services()->attach(Service::factory()->create());

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.update', $doctor), ['specialty' => 'Pediatrics'])
            ->assertSessionHasErrors('service_ids');

        expect($doctor->services()->count())->toBe(1);
    });

    it('rejects the same service twice', function () {
        $doctor = Doctor::factory()->create();
        $service = Service::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.doctors.update', $doctor), doctorInput(['service_ids' => [$service->id, $service->id]]))
            ->assertSessionHasErrors('service_ids.1');

        expect($doctor->services()->count())->toBe(0);
    });

    it('answers 404 for a doctor that does not exist', function () {
        $this->actingAs(userWithRole(Role::Admin))->get(route('admin.doctors.edit', 999999))->assertNotFound();
    });
});

describe('the update doctor action', function () {
    it('saves the profile and the services together, or neither', function () {
        $doctor = Doctor::factory()->create(['specialty' => 'Dermatology']);
        $kept = Service::factory()->create();
        $doctor->services()->attach($kept);

        // An id that is not a service breaks the pivot insert (foreign key), after the profile update ran.
        expect(fn () => app(UpdateDoctor::class)->handle($doctor, 'Pediatrics', null, [999999]))->toThrow(Exception::class);

        expect($doctor->fresh()->specialty)->toBe('Dermatology')
            ->and(DB::table('doctor_service')->where('doctor_id', $doctor->id)->pluck('service_id')->all())->toBe([$kept->id]);
    });

    it('has no create or delete route for doctors', function () {
        $doctor = Doctor::factory()->create();
        $admin = userWithRole(Role::Admin);

        $this->actingAs($admin)->post('/admin/doctors', doctorInput())->assertStatus(405);
        $this->actingAs($admin)->delete("/admin/doctors/{$doctor->id}")->assertStatus(405);

        expect(Doctor::query()->count())->toBe(1);
    });
});
