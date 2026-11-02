import { Link } from '@inertiajs/react';
import { Check, Info } from 'lucide-react';
import IntakeController from '@/actions/App/Http/Controllers/Patient/IntakeController';
import StatusBadge from '@/components/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatClinicDateTime, formatClinicTime } from '@/lib/clinic-time';
import type { MyAppointment } from '@/types';
import CancelAppointmentDialog from './cancel-appointment-dialog';

type Props = {
    appointment: MyAppointment;
    /** Show the cancel button or the "too late" hint. Only an upcoming appointment can be cancelled. */
    upcoming: boolean;
};

/** One appointment. The status is always written out, so it never depends on color alone. */
export default function AppointmentCard({ appointment, upcoming }: Props) {
    const { service, doctor, startsAt, endsAt, status } = appointment;

    // A booked visit that has no intake yet is the one that needs attention. Once the patient is checked in, an
    // empty intake can no longer be filled, so only a saved one is mentioned.
    const intakeStatus = appointment.intakeFilled
        ? 'Intake done'
        : status === 'booked'
          ? 'Intake needed'
          : null;
    const showIntakeButton = upcoming && appointment.can.fillIntake;
    const intakeButtonLabel = appointment.intakeFilled
        ? 'Edit intake'
        : 'Fill intake';

    return (
        <li className="flex flex-col gap-3 rounded-xl border bg-card p-4 sm:flex-row sm:items-start sm:justify-between">
            <div className="space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                    <h3 className="font-semibold">
                        {formatClinicDateTime(startsAt)} to{' '}
                        {formatClinicTime(endsAt)}
                    </h3>
                    <StatusBadge
                        status={status}
                        label={appointment.statusLabel}
                    />
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
                {upcoming && intakeStatus && (
                    <p>
                        {/* Written out, so it never depends on color alone. */}
                        <Badge variant="outline" className="gap-1">
                            {appointment.intakeFilled ? (
                                <Check aria-hidden className="size-3" />
                            ) : (
                                <Info aria-hidden className="size-3" />
                            )}
                            {intakeStatus}
                        </Badge>
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

            {(showIntakeButton || appointment.can.cancel) && (
                <div className="flex shrink-0 flex-wrap gap-2">
                    {showIntakeButton && (
                        <Button
                            asChild
                            variant={
                                appointment.intakeFilled ? 'outline' : 'default'
                            }
                        >
                            <Link
                                href={IntakeController.edit(appointment.ulid)}
                                aria-label={`${intakeButtonLabel} for ${service.name} on ${formatClinicDateTime(startsAt)}`}
                            >
                                {intakeButtonLabel}
                            </Link>
                        </Button>
                    )}
                    {appointment.can.cancel && (
                        <CancelAppointmentDialog appointment={appointment} />
                    )}
                </div>
            )}
        </li>
    );
}
