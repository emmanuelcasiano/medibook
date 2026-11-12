import { ChevronDown } from 'lucide-react';
import { useId, useState } from 'react';
import OpenPanelLink from '@/components/schedule/open-panel-link';
import { useNow } from '@/hooks/use-now';
import { formatClinicTime } from '@/lib/clinic-time';
import {
    formatWait,
    LONG_WAIT_MINUTES,
    waitMinutes,
} from '@/lib/schedule-time';
import { cn } from '@/lib/utils';
import type { DaySchedule, ScheduleAppointment } from '@/types';

type Props = {
    schedule: DaySchedule;
};

type Waiting = {
    appointment: ScheduleAppointment;
    /** From the server, so it is never null here. */
    checkedInAt: string;
    minutes: number;
};

type WaitingDoctor = {
    id: number;
    name: string;
    patients: Waiting[];
};

const longWait = 'text-amber-ink';

/**
 * Who has checked in and is still waiting, by doctor, longest wait first. Built from the bookings the page already
 * holds, so a visit the doctor finishes leaves the strip when the page reloads with the new status.
 */
function waitingByDoctor(schedule: DaySchedule, now: number): WaitingDoctor[] {
    return schedule.doctors
        .map((doctor) => ({
            id: doctor.id,
            name: doctor.name,
            patients: doctor.appointments
                .flatMap((appointment): Waiting[] =>
                    appointment.status === 'checked_in' &&
                    appointment.checkedInAt !== null
                        ? [
                              {
                                  appointment,
                                  checkedInAt: appointment.checkedInAt,
                                  minutes: waitMinutes(
                                      appointment.checkedInAt,
                                      now,
                                  ),
                              },
                          ]
                        : [],
                )
                .sort(
                    (a, b) =>
                        Date.parse(a.checkedInAt) - Date.parse(b.checkedInAt) ||
                        a.appointment.ulid.localeCompare(b.appointment.ulid),
                ),
        }))
        .filter((doctor) => doctor.patients.length > 0)
        .sort(
            (a, b) =>
                Date.parse(a.patients[0].checkedInAt) -
                    Date.parse(b.patients[0].checkedInAt) || a.id - b.id,
        );
}

/**
 * A strip under the page header that answers "who is waiting, for whom, and for how long?". Today only: the wait is
 * counted on the browser clock, which means nothing on another day. It opens to the patients by doctor, and a name
 * opens that booking in the side panel.
 */
export default function WaitingStrip({ schedule }: Props) {
    const now = useNow();
    const [open, setOpen] = useState(false);
    const listId = useId();

    // `null` is the server and the first render in the browser, which have no clock to count from (see `useNow`).
    if (schedule.date !== schedule.today || now === null) {
        return null;
    }

    const doctors = waitingByDoctor(schedule, now);
    const count = doctors.reduce(
        (sum, doctor) => sum + doctor.patients.length,
        0,
    );
    // The first patient of the first doctor has waited the longest: both lists are oldest first.
    const longest = doctors[0]?.patients[0]?.minutes ?? 0;
    const isLong = longest >= LONG_WAIT_MINUTES;

    return (
        <section
            aria-label="Waiting patients"
            data-test="waiting-strip"
            className="rounded-xl border bg-card"
        >
            {/* Only the count is read out when it changes. The minutes below change every minute and are not. */}
            <p role="status" aria-live="polite" className="sr-only">
                {count === 0
                    ? 'No one is waiting'
                    : `${count} ${count === 1 ? 'patient is' : 'patients are'} waiting`}
            </p>

            {count === 0 ? (
                <p className="px-4 py-3 text-sm text-muted-foreground">
                    No one is waiting
                </p>
            ) : (
                <>
                    <button
                        type="button"
                        aria-expanded={open}
                        aria-controls={listId}
                        onClick={() => setOpen(!open)}
                        className="flex w-full items-center gap-2 rounded-xl px-4 py-3 text-left text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <ChevronDown
                            aria-hidden
                            className={cn(
                                'size-4 shrink-0 transition-transform',
                                !open && '-rotate-90',
                            )}
                        />
                        <span className="font-semibold">{count} waiting</span>
                        <span className={cn(isLong && longWait)}>
                            · longest {formatWait(longest).toLowerCase()}
                            {isLong && ' · waiting a long time'}
                        </span>
                    </button>

                    {open && (
                        <div
                            id={listId}
                            className="space-y-3 border-t px-4 py-3"
                        >
                            {doctors.map((doctor) => (
                                <section
                                    key={doctor.id}
                                    aria-labelledby={`${listId}-${doctor.id}`}
                                >
                                    <h2
                                        id={`${listId}-${doctor.id}`}
                                        className="text-sm font-bold text-teal-ink"
                                    >
                                        {doctor.name}
                                    </h2>
                                    <ul className="mt-1 divide-y">
                                        {doctor.patients.map((patient) => (
                                            <WaitingRow
                                                key={patient.appointment.ulid}
                                                patient={patient}
                                                date={schedule.date}
                                            />
                                        ))}
                                    </ul>
                                </section>
                            ))}
                        </div>
                    )}
                </>
            )}
        </section>
    );
}

function WaitingRow({
    patient,
    date,
}: {
    patient: Waiting;
    /** The clinic day on the page ('Y-m-d'), so the link stays on it. */
    date: string;
}) {
    const { appointment, checkedInAt, minutes } = patient;
    const isLong = minutes >= LONG_WAIT_MINUTES;

    return (
        <li
            data-test="waiting-row"
            className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 py-2 text-sm"
        >
            <OpenPanelLink
                date={date}
                ulid={appointment.ulid}
                // Closing the panel then gives the focus back to this link, as it does for a block.
                data-appointment={appointment.ulid}
                className="min-w-0 truncate rounded-sm font-medium underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                {appointment.patientName}
            </OpenPanelLink>
            <span className="text-muted-foreground">
                Checked in {formatClinicTime(checkedInAt)} ·{' '}
                <span className={cn(isLong && cn(longWait, 'font-medium'))}>
                    {formatWait(minutes)}
                    {isLong && ' · waiting a long time'}
                </span>
            </span>
        </li>
    );
}
