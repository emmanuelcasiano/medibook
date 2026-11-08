import { Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import IntakeSection from '@/components/intake/intake-section';
import type { IntakeAnswers, IntakeDurationOption } from '@/types';

type Props = {
    answers: IntakeAnswers;
    durations: IntakeDurationOption[];
};

const NOT_GIVEN = 'Not given';

const yesNo = (value: boolean) => (value ? 'Yes' : 'No');

/** The saved answers, read-only. Shown once the appointment is checked in and the form is closed. */
export default function IntakeAnswersView({ answers, durations }: Props) {
    const duration = durations.find(
        (option) => option.value === answers.duration,
    )?.label;

    return (
        <div className="grid gap-6">
            <p className="flex items-center gap-2 rounded-lg border bg-muted/50 px-4 py-3 text-sm text-muted-foreground">
                <Lock aria-hidden className="size-4 shrink-0" />
                Can no longer be changed
            </p>

            <IntakeSection title="About this visit">
                <dl className="grid gap-4">
                    <Answer label="Reason for your visit">
                        {answers.reason}
                    </Answer>
                </dl>
            </IntakeSection>

            <IntakeSection title="Symptoms">
                <dl className="grid gap-4">
                    <Answer label="Your symptoms">
                        {answers.symptoms ?? NOT_GIVEN}
                    </Answer>
                    <Answer label="How long you have had this">
                        {duration ?? NOT_GIVEN}
                    </Answer>
                </dl>
            </IntakeSection>

            <IntakeSection title="Health background">
                <dl className="grid gap-4">
                    <Answer label="Allergies">
                        {answers.allergies ?? NOT_GIVEN}
                    </Answer>
                    <Answer label="Medicines you take now">
                        {answers.medicines ?? NOT_GIVEN}
                    </Answer>
                </dl>
                {/* The grid is the list itself: a <dl> may only hold <dt>/<dd> pairs (or one <div> around each pair). */}
                <dl className="grid grid-cols-1 gap-4 border-t pt-4 sm:grid-cols-3">
                    <Answer label="Fever">{yesNo(answers.fever)}</Answer>
                    <Answer label="Pregnant, or might be">
                        {yesNo(answers.pregnant)}
                    </Answer>
                    <Answer label="Recent surgery">
                        {yesNo(answers.recent_surgery)}
                    </Answer>
                </dl>
            </IntakeSection>
        </div>
    );
}

function Answer({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-1">
            <dt className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                {label}
            </dt>
            {/* Patient text: rendered as text only, never as HTML. */}
            <dd className="whitespace-pre-line">{children}</dd>
        </div>
    );
}
