import { ChevronDown, PanelRightOpen } from 'lucide-react';
import { useState } from 'react';
import AppointmentFlags from '@/components/schedule/appointment-flags';
import CheckInButton from '@/components/schedule/check-in-button';
import LateLabel from '@/components/schedule/late-label';
import NoShowDialog from '@/components/schedule/no-show-dialog';
import OpenPanelLink from '@/components/schedule/open-panel-link';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { useNow } from '@/hooks/use-now';
import { formatClinicTime } from '@/lib/clinic-time';
import { groupArrivals, lateMinutes } from '@/lib/schedule-time';
import type { ArrivalGroup, ArrivalRow } from '@/lib/schedule-time';
import { cn } from '@/lib/utils';
import type { DaySchedule, ScheduleDoctor } from '@/types';

type Props = {
    schedule: DaySchedule;
    /** The doctors to list, already narrowed by the chips. */
    doctors: ScheduleDoctor[];
    /** An admin may look at the schedule but not check anyone in. */
    viewOnly: boolean;
    /** The booking whose side panel is open, if any. */
    selectedUlid: string | null;
};

const chip =
    'inline-flex h-8 items-center rounded-full border px-3 pointer-coarse:h-11 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

/**
 * The day as one list in time order across all doctors, grouped by what the front desk does next (on today: waiting,
 * due now, not arrived yet, later, done). The groups come from the bookings the page already holds and the browser
 * clock, renewed every minute: nothing is requested from the server. The Check in button is the same one the search
 * and the side panel use.
 */
export default function ArrivalsList({
    schedule,
    doctors,
    viewOnly,
    selectedUlid,
}: Props) {
    const now = useNow();
    const [needsActionOnly, setNeedsActionOnly] = useState(false);
    // null: the default, which is closed on today and open on any other day. A choice stays when the day changes.
    const [doneOpen, setDoneOpen] = useState<boolean | null>(null);

    const isToday = schedule.date === schedule.today;
    const rows: ArrivalRow[] = doctors.flatMap((doctor) =>
        doctor.appointments.map((appointment) => ({
            appointment,
            doctorName: doctor.name,
        })),
    );
    // Only today's groups depend on the clock, which the server does not have (see `useNow`).
    const groups = groupArrivals(rows, now ?? 0, isToday).filter(
        (group) => !(needsActionOnly && group.key === 'done'),
    );
    const total = groups.reduce((sum, group) => sum + group.rows.length, 0);

    if (isToday && now === null) {
        return (
            <p className="rounded-xl border bg-card p-8 text-center text-sm text-muted-foreground">
                Loading today&apos;s list…
            </p>
        );
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                {/* Read out when a filter or the clock changes what the list holds. */}
                <p
                    role="status"
                    aria-live="polite"
                    className="text-sm text-muted-foreground"
                >
                    {total} {total === 1 ? 'booking' : 'bookings'} in the list
                </p>

                <div
                    role="group"
                    aria-label="Show bookings"
                    className="flex gap-2"
                >
                    {[
                        { needsAction: true, label: 'Needs action' },
                        { needsAction: false, label: 'All' },
                    ].map((option) => (
                        <button
                            key={option.label}
                            type="button"
                            aria-pressed={
                                needsActionOnly === option.needsAction
                            }
                            onClick={() =>
                                setNeedsActionOnly(option.needsAction)
                            }
                            className={cn(
                                chip,
                                needsActionOnly === option.needsAction
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'bg-card hover:bg-accent',
                            )}
                        >
                            {option.label}
                        </button>
                    ))}
                </div>
            </div>

            {total === 0 && (
                <p className="rounded-xl border bg-card p-8 text-center text-sm text-muted-foreground">
                    No bookings to show.
                </p>
            )}

            {groups.map((group) => (
                <ArrivalGroupSection
                    key={group.key}
                    group={group}
                    // Today's list always shows Waiting, even empty: "nobody is waiting" is an answer the desk needs.
                    showWhenEmpty={isToday && group.key === 'waiting'}
                    date={schedule.date}
                    now={now}
                    isToday={isToday}
                    viewOnly={viewOnly}
                    selectedUlid={selectedUlid}
                    open={
                        group.key === 'done'
                            ? (doneOpen ?? !isToday)
                            : undefined
                    }
                    onToggle={() => setDoneOpen(!(doneOpen ?? !isToday))}
                />
            ))}
        </div>
    );
}

type GroupProps = {
    group: ArrivalGroup;
    showWhenEmpty: boolean;
    date: string;
    /** The browser clock and whether the page shows today: together they decide which rows are late. */
    now: number | null;
    isToday: boolean;
    viewOnly: boolean;
    selectedUlid: string | null;
    /** Only the Done group can be closed. `undefined` for every other group. */
    open: boolean | undefined;
    onToggle: () => void;
};

function ArrivalGroupSection({
    group,
    showWhenEmpty,
    date,
    now,
    isToday,
    viewOnly,
    selectedUlid,
    open,
    onToggle,
}: GroupProps) {
    if (group.rows.length === 0 && !showWhenEmpty) {
        return null;
    }

    const headingId = `arrivals-${group.key}`;
    const listId = `arrivals-${group.key}-list`;
    const collapsible = open !== undefined;
    const lateCount = group.rows.filter(
        (row) => lateMinutes(row.appointment, now, isToday) !== null,
    ).length;
    const title = (
        <>
            {group.label}
            <span className="ml-2 font-normal text-muted-foreground">
                {group.rows.length}
            </span>
            {lateCount > 0 && (
                <span className="ml-2 text-sm font-medium text-amber-ink">
                    · {lateCount} late
                </span>
            )}
        </>
    );

    return (
        <section aria-labelledby={headingId} className="space-y-2">
            {/* A real heading, so a screen reader user can jump from group to group. The Done group's button sits inside it. */}
            <h2 id={headingId} className="text-base font-semibold">
                {collapsible ? (
                    <button
                        type="button"
                        aria-expanded={open}
                        aria-controls={listId}
                        onClick={onToggle}
                        className="flex items-center gap-1.5 rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <ChevronDown
                            aria-hidden
                            className={cn(
                                'size-4 transition-transform',
                                !open && '-rotate-90',
                            )}
                        />
                        <span>{title}</span>
                    </button>
                ) : (
                    title
                )}
            </h2>

            {group.hint && !collapsible && (
                <p className="text-xs text-muted-foreground">{group.hint}</p>
            )}

            {group.rows.length === 0 ? (
                <p className="rounded-xl border bg-card p-4 text-sm text-muted-foreground">
                    Nobody is waiting.
                </p>
            ) : (
                open !== false && (
                    <ul
                        id={listId}
                        className="divide-y overflow-hidden rounded-xl border bg-card"
                    >
                        {group.rows.map((row) => (
                            <ArrivalItem
                                key={row.appointment.ulid}
                                row={row}
                                date={date}
                                late={lateMinutes(
                                    row.appointment,
                                    now,
                                    isToday,
                                )}
                                viewOnly={viewOnly}
                                selected={row.appointment.ulid === selectedUlid}
                            />
                        ))}
                    </ul>
                )
            )}
        </section>
    );
}

type ItemProps = {
    row: ArrivalRow;
    /** The clinic day on the page ('Y-m-d'), so the Open link stays on it. */
    date: string;
    /** Minutes late, or `null` when the booking is not late. */
    late: number | null;
    viewOnly: boolean;
    selected: boolean;
};

// The server answers a check-in or a no-show by opening that booking in the side panel, and the panel takes the
// focus. When the panel is closed, the focus goes back to the booking's Open link, which is in its new group by then.
function afterCheckIn() {}

function ArrivalItem({ row, date, late, viewOnly, selected }: ItemProps) {
    const { appointment, doctorName } = row;
    const time = formatClinicTime(appointment.startsAt);
    // Hiding the button for an admin is only a hint: the Policy and the Action refuse them again on the server.
    // A booking with no intake still gets the button: it opens a dialog that asks first.
    const canCheckIn = !viewOnly && appointment.status === 'booked';
    // Only a late booking has Mark no-show here (it has started, so the Action's start-time rule is met). An earlier
    // one is marked from the side panel. The dialog asks first, so a single click never marks anyone.
    const canNoShow = canCheckIn && late !== null;

    return (
        <li
            data-test="arrival-row"
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
                    <span className="min-w-0 truncate font-medium">
                        {appointment.patientName}
                    </span>
                    <AppointmentFlags
                        appointment={appointment}
                        className="shrink-0"
                    />
                    {late !== null && <LateLabel minutes={late} />}
                </p>
                <p className="truncate text-xs text-muted-foreground">
                    <span className="font-bold text-teal-ink">
                        {doctorName}
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

            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 md:w-80 md:shrink-0 md:justify-end">
                {appointment.intakeMissing && (
                    <span className="text-xs font-medium text-amber-ink">
                        No intake yet
                    </span>
                )}

                {canCheckIn ? (
                    // One no-wrap group, so Check in and Mark no-show stay side by side. "No intake yet" wraps above it.
                    <div className="flex w-full items-start gap-2 md:w-auto">
                        <CheckInButton
                            appointment={appointment}
                            variant="row"
                            onCheckedIn={afterCheckIn}
                        />
                        {canNoShow && (
                            <NoShowDialog
                                appointment={appointment}
                                started
                                variant="row"
                                onDone={afterCheckIn}
                            />
                        )}
                    </div>
                ) : (
                    <Button
                        asChild
                        variant="outline"
                        className="w-full border-teal-line/50 md:w-auto"
                    >
                        <OpenPanelLink
                            date={date}
                            ulid={appointment.ulid}
                            aria-label={`Open ${appointment.patientName} at ${time}`}
                            // Closing the panel then gives the focus back to this button, as it does for a block.
                            data-appointment={appointment.ulid}
                            data-test="arrival-open-button"
                        >
                            <PanelRightOpen aria-hidden />
                            Open
                        </OpenPanelLink>
                    </Button>
                )}
            </div>
        </li>
    );
}
