<?php

declare(strict_types=1);

namespace App\Http\Requests\Doctor;

use App\Models\Appointment;
use App\Models\VisitNote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The visit-notes form on the doctor's appointment page. The appointment comes from the URL and the doctor comes from
 * the server. The only field is the note text.
 */
final class SaveVisitNoteRequest extends FormRequest
{
    /**
     * Checked here as well as in the controller, so a user who may not write the note gets a 403 and not a validation
     * message that confirms the route exists and what it wants.
     */
    public function authorize(): bool
    {
        $appointment = $this->route('appointment');

        return $appointment instanceof Appointment && Gate::allows('create', [VisitNote::class, $appointment]);
    }

    /**
     * The cap keeps one request from storing a huge text. 10,000 characters is several pages of notes for one visit.
     * The column is `longText`, so the limit is ours and not the database's. An empty text is refused, because
     * Laravel turns an empty string into null before this runs.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:10000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'body.required' => 'Write a note before you save.',
            'body.max' => 'The note can be up to :max characters.',
        ];
    }
}
