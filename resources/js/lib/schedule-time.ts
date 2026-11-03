import type { ScheduleAppointment } from '@/types';

/**
 * How far from its start a booking still counts as "due now", before and after. A first guess: the front desk will
 * tune it. It is the only place the number lives.
 */
export const DUE_WINDOW_MINUTES = 30;

const MINUTE = 60_000;

/** One booking in the arrivals list, with the name of the doctor whose column it came from. */
export type ArrivalRow = {
    appointment: ScheduleAppointment;
    doctorName: string;
};

export type ArrivalGroupKey =
    | 'waiting'
    | 'due'
    | 'notArrived'
    | 'later'
    | 'booked'
    | 'checkedIn'
    | 'done';

export type ArrivalGroup = {
    key: ArrivalGroupKey;
    label: string;
    /** A short line under the heading that says what the group means. */
    hint?: string;
    rows: ArrivalRow[];
};

function byStart(a: ArrivalRow, b: ArrivalRow): number {
    return (
        Date.parse(a.appointment.startsAt) -
            Date.parse(b.appointment.startsAt) ||
        a.doctorName.localeCompare(b.doctorName) ||
        a.appointment.ulid.localeCompare(b.appointment.ulid)
    );
}

/**
 * Sort the day's bookings into the groups the front desk works from. Pure: "now" comes in as a number, so the same
 * rows move between groups as the clock moves and nothing is asked of the server.
 *
 * On today the groups follow what has to happen next: who is waiting, who is due, who is missing, who comes later,
 * and who is done. On any other day there is no "due", so the groups are the plain statuses. Every group is returned,
 * even empty ones, in the order they are shown; the caller decides what to do with an empty one.
 *
 * Waiting is sorted by start time until the check-in time reaches the schedule (`staff-waiting-strip`).
 */
export function groupArrivals(
    rows: ArrivalRow[],
    now: number,
    isToday: boolean,
): ArrivalGroup[] {
    const ordered = [...rows].sort(byStart);
    const of = (...statuses: ScheduleAppointment['status'][]) =>
        ordered.filter((row) => statuses.includes(row.appointment.status));

    const done: ArrivalGroup = {
        key: 'done',
        label: 'Done',
        rows: of('completed', 'no_show'),
    };

    if (!isToday) {
        return [
            { key: 'booked', label: 'Booked', rows: of('booked') },
            { key: 'checkedIn', label: 'Checked in', rows: of('checked_in') },
            done,
        ];
    }

    const window = DUE_WINDOW_MINUTES * MINUTE;
    const booked = of('booked');
    const start = (row: ArrivalRow) => Date.parse(row.appointment.startsAt);

    return [
        {
            key: 'waiting',
            label: 'Waiting',
            hint: 'Checked in, and waiting for the doctor.',
            rows: of('checked_in'),
        },
        {
            key: 'due',
            label: 'Due now',
            hint: `Starting within ${DUE_WINDOW_MINUTES} minutes, or started up to ${DUE_WINDOW_MINUTES} minutes ago.`,
            rows: booked.filter(
                (row) =>
                    start(row) >= now - window && start(row) <= now + window,
            ),
        },
        {
            key: 'notArrived',
            label: 'Not arrived yet',
            hint: `Started more than ${DUE_WINDOW_MINUTES} minutes ago.`,
            rows: booked.filter((row) => start(row) < now - window),
        },
        {
            key: 'later',
            label: 'Later today',
            rows: booked.filter((row) => start(row) > now + window),
        },
        done,
    ];
}
