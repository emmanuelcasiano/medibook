<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Actions\Triage\ListVitalsQueue;
use App\Http\Controllers\Controller;
use App\Http\Resources\Nurse\VitalsQueueResource;
use App\Models\VitalsRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class HomeController extends Controller
{
    /**
     * The nurse's triage queue: today's checked-in patients, with those who still need vitals apart from those who
     * have them. The `role:nurse` group closes the route to other roles and the Policy says the same again. The list
     * is not paginated: one clinic day is a bounded list. The queue holds names and times, never a reading.
     */
    public function __invoke(ListVitalsQueue $queue): Response
    {
        Gate::authorize('viewQueue', VitalsRecord::class);

        // One "now" for the whole response, so the day of the list can't change in the middle of it.
        $today = CarbonImmutable::now((string) config('app.clinic_timezone'));

        return Inertia::render('nurse/home', [
            'queue' => (new VitalsQueueResource($queue->handle($today)))->resolve(),
        ]);
    }
}
