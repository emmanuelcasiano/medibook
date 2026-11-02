import { Form } from '@inertiajs/react';
import { NotebookPen, Save } from 'lucide-react';
import VisitNoteController from '@/actions/App/Http/Controllers/Doctor/VisitNoteController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatClinicDateTime } from '@/lib/clinic-time';
import type { VisitNote } from '@/types';

type Props = {
    /** The appointment the note belongs to. The form puts the note there. */
    appointmentUlid: string;
    /** null until the doctor saves the first note. */
    note: VisitNote | null;
};

/** The doctor's private note for this visit. Nobody else can read or write it. */
export default function VisitNoteCard({ appointmentUlid, note }: Props) {
    return (
        <section
            aria-labelledby="visit-note-heading"
            className="space-y-4 rounded-xl border p-5"
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

            {/* `key` gives the form a fresh start after a save, so the text shown is the text the server kept. */}
            <Form
                key={note?.updatedAt ?? 'new'}
                {...VisitNoteController.update.form(appointmentUlid)}
                options={{ preserveScroll: true }}
                className="space-y-3"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="visit-note-body">Note</Label>
                            <textarea
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
                                className="min-h-40 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            />
                            <div id="visit-note-body-error" role="alert">
                                <InputError message={errors.body} />
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <Button
                                type="submit"
                                disabled={processing}
                                data-test="save-visit-note-button"
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <Save aria-hidden />
                                )}
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
        </section>
    );
}
