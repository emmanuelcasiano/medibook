import { Badge } from '@/components/ui/badge';
import { formatClinicDateTime, formatClinicTime } from '@/lib/clinic-time';
import type { AppointmentStatus, MyAppointment } from '@/types';
import CancelAppointmentDialog from './cancel-appointment-dialog';

type Props = {
    appointment: MyAppointment;
    /** Show the cancel button or the "too late" hint. Only an upcoming appointment can be cancelled. */
    upcoming: boolean;
};

const badgeVariants: Record<
    AppointmentStatus,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    booked: 'default',
    checked_in: 'secondary',
    completed: 'outline',
    cancelled: 'destructive',
    no_show: 'destructive',
};

/** One appointment. The status is always written out, so it never depends on color alone. */
export default function AppointmentCard({ appointment, upcoming }: Props) {
    const { service, doctor, startsAt, endsAt, status } = appointment;

    return (
        <li className="flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-start sm:justify-between">
            <div className="space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                    <h3 className="font-semibold">
                        {formatClinicDateTime(startsAt)} to{' '}
                        {formatClinicTime(endsAt)}
                    </h3>
                    <Badge variant={badgeVariants[status]}>
                        {appointment.statusLabel}
                    </Badge>
                </div>
                <p>
                    {service.name} · {service.durationMinutes} min
                </p>
                <p className="text-sm text-muted-foreground">
                    {doctor.name} · {doctor.specialty}
                </p>
                {status === 'cancelled' && (
                    <p className="text-sm text-muted-foreground">
                        {appointment.cancelledByClinic
                            ? 'Cancelled by the clinic.'
                            : 'Cancelled by you.'}
                        {appointment.cancelReason &&
                            ` Reason: ${appointment.cancelReason}`}
                    </p>
                )}
                {upcoming && status === 'booked' && (
                    <p className="text-sm text-muted-foreground">
                        {appointment.can.cancel ? (
                            <>
                                Free to cancel until{' '}
                                {formatClinicDateTime(appointment.cancelUntil)}.
                            </>
                        ) : (
                            'It is too late to cancel online. Please call the clinic.'
                        )}
                    </p>
                )}
            </div>

            {appointment.can.cancel && (
                <div className="shrink-0">
                    <CancelAppointmentDialog appointment={appointment} />
                </div>
            )}
        </li>
    );
}
