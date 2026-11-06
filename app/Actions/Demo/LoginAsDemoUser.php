<?php

declare(strict_types=1);

namespace App\Actions\Demo;

use App\Enums\Role;
use App\Models\User;
use App\Support\DemoMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signs a visitor in as the seeded demo user of a role (PROJECT.md F12). It skips the password form and the 2FA
 * challenge on purpose, so it refuses unless demo mode is on, even if a caller forgets the route middleware.
 */
final class LoginAsDemoUser
{
    public function handle(Request $request, Role $role): User
    {
        abort_unless(DemoMode::enabled(), 404);

        // Looked up by the fixed demo email for the role. A missing user (not seeded, or wiped) is a 404, so a real
        // account with the same role can never be reached from here.
        $user = User::query()->where('email', DemoMode::emailFor($role))->where('role', $role)->firstOrFail();

        Auth::login($user);

        // A new session id after login stops session fixation, like Fortify's own login does.
        $request->session()->regenerate();

        return $user;
    }
}
