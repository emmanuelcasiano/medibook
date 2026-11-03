import { Form, Link } from '@inertiajs/react';
import { Check, PanelRightOpen, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import StaffAppointmentController from '@/actions/App/Http/Controllers/Staff/AppointmentController';
import ScheduleController from '@/actions/App/Http/Controllers/Staff/ScheduleController';
import AppointmentFlags from '@/components/schedule/appointment-flags';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatClinicDate, formatClinicTime } from '@/lib/clinic-time';
import {
    findPatients,
    highlightName,
    searchTerms,
    type PatientMatch,
} from '@/lib/patient-search';
import { cn } from '@/lib/utils';
import type { DaySchedule } from '@/types';

type Props = {
    schedule: DaySchedule;
    /** An admin may look at the schedule but not check anyone in. */
    viewOnly: boolean;
    /** The booking whose side panel is open, if any. */
    selectedUlid: string | null;
};

/**
 * Whether a `/` keystroke should jump to the search. Not while the person is typing in a field, and not with a dialog
 * open, where the key belongs to the dialog.
 */
function shouldFocusSearch(event: KeyboardEvent): boolean {
    if (
        event.key !== '/' ||
        event.ctrlKey ||
        event.metaKey ||
        event.altKey ||
        event.isComposing ||
        event.defaultPrevented
    ) {
        return false;
    }

    const target = event.target;

    if (
        target instanceof HTMLElement &&
        (target.isContentEditable ||
            target.closest('input, textarea, select') !== null)
    ) {
        return false;
    }

    return (
        document.querySelector('[role="dialog"], [role="alertdialog"]') === null
    );
}

/**
 * Find a patient on the day shown, from the bookings the page already holds. What is typed never leaves the browser:
 * nothing is sent, stored or logged. Each result has one button: Check in for staff on a booked patient whose intake is
 * filled in, Open (the side panel) for every other case. The server checks the Policy again on a check-in.
 */
export default function PatientSearch({
    schedule,
    viewOnly,
    selectedUlid,
}: Props) {
    const [query, setQuery] = useState('');
    const inputRef = useRef<HTMLInputElement>(null);
    const resultsRef = useRef<HTMLUListElement>(null);

    const terms = searchTerms(query);
    const matches = findPatients(schedule.doctors, terms);

    useEffect(() => {
        function onKeyDown(event: KeyboardEvent) {
            if (shouldFocusSearch(event)) {
                event.preventDefault();
                inputRef.current?.focus();
            }
        }

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    function onKeyDown(event: React.KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'Escape') {
            // Clear and stay in the field, ready for the next name.
            event.preventDefault();
            setQuery('');
        } else if (event.key === 'Enter') {
            // Each result's button carries `data-search-action`; the first one is next in line.
            event.preventDefault();
            resultsRef.current
                ?.querySelector<HTMLElement>('[data-search-action]')
                ?.focus();
        }
    }

    // After a check-in the row is gone from the list of "booked" results, so the field takes the focus for the next
    // patient.
    function checkedIn() {
        setQuery('');
        inputRef.current?.focus();
    }

    let status = '';

    if (terms.length > 0) {
        status =
            matches.length === 0
                ? `No patient matching "${query.trim()}" on ${formatClinicDate(schedule.date)}. This search covers the day shown.`
                : `${matches.length} ${matches.length === 1 ? 'patient' : 'patients'} found`;
    }

    return (
        // One card holds the field, the count and the results, so they read as a single block.
        <div
            role="search"
            className="overflow-hidden rounded-xl border bg-card shadow-xs"
        >
            <div className="space-y-2 p-4">
                <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                    <Label htmlFor="patient-search">Find a patient</Label>
                    <p
                        id="patient-search-hint"
                        className="text-xs text-muted-foreground"
                    >
                        Searches the day shown. Press / to jump here, Esc to
                        clear.
                    </p>
                </div>
                <div className="relative">
                    <Search
                        aria-hidden
                        className="pointer-events-none absolute inset-y-0 left-3 my-auto size-4 text-muted-foreground"
                    />
                    {/* `ps-9`, not `pl-9`: the Input's own `px-3` wins over `pl-9` and puts the text under the icon. */}
                    <Input
                        id="patient-search"
                        ref={inputRef}
                        type="text"
                        inputMode="search"
                        enterKeyHint="search"
                        autoComplete="off"
                        spellCheck={false}
                        placeholder="Type a name, for example ana ram"
                        aria-describedby="patient-search-hint"
                        aria-keyshortcuts="/"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        onKeyDown={onKeyDown}
                        className="ps-9"
                    />
                </div>
            </div>

            {/* Read out as the list changes. Visible too, because the empty message tells staff which day was searched. */}
            <p
                role="status"
                aria-live="polite"
                className={cn(
                    'border-t bg-muted/50 px-4 py-2 text-sm text-muted-foreground',
                    status === '' && 'sr-only',
                )}
            >
                {status}
            </p>

            {matches.length > 0 && (
                <ul
                    ref={resultsRef}
                    aria-label="Patients found"
                    className="max-h-138 divide-y overflow-y-auto border-t"
                >
                    {matches.map((match) => (
                        <PatientResult
                            key={match.appointment.ulid}
                            match={match}
                            terms={terms}
                            date={schedule.date}
                            viewOnly={viewOnly}
                            selected={match.appointment.ulid === selectedUlid}
                            onCheckedIn={checkedIn}
                        />
                    ))}
                </ul>
            )}
        </div>
    );
}

type PatientResultProps = {
    match: PatientMatch;
    terms: string[];
    /** The clinic day on the page ('Y-m-d'), so the Open link stays on it. */
    date: string;
    viewOnly: boolean;
    selected: boolean;
    onCheckedIn: () => void;
};

function PatientResult({
    match,
    terms,
    date,
    viewOnly,
    selected,
    onCheckedIn,
}: PatientResultProps) {
    const { appointment, doctor } = match;
    const time = formatClinicTime(appointment.startsAt);
    // Hiding the button for an admin is only a hint: the Policy and the Action refuse them again on the server.
    const canCheckIn =
        !viewOnly &&
        appointment.status === 'booked' &&
        !appointment.intakeMissing;

    return (
        <li
            data-test="patient-result"
            className={cn(
                'flex flex-col gap-2 px-4 py-3 md:flex-row md:items-center md:gap-4',
                selected && 'bg-muted',
            )}
        >
            <span className="font-mono text-sm tabular-nums md:w-20 md:shrink-0">
                {time}
            </span>

            <div className="min-w-0 flex-1">
                <p className="flex items-center gap-1.5">
                    <span className="min-w-0 truncate">
                        {highlightName(appointment.patientName, terms).map(
                            (segment, index) =>
                                segment.match ? (
                                    <strong key={index} className="font-bold">
                                        {segment.text}
                                    </strong>
                                ) : (
                                    <span key={index}>{segment.text}</span>
                                ),
                        )}
                    </span>
                    <AppointmentFlags
                        appointment={appointment}
                        className="shrink-0"
                    />
                </p>
                <p className="truncate text-xs text-muted-foreground">
                    <span className="font-bold text-teal-ink">
                        {doctor.name}
                    </span>{' '}
                    · {appointment.serviceName}
                </p>
            </div>

            {/* A fixed width, so the badges and buttons of the rows line up in columns. */}
            <div className="md:w-28 md:shrink-0">
                <StatusBadge
                    status={appointment.status}
                    label={appointment.statusLabel}
                />
            </div>

            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 md:w-52 md:shrink-0 md:flex-nowrap md:justify-end">
                {appointment.intakeMissing && (
                    <span className="text-xs font-medium text-amber-700 dark:text-amber-400">
                        No intake yet
                    </span>
                )}

                {canCheckIn ? (
                    <Form
                        {...StaffAppointmentController.checkIn.form(
                            appointment.ulid,
                        )}
                        options={{ preserveScroll: true }}
                        onSuccess={onCheckedIn}
                        className="w-full md:w-auto"
                    >
                        {({ errors, processing }) => (
                            <div className="space-y-1">
                                <Button
                                    type="submit"
                                    className="w-full md:w-auto"
                                    disabled={processing}
                                    aria-label={`Check in ${appointment.patientName} at ${time}`}
                                    data-test="search-check-in-button"
                                    data-search-action=""
                                >
                                    {processing ? (
                                        <Spinner />
                                    ) : (
                                        <Check aria-hidden />
                                    )}
                                    Check in
                                </Button>
                                <div role="alert">
                                    {errors.appointment && (
                                        <p className="text-xs text-destructive">
                                            {errors.appointment}
                                        </p>
                                    )}
                                </div>
                            </div>
                        )}
                    </Form>
                ) : (
                    <Button
                        asChild
                        variant="outline"
                        className="w-full border-teal-line/50 md:w-auto"
                    >
                        <Link
                            href={ScheduleController.index({
                                query: { date, appointment: appointment.ulid },
                            })}
                            preserveScroll
                            preserveState
                            aria-label={`Open ${appointment.patientName} at ${time}`}
                            // Closing the panel then gives the focus back to this button, as it does for a block.
                            data-appointment={appointment.ulid}
                            data-test="search-open-button"
                            data-search-action=""
                        >
                            <PanelRightOpen aria-hidden />
                            Open
                        </Link>
                    </Button>
                )}
            </div>
        </li>
    );
}
