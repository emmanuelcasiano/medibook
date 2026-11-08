import { Head } from '@inertiajs/react';
import { Siren } from 'lucide-react';
import IntakeAnswersView from '@/components/intake/intake-answers';
import IntakeForm from '@/components/intake/intake-form';
import PageHeader from '@/components/page-header';
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

            <div className="space-y-6 p-4">
                {/* The one large red block on the patient side. The text is the exact wording of PROJECT.md F5. */}
                {emergency && (
                    <section
                        role="alert"
                        className="flex items-start gap-4 rounded-xl bg-destructive p-5 text-white"
                    >
                        <Siren aria-hidden className="mt-0.5 size-7 shrink-0" />
                        <p className="text-lg leading-7 font-semibold">
                            This may be an emergency. Call 911 or go to the
                            nearest emergency room now.
                        </p>
                    </section>
                )}

                <div className="max-w-2xl space-y-6">
                    <PageHeader
                        title="Intake form"
                        description="Your doctor reads this before your visit. Short, plain answers are fine."
                    />

                    <section
                        aria-label="Your appointment"
                        className="space-y-1 rounded-xl border bg-card p-4"
                    >
                        <p className="font-mono text-base font-medium tabular-nums">
                            {formatClinicDateTime(startsAt)}
                        </p>
                        <p>{service.name}</p>
                        <p className="text-sm text-muted-foreground">
                            {doctor.name} · {doctor.specialty}
                        </p>
                    </section>

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
                            <h2 className="text-lg font-semibold">
                                Not filled in
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                This visit is no longer open for an intake form.
                                Please tell the front desk anything your doctor
                                should know.
                            </p>
                        </section>
                    )}
                </div>
            </div>
        </>
    );
}
