<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\CreateStaffUser;
use App\Actions\Admin\DeactivateUser;
use App\Actions\Admin\ListUsers;
use App\Actions\Admin\ReactivateUser;
use App\Actions\Admin\UpdateUser;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexUserRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class UserController extends Controller
{
    public function index(IndexUserRequest $request, ListUsers $list): Response
    {
        Gate::authorize('viewAny', User::class);

        $users = $list->handle($request->search(), $request->role(), $request->active())
            // Keep the filters in the "next" and "previous" links.
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'roleLabel' => $user->role->label(),
                'isActive' => $user->is_active,
                'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
                'can' => [
                    'update' => Gate::allows('update', $user),
                    'deactivate' => $user->is_active && Gate::allows('deactivate', $user),
                    'reactivate' => Gate::allows('reactivate', $user),
                ],
            ]);

        return Inertia::render('admin/users/index', [
            'users' => $users,
            'filters' => $request->filters(),
            'roles' => collect(Role::cases())
                ->map(fn (Role $role): array => ['value' => $role->value, 'label' => $role->label()])
                ->values(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('admin/users/create', [
            'roles' => collect(Role::cases())
                ->reject(fn (Role $role): bool => $role === Role::Patient)
                ->map(fn (Role $role): array => ['value' => $role->value, 'label' => $role->label()])
                ->values(),
        ]);
    }

    public function store(StoreUserRequest $request, #[CurrentUser] User $actor, CreateStaffUser $create): RedirectResponse
    {
        Gate::authorize('create', User::class);

        $data = $request->validated();

        $user = $create->handle(
            $actor,
            $data['name'],
            $data['email'],
            $data['password'],
            Role::from($data['role']),
            $data['specialty'] ?? null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name can now log in as :role.', [
            'name' => $user->name,
            'role' => strtolower($user->role->label()),
        ])]);

        return to_route('admin.users.index');
    }

    public function edit(User $user): Response
    {
        Gate::authorize('update', $user);

        return Inertia::render('admin/users/edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roleLabel' => $user->role->label(),
            ],
        ]);
    }

    public function update(UpdateUserRequest $request, #[CurrentUser] User $actor, User $user, UpdateUser $update): RedirectResponse
    {
        Gate::authorize('update', $user);

        $data = $request->validated();

        $update->handle($actor, $user, $data['name'], $data['email']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name has been updated.', ['name' => $user->name])]);

        return to_route('admin.users.index');
    }

    public function deactivate(#[CurrentUser] User $actor, User $user, DeactivateUser $deactivate): RedirectResponse
    {
        Gate::authorize('deactivate', $user);

        $deactivate->handle($actor, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name has been deactivated.', ['name' => $user->name])]);

        return to_route('admin.users.index');
    }

    public function reactivate(#[CurrentUser] User $actor, User $user, ReactivateUser $reactivate): RedirectResponse
    {
        Gate::authorize('reactivate', $user);

        $reactivate->handle($actor, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name has been reactivated.', ['name' => $user->name])]);

        return to_route('admin.users.index');
    }
}
