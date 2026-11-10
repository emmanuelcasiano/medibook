<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Actions\Triage\RecordVitals;
use App\Http\Controllers\Controller;
use App\Http\Requests\Nurse\SaveVitalsRequest;
use App\Http\Resources\Nurse\VitalsFormResource;
use App\Models\Appointment;
use App\Models\User;
use App\Models\VitalsRecord;
use App\Support\AuditLogger;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class VitalsController extends Controller
{
    /**
     * The vitals form of one checked-in appointment. One address serves both: it is empty the first time and holds the
     * saved readings after that, so the nurse can correct a typo.
     *
     * The Policy refuses every other role and every appointment that is not checked in (403), before anything is
     * loaded. Opening a form that already holds readings writes `vitals.viewed`, after the check has passed and before
     * the readings are sent, so a refused request leaves no row and no reading goes out without one. The row holds
     * the record id and nothing else. An empty form has nothing to view, so it writes nothing.
     */
    public function edit(#[CurrentUser] User $nurse, Appointment $appointment, AuditLogger $audit): Response
    {
        // Column lists keep the page to what it shows. The intake, emergency flag and birth date are not loaded.
        $appointment->load(['patient:id,name', 'doctor:id,user_id', 'doctor.user:id,name', 'service:id,name', 'vitals']);

        $record = $appointment->vitals;

        Gate::authorize($record === null ? 'create' : 'update', [VitalsRecord::class, $appointment]);

        if ($record !== null) {
            $audit->record($nurse, 'vitals.viewed', $record);
        }

        return Inertia::render('nurse/vitals', [
            'appointment' => (new VitalsFormResource($appointment))->resolve(),
            'readings' => $record?->readings,
        ]);
    }

    /**
     * Saves the readings: creates the record the first time and corrects it after. The nurse comes from the server
     * and the appointment from the address. The only input is the six numbers. The Policy runs here, in the Form
     * Request and again inside the Action, on the row it locked.
     */
    public function update(SaveVitalsRequest $request, #[CurrentUser] User $nurse, Appointment $appointment, RecordVitals $recordVitals): RedirectResponse
    {
        Gate::authorize('create', [VitalsRecord::class, $appointment]);

        $recordVitals->handle($nurse, $appointment, $request->readings());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The vitals are saved.')]);

        return to_route('nurse.home');
    }
}
