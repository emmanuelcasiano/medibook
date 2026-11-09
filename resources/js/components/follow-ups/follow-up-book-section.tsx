import { Form, router } from '@inertiajs/react';
import { CalendarPlus } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import FollowUpController from '@/actions/App/Http/Controllers/Staff/FollowUpController';
import AlertError from '@/components/alert-error';
import SlotGrid from '@/components/booking/slot-grid';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { formatClinicDate, formatClinicDateTime } from '@/lib/clinic-time';
import type { FollowUpBookingOptions, StaffFollowUpPanel } from '@/types';

type Props = {
    followUp: StaffFollowUpPanel;
    /** `undefined` until the server has sent it, `null` when the user may not book this follow-up. */
    book: FollowUpBookingOptions | null | undefined;
};

/**
 * The "Book for patient" part of the staff side panel: pick one of the days the doctor asked for, then one of that
 * day's free times. The days and times come from the server (a partial reload of the `book` prop), so every rule of the
 * slot finder applies and the browser works out nothing. The server checks the choice again on submit (the time may
 * have been taken since). The patient, the visit type and the doctor are the follow-up's own, so none of them is a field.
 */
export default function FollowUpBookSection({ followUp, book }: Props) {
    const panelId = useId();
    const dayFieldId = useId();
    const [open, setOpen] = useState(false);
    const [startsAt, setStartsAt] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    // The day the list on the page was asked for. A reload happens for a new day, or when the page has no list yet
    // (opening the section, or coming back from a refused booking, whose time may be gone).
    const [date, setDate] = useState<string | null>(null);
    const asked = useRef<string | null | undefined>(undefined);

    useEffect(() => {
        if (!open) {
            // Closed: the next opening asks again, since the free times may have changed.
            asked.current = undefined;

            return;
        }

        if (book !== undefined && asked.current === date) {
            return;
        }

        asked.current = date;

        router.reload({
            only: ['book'],
            data: date === null ? {} : { book_date: date },
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    }, [open, date, book]);

    const days = book?.days ?? [];
    const slots = book?.slots ?? [];
    const shownDate = date ?? book?.selectedDate ?? '';
    const status = loading
        ? 'Looking for free times.'
        : book?.unavailable
          ? book.unavailable
          : book && days.length === 0
            ? 'There are no days to book in this range yet.'
            : book && slots.length === 0
              ? 'No free times on this day. Pick another day.'
              : book
                ? `${slots.length} free ${slots.length === 1 ? 'time' : 'times'}.`
                : '';

    return (
        <div className="space-y-3">
            <Button
                type="button"
                className="w-full"
                aria-expanded={open}
                aria-controls={panelId}
                onClick={() => setOpen((current) => !current)}
                data-test="book-for-patient-button"
            >
                <CalendarPlus aria-hidden />
                {open
                    ? 'Close the booking form'
                    : `Book for ${firstName(followUp.patientName)}`}
            </Button>

            <div id={panelId} hidden={!open}>
                {open && (
                    <Form
                        {...FollowUpController.book.form(followUp.ulid)}
                        options={{ preserveScroll: true }}
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor={dayFieldId}>Day</Label>
                                    <NativeSelect
                                        id={dayFieldId}
                                        value={shownDate}
                                        disabled={days.length === 0}
                                        onChange={(event) => {
                                            setDate(event.target.value);
                                            setStartsAt(null);
                                        }}
                                    >
                                        {days.length === 0 && (
                                            <option value="">No days</option>
                                        )}
                                        {days.map((day) => (
                                            <option
                                                key={day.date}
                                                value={day.date}
                                                disabled={day.slotsCount === 0}
                                            >
                                                {formatClinicDate(day.date)} ·{' '}
                                                {day.slotsCount === 0
                                                    ? 'no free times'
                                                    : `${day.slotsCount} free`}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </div>

                                <div className="space-y-2">
                                    <p
                                        aria-live="polite"
                                        className="flex items-center gap-2 text-sm text-muted-foreground"
                                    >
                                        {loading && <Spinner />}
                                        {status}
                                    </p>

                                    {!loading && slots.length > 0 && (
                                        <div className="max-h-60 overflow-y-auto pr-1">
                                            <SlotGrid
                                                slots={slots}
                                                selected={startsAt}
                                                onSelect={setStartsAt}
                                                className="sm:grid-cols-3"
                                            />
                                        </div>
                                    )}
                                </div>

                                <input
                                    type="hidden"
                                    name="starts_at"
                                    value={startsAt ?? ''}
                                />

                                <div role="alert">
                                    {(errors.starts_at || errors.follow_up) && (
                                        <AlertError
                                            errors={[
                                                errors.starts_at,
                                                errors.follow_up,
                                            ].filter(
                                                (message): message is string =>
                                                    message !== undefined,
                                            )}
                                            title="The visit was not booked."
                                        />
                                    )}
                                </div>

                                <Button
                                    type="submit"
                                    className="w-full"
                                    disabled={processing || startsAt === null}
                                    data-test="confirm-book-for-patient-button"
                                >
                                    {processing && <Spinner />}
                                    {startsAt === null
                                        ? 'Pick a time to book'
                                        : `Book ${formatClinicDateTime(startsAt)}`}
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </div>
    );
}

function firstName(name: string): string {
    return name.split(' ')[0] ?? name;
}
