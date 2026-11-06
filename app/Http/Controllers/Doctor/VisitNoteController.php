<?php

declare(strict_types=1);

namespace App\Http\Controllers\Doctor;

use App\Actions\Doctor\SaveVisitNote;
use App\Http\Controllers\Controller;
use App\Http\Requests\Doctor\SaveVisitNoteRequest;
use App\Models\Appointment;
use App\Models\User;
use App\Models\VisitNote;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class VisitNoteController extends Controller
{
    /**
     * Saves the doctor's note for the appointment: creates it the first time and edits it after. The doctor comes
     * from the server. The only input is the text. The Policy runs here and again inside the Action.
     */
    public function update(SaveVisitNoteRequest $request, #[CurrentUser] User $user, Appointment $appointment, SaveVisitNote $saveNote): RedirectResponse
    {
        Gate::authorize('create', [VisitNote::class, $appointment]);

        $saveNote->handle($user, $appointment, $request->validated('body'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The visit note is saved.')]);

        return to_route('doctor.appointments.show', $appointment);
    }
}
