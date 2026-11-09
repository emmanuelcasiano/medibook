import { Form } from '@inertiajs/react';
import { Lock, Mail } from 'lucide-react';
import { useEffect, useRef } from 'react';
import FollowUpController from '@/actions/App/Http/Controllers/Staff/FollowUpController';
import AlertError from '@/components/alert-error';
import FollowUpBookSection from '@/components/follow-ups/follow-up-book-section';
import FollowUpStateBadge from '@/components/follow-ups/follow-up-state-badge';
import LogCallDialog from '@/components/follow-ups/log-call-dialog';
import { Button } from '@/components/ui/button';
import { SheetTitle } from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { formatClinicDate, formatClinicDateTime } from '@/lib/clinic-time';
import type { FollowUpBookingOptions, StaffFollowUpPanel } from '@/types';

type Props = {
    followUp: StaffFollowUpPanel;
    /** The Book picker's data, which the Book section asks the server for. See `FollowUpBookingOptions`. */
    book: FollowUpBookingOptions | null | undefined;
};

/**
 * The content of the Follow-ups drawer: one follow-up, and the buttons staff may use on it. It is the content of a
 * slide-over `Sheet`, like the schedule's panel, so it brings the card, the close button and the focus trap.
 *
 * What the buttons do is decided by the server: `canManage` hides them from an admin and from a follow-up that is no
 * longer open, and the Actions refuse again on submit. The doctor's note is not here and is not sent: it is health text.
 */
export default function FollowUpPanel({ followUp, book }: Props) {
    const headingRef = useRef<HTMLHeadingElement>(null);

    // Move to the panel when it opens, so a keyboard or screen reader user lands on what they asked for.
    useEffect(() => {
        headingRef.current?.focus();
    }, [followUp.ulid]);

    const closed =
        followUp.state === 'booked' || followUp.state === 'dismissed';

    return (
        <div className="divide-y">
            {/* Right padding leaves room for the sheet's own close button. */}
            <div className="space-y-2 p-4 pr-12">
                <FollowUpStateBadge
                    state={followUp.state}
                    label={followUp.stateLabel}
                />
                <SheetTitle asChild>
                    <h2
                        ref={headingRef}
                        tabIndex={-1}
                        className="text-lg font-semibold wrap-break-word outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        {followUp.patientName}
                    </h2>
                </SheetTitle>
                <p className="text-sm break-all text-muted-foreground">
                    {followUp.patientEmail}
                </p>
            </div>

            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 p-4 text-sm">
                <dt className="text-muted-foreground">Visit type</dt>
                <dd>
                    {followUp.serviceName} · {followUp.durationMinutes} min
                </dd>
                <dt className="text-muted-foreground">With</dt>
                <dd>
                    {followUp.sameDoctor ? followUp.doctorName : 'Any doctor'}
                </dd>
                <dt className="text-muted-foreground">Between</dt>
                <dd>
                    {formatClinicDate(followUp.windowStartsOn)} and{' '}
                    {formatClinicDate(followUp.windowEndsOn)}
                </dd>
                <dt className="text-muted-foreground">From visit</dt>
                <dd>{formatClinicDateTime(followUp.sourceVisitAt)}</dd>
            </dl>

            <p className="flex gap-2 bg-muted/40 p-4 text-xs text-muted-foreground">
                <Lock aria-hidden className="mt-0.5 size-3.5 shrink-0" />
                <span>
                    The doctor&apos;s note is private to the patient and the
                    doctor.
                </span>
            </p>

            <section aria-label="Actions" className="space-y-3 p-4">
                {followUp.canManage ? (
                    <>
                        <FollowUpBookSection followUp={followUp} book={book} />
                        <div className="grid grid-cols-2 gap-2">
                            <LogCallDialog followUp={followUp} />
                            <ReminderButton followUp={followUp} />
                        </div>
                    </>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        {closed
                            ? 'This follow-up is closed, so there is nothing left to do.'
                            : 'You can look at the follow-ups. Only front-desk staff can make changes.'}
                    </p>
                )}
            </section>

            <section
                aria-labelledby="follow-up-history"
                className="space-y-3 p-4"
            >
                <h3
                    id="follow-up-history"
                    className="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    History
                </h3>
                {followUp.history.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nothing has been done yet.
                    </p>
                ) : (
                    <ol className="space-y-3 border-l pl-4">
                        {followUp.history.map((item) => (
                            <li key={item.id} className="text-sm">
                                <span>{item.what}</span>
                                <span className="block text-xs text-muted-foreground">
                                    {formatClinicDateTime(item.at)}
                                </span>
                            </li>
                        ))}
                    </ol>
                )}
            </section>
        </div>
    );
}

/**
 * "Send reminder": one click, no input. The email holds the doctor, the visit type and the dates (no health details). The
 * server refuses a second one the same day, which is what stops a double click from sending two.
 */
function ReminderButton({ followUp }: { followUp: StaffFollowUpPanel }) {
    return (
        <Form
            {...FollowUpController.remind.form(followUp.ulid)}
            options={{ preserveScroll: true }}
            className="space-y-2"
        >
            {({ errors, processing }) => (
                <>
                    <Button
                        type="submit"
                        variant="outline"
                        className="w-full"
                        disabled={processing}
                        data-test="send-reminder-button"
                    >
                        {processing ? <Spinner /> : <Mail aria-hidden />}
                        Send reminder
                    </Button>
                    <div role="alert">
                        {errors.follow_up && (
                            <AlertError
                                errors={[errors.follow_up]}
                                title="No reminder was sent."
                            />
                        )}
                    </div>
                </>
            )}
        </Form>
    );
}
