import { ClipboardList } from 'lucide-react';
import { formatClinicDateTime } from '@/lib/clinic-time';
import type { DoctorIntake } from '@/types';

type Props = {
    /** null when the patient has not filled in the form. */
    intake: DoctorIntake | null;
};

const NOT_GIVEN = 'Not given';

/** The patient's own words, read-only. Always shown, whatever the state of the AI summary. */
export default function IntakeAnswersCard({ intake }: Props) {
    if (intake === null) {
        return (
            <section
                aria-labelledby="intake-answers-heading"
                className="space-y-3 rounded-xl border bg-card p-5"
            >
                <h2
                    id="intake-answers-heading"
                    className="text-lg font-semibold"
                >
                    Intake answers
                </h2>
                <div className="flex flex-col items-center gap-2 rounded-lg bg-muted/50 p-6 text-center">
                    <ClipboardList
                        aria-hidden
                        className="size-6 text-muted-foreground"
                    />
                    <p className="font-medium">Not filled in</p>
                    <p className="text-sm text-muted-foreground">
                        The patient has not filled in the intake form yet.
                    </p>
                </div>
            </section>
        );
    }

    const { answers } = intake;
    const yesNo = (value: boolean) => (value ? 'Yes' : 'No');

    const rows: { label: string; value: string }[] = [
        { label: 'Reason for the visit', value: answers.reason },
        { label: 'Symptoms', value: answers.symptoms ?? NOT_GIVEN },
        { label: 'How long', value: intake.durationLabel ?? NOT_GIVEN },
        { label: 'Allergies', value: answers.allergies ?? NOT_GIVEN },
        { label: 'Medicines now', value: answers.medicines ?? NOT_GIVEN },
        { label: 'Fever', value: yesNo(answers.fever) },
        { label: 'Pregnant, or might be', value: yesNo(answers.pregnant) },
        { label: 'Recent surgery', value: yesNo(answers.recent_surgery) },
    ];

    return (
        <section
            aria-labelledby="intake-answers-heading"
            className="space-y-4 rounded-xl border bg-card p-5"
        >
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2
                    id="intake-answers-heading"
                    className="text-lg font-semibold"
                >
                    Intake answers
                </h2>
                <span className="text-sm text-muted-foreground">
                    From the patient ·{' '}
                    {formatClinicDateTime(intake.submittedAt)}
                </span>
            </div>

            <dl className="grid gap-4">
                {rows.map(({ label, value }) => (
                    <div key={label} className="grid gap-1">
                        <dt className="text-sm font-medium text-muted-foreground">
                            {label}
                        </dt>
                        {/* Patient text: rendered as text only, never as HTML. */}
                        <dd className="whitespace-pre-line">{value}</dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}
