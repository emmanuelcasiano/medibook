<?php

declare(strict_types=1);

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Http\Resources\Booking\BookableDoctorResource;
use App\Http\Resources\Booking\BookableServiceResource;
use App\Models\Service;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

final class DoctorController extends Controller
{
    /**
     * Step 2 of booking: pick a doctor, or "Any doctor". Public and read-only, so no Policy. A switched-off
     * service is a 404, the same answer as a service that does not exist, so the page doesn't reveal it.
     * Only the doctors of one service are listed, a clinic has a few of them, and the patient chooses from the
     * whole list, so it is not paginated.
     */
    public function index(Service $service): Response
    {
        abort_unless($service->is_active, 404);

        $doctors = $service->doctors()
            ->bookable()
            ->with('user')
            // The name lives on the user, so sort with a subquery instead of loading every doctor to sort in PHP.
            ->orderBy(User::query()->select('name')->whereColumn('users.id', 'doctors.user_id'))
            ->orderBy('doctors.id')
            ->get();

        return Inertia::render('booking/doctors', [
            'service' => (new BookableServiceResource($service))->resolve(),
            'doctors' => BookableDoctorResource::collection($doctors)->resolve(),
        ]);
    }
}
