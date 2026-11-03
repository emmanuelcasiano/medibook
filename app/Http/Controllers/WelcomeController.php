<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Booking\ListAdvertisedDoctors;
use App\Http\Resources\Booking\BookableDoctorResource;
use Inertia\Inertia;
use Inertia\Response;

final class WelcomeController extends Controller
{
    /**
     * The landing page. It is public and read-only, so it has no Policy and no Form Request (it takes no input).
     * It names the doctors a patient can book, with the same public fields the booking pages send: no email and no
     * user id. Which doctors count is decided in the Action.
     */
    public function __invoke(ListAdvertisedDoctors $advertisedDoctors): Response
    {
        return Inertia::render('welcome', [
            'doctors' => BookableDoctorResource::collection($advertisedDoctors->handle())->resolve(),
        ]);
    }
}
