import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import ScheduleController from '@/actions/App/Http/Controllers/Staff/ScheduleController';
import StatusBadge from '@/components/status-badge';
import { formatClinicTime } from '@/lib/clinic-time';
import { cn } from '@/lib/utils';
import type { ScheduleDoctor } from '@/types';
import AppointmentFlags from './appointment-flags';

type Props = {
    doctors: ScheduleDoctor[];
    /** The clinic day on the page ('Y-m-d'), so a row's link stays on it. */
    date: string;
    /** The booking whose side panel is open, if any. */
    selectedUlid: string | null;
};

/**
 * The schedule for phones: one list per doctor in time order, instead of the grid. A native `<details>` opens
 * and closes with the keyboard (Enter or Space on the summary) and needs no script.
 */
export default function ScheduleDoctorList({
    doctors,
    date,
    selectedUlid,
}: Props) {
    return (
        <div className="space-y-3 md:hidden">
            {doctors.map((doctor, index) => (
                <details
                    key={doctor.id}
                    // The first doctor opens by default. So does the doctor whose booking is selected, or the
                    // selected row would be hidden.
                    open={
                        index === 0 ||
                        doctor.appointments.some(
                            (appointment) => appointment.ulid === selectedUlid,
                        )
                            ? true
                            : undefined
                    }
                    className="group rounded-xl border bg-card"
                >
                    <summary className="flex cursor-pointer list-none items-center justify-between gap-3 rounded-xl p-4 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                        <span>
                            <span className="block font-medium">
                                {doctor.name}
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {doctor.specialty} ·{' '}
                                {doctor.appointments.length}{' '}
                                {doctor.appointments.length === 1
                                    ? 'appointment'
                                    : 'appointments'}
                                {!doctor.isActive && ' · Deactivated'}
                                {!doctor.isWorking && ' · Not working today'}
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
                                    {/* The whole row opens the side panel, which shows under the list on a phone. */}
                                    <Link
                                        href={ScheduleController.index({
                                            query: {
                                                date,
                                                appointment: appointment.ulid,
                                            },
                                        })}
                                        preserveScroll
                                        preserveState
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
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {appointment.serviceName}
                                            </span>
                                        </span>
                                        <StatusBadge
                                            status={appointment.status}
                                            label={appointment.statusLabel}
                                        />
                                    </Link>
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
