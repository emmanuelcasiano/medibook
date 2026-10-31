<?php

declare(strict_types=1);

namespace App\Http\Controllers\Patient;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class IntakeController extends Controller
{
    /**
     * Placeholder so the "You're booked" page has a real address to link to. The form itself is Day 8 (F5).
     * It already has the Policy check, so the address is closed to other patients from the start.
     */
    public function edit(Appointment $appointment): Response
    {
        Gate::authorize('view', $appointment);

        return Inertia::render('role-home', [
            'title' => 'Intake form',
            'note' => 'The intake form is built on Day 8.',
        ]);
    }
}
