import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Siren } from 'lucide-react';
import { useRef } from 'react';
import FinishVisitForm from '@/components/doctor/finish-visit-form';
import IntakeAnswersCard from '@/components/doctor/intake-answers-card';
import SummaryCard from '@/components/doctor/summary-card';
import VisitNoteCard from '@/components/doctor/visit-note-card';
import { Alert, AlertTitle } from '@/components/ui/alert';
import StatusBadge from '@/components/status-badge';
import { formatClinicDateTime, formatClinicTime } from '@/lib/clinic-time';
import { home as doctorHome } from '@/routes/doctor';
import type {
    DoctorAppointment,
    DoctorIntake,
    IntakeSummary,
    VisitNote,
} from '@/types';

type Props = {
    appointment: DoctorAppointment;
    /** null when the patient has not filled in the form. */
    intake: DoctorIntake | null;
    /** null when there is no intake, or an intake with no summary row. */
    summary: IntakeSummary | null;
    /** null until the doctor saves the first note. Only ever the signed-in doctor's own. */
    visitNote: VisitNote | null;
    /** False for a booking that has not started, a cancellation and a no-show. The server checks again on save. */
    canSaveVisitNote: boolean;
};

export default function DoctorAppointmentShow({
    appointment,
    intake,
    summary,
    visitNote,
    canSaveVisitNote,
}: Props) {
    const { patientFirstName, patientAge } = appointment;
    const headingRef = useRef<HTMLHeadingElement>(null);

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
                    className="space-y-3 rounded-xl border bg-card p-5"
                >
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <h1
                            id="patient-heading"
                            ref={headingRef}
                            tabIndex={-1}
                            className="rounded-sm text-2xl font-semibold tracking-tight outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            {patientFirstName}
                        </h1>
                        {patientAge !== null && (
                            <span className="text-muted-foreground">
                                {patientAge} years old
                            </span>
                        )}
                        {/* Written out, so the status never depends on color alone. */}
                        <StatusBadge
                            status={appointment.status}
                            label={appointment.statusLabel}
                        />
                    </div>
                    <p className="text-sm">
                        {appointment.serviceName} ·{' '}
                        {formatClinicDateTime(appointment.startsAt)} to{' '}
                        {formatClinicTime(appointment.endsAt)}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        All times are Manila time.
                    </p>
                    {appointment.can.complete && (
                        <FinishVisitForm
                            appointmentUlid={appointment.ulid}
                            // The button is gone once the visit is finished, so focus would drop to the top of the
                            // page. The patient heading is the place to go instead.
                            onFinished={() => headingRef.current?.focus()}
                        />
                    )}
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
                        appointmentUlid={appointment.ulid}
                        summary={summary}
                        hasIntake={intake !== null}
                        emergency={appointment.emergency}
                    />
                </div>

                <VisitNoteCard
                    appointmentUlid={appointment.ulid}
                    note={visitNote}
                    canSave={canSaveVisitNote}
                />
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
