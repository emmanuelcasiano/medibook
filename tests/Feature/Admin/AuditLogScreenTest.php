<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Intake;
use App\Models\Service;
use App\Support\AuditLogger;
use Inertia\Testing\AssertableInertia as Assert;

/** A log row with no actor or subject records behind it, so a test only creates what it checks. @param array<string, mixed> $attributes */
function auditRow(array $attributes = []): AuditLog
{
    return AuditLog::factory()->create([
        'actor_id' => null,
        'subject_type' => null,
        'subject_id' => null,
        'metadata' => null,
        ...$attributes,
    ]);
}

describe('audit log screen', function () {
    it('shows the log to an admin, newest first', function () {
        $old = auditRow(['created_at' => now()->subDays(2)]);
        $new = auditRow(['created_at' => now()->subHour()]);
        $middle = auditRow(['created_at' => now()->subDay()]);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/audit-logs/index')
                ->where('logs.total', 3)
                ->where('logs.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$new->id, $middle->id, $old->id]));
    });

    it('shows who did what to which record, with the safe details', function () {
        $doctor = userWithRole(Role::Doctor);
        auditRow([
            'actor_id' => $doctor->id,
            'action' => 'appointment.cancelled',
            'subject_type' => Appointment::class,
            'subject_id' => 12,
            'metadata' => ['status_from' => 'booked', 'status_to' => 'cancelled', 'by_staff' => true],
        ]);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.audit-logs.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.data.0.actorName', $doctor->name)
                ->where('logs.data.0.actorRoleLabel', Role::Doctor->label())
                ->where('logs.data.0.action', 'appointment.cancelled')
                ->where('logs.data.0.subject', 'Appointment #12')
                // MySQL's JSON column stores keys in its own order, so compare the lines without their order.
                ->where('logs.data.0.details', fn ($lines) => collect($lines)->sort()->values()->all() === ['by staff: yes', 'status from: booked', 'status to: cancelled']));
    });

    it('shows an entry with no actor as such', function () {
        auditRow(['action' => 'appointment.booked']);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.audit-logs.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.data.0.actorName', null)
                ->where('logs.data.0.subject', null)
                ->where('logs.data.0.details', []));
    });

    it('is paginated, and keeps the filters in the page links', function () {
        AuditLog::factory()->count(51)->create(['actor_id' => null, 'subject_type' => null, 'subject_id' => null, 'action' => 'intake.viewed']);
        auditRow(['action' => 'user.created']);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.audit-logs.index', ['action' => 'intake.viewed']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs.data', 50)
                ->where('logs.total', 51)
                ->where('logs.next_page_url', fn (string $url) => str_contains($url, 'action=intake.viewed') && str_contains($url, 'page=2')));

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.audit-logs.index', ['action' => 'intake.viewed', 'page' => 2]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs.data', 1)
                ->where('logs.next_page_url', null));
    });

    it('keeps the hashed address and the whole model out of the page data', function () {
        auditRow(['action' => 'appointment.booked', 'ip_hash' => hash('sha256', '203.0.113.7')]);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.audit-logs.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.data.0', fn ($row) => array_keys(collect($row)->all()) === ['id', 'createdAt', 'actorName', 'actorRoleLabel', 'action', 'subject', 'details']));
    });

    it('shows no intake text, even for a row written by a real intake view', function () {
        $intake = Intake::factory()->create(['answers' => [
            'reason' => 'SECRET-REASON-TEXT',
            'symptoms' => 'SECRET-SYMPTOM-TEXT',
            'allergies' => 'None',
            'medicines' => 'None',
        ]]);
        app(AuditLogger::class)->record(userWithRole(Role::Doctor), 'intake.viewed', $intake, ['appointment_id' => $intake->appointment_id]);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.audit-logs.index'))
            ->assertOk()
            ->assertSee('intake.viewed')
            ->assertDontSee('SECRET-REASON-TEXT')
            ->assertDontSee('SECRET-SYMPTOM-TEXT');
    });

    it('does not write to the log when an admin reads it', function () {
        auditRow();

        $this->actingAs(userWithRole(Role::Admin))->get(route('admin.audit-logs.index'))->assertOk();

        expect(AuditLog::query()->count())->toBe(1);
    });
});

describe('audit log filters', function () {
    it('filters by actor', function () {
        $doctor = userWithRole(Role::Doctor);
        $staff = userWithRole(Role::Staff);
        $byDoctor = auditRow(['actor_id' => $doctor->id]);
        auditRow(['actor_id' => $staff->id]);
        auditRow();

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.audit-logs.index', ['actor' => $doctor->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.total', 1)
                ->where('logs.data.0.id', $byDoctor->id)
                ->where('filters.actor', (string) $doctor->id));
    });

    it('filters by action', function () {
        $viewed = auditRow(['action' => 'intake.viewed']);
        auditRow(['action' => 'intake.saved']);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.audit-logs.index', ['action' => 'intake.viewed']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.total', 1)
                ->where('logs.data.0.id', $viewed->id));
    });

    it('filters by the kind of subject', function () {
        $appointment = auditRow(['subject_type' => Appointment::class, 'subject_id' => 3]);
        auditRow(['subject_type' => Service::class, 'subject_id' => 3]);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.audit-logs.index', ['subject_type' => 'Appointment']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.total', 1)
                ->where('logs.data.0.id', $appointment->id));
    });

    it('filters by one record: the kind and the id together', function () {
        $wanted = auditRow(['subject_type' => Appointment::class, 'subject_id' => 3]);
        auditRow(['subject_type' => Appointment::class, 'subject_id' => 4]);
        auditRow(['subject_type' => Service::class, 'subject_id' => 3]);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.audit-logs.index', ['subject_type' => 'Appointment', 'subject_id' => 3]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.total', 1)
                ->where('logs.data.0.id', $wanted->id));
    });

    it('filters by a clinic day, so a late-evening UTC entry lands on the next Manila day', function () {
        config(['app.clinic_timezone' => 'Asia/Manila']);
        // 23:30 UTC on 4 Nov is 07:30 on 5 Nov in Manila.
        $lateUtc = auditRow(['created_at' => '2026-11-04 23:30:00']);
        $before = auditRow(['created_at' => '2026-11-04 15:59:59']);
        $after = auditRow(['created_at' => '2026-11-05 16:00:00']);

        $admin = userWithRole(Role::Admin);

        $this->actingAs($admin)
            ->get(route('admin.audit-logs.index', ['date' => '2026-11-05']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.total', 1)
                ->where('logs.data.0.id', $lateUtc->id));

        $this->actingAs($admin)
            ->get(route('admin.audit-logs.index', ['date' => '2026-11-04']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.total', 1)
                ->where('logs.data.0.id', $before->id));

        $this->actingAs($admin)
            ->get(route('admin.audit-logs.index', ['date' => '2026-11-06']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.total', 1)
                ->where('logs.data.0.id', $after->id));
    });

    it('combines filters with AND', function () {
        $doctor = userWithRole(Role::Doctor);
        $wanted = auditRow(['actor_id' => $doctor->id, 'action' => 'intake.viewed']);
        auditRow(['actor_id' => $doctor->id, 'action' => 'notes.viewed']);
        auditRow(['action' => 'intake.viewed']);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.audit-logs.index', ['actor' => $doctor->id, 'action' => 'intake.viewed']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.total', 1)
                ->where('logs.data.0.id', $wanted->id));
    });

    it('shows an empty list, not an error, for an actor or action that is not in the log', function () {
        auditRow(['action' => 'intake.viewed']);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.audit-logs.index', ['actor' => 999999, 'action' => 'made.up', 'subject_type' => 'Nothing']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('logs.total', 0));
    });

    it('offers only the actors, actions and subjects that are in the log', function () {
        $doctor = userWithRole(Role::Doctor);
        userWithRole(Role::Staff);
        auditRow(['actor_id' => $doctor->id, 'action' => 'notes.viewed', 'subject_type' => Appointment::class, 'subject_id' => 1]);
        auditRow(['action' => 'intake.viewed', 'subject_type' => Intake::class, 'subject_id' => 1]);
        auditRow(['action' => 'intake.viewed']);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.audit-logs.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.actors', [['id' => $doctor->id, 'name' => $doctor->name]])
                ->where('options.actions', ['intake.viewed', 'notes.viewed'])
                ->where('options.subjectTypes', ['Appointment', 'Intake']));
    });
});

describe('audit log access', function () {
    it('denies the log to everyone but admins', function (Role $role) {
        auditRow();

        $this->actingAs(userWithRole($role))->get(route('admin.audit-logs.index'))->assertForbidden();
    })->with('non_admins');

    it('sends a guest to the login page', function () {
        $this->get(route('admin.audit-logs.index'))->assertRedirect(route('login'));
    });

    it('has no route that changes a row', function (string $method) {
        $row = auditRow();

        $this->actingAs(userWithRole(Role::Admin))
            ->call($method, '/admin/audit-logs/'.$row->id)
            ->assertNotFound();

        $this->actingAs(userWithRole(Role::Admin))
            ->call($method, '/admin/audit-logs')
            ->assertStatus(405);

        expect(AuditLog::query()->count())->toBe(1);
    })->with(['POST', 'PUT', 'PATCH', 'DELETE']);
});

describe('audit log validation', function () {
    it('rejects a bad filter', function (array $query, string $invalidField) {
        auditRow();

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.audit-logs.index', $query))
            ->assertSessionHasErrors($invalidField);
    })->with([
        'actor must be a number' => [['actor' => 'abc'], 'actor'],
        'actor must be positive' => [['actor' => 0], 'actor'],
        'action must look like an action name' => [['action' => "intake.viewed' OR 1=1"], 'action'],
        'action must have a dot' => [['action' => 'viewed'], 'action'],
        'action is not too long' => [['action' => str_repeat('a', 70).'.viewed'], 'action'],
        'subject type must be a class name' => [['subject_type' => 'App\\Models\\Appointment'], 'subject_type'],
        'subject id needs a subject type' => [['subject_id' => 5], 'subject_type'],
        'subject id must be a number' => [['subject_type' => 'Appointment', 'subject_id' => 'x'], 'subject_id'],
        'subject id must be positive' => [['subject_type' => 'Appointment', 'subject_id' => 0], 'subject_id'],
        'date must be a date' => [['date' => 'yesterday'], 'date'],
        'date must be a real day' => [['date' => '2026-02-31'], 'date'],
    ]);

    it('opens with no filters at all', function () {
        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters', ['actor' => '', 'action' => '', 'subject_type' => '', 'subject_id' => '', 'date' => '']));
    });
});
