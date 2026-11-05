import { ChevronDown, Siren } from 'lucide-react';
import { useId, useState } from 'react';
import OpenPanelLink from '@/components/schedule/open-panel-link';
import { formatClinicTime } from '@/lib/clinic-time';
import { cn } from '@/lib/utils';
import type { DaySchedule } from '@/types';

type Props = {
    schedule: DaySchedule;
};

/**
 * A red banner under the page header that counts the day's emergency flags and opens to who they are: name, doctor and
 * time, and a name opens that booking in the side panel. It is built from the bookings the page already holds. The
 * server sends `emergencyFlag` to staff only, so for an admin the key is missing, nothing is found, and no banner is
 * drawn: that, and not this component, is what keeps the flags from an admin. With no flags, nothing is drawn either.
 * Red is kept for the emergency flag, so it is not used for lateness.
 */
export default function AttentionBanner({ schedule }: Props) {
    const [open, setOpen] = useState(false);
    const listId = useId();

    const flagged = schedule.doctors
        .flatMap((doctor) =>
            doctor.appointments
                .filter((appointment) => appointment.emergencyFlag === true)
                .map((appointment) => ({
                    appointment,
                    doctorName: doctor.name,
                })),
        )
        .sort(
            (a, b) =>
                Date.parse(a.appointment.startsAt) -
                    Date.parse(b.appointment.startsAt) ||
                a.appointment.ulid.localeCompare(b.appointment.ulid),
        );

    if (flagged.length === 0) {
        return null;
    }

    const when = schedule.date === schedule.today ? 'today' : 'on this day';

    return (
        <section
            aria-label="Needs attention"
            data-test="attention-banner"
            className="rounded-xl border border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100"
        >
            <button
                type="button"
                aria-expanded={open}
                aria-controls={listId}
                onClick={() => setOpen(!open)}
                className="flex w-full items-center gap-2 rounded-xl px-4 py-3 text-left text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <Siren aria-hidden className="size-4 shrink-0" />
                <span className="font-semibold">
                    {flagged.length} emergency{' '}
                    {flagged.length === 1 ? 'flag' : 'flags'} {when}
                </span>
                <ChevronDown
                    aria-hidden
                    className={cn(
                        'ml-auto size-4 shrink-0 transition-transform',
                        !open && '-rotate-90',
                    )}
                />
            </button>

            {open && (
                <ul
                    id={listId}
                    className="divide-y divide-red-200 border-t border-red-200 px-4 dark:divide-red-900 dark:border-red-900"
                >
                    {flagged.map(({ appointment, doctorName }) => (
                        <li
                            key={appointment.ulid}
                            data-test="attention-row"
                            className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 py-2 text-sm"
                        >
                            <OpenPanelLink
                                date={schedule.date}
                                ulid={appointment.ulid}
                                // Closing the panel then gives the focus back to this link, as it does for a block.
                                data-appointment={appointment.ulid}
                                className="min-w-0 truncate rounded-sm font-medium underline underline-offset-4 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {appointment.patientName}
                            </OpenPanelLink>
                            <span>
                                {doctorName} ·{' '}
                                {formatClinicTime(appointment.startsAt)} ·{' '}
                                {appointment.statusLabel}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
