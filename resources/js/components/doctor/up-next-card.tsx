import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import DoctorAppointmentController from '@/actions/App/Http/Controllers/Doctor/AppointmentController';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { formatClinicTime } from '@/lib/clinic-time';
import type { TodayAppointment } from '@/types';

type Props = {
    appointment: TodayAppointment;
};

/**
 * The one dark-teal card of the Today screen: the patient who is waiting, or else the next booking. It only repeats
 * what the list row already receives (first name, service, status), so no new data is sent.
 */
export default function UpNextCard({ appointment }: Props) {
    const waiting = appointment.status === 'checked_in';

    return (
        <section
            aria-labelledby="up-next-heading"
            className="flex flex-wrap items-center justify-between gap-x-6 gap-y-4 rounded-xl bg-deep p-5 text-deep-foreground"
        >
            <div className="min-w-0 space-y-2">
                <h2
                    id="up-next-heading"
                    className="text-xs font-semibold tracking-wider text-deep-muted uppercase"
                >
                    {waiting ? 'Up next · waiting now' : 'Up next'}
                </h2>
                <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span className="font-mono text-lg tabular-nums">
                        {formatClinicTime(appointment.startsAt)}
                    </span>
                    <strong className="text-[22px] leading-7 font-semibold text-white">
                        {appointment.patientFirstName}
                    </strong>
                </p>
                <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <span className="text-sm text-deep-muted">
                        {appointment.serviceName}
                    </span>
                    <StatusBadge
                        status={appointment.status}
                        label={appointment.statusLabel}
                    />
                </div>
            </div>

            <Button asChild variant="secondary" size="lg">
                <Link
                    href={DoctorAppointmentController.show(appointment.ulid)}
                    aria-label={`Open ${appointment.patientFirstName}'s appointment`}
                >
                    Open
                    <ArrowRight aria-hidden />
                </Link>
            </Button>
        </section>
    );
}
