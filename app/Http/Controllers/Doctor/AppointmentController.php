<?php

declare(strict_types=1);

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class AppointmentController extends Controller
{
    /**
     * Open one appointment. For now this only proves the door: the Policy lets a doctor open their own patients'
     * appointments and refuses every other doctor (403). The page content (intake, summary, age) is step 10.2, so
     * nothing about the patient is sent yet.
     */
    public function show(Appointment $appointment): Response
    {
        Gate::authorize('view', $appointment);

        return Inertia::render('role-home', [
            'title' => 'Appointment',
            'note' => 'The appointment detail is built in step 10.2.',
        ]);
    }
}
