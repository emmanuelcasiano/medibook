<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * An admin with a fixed name and email, so a search in these tests can't match them by chance
 * (`userWithRole` makes a random name and email).
 */
function searchAdmin(): User
{
    $admin = userWithRole(Role::Admin);
    $admin->update(['name' => 'Boss Admin', 'email' => 'boss@clinic.test']);

    return $admin;
}

/**
 * The names on the page, in order.
 *
 * @return list<string>
 */
function listedUserNames(TestResponse $response): array
{
    return collect(Assert::fromTestResponse($response)->toArray()['props']['users']['data'])->pluck('name')->all();
}

describe('searching the users list', function () {
    it('finds users by a part of the name, on every page', function () {
        User::factory()->count(16)->sequence(fn ($sequence) => ['name' => 'Zeta Match '.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT)])->create();
        User::factory()->count(3)->sequence(fn ($sequence) => ['name' => "Alpha Other {$sequence->index}"])->create();
        $admin = searchAdmin();

        $first = $this->actingAs($admin)->get(route('admin.users.index', ['q' => 'zeta match']))->assertOk();
        $second = $this->actingAs($admin)->get(route('admin.users.index', ['q' => 'zeta match', 'page' => 2]))->assertOk();

        expect(listedUserNames($first))->toHaveCount(15)
            ->and(listedUserNames($first)[0])->toBe('Zeta Match 00')
            ->and(listedUserNames($second))->toBe(['Zeta Match 15']);

        $first->assertInertia(fn (Assert $page) => $page->where('users.total', 16));
    });

    it('finds a user by a part of the email', function () {
        User::factory()->create(['name' => 'Rare Person', 'email' => 'rare.finder@clinic.test']);
        User::factory()->create(['name' => 'Common Person', 'email' => 'common@clinic.test']);

        $response = $this->actingAs(searchAdmin())->get(route('admin.users.index', ['q' => 'rare.finder']));

        expect(listedUserNames($response))->toBe(['Rare Person']);
    });

    it('ignores letter case', function () {
        User::factory()->create(['name' => 'Maria Santos']);

        $response = $this->actingAs(searchAdmin())->get(route('admin.users.index', ['q' => 'MARIA']));

        expect(listedUserNames($response))->toBe(['Maria Santos']);
    });

    it('keeps only the chosen role', function (string $role, string $expected) {
        User::factory()->staff()->create(['name' => 'A Staff']);
        User::factory()->nurse()->create(['name' => 'B Nurse']);
        User::factory()->create(['name' => 'C Patient']);

        $response = $this->actingAs(searchAdmin())->get(route('admin.users.index', ['role' => $role]));

        expect(listedUserNames($response))->toBe([$expected]);
    })->with([
        'staff' => ['staff', 'A Staff'],
        'nurse' => ['nurse', 'B Nurse'],
        'patient' => ['patient', 'C Patient'],
    ]);

    it('keeps only active or only inactive accounts', function (string $status, array $expected) {
        User::factory()->create(['name' => 'Active One']);
        User::factory()->inactive()->create(['name' => 'Inactive One']);

        $response = $this->actingAs(searchAdmin())->get(route('admin.users.index', ['status' => $status]));

        expect(listedUserNames($response))->toBe($expected);
    })->with([
        'active' => ['active', ['Active One', 'Boss Admin']],
        'inactive' => ['inactive', ['Inactive One']],
    ]);

    it('combines the search with the role and status filters', function () {
        User::factory()->nurse()->create(['name' => 'Ana Nurse']);
        User::factory()->nurse()->inactive()->create(['name' => 'Ana Nurse Old']);
        User::factory()->staff()->create(['name' => 'Ana Staff']);
        User::factory()->nurse()->create(['name' => 'Ben Nurse']);

        $response = $this->actingAs(searchAdmin())
            ->get(route('admin.users.index', ['q' => 'ana', 'role' => 'nurse', 'status' => 'active']));

        expect(listedUserNames($response))->toBe(['Ana Nurse']);
    });

    it('treats % _ and \ as plain characters, not wildcards', function (string $term, string $expected) {
        User::factory()->create(['name' => '100% Sure', 'email' => 'sure@clinic.test']);
        User::factory()->create(['name' => 'Under_score', 'email' => 'under@clinic.test']);
        User::factory()->create(['name' => 'Back\\slash', 'email' => 'back@clinic.test']);
        User::factory()->create(['name' => 'Plain Person', 'email' => 'plain@clinic.test']);

        $response = $this->actingAs(searchAdmin())->get(route('admin.users.index', ['q' => $term]));

        expect(listedUserNames($response))->toBe([$expected]);
    })->with([
        'percent' => ['%', '100% Sure'],
        'underscore' => ['_', 'Under_score'],
        'backslash' => ['\\', 'Back\\slash'],
    ]);

    it('ignores spaces around the search, and treats a blank search as not set', function () {
        User::factory()->create(['name' => 'Maria Santos']);
        $admin = searchAdmin();

        $padded = $this->actingAs($admin)->get(route('admin.users.index', ['q' => '  maria  ']));
        $blank = $this->actingAs($admin)->get(route('admin.users.index', ['q' => '   ']));

        expect(listedUserNames($padded))->toBe(['Maria Santos'])
            ->and(listedUserNames($blank))->toBe(['Boss Admin', 'Maria Santos']);

        $blank->assertInertia(fn (Assert $page) => $page->where('filters.q', ''));
    });

    it('answers an empty page when nothing matches', function () {
        User::factory()->create(['name' => 'Someone Else']);

        $this->actingAs(searchAdmin())
            ->get(route('admin.users.index', ['q' => 'nobody-has-this-name']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/index')
                ->has('users.data', 0)
                ->where('users.total', 0)
                ->where('users.from', null));
    });

    it('keeps the filters in the Next and Previous links', function () {
        User::factory()->count(16)->nurse()->create();

        $this->actingAs(searchAdmin())
            ->get(route('admin.users.index', ['role' => 'nurse', 'status' => 'active', 'q' => 'a']))
            ->assertInertia(fn (Assert $page) => $page->where('users.next_page_url', fn (?string $url): bool => $url !== null
                && Str::contains($url, ['role=nurse', 'status=active', 'q=a', 'page=2'])));
    });

    it('sends back the filters it applied, and every role for the select', function () {
        $this->actingAs(searchAdmin())
            ->get(route('admin.users.index', ['q' => 'ana', 'role' => 'nurse', 'status' => 'inactive']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters', ['q' => 'ana', 'role' => 'nurse', 'status' => 'inactive'])
                ->where('roles', fn ($roles): bool => collect($roles)->pluck('value')->sort()->values()->all() === ['admin', 'doctor', 'nurse', 'patient', 'staff']));
    });

    it('sends empty filters and the whole list when none are set', function () {
        User::factory()->count(3)->create();

        $this->actingAs(searchAdmin())
            ->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters', ['q' => '', 'role' => '', 'status' => ''])
                ->where('users.total', 4));
    });

    it('treats empty filters from the form as not set', function () {
        User::factory()->count(3)->create();

        $this->actingAs(searchAdmin())
            ->get(route('admin.users.index', ['q' => '', 'role' => '', 'status' => '']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('users.total', 4));
    });

    it('accepts a search of exactly 100 characters', function () {
        $this->actingAs(searchAdmin())
            ->get(route('admin.users.index', ['q' => str_repeat('a', 100)]))
            ->assertOk()
            ->assertSessionHasNoErrors();
    });

    it('does not write the search to the audit log', function () {
        $this->actingAs(searchAdmin())->get(route('admin.users.index', ['q' => 'maria@example.test']))->assertOk();

        expect(AuditLog::query()->count())->toBe(0);
    });

    it('still hides private columns on a filtered list', function () {
        User::factory()->create(['name' => 'Private Person']);

        $this->actingAs(searchAdmin())
            ->get(route('admin.users.index', ['q' => 'private']))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('users.data.0.password')
                ->missing('users.data.0.birth_date'));
    });
});

describe('access to the filtered list', function () {
    it('denies it to everyone but admins', function (Role $role) {
        User::factory()->create(['name' => 'Zeta Match']);

        $this->actingAs(userWithRole($role))
            ->get(route('admin.users.index', ['q' => 'zeta', 'role' => 'patient', 'status' => 'active']))
            ->assertForbidden();
    })->with('non_admins');

    it('sends a guest to the login page', function () {
        $this->get(route('admin.users.index', ['q' => 'zeta']))->assertRedirect(route('login'));
    });
});

describe('validating the filters', function () {
    it('rejects a bad filter with an error, and shows no list', function (array $query, string $invalidField) {
        $this->actingAs(searchAdmin())
            ->get(route('admin.users.index', $query))
            ->assertRedirect()
            ->assertSessionHasErrors($invalidField);
    })->with([
        'a search over 100 characters' => [['q' => str_repeat('a', 101)], 'q'],
        'a search that is not text' => [['q' => ['a']], 'q'],
        'an unknown role' => [['role' => 'superuser'], 'role'],
        'an unknown status' => [['status' => 'banned'], 'status'],
    ]);

    it('lists everyone again once the bad filter is dropped', function () {
        User::factory()->count(2)->create();
        $admin = searchAdmin();

        $this->actingAs($admin)->get(route('admin.users.index', ['role' => 'superuser']))->assertSessionHasErrors('role');

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page) => $page->where('users.total', 3));
    });
});
