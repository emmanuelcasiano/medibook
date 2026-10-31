import { ChevronDown } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { formatClinicTime } from '@/lib/clinic-time';
import type { ScheduleDoctor } from '@/types';

type Props = {
    doctors: ScheduleDoctor[];
};

/**
 * The schedule for phones: one list per doctor in time order, instead of the grid. A native `<details>` opens
 * and closes with the keyboard (Enter or Space on the summary) and needs no script.
 */
export default function ScheduleDoctorList({ doctors }: Props) {
    return (
        <div className="space-y-3 md:hidden">
            {doctors.map((doctor, index) => (
                <details
                    key={doctor.id}
                    open={index === 0 ? true : undefined}
                    className="group rounded-xl border"
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
                                    className="flex items-start gap-3 p-4"
                                >
                                    <span className="w-16 shrink-0 text-sm font-medium">
                                        {formatClinicTime(appointment.startsAt)}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-medium">
                                            {appointment.patientName}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {appointment.serviceName}
                                        </span>
                                    </span>
                                    <Badge variant="outline">
                                        {appointment.statusLabel}
                                    </Badge>
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
