<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Demo\LoginAsDemoUser;
use App\Enums\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class DemoLoginController extends Controller
{
    /**
     * One-click demo login (PROJECT.md F12). It takes no form input, so there is no Form Request: the only input is
     * the `{role}` in the URL, and Laravel's enum binding answers 404 for anything that is not one of the five roles.
     * There is no Policy either, because it is a guest-only route that opens a fixed account. The route middleware
     * closes it unless demo mode is on, and the Action checks that again.
     */
    public function __invoke(Request $request, Role $role, LoginAsDemoUser $loginAsDemoUser): RedirectResponse
    {
        $user = $loginAsDemoUser->handle($request, $role);

        return to_route($user->role->homeRoute());
    }
}
