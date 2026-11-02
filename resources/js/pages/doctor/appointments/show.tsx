import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Siren } from 'lucide-react';
import IntakeAnswersCard from '@/components/doctor/intake-answers-card';
import SummaryCard from '@/components/doctor/summary-card';
import { Alert, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { formatClinicDateTime, formatClinicTime } from '@/lib/clinic-time';
import { home as doctorHome } from '@/routes/doctor';
import type {
    AppointmentStatus,
    DoctorAppointment,
    DoctorIntake,
    IntakeSummary,
} from '@/types';

type Props = {
    appointment: DoctorAppointment;
    /** null when the patient has not filled in the form. */
    intake: DoctorIntake | null;
    /** null when there is no intake, or an intake with no summary row. */
    summary: IntakeSummary | null;
};

const statusVariants: Record<
    AppointmentStatus,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    booked: 'default',
    checked_in: 'secondary',
    completed: 'outline',
    cancelled: 'destructive',
    no_show: 'destructive',
};

export default function DoctorAppointmentShow({
    appointment,
    intake,
    summary,
}: Props) {
    const { patientFirstName, patientAge } = appointment;

    return (
        <>
            <Head title={patientFirstName} />

            <div className="space-y-6 p-4">
                <Link
                    href={doctorHome()}
                    className="inline-flex items-center gap-1.5 rounded-sm text-sm text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <ArrowLeft aria-hidden className="size-4" />
                    Today
                </Link>

                <section
                    aria-labelledby="patient-heading"
                    className="space-y-3 rounded-xl border p-5"
                >
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <h1
                            id="patient-heading"
                            className="text-2xl font-semibold tracking-tight"
                        >
                            {patientFirstName}
                        </h1>
                        {patientAge !== null && (
                            <span className="text-muted-foreground">
                                {patientAge} years old
                            </span>
                        )}
                        {/* Written out, so the status never depends on color alone. */}
                        <Badge variant={statusVariants[appointment.status]}>
                            {appointment.statusLabel}
                        </Badge>
                    </div>
                    <p className="text-sm">
                        {appointment.serviceName} ·{' '}
                        {formatClinicDateTime(appointment.startsAt)} to{' '}
                        {formatClinicTime(appointment.endsAt)}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        All times are Manila time.
                    </p>
                </section>

                {appointment.emergency && (
                    <Alert variant="destructive">
                        <Siren />
                        {/* The title clamps to one line by default. This safety message must always show in full. */}
                        <AlertTitle className="line-clamp-none">
                            The intake has emergency words. Read the answers
                            first.
                        </AlertTitle>
                    </Alert>
                )}

                <div className="grid items-start gap-6 lg:grid-cols-2">
                    <IntakeAnswersCard intake={intake} />
                    <SummaryCard
                        summary={summary}
                        hasIntake={intake !== null}
                        emergency={appointment.emergency}
                    />
                </div>
            </div>
        </>
    );
}

DoctorAppointmentShow.layout = {
    breadcrumbs: [
        {
            title: 'Today',
            href: doctorHome(),
        },
        {
            title: 'Appointment',
            href: doctorHome(),
        },
    ],
};
