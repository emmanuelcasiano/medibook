import { Lock } from 'lucide-react';
import type { IntakeAnswers, IntakeDurationOption } from '@/types';

type Props = {
    answers: IntakeAnswers;
    durations: IntakeDurationOption[];
};

const NOT_GIVEN = 'Not given';

/** The saved answers, read-only. Shown once the appointment is checked in and the form is closed. */
export default function IntakeAnswersView({ answers, durations }: Props) {
    const duration = durations.find(
        (option) => option.value === answers.duration,
    )?.label;

    const yesNo = (value: boolean) => (value ? 'Yes' : 'No');

    const rows: { label: string; value: string }[] = [
        { label: 'Reason for your visit', value: answers.reason },
        { label: 'Your symptoms', value: answers.symptoms ?? NOT_GIVEN },
        { label: 'How long you have had this', value: duration ?? NOT_GIVEN },
        { label: 'Allergies', value: answers.allergies ?? NOT_GIVEN },
        {
            label: 'Medicines you take now',
            value: answers.medicines ?? NOT_GIVEN,
        },
        { label: 'Fever', value: yesNo(answers.fever) },
        { label: 'Pregnant, or might be', value: yesNo(answers.pregnant) },
        { label: 'Recent surgery', value: yesNo(answers.recent_surgery) },
    ];

    return (
        <section
            aria-labelledby="intake-answers-heading"
            className="space-y-4 rounded-xl border bg-card p-5"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2
                    id="intake-answers-heading"
                    className="text-lg font-semibold"
                >
                    Your intake answers
                </h2>
                <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
                    <Lock aria-hidden className="size-3.5" />
                    Can no longer be changed
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
