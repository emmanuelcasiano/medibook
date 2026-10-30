<?php

declare(strict_types=1);

use App\Actions\Admin\DeleteService;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array<string, mixed> */
function newServiceInput(array $overrides = []): array
{
    return [
        'name' => 'Dental cleaning',
        'description' => 'A basic cleaning and check.',
        'duration_minutes' => 45,
        'buffer_minutes' => 15,
        'is_active' => '1',
        ...$overrides,
    ];
}

describe('guests', function () {
    it('are sent to the login page from every services route, and change nothing', function (string $method, string $route, bool $forExistingService) {
        $service = Service::factory()->create(['name' => 'Skin check']);

        $this->{$method}(route($route, $forExistingService ? $service : []), newServiceInput())
            ->assertRedirect(route('login'));

        expect(Service::query()->count())->toBe(1)
            ->and($service->fresh()->name)->toBe('Skin check');
    })->with([
        'create form' => ['get', 'admin.services.create', false],
        'store' => ['post', 'admin.services.store', false],
        'edit form' => ['get', 'admin.services.edit', true],
        'update' => ['put', 'admin.services.update', true],
    ]);
});

describe('services list', function () {
    it('shows a paginated list to an admin, with doctors and what can be deleted', function () {
        $used = Service::factory()->create(['name' => 'A used service']);
        Appointment::factory()->completed()->create(['service_id' => $used->id]);
        $unused = Service::factory()->create(['name' => 'B unused service']);
        $doctor = Doctor::factory()->create();
        $unused->doctors()->attach($doctor);
        Service::factory()->count(15)->create(['name' => 'Z filler']);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.services.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/services/index')
                ->has('services.data', 15)
                ->where('services.total', 17)
                ->where('services.data.0.name', 'A used service')
                ->where('services.data.0.can.delete', false)
                ->where('services.data.0.appointmentsCount', 1)
                ->where('services.data.1.can.delete', true)
                ->where('services.data.1.doctorNames', [$doctor->user->name])
                ->missing('services.data.0.slug')
                ->missing('services.data.0.created_at'));
    });

    it('denies the list to everyone but admins', function (Role $role) {
        $this->actingAs(userWithRole($role))->get(route('admin.services.index'))->assertForbidden();
    })->with('non_admins');

    it('sends a guest to the login page', function () {
        $this->get(route('admin.services.index'))->assertRedirect(route('login'));
    });
});

describe('creating a service', function () {
    it('shows the create form to an admin', function () {
        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.services.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/services/create'));
    });

    it('lets an admin create a service, with a slug made from the name', function () {
        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.services.store'), newServiceInput())
            ->assertRedirect(route('admin.services.index'));

        $service = Service::query()->where('name', 'Dental cleaning')->firstOrFail();

        expect($service->slug)->toBe('dental-cleaning')
            ->and($service->description)->toBe('A basic cleaning and check.')
            ->and($service->duration_minutes)->toBe(45)
            ->and($service->buffer_minutes)->toBe(15)
            ->and($service->is_active)->toBeTrue();
    });

    it('saves a service that is turned off, with no description and no buffer', function () {
        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.services.store'), newServiceInput(['description' => '', 'buffer_minutes' => 0, 'is_active' => '0']))
            ->assertRedirect(route('admin.services.index'));

        $service = Service::query()->where('name', 'Dental cleaning')->firstOrFail();

        expect($service->description)->toBeNull()
            ->and($service->buffer_minutes)->toBe(0)
            ->and($service->is_active)->toBeFalse();
    });

    it('accepts the smallest and the largest allowed length and buffer', function (int $duration, int $buffer) {
        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.services.store'), newServiceInput(['duration_minutes' => $duration, 'buffer_minutes' => $buffer]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.services.index'));

        $service = Service::query()->where('name', 'Dental cleaning')->firstOrFail();

        expect($service->duration_minutes)->toBe($duration)->and($service->buffer_minutes)->toBe($buffer);
    })->with([
        'smallest' => [1, 0],
        'largest' => [480, 120],
    ]);

    it('limits how fast services can be created', function () {
        $admin = userWithRole(Role::Admin);

        foreach (range(1, 20) as $attempt) {
            $this->actingAs($admin)->post(route('admin.services.store'), [])->assertSessionHasErrors();
        }

        $this->actingAs($admin)->post(route('admin.services.store'), newServiceInput())->assertTooManyRequests();
        expect(Service::query()->count())->toBe(0);
    });

    it('denies creating services to everyone but admins, and creates nothing', function (Role $role) {
        $actor = userWithRole($role);

        $this->actingAs($actor)->get(route('admin.services.create'))->assertForbidden();
        $this->actingAs($actor)->post(route('admin.services.store'), newServiceInput())->assertForbidden();

        expect(Service::query()->count())->toBe(0);
    })->with('non_admins');

    it('validates the input', function (array $overrides, string $invalidField) {
        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.services.store'), newServiceInput($overrides))
            ->assertSessionHasErrors($invalidField);

        expect(Service::query()->count())->toBe(0);
    })->with([
        'name is required' => [['name' => ''], 'name'],
        'name is too long' => [['name' => str_repeat('a', 256)], 'name'],
        'description is too long' => [['description' => str_repeat('a', 1001)], 'description'],
        'length is required' => [['duration_minutes' => ''], 'duration_minutes'],
        'length must be a number' => [['duration_minutes' => 'long'], 'duration_minutes'],
        'length must be a whole number' => [['duration_minutes' => '30.5'], 'duration_minutes'],
        'length must be at least 1' => [['duration_minutes' => 0], 'duration_minutes'],
        'length can not be longer than 8 hours' => [['duration_minutes' => 481], 'duration_minutes'],
        'buffer is required' => [['buffer_minutes' => ''], 'buffer_minutes'],
        'buffer can not be negative' => [['buffer_minutes' => -5], 'buffer_minutes'],
        'buffer can not be longer than 2 hours' => [['buffer_minutes' => 121], 'buffer_minutes'],
        'bookable is required' => [['is_active' => ''], 'is_active'],
        'bookable must be true or false' => [['is_active' => 'maybe'], 'is_active'],
    ]);

    it('rejects a name that is already used', function () {
        Service::factory()->create(['name' => 'Dental cleaning', 'slug' => 'dental-cleaning']);

        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.services.store'), newServiceInput())
            ->assertSessionHasErrors('name');

        expect(Service::query()->count())->toBe(1);
    });

    it('rejects a different name that would give the same slug', function () {
        Service::factory()->create(['name' => 'Check-up', 'slug' => 'check-up']);

        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.services.store'), newServiceInput(['name' => 'Check up']))
            ->assertSessionHasErrors('name');

        expect(Service::query()->count())->toBe(1);
    });

    it('ignores a slug sent in the form', function () {
        $this->actingAs(userWithRole(Role::Admin))
            ->post(route('admin.services.store'), newServiceInput(['slug' => 'something-else', 'id' => 99]));

        $service = Service::query()->where('name', 'Dental cleaning')->firstOrFail();

        expect($service->slug)->toBe('dental-cleaning')->and($service->id)->not->toBe(99);
    });
});

describe('editing a service', function () {
    it('shows the edit form with the saved values', function () {
        $service = Service::factory()->create(['name' => 'Skin check', 'duration_minutes' => 20, 'buffer_minutes' => 5]);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.services.edit', $service))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/services/edit')
                ->where('service.id', $service->id)
                ->where('service.name', 'Skin check')
                ->where('service.durationMinutes', 20)
                ->where('service.bufferMinutes', 5)
                ->where('service.isActive', true)
                ->missing('service.slug'));
    });

    it('lets an admin update a service, and keeps its slug', function () {
        $service = Service::factory()->create(['name' => 'Skin check', 'slug' => 'skin-check']);

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.services.update', $service), newServiceInput(['name' => 'Skin and hair check']))
            ->assertRedirect(route('admin.services.index'));

        $service->refresh();

        expect($service->name)->toBe('Skin and hair check')
            ->and($service->slug)->toBe('skin-check')
            ->and($service->duration_minutes)->toBe(45)
            ->and($service->buffer_minutes)->toBe(15);
    });

    it('lets an admin turn a service off', function () {
        $service = Service::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.services.update', $service), newServiceInput(['name' => $service->name, 'is_active' => '0']))
            ->assertRedirect(route('admin.services.index'));

        expect($service->fresh()->is_active)->toBeFalse();
    });

    it('lets an admin clear the description', function () {
        $service = Service::factory()->create(['description' => 'Old text']);

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.services.update', $service), newServiceInput(['name' => $service->name, 'description' => '']))
            ->assertSessionHasNoErrors();

        expect($service->fresh()->description)->toBeNull();
    });

    it('lets an admin save a service without changing its name', function () {
        $service = Service::factory()->create(['name' => 'Skin check']);

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.services.update', $service), newServiceInput(['name' => 'Skin check']))
            ->assertSessionHasNoErrors();
    });

    it('leaves the times of existing bookings alone when the length changes', function () {
        $service = Service::factory()->lasting(30, 10)->create();
        $appointment = Appointment::factory()->create(['service_id' => $service->id]);
        $endsAt = $appointment->ends_at;
        $blockedUntil = $appointment->blocked_until;

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.services.update', $service), newServiceInput(['name' => $service->name, 'duration_minutes' => 60, 'buffer_minutes' => 20]));

        $appointment->refresh();

        expect($appointment->ends_at->equalTo($endsAt))->toBeTrue()
            ->and($appointment->blocked_until->equalTo($blockedUntil))->toBeTrue();
    });

    it('denies editing services to everyone but admins, and changes nothing', function (Role $role) {
        $service = Service::factory()->create(['name' => 'Skin check']);
        $actor = userWithRole($role);

        $this->actingAs($actor)->get(route('admin.services.edit', $service))->assertForbidden();
        $this->actingAs($actor)->put(route('admin.services.update', $service), newServiceInput())->assertForbidden();

        expect($service->fresh()->name)->toBe('Skin check');
    })->with('non_admins');

    it('validates the input', function (array $overrides, string $invalidField) {
        $service = Service::factory()->create(['name' => 'Skin check']);

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.services.update', $service), newServiceInput($overrides))
            ->assertSessionHasErrors($invalidField);

        expect($service->fresh()->name)->toBe('Skin check');
    })->with([
        'name is required' => [['name' => ''], 'name'],
        'length must be at least 1' => [['duration_minutes' => 0], 'duration_minutes'],
        'buffer can not be negative' => [['buffer_minutes' => -1], 'buffer_minutes'],
        'bookable must be true or false' => [['is_active' => 'maybe'], 'is_active'],
    ]);

    it('rejects a name that another service uses', function () {
        Service::factory()->create(['name' => 'Dental cleaning']);
        $service = Service::factory()->create(['name' => 'Skin check']);

        $this->actingAs(userWithRole(Role::Admin))
            ->put(route('admin.services.update', $service), newServiceInput())
            ->assertSessionHasErrors('name');

        expect($service->fresh()->name)->toBe('Skin check');
    });

    it('answers 404 for a service that does not exist', function () {
        $this->actingAs(userWithRole(Role::Admin))->get(route('admin.services.edit', 999999))->assertNotFound();
    });
});

describe('deleting a service', function () {
    it('lets an admin delete a service nobody has booked', function () {
        $service = Service::factory()->create();

        $this->actingAs(userWithRole(Role::Admin))
            ->delete(route('admin.services.destroy', $service))
            ->assertRedirect(route('admin.services.index'));

        expect(Service::query()->whereKey($service->id)->exists())->toBeFalse();
    });

    it('unlinks the doctors who offered it, and keeps the doctors', function () {
        $service = Service::factory()->create();
        $other = Service::factory()->create();
        $doctor = Doctor::factory()->create();
        $doctor->services()->attach([$service->id, $other->id]);

        $this->actingAs(userWithRole(Role::Admin))->delete(route('admin.services.destroy', $service));

        expect(Service::query()->whereKey($service->id)->exists())->toBeFalse()
            ->and(Doctor::query()->whereKey($doctor->id)->exists())->toBeTrue()
            ->and(DB::table('doctor_service')->where('service_id', $service->id)->exists())->toBeFalse()
            ->and($doctor->services()->pluck('services.id')->all())->toBe([$other->id]);
    });

    it('refuses to delete a service that has an appointment, in any status', function (string $state) {
        $service = Service::factory()->create();
        $doctor = Doctor::factory()->create();
        $doctor->services()->attach($service);
        Appointment::factory()->{$state}()->create(['service_id' => $service->id, 'doctor_id' => $doctor->id]);

        $this->actingAs(userWithRole(Role::Admin))
            ->from(route('admin.services.index'))
            ->delete(route('admin.services.destroy', $service))
            ->assertRedirect(route('admin.services.index'))
            ->assertSessionHasErrors('service');

        expect(Service::query()->whereKey($service->id)->exists())->toBeTrue()
            ->and($doctor->services()->count())->toBe(1);
    })->with(['booked', 'checkedIn', 'completed', 'cancelled', 'noShow']);

    it('throws from the Action when the service has appointments', function () {
        $service = Service::factory()->create();
        Appointment::factory()->completed()->create(['service_id' => $service->id]);

        expect(fn () => app(DeleteService::class)->handle($service))->toThrow(ValidationException::class);
        expect(Service::query()->whereKey($service->id)->exists())->toBeTrue();
    });

    it('denies deleting services to everyone but admins, and changes nothing', function (Role $role) {
        $service = Service::factory()->create();

        $this->actingAs(userWithRole($role))->delete(route('admin.services.destroy', $service))->assertForbidden();

        expect(Service::query()->whereKey($service->id)->exists())->toBeTrue();
    })->with('non_admins');

    it('sends a guest to the login page', function () {
        $service = Service::factory()->create();

        $this->delete(route('admin.services.destroy', $service))->assertRedirect(route('login'));

        expect(Service::query()->whereKey($service->id)->exists())->toBeTrue();
    });

    it('answers 404 for a service that does not exist', function () {
        $this->actingAs(userWithRole(Role::Admin))->delete(route('admin.services.destroy', 999999))->assertNotFound();
    });
});
