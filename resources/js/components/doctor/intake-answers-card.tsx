import { ClipboardList, ShieldAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { formatClinicDateTime } from '@/lib/clinic-time';
import { cn } from '@/lib/utils';
import type { DoctorIntake } from '@/types';

type Props = {
    /** null when the patient has not filled in the form. */
    intake: DoctorIntake | null;
};

const NOT_GIVEN = 'Not given';

const yesNo = (value: boolean) => (value ? 'Yes' : 'No');

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

            {/* The safety answers, picked by their fixed keys (not by matching text), shown in full. */}
            <section
                aria-labelledby="check-first-heading"
                className="space-y-4 rounded-lg border border-amber-line bg-amber-soft p-4 text-amber-ink"
            >
                <h3
                    id="check-first-heading"
                    className="flex items-center gap-2 text-sm font-semibold"
                >
                    <ShieldAlert aria-hidden className="size-4" />
                    Check first
                </h3>
                {/* The grid is the list itself: a <dl> may only hold <dt>/<dd> pairs (or one <div> around each pair). */}
                <dl className="grid grid-cols-2 gap-4">
                    <Answer label="Allergies" className="col-span-2">
                        {answers.allergies ?? NOT_GIVEN}
                    </Answer>
                    <Answer label="Medicines now" className="col-span-2">
                        {answers.medicines ?? NOT_GIVEN}
                    </Answer>
                    <Answer label="Fever">{yesNo(answers.fever)}</Answer>
                    <Answer label="Pregnant, or might be">
                        {yesNo(answers.pregnant)}
                    </Answer>
                </dl>
            </section>

            <dl className="grid gap-4">
                <Answer label="Reason for the visit" plain>
                    {answers.reason}
                </Answer>
                <Answer label="Symptoms" plain>
                    {answers.symptoms ?? NOT_GIVEN}
                </Answer>
            </dl>

            <dl className="grid grid-cols-2 gap-4 border-t pt-4">
                <Answer label="How long" plain>
                    {intake.durationLabel ?? NOT_GIVEN}
                </Answer>
                <Answer label="Recent surgery" plain>
                    {yesNo(answers.recent_surgery)}
                </Answer>
            </dl>
        </section>
    );
}

/** One label and its answer. `plain` is for the page's own colors; the default inherits the Check first colors. */
function Answer({
    label,
    plain = false,
    className,
    children,
}: {
    label: string;
    plain?: boolean;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('grid gap-1', className)}>
            <dt
                className={
                    plain
                        ? 'text-xs font-semibold tracking-wider text-muted-foreground uppercase'
                        : 'text-xs font-semibold tracking-wider uppercase'
                }
            >
                {label}
            </dt>
            {/* Patient text: rendered as text only, never as HTML. */}
            <dd
                className={
                    plain
                        ? 'whitespace-pre-line'
                        : 'font-medium whitespace-pre-line text-foreground'
                }
            >
                {children}
            </dd>
        </div>
    );
}
