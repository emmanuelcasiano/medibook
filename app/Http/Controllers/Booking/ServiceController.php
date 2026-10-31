<?php

declare(strict_types=1);

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Http\Resources\Booking\BookableServiceResource;
use App\Models\Service;
use Inertia\Inertia;
use Inertia\Response;

final class ServiceController extends Controller
{
    /**
     * Step 1 of booking: pick a service. This page is public and read-only, so it has no Policy (see
     * ServicePolicy). Only the public catalogue fields are sent, and only services that can be booked.
     * A clinic has a handful of services, and a patient picks one from the whole list, so it is not paginated.
     */
    public function index(): Response
    {
        $services = Service::query()
            ->bookable()
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return Inertia::render('booking/services', [
            'services' => BookableServiceResource::collection($services)->resolve(),
        ]);
    }
}
