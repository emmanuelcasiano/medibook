import { Form } from '@inertiajs/react';
import { AlertTriangle, Check, Info, Sparkles } from 'lucide-react';
import { useRef } from 'react';
import type { ReactNode } from 'react';
import DoctorAppointmentController from '@/actions/App/Http/Controllers/Doctor/AppointmentController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatClinicDateTime } from '@/lib/clinic-time';
import type { IntakeSummary, SummaryDraft } from '@/types';

type Props = {
    /** The appointment the summary belongs to. The "Mark reviewed" button posts to it. */
    appointmentUlid: string;
    /** null when there is no intake, or an intake with no summary row. */
    summary: IntakeSummary | null;
    /** Whether the patient filled in the form at all. */
    hasIntake: boolean;
    /** True when the intake had an emergency phrase, which is why the AI was skipped. */
    emergency: boolean;
};

type BodyProps = Props & {
    /** Called after "Mark reviewed" worked, once the button is gone. */
    onReviewed: () => void;
};

/** The AI draft next to the raw answers. AI text is rendered as text only, never as HTML. */
export default function SummaryCard({
    appointmentUlid,
    summary,
    hasIntake,
    emergency,
}: Props) {
    const headingRef = useRef<HTMLHeadingElement>(null);

    return (
        <section
            aria-labelledby="ai-summary-heading"
            className="space-y-4 rounded-xl border bg-muted/30 p-5"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2
                    id="ai-summary-heading"
                    ref={headingRef}
                    tabIndex={-1}
                    className="flex items-center gap-2 rounded-sm text-lg font-semibold outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <Sparkles aria-hidden className="size-4" />
                    AI summary
                </h2>
                {summary?.status === 'ready' && summary.summary !== null && (
                    <Badge variant="outline">
                        AI draft. The doctor must review.
                    </Badge>
                )}
            </div>

            <SummaryBody
                appointmentUlid={appointmentUlid}
                summary={summary}
                hasIntake={hasIntake}
                emergency={emergency}
                // The button is gone once the review is saved, so focus would drop to the top of the page. The
                // card heading is the place to go instead (the staff panel does the same).
                onReviewed={() => headingRef.current?.focus()}
            />
        </section>
    );
}

function SummaryBody({
    appointmentUlid,
    summary,
    hasIntake,
    emergency,
    onReviewed,
}: BodyProps) {
    if (!hasIntake) {
        return (
            <State
                icon={<Info aria-hidden className="size-5" />}
                title="No intake yet"
            >
                The patient has not filled in the form, so there is nothing to
                summarize.
            </State>
        );
    }

    if (summary === null || summary.summary === null) {
        switch (summary?.status) {
            case 'pending':
                return (
                    <State icon={<Spinner />} title="Preparing summary">
                        This usually takes a few seconds. The answers are
                        complete without it.
                    </State>
                );
            case 'failed':
                return (
                    <State
                        icon={<Info aria-hidden className="size-5" />}
                        title="Summary unavailable"
                    >
                        The AI summary could not be made. Read the patient's
                        answers.
                    </State>
                );
            default:
                return (
                    <State
                        icon={<AlertTriangle aria-hidden className="size-5" />}
                        title={
                            emergency
                                ? 'Summary skipped · emergency words found'
                                : 'No AI summary'
                        }
                    >
                        No answers were sent to the AI. Read the patient's
                        answers.
                    </State>
                );
        }
    }

    return (
        <div className="space-y-4">
            <DraftText draft={summary.summary} />

            <div className="flex flex-wrap items-center justify-between gap-2 border-t pt-3 text-sm text-muted-foreground">
                <span>
                    Made from the intake answers only
                    {summary.model !== null && ` · ${summary.model}`}
                </span>
                {summary.reviewedAt !== null && (
                    <Badge variant="secondary">
                        <Check aria-hidden />
                        Reviewed {formatClinicDateTime(summary.reviewedAt)}
                    </Badge>
                )}
            </div>

            {summary.can.review && (
                <Form
                    {...DoctorAppointmentController.review.form(
                        appointmentUlid,
                    )}
                    options={{ preserveScroll: true }}
                    onSuccess={onReviewed}
                    className="space-y-2"
                >
                    {({ errors, processing }) => (
                        <>
                            {/* The draft the doctor is reading. The server refuses the review if it has changed. */}
                            <input
                                type="hidden"
                                name="version"
                                value={summary.version}
                            />
                            <Button
                                type="submit"
                                disabled={processing}
                                data-test="mark-reviewed-button"
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <Check aria-hidden />
                                )}
                                Mark reviewed
                            </Button>
                            <div role="alert">
                                <InputError
                                    message={errors.summary ?? errors.version}
                                />
                            </div>
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}

function DraftText({ draft }: { draft: SummaryDraft }) {
    return (
        <>
            <p className="text-base font-medium">{draft.headline}</p>

            {draft.checkWithPatient.length > 0 && (
                // The icon and the heading carry the warning, so it does not rely on the amber colour alone.
                <section
                    aria-labelledby="check-with-patient-heading"
                    className="space-y-1 rounded-lg border border-amber-line bg-amber-soft p-3 text-amber-ink"
                >
                    <h3
                        id="check-with-patient-heading"
                        className="flex items-center gap-2 text-sm font-semibold"
                    >
                        <AlertTriangle aria-hidden className="size-4" />
                        Check with the patient
                    </h3>
                    <TextList items={draft.checkWithPatient} />
                </section>
            )}

            {draft.gaps.length > 0 && (
                <Block title="Ask about">
                    <ul className="list-disc space-y-1 pl-5">
                        {draft.gaps.map((gap) => (
                            <li key={gap.key}>{gap.label}</li>
                        ))}
                    </ul>
                </Block>
            )}

            {draft.timeline.length > 0 && (
                <Block title="Timeline">
                    <ol className="list-decimal space-y-1 pl-5">
                        {draft.timeline.map((event, index) => (
                            <li key={index}>{event}</li>
                        ))}
                    </ol>
                </Block>
            )}

            {draft.inEnglish !== null && (
                <Block title="In English · AI translation">
                    <p>{draft.inEnglish}</p>
                </Block>
            )}
        </>
    );
}

function Block({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="space-y-1">
            <h3 className="text-sm font-medium text-muted-foreground">
                {title}
            </h3>
            {children}
        </div>
    );
}

function TextList({ items }: { items: string[] }) {
    return (
        <ul className="list-disc space-y-1 pl-5">
            {items.map((item, index) => (
                <li key={index}>{item}</li>
            ))}
        </ul>
    );
}

function State({
    icon,
    title,
    children,
}: {
    icon: ReactNode;
    title: string;
    children: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center gap-2 rounded-lg bg-background p-6 text-center">
            <span className="text-muted-foreground">{icon}</span>
            <p className="font-medium">{title}</p>
            <p className="text-sm text-muted-foreground">{children}</p>
        </div>
    );
}
