import { Form } from '@inertiajs/react';
import { NotebookPen, Save } from 'lucide-react';
import VisitNoteController from '@/actions/App/Http/Controllers/Doctor/VisitNoteController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatClinicDateTime } from '@/lib/clinic-time';
import type { VisitNote } from '@/types';

type Props = {
    /** The appointment the note belongs to. The form puts the note there. */
    appointmentUlid: string;
    /** null until the doctor saves the first note. */
    note: VisitNote | null;
    /** False until the patient is checked in, and for a cancellation or a no-show. */
    canSave: boolean;
};

/** The doctor's private note for this visit. Nobody else can read or write it. */
export default function VisitNoteCard({
    appointmentUlid,
    note,
    canSave,
}: Props) {
    return (
        <section
            aria-labelledby="visit-note-heading"
            className="space-y-4 rounded-xl border bg-card p-5"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2
                    id="visit-note-heading"
                    className="flex items-center gap-2 text-lg font-semibold"
                >
                    <NotebookPen aria-hidden className="size-4" />
                    Visit notes
                </h2>
                <span className="text-sm text-muted-foreground">
                    Private. Only you can read and write this note.
                </span>
            </div>

            {canSave ? (
                <NoteForm appointmentUlid={appointmentUlid} note={note} />
            ) : (
                <ReadOnlyNote note={note} />
            )}
        </section>
    );
}

function NoteForm({
    appointmentUlid,
    note,
}: {
    appointmentUlid: string;
    note: VisitNote | null;
}) {
    return (
        // No `key` on the form: a new key after each save would rebuild it and drop the keyboard focus from the Save
        // button. The textarea keeps what the doctor typed, which is the text the server saved.
        <Form
            {...VisitNoteController.update.form(appointmentUlid)}
            options={{ preserveScroll: true }}
            className="space-y-3"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="visit-note-body">Note</Label>
                        <Textarea
                            id="visit-note-body"
                            name="body"
                            rows={8}
                            required
                            maxLength={10000}
                            defaultValue={note?.body ?? ''}
                            aria-invalid={errors.body ? true : undefined}
                            aria-describedby={
                                errors.body
                                    ? 'visit-note-body-error'
                                    : undefined
                            }
                            className="min-h-40"
                        />
                        <div id="visit-note-body-error" role="alert">
                            <InputError message={errors.body} />
                        </div>
                    </div>

                    {/* A refusal about the appointment itself (for example, it was cancelled while the page was open). */}
                    <div role="alert">
                        <InputError message={errors.appointment} />
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Button
                            type="submit"
                            disabled={processing}
                            data-test="save-visit-note-button"
                        >
                            {processing ? <Spinner /> : <Save aria-hidden />}
                            Save note
                        </Button>
                        {note !== null && (
                            <span className="text-sm text-muted-foreground">
                                Saved {formatClinicDateTime(note.updatedAt)}
                            </span>
                        )}
                    </div>
                </>
            )}
        </Form>
    );
}

/** Shown when the appointment does not take a note. A note saved earlier is still there to read. */
function ReadOnlyNote({ note }: { note: VisitNote | null }) {
    if (note === null) {
        return (
            <p
                className="rounded-lg bg-muted/40 p-4 text-sm text-muted-foreground"
                data-test="visit-note-unavailable"
            >
                You can write a note once the patient is checked in. A cancelled
                booking or a no-show has no visit to write about.
            </p>
        );
    }

    return (
        <div className="space-y-2">
            {/* Plain text only: React escapes it, and `whitespace-pre-wrap` keeps the doctor's line breaks. */}
            <p className="text-sm whitespace-pre-wrap">{note.body}</p>
            <p className="text-sm text-muted-foreground">
                Saved {formatClinicDateTime(note.updatedAt)}. A note can be
                edited only on a checked-in or completed appointment.
            </p>
        </div>
    );
}
