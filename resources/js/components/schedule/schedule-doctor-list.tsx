import { ChevronDown } from 'lucide-react';
import StatusBadge from '@/components/status-badge';
import { Badge } from '@/components/ui/badge';
import { useNow } from '@/hooks/use-now';
import { formatClinicTime } from '@/lib/clinic-time';
import { lateMinutes } from '@/lib/schedule-time';
import { cn } from '@/lib/utils';
import type { ScheduleDoctor } from '@/types';
import AppointmentFlags from './appointment-flags';
import { isOffToday } from './doctor-filter';
import LateLabel from './late-label';
import OpenPanelLink from './open-panel-link';

type Props = {
    /** The doctors to list, already narrowed by the chips. */
    doctors: ScheduleDoctor[];
    /** The clinic day on the page ('Y-m-d'), so a row's link stays on it. */
    date: string;
    /** The page shows today, the only day a booking can be late. */
    isToday: boolean;
    /** The booking whose side panel is open, if any. */
    selectedUlid: string | null;
};

/** Doctors with bookings first, then doctors who work but have none, then doctors who are off today. */
function rank(doctor: ScheduleDoctor): number {
    if (doctor.appointments.length > 0) {
        return 0;
    }

    return isOffToday(doctor) ? 2 : 1;
}

/**
 * The "By doctor" view for phones: one list per doctor in time order, instead of the grid. The page shows it only
 * when that view is chosen. A native `<details>` opens and closes with the keyboard (Enter or Space on the summary)
 * and needs no script. Unlike the grid, it keeps the doctors who are off today: last and closed, with an "Off today"
 * label.
 */
export default function ScheduleDoctorList({
    doctors,
    date,
    isToday,
    selectedUlid,
}: Props) {
    const now = useNow();
    // `sort` is stable, so doctors of the same rank keep the server's order.
    const ordered = [...doctors].sort((a, b) => rank(a) - rank(b));

    return (
        <div className="space-y-3">
            {ordered.map((doctor) => (
                <details
                    key={doctor.id}
                    // A doctor with bookings opens by default, which also keeps a selected row from being hidden.
                    open={doctor.appointments.length > 0 ? true : undefined}
                    className="group rounded-xl border bg-card"
                >
                    <summary className="flex cursor-pointer list-none items-center justify-between gap-3 rounded-xl p-4 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                        <span>
                            <span className="flex items-center gap-2 font-medium">
                                {doctor.name}
                                {isOffToday(doctor) && (
                                    <Badge variant="outline">Off today</Badge>
                                )}
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {doctor.specialty} ·{' '}
                                {doctor.appointments.length}{' '}
                                {doctor.appointments.length === 1
                                    ? 'booking'
                                    : 'bookings'}
                                {!doctor.isActive && ' · Deactivated'}
                                {!doctor.isWorking &&
                                    !isOffToday(doctor) &&
                                    ' · Not working today'}
                            </span>
                        </span>
                        <ChevronDown
                            aria-hidden
                            className="size-4 shrink-0 transition-transform group-open:rotate-180"
                        />
                    </summary>

                    {doctor.appointments.length > 0 ? (
                        <ol className="divide-y border-t">
                            {doctor.appointments.map((appointment) => (
                                <li
                                    key={appointment.ulid}
                                    className={cn(
                                        appointment.ulid === selectedUlid &&
                                            'bg-muted',
                                    )}
                                >
                                    {/* The whole row opens the side panel, which slides over the list. */}
                                    <OpenPanelLink
                                        date={date}
                                        ulid={appointment.ulid}
                                        aria-current={
                                            appointment.ulid === selectedUlid
                                                ? 'true'
                                                : undefined
                                        }
                                        data-appointment={appointment.ulid}
                                        className="flex items-start gap-3 p-4 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                                    >
                                        <span className="w-16 shrink-0 text-sm font-medium">
                                            {formatClinicTime(
                                                appointment.startsAt,
                                            )}
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="flex items-center gap-1.5">
                                                <span className="truncate font-medium">
                                                    {appointment.patientName}
                                                </span>
                                                <AppointmentFlags
                                                    appointment={appointment}
                                                    className="shrink-0"
                                                />
                                                {lateMinutes(
                                                    appointment,
                                                    now,
                                                    isToday,
                                                ) !== null && <LateLabel />}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {appointment.serviceName}
                                            </span>
                                        </span>
                                        <StatusBadge
                                            status={appointment.status}
                                            label={appointment.statusLabel}
                                        />
                                    </OpenPanelLink>
                                </li>
                            ))}
                        </ol>
                    ) : (
                        <p className="border-t p-4 text-sm text-muted-foreground">
                            No appointments.
                        </p>
                    )}
                </details>
            ))}
        </div>
    );
}
