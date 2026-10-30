<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role gate for a whole route group (`role:admin`). It is a second lock on top of the Policies:
 * a route added to the /admin group later is closed to other roles even if its author forgets a Policy.
 */
final class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_map(fn (string $role): Role => Role::from($role), $roles);

        abort_unless($request->user() !== null && in_array($request->user()->role, $allowed, true), 403);

        return $next($request);
    }
}
