import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import ScheduleController from '@/actions/App/Http/Controllers/Staff/ScheduleController';
import PageHeader from '@/components/page-header';
import AppointmentPanel from '@/components/schedule/appointment-panel';
import ArrivalsList from '@/components/schedule/arrivals-list';
import DoctorFilter, {
    chosenDoctors,
    isOffToday,
} from '@/components/schedule/doctor-filter';
import PatientSearch from '@/components/schedule/patient-search';
import ScheduleDoctorList from '@/components/schedule/schedule-doctor-list';
import ScheduleGrid from '@/components/schedule/schedule-grid';
import {
    statusLabels,
    statusStyles,
} from '@/components/schedule/status-styles';
import ViewToggle from '@/components/schedule/view-toggle';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent } from '@/components/ui/sheet';
import { useMediaQuery } from '@/hooks/use-media-query';
import { useScheduleView } from '@/hooks/use-schedule-view';
import { formatClinicDate, isCompleteDate } from '@/lib/clinic-time';
import { cn } from '@/lib/utils';
import type { DaySchedule, MoveOptions, SelectedAppointment } from '@/types';

type Props = {
    schedule: DaySchedule;
    /** The booking whose side panel is open, or null. */
    selected: SelectedAppointment | null;
    /**
     * The Move picker's doctors and times. Not part of the first load: the panel's Move section asks for it, so it is
     * `undefined` until then, and `null` when the user may not move this booking.
     */
    move?: MoveOptions | null;
};

const legendStatuses = Object.keys(statusLabels) as Array<
    keyof typeof statusLabels
>;

/** Give the focus back to the block that opened the panel, once the panel is closed. */
function useReturnFocusOnClose(selectedUlid: string | null) {
    const previous = useRef<string | null>(null);

    useEffect(() => {
        if (previous.current !== null && selectedUlid === null) {
            // The grid and the phone list both hold the booking, and only one of them is on screen.
            const visible = Array.from(
                document.querySelectorAll<HTMLElement>(
                    `[data-appointment="${previous.current}"]`,
                ),
            ).find((element) => element.offsetParent !== null);

            visible?.focus();
        }

        previous.current = selectedUlid;
    }, [selectedUlid]);
}

export default function StaffSchedule({ schedule, selected, move }: Props) {
    const { auth } = usePage().props;
    const viewOnly = auth.user?.role === 'admin';

    useReturnFocusOnClose(selected?.ulid ?? null);

    // From `2xl` up the panel sits beside the grid. Below it, the panel slides over the grid, so it never squeezes it.
    const panelBesideGrid = useMediaQuery('(min-width: 96rem)');

    // Grid, arrivals list, or (phone) one list per doctor. Staff open the list, an admin the grid.
    const { view, choose, options } = useScheduleView(viewOnly);

    // Which doctors to show is UI state, not server data. The day links keep the page state (`preserveState`), so
    // the chips survive a change of day. A chosen doctor who is not in today's list is ignored, not an empty grid.
    const [chosenIds, setChosenIds] = useState<number[]>([]);
    const [showOff, setShowOff] = useState(false);

    const activeIds = chosenIds.filter((id) =>
        schedule.doctors.some((doctor) => doctor.id === id),
    );
    const chosen = chosenDoctors(schedule.doctors, activeIds);
    const offCount = schedule.doctors.filter(isOffToday).length;
    // The grid hides doctors who are off today (no hours, no bookings) unless asked, or unless chosen by name. The
    // phone list keeps them, last and closed.
    const gridDoctors =
        activeIds.length > 0 || showOff
            ? chosen
            : chosen.filter((doctor) => !isOffToday(doctor));

    function closePanel() {
        router.get(
            ScheduleController.index.url({ query: { date: schedule.date } }),
            {},
            { preserveScroll: true, preserveState: true },
        );
    }

    const appointments = schedule.doctors.flatMap(
        (doctor) => doctor.appointments,
    );
    const count = (status: (typeof appointments)[number]['status']) =>
        appointments.filter((appointment) => appointment.status === status)
            .length;

    // What the person has typed in the date field, until the page for it has loaded. null means "show the day of the
    // page". A date field reports a valid date after every digit of the year ("0002-11-02", "0020-11-02", ...), so
    // the page must not follow each change: it would reload on the first digit and the person could not finish typing.
    const [draft, setDraft] = useState<string | null>(null);

    function changeDate(value: string) {
        setDraft(value);

        if (!isCompleteDate(value)) {
            return;
        }

        router.get(
            ScheduleController.index.url({ query: { date: value } }),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setDraft(null),
            },
        );
    }

    return (
        <>
            <Head title="Day schedule" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <PageHeader
                        title={
                            <>
                                Day schedule
                                {viewOnly && (
                                    <Badge variant="outline">View only</Badge>
                                )}
                            </>
                        }
                        description={
                            <>
                                <p>
                                    {formatClinicDate(schedule.date)}. All times
                                    are Manila time.
                                </p>
                                {/* Read out when the day changes. */}
                                <p aria-live="polite">
                                    {appointments.length}{' '}
                                    {appointments.length === 1
                                        ? 'appointment'
                                        : 'appointments'}{' '}
                                    · {count('booked')} booked ·{' '}
                                    {count('checked_in')} checked in ·{' '}
                                    {count('completed')} completed ·{' '}
                                    {count('no_show')} no-show
                                </p>
                            </>
                        }
                    />

                    <nav
                        aria-label="Choose a day"
                        className="flex flex-wrap items-center gap-2"
                    >
                        <Button asChild variant="outline" size="icon">
                            <Link
                                href={ScheduleController.index({
                                    query: { date: schedule.previousDate },
                                })}
                                preserveScroll
                                preserveState
                                aria-label="Previous day"
                            >
                                <ChevronLeft aria-hidden />
                            </Link>
                        </Button>
                        <div>
                            <label htmlFor="schedule-date" className="sr-only">
                                Date
                            </label>
                            {/* No `key` on this field: rebuilding it after each load would take the focus away. */}
                            <Input
                                id="schedule-date"
                                type="date"
                                value={draft ?? schedule.date}
                                onChange={(event) =>
                                    changeDate(event.target.value)
                                }
                                // Leaving a half-typed date puts the field back to the day on the page.
                                onBlur={() => setDraft(null)}
                                className="w-auto font-medium"
                            />
                        </div>
                        <Button asChild variant="outline" size="icon">
                            <Link
                                href={ScheduleController.index({
                                    query: { date: schedule.nextDate },
                                })}
                                preserveScroll
                                preserveState
                                aria-label="Next day"
                            >
                                <ChevronRight aria-hidden />
                            </Link>
                        </Button>
                        <Button asChild variant="secondary">
                            <Link
                                href={ScheduleController.index()}
                                preserveState
                            >
                                Today
                            </Link>
                        </Button>
                        <ViewToggle
                            view={view}
                            options={options}
                            onChange={choose}
                        />
                    </nav>
                </div>

                <PatientSearch
                    schedule={schedule}
                    viewOnly={viewOnly}
                    selectedUlid={selected?.ulid ?? null}
                />

                <ul
                    aria-label="What the colors mean"
                    className={cn(
                        'hidden flex-wrap gap-x-4 gap-y-1 text-xs',
                        view === 'grid' && 'md:flex',
                    )}
                >
                    {legendStatuses.map((status) => (
                        <li key={status} className="flex items-center gap-1.5">
                            <span
                                aria-hidden
                                className={cn(
                                    'size-3 rounded-sm border',
                                    statusStyles[status],
                                )}
                            />
                            {statusLabels[status]}
                        </li>
                    ))}
                </ul>

                {schedule.doctors.length > 0 ? (
                    <div className="flex flex-col gap-6 2xl:flex-row 2xl:items-start">
                        <div className="min-w-0 flex-1 space-y-4">
                            <DoctorFilter
                                doctors={schedule.doctors}
                                chosenIds={activeIds}
                                onChange={setChosenIds}
                                // Only the grid hides doctors who are off today, so only the grid needs the toggle.
                                offCount={view === 'grid' ? offCount : 0}
                                showOff={showOff}
                                onShowOffChange={setShowOff}
                            />
                            {view === 'grid' && (
                                <ScheduleGrid
                                    schedule={schedule}
                                    doctors={gridDoctors}
                                    selectedUlid={selected?.ulid ?? null}
                                    panelOverlaps={
                                        selected !== null && !panelBesideGrid
                                    }
                                />
                            )}
                            {view === 'list' && (
                                <ArrivalsList
                                    schedule={schedule}
                                    doctors={chosen}
                                    viewOnly={viewOnly}
                                    selectedUlid={selected?.ulid ?? null}
                                />
                            )}
                            {view === 'doctor' && (
                                <ScheduleDoctorList
                                    doctors={chosen}
                                    date={schedule.date}
                                    selectedUlid={selected?.ulid ?? null}
                                />
                            )}
                        </div>
                        {selected && panelBesideGrid && (
                            <AppointmentPanel
                                key={selected.ulid}
                                schedule={schedule}
                                selected={selected}
                                move={move}
                                viewOnly={viewOnly}
                            />
                        )}
                    </div>
                ) : (
                    <p className="rounded-xl border bg-card p-8 text-center text-sm text-muted-foreground">
                        There are no doctors to show.
                    </p>
                )}
            </div>

            {/* Below `2xl` the panel is a slide-over: the sheet traps the focus, closes on Esc and a click outside. */}
            {selected && !panelBesideGrid && (
                <Sheet
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            closePanel();
                        }
                    }}
                >
                    <SheetContent
                        // The panel's own heading takes the focus, so the sheet must not pick a button.
                        onOpenAutoFocus={(event) => event.preventDefault()}
                        aria-describedby={undefined}
                        className="w-full gap-0 overflow-y-auto sm:max-w-sm"
                    >
                        <AppointmentPanel
                            key={selected.ulid}
                            schedule={schedule}
                            selected={selected}
                            move={move}
                            viewOnly={viewOnly}
                            inSheet
                        />
                    </SheetContent>
                </Sheet>
            )}
        </>
    );
}

StaffSchedule.layout = {
    breadcrumbs: [
        {
            title: 'Day schedule',
            href: ScheduleController.index(),
        },
    ],
};
