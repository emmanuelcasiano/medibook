<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\CreateStaffUser;
use App\Actions\Admin\DeactivateUser;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class UserController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', User::class);

        $users = User::query()
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'roleLabel' => $user->role->label(),
                'isActive' => $user->is_active,
                'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
                'can' => ['deactivate' => $user->is_active && Gate::allows('deactivate', $user)],
            ]);

        return Inertia::render('admin/users/index', ['users' => $users]);
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

    public function store(StoreUserRequest $request, CreateStaffUser $create): RedirectResponse
    {
        Gate::authorize('create', User::class);

        $data = $request->validated();

        $user = $create->handle(
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

    public function deactivate(User $user, DeactivateUser $deactivate): RedirectResponse
    {
        Gate::authorize('deactivate', $user);

        $deactivate->handle($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name has been deactivated.', ['name' => $user->name])]);

        return to_route('admin.users.index');
    }
}
