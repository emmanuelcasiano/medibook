import { Link } from '@inertiajs/react';
import { Check, Info } from 'lucide-react';
import IntakeController from '@/actions/App/Http/Controllers/Patient/IntakeController';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    formatClinicDateTime,
    formatClinicInstantDay,
    formatClinicInstantDayNumber,
    formatClinicInstantShortMonth,
    formatClinicTime,
} from '@/lib/clinic-time';
import { cn } from '@/lib/utils';
import type { MyAppointment } from '@/types';
import CancelAppointmentDialog from './cancel-appointment-dialog';

type Props = {
    appointment: MyAppointment;
    /** Show the cancel button or the "too late" hint. Only an upcoming appointment can be cancelled. */
    upcoming: boolean;
    /** The next visit. It gets the dark-teal date box, the one coloured accent of the list. */
    next?: boolean;
};

/** One appointment. The status is always written out, so it never depends on color alone. */
export default function AppointmentCard({
    appointment,
    upcoming,
    next = false,
}: Props) {
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
        <li
            className={cn(
                'flex flex-col gap-4 rounded-xl border bg-card p-4 outline-none focus-visible:ring-[3px] focus-visible:ring-ring sm:flex-row sm:items-center',
                !upcoming && 'text-muted-foreground',
            )}
        >
            {/* The date is read out in the heading below, so the box is for the eye only. */}
            <div
                aria-hidden
                className={cn(
                    'flex size-16 shrink-0 flex-col items-center justify-center rounded-lg leading-none',
                    next
                        ? 'bg-deep text-deep-foreground dark:bg-teal-soft dark:text-teal-ink dark:ring-1 dark:ring-teal-line'
                        : 'bg-muted text-foreground',
                    !upcoming && 'text-muted-foreground',
                )}
            >
                <span className="text-xs font-semibold tracking-wider uppercase">
                    {formatClinicInstantShortMonth(startsAt)}
                </span>
                <span className="mt-1 text-2xl font-semibold tabular-nums">
                    {formatClinicInstantDayNumber(startsAt)}
                </span>
            </div>

            <div className="min-w-0 flex-1 space-y-1">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <h3
                        className={cn(
                            'font-mono text-base font-medium tabular-nums',
                            upcoming && 'text-foreground',
                        )}
                    >
                        <span className="sr-only">
                            {formatClinicInstantDay(startsAt)},{' '}
                        </span>
                        {formatClinicTime(startsAt)} to{' '}
                        {formatClinicTime(endsAt)}
                    </h3>
                    <StatusBadge
                        status={status}
                        label={appointment.statusLabel}
                    />
                </div>
                <p className={cn(upcoming && 'text-foreground')}>
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
                    <p className="pt-1">
                        {/* Written out, so it never depends on color alone. Only the missing intake is loud. */}
                        {appointment.intakeFilled ? (
                            <span className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
                                <Check aria-hidden className="size-3.5" />
                                {intakeStatus}
                            </span>
                        ) : (
                            <span className="inline-flex items-center gap-1.5 rounded-md bg-amber-soft px-2 py-1 text-sm font-medium text-amber-ink">
                                <Info aria-hidden className="size-3.5" />
                                {intakeStatus}
                            </span>
                        )}
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
