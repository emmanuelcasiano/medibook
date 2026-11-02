import { Head } from '@inertiajs/react';
import { Siren } from 'lucide-react';
import IntakeAnswersView from '@/components/intake/intake-answers';
import IntakeForm from '@/components/intake/intake-form';
import PageHeader from '@/components/page-header';
import { Alert, AlertTitle } from '@/components/ui/alert';
import { formatClinicDateTime } from '@/lib/clinic-time';
import type {
    IntakeAppointment,
    IntakeDurationOption,
    SavedIntake,
} from '@/types';

type Props = {
    appointment: IntakeAppointment;
    /** null until the patient saves the form for the first time. */
    intake: SavedIntake | null;
    durations: IntakeDurationOption[];
    /** True when the saved answers have an emergency phrase. Set by the server, never by this page. */
    emergency: boolean;
    /** Computed on the server. React only shows or hides the form; SaveIntake checks the Policy again on submit. */
    can: { save: boolean };
};

export default function MyAppointmentsIntake({
    appointment,
    intake,
    durations,
    emergency,
    can,
}: Props) {
    const { service, doctor, startsAt } = appointment;

    return (
        <>
            <Head title="Intake form" />

            <div className="max-w-2xl space-y-6 p-4">
                <PageHeader
                    title="Intake form"
                    description={
                        <>
                            <p>
                                Your doctor reads this before your visit. Short,
                                plain answers are fine.
                            </p>
                            <p className="text-foreground">
                                {service.name} with {doctor.name} ·{' '}
                                {formatClinicDateTime(startsAt)}
                            </p>
                        </>
                    }
                />

                {emergency && (
                    <Alert variant="destructive">
                        <Siren />
                        {/* The title clamps to one line by default. This safety message must always show in full. */}
                        <AlertTitle className="line-clamp-none">
                            This may be an emergency. Call 911 or go to the
                            nearest emergency room now.
                        </AlertTitle>
                    </Alert>
                )}

                {can.save ? (
                    <>
                        <p className="text-sm text-muted-foreground">
                            Demo with fake data. Do not enter real health
                            information.
                        </p>
                        {/* A new key remounts the form after a save, so it shows what was stored. */}
                        <IntakeForm
                            key={intake?.submittedAt ?? 'new'}
                            appointmentUlid={appointment.ulid}
                            answers={intake?.answers ?? null}
                            durations={durations}
                        />
                    </>
                ) : intake ? (
                    <IntakeAnswersView
                        answers={intake.answers}
                        durations={durations}
                    />
                ) : (
                    <section className="space-y-2 rounded-xl border bg-muted/50 p-5">
                        <h2 className="text-lg font-semibold">Not filled in</h2>
                        <p className="text-sm text-muted-foreground">
                            This visit is no longer open for an intake form.
                            Please tell the front desk anything your doctor
                            should know.
                        </p>
                    </section>
                )}
            </div>
        </>
    );
}
