<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Booking\ListMyFollowUps;
use App\Models\FollowUp;
use App\Support\DemoMode;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Only a flag: it shows the fake-data banner and the one-click logins. The server still checks it on the route.
            'demo' => DemoMode::enabled(),
            'auth' => [
                'user' => $request->user(),
            ],
            // How many follow-ups the signed-in patient can book. A number only, for the dot in the sidebar. The closure
            // runs when a page is rendered, not on a redirect, and other roles pay for no query.
            'openFollowUps' => fn (): int => $request->user()?->isPatient()
                ? app(ListMyFollowUps::class)->query($request->user())->count()
                : 0,
            // How many follow-ups need a phone call, for the badge on staff's Follow-ups link. A number only. Staff and
            // admins only (the roles that can open the list), and no query for anyone else.
            'followUpsToCall' => fn (): int => $request->user()?->isStaff() || $request->user()?->isAdmin()
                ? FollowUp::query()->needingCall(CarbonImmutable::now())->count()
                : 0,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
