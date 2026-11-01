import { Form, Link } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import StaffAppointmentController from '@/actions/App/Http/Controllers/Staff/AppointmentController';
import ScheduleController from '@/actions/App/Http/Controllers/Staff/ScheduleController';
import AlertError from '@/components/alert-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { formatClinicDateTime, formatClinicTime } from '@/lib/clinic-time';
import type {
    DaySchedule,
    ScheduleAppointment,
    ScheduleDoctor,
    SelectedAppointment,
} from '@/types';

type Props = {
    schedule: DaySchedule;
    selected: SelectedAppointment;
    /** An admin may look at the schedule but not change it. */
    viewOnly: boolean;
};

/** The longest delay `setTimeout` accepts (about 24.8 days). A longer one fires at once. */
const MAX_TIMEOUT_MS = 2 ** 31 - 1;

/** The booking and the doctor's column it sits in, found by its ULID in the schedule the page already holds. */
function findAppointment(
    schedule: DaySchedule,
    ulid: string,
): { appointment: ScheduleAppointment; doctor: ScheduleDoctor } | null {
    for (const doctor of schedule.doctors) {
        const appointment = doctor.appointments.find(
            (candidate) => candidate.ulid === ulid,
        );

        if (appointment) {
            return { appointment, doctor };
        }
    }

    return null;
}

/**
 * Whether the visit has started. The server's answer is used first. If the page stays open past the start time, the
 * browser's clock turns it on without a reload. The server still refuses a no-show that is too early, so a browser
 * clock that runs fast only gets the person an error message.
 */
function useHasStarted(startsAt: string, serverSaysStarted: boolean): boolean {
    const [clockSaysStarted, setClockSaysStarted] = useState(
        () => Date.now() >= Date.parse(startsAt),
    );

    useEffect(() => {
        const delay = Date.parse(startsAt) - Date.now();

        if (clockSaysStarted || delay > MAX_TIMEOUT_MS) {
            return;
        }

        const timer = window.setTimeout(
            () => setClockSaysStarted(true),
            Math.max(delay, 0),
        );

        return () => window.clearTimeout(timer);
    }, [clockSaysStarted, startsAt]);

    return serverSaysStarted || clockSaysStarted;
}

/**
 * The side panel of the staff schedule: one booking, and the buttons staff may use on it. What the buttons do is
 * decided by the server: `selected.can` hides a button the Policy or the status would refuse, and the Actions
 * refuse again on submit. Only the patient's name is shown (staff and admins don't read health data).
 */
export default function AppointmentPanel({
    schedule,
    selected,
    viewOnly,
}: Props) {
    const found = findAppointment(schedule, selected.ulid);
    const headingRef = useRef<HTMLHeadingElement>(null);
    const started = useHasStarted(
        found?.appointment.startsAt ?? '',
        selected.hasStarted,
    );

    // Move to the panel when it opens, so a keyboard or screen reader user lands on what they asked for.
    useEffect(() => {
        headingRef.current?.focus();
    }, [selected.ulid]);

    if (!found) {
        return null;
    }

    const { appointment, doctor } = found;
    const canAct = selected.can.checkIn || selected.can.markNoShow;

    // After a button works, the button is gone (the booking has a new status), so focus would drop to the top of the
    // page. The panel heading is the place to go instead.
    const focusHeading = () => headingRef.current?.focus();

    return (
        <aside
            aria-labelledby="appointment-panel-heading"
            className="space-y-5 rounded-xl border p-4 md:w-80 md:shrink-0"
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 space-y-2">
                    <Badge variant="outline">{appointment.statusLabel}</Badge>
                    <h2
                        id="appointment-panel-heading"
                        ref={headingRef}
                        tabIndex={-1}
                        className="text-lg font-semibold break-words outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        {appointment.patientName}
                    </h2>
                </div>
                <Button asChild variant="ghost" size="icon">
                    <Link
                        href={ScheduleController.index({
                            query: { date: schedule.date },
                        })}
                        preserveScroll
                        preserveState
                        aria-label="Close the appointment panel"
                    >
                        <X aria-hidden />
                    </Link>
                </Button>
            </div>

            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                <dt className="text-muted-foreground">Time</dt>
                <dd>
                    {formatClinicDateTime(appointment.startsAt)} to{' '}
                    {formatClinicTime(appointment.endsAt)}
                </dd>
                <dt className="text-muted-foreground">Doctor</dt>
                <dd>{doctor.name}</dd>
                <dt className="text-muted-foreground">Service</dt>
                <dd>{appointment.serviceName}</dd>
            </dl>

            <section aria-label="Actions" className="space-y-3">
                {selected.can.checkIn && (
                    <Form
                        {...StaffAppointmentController.checkIn.form(
                            appointment.ulid,
                        )}
                        options={{ preserveScroll: true }}
                        onSuccess={focusHeading}
                        className="space-y-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Button
                                    type="submit"
                                    className="w-full"
                                    disabled={processing}
                                    data-test="check-in-button"
                                >
                                    {processing ? (
                                        <Spinner />
                                    ) : (
                                        <Check aria-hidden />
                                    )}
                                    Check in
                                </Button>
                                <div role="alert">
                                    {errors.appointment && (
                                        <AlertError
                                            errors={[errors.appointment]}
                                            title="The patient was not checked in."
                                        />
                                    )}
                                </div>
                            </>
                        )}
                    </Form>
                )}

                {selected.can.markNoShow && (
                    <NoShowDialog
                        appointment={appointment}
                        started={started}
                        onDone={focusHeading}
                    />
                )}

                {!canAct && (
                    <p className="text-sm text-muted-foreground">
                        {viewOnly
                            ? 'You can look at the schedule. Only front-desk staff can make changes.'
                            : `No actions for a ${appointment.statusLabel.toLowerCase()} appointment.`}
                    </p>
                )}
            </section>
        </aside>
    );
}

type NoShowDialogProps = {
    appointment: ScheduleAppointment;
    started: boolean;
    /** Called after the no-show is saved, because the button that opened the dialog is gone by then. */
    onDone: () => void;
};

/**
 * The no-show button and its confirmation. A no-show frees the slot and can't be undone from here, so it asks first.
 * Until the start time the button is off, and a line says when it turns on.
 */
function NoShowDialog({ appointment, started, onDone }: NoShowDialogProps) {
    const saved = useRef(false);
    const hintId = `no-show-hint-${appointment.ulid}`;

    // Normally the dialog hands focus back to its button. After a successful no-show that button is gone, so the
    // panel heading takes the focus instead.
    function returnFocus(event: Event) {
        if (!saved.current) {
            return;
        }

        event.preventDefault();
        onDone();
    }

    return (
        <div className="space-y-1">
            <Dialog>
                <DialogTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        className="w-full"
                        disabled={!started}
                        aria-describedby={started ? undefined : hintId}
                        data-test="no-show-button"
                    >
                        Mark no-show
                    </Button>
                </DialogTrigger>

                <DialogContent onCloseAutoFocus={returnFocus}>
                    <Form
                        {...StaffAppointmentController.noShow.form(
                            appointment.ulid,
                        )}
                        options={{ preserveScroll: true }}
                        onSuccess={() => {
                            saved.current = true;
                        }}
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <DialogHeader>
                                    <DialogTitle>
                                        Mark as a no-show?
                                    </DialogTitle>
                                    <DialogDescription>
                                        {appointment.patientName} did not arrive
                                        for{' '}
                                        {formatClinicTime(appointment.startsAt)}
                                        . The time becomes free for other
                                        patients.
                                    </DialogDescription>
                                </DialogHeader>

                                <div role="alert">
                                    {errors.appointment && (
                                        <AlertError
                                            errors={[errors.appointment]}
                                            title="This was not saved."
                                        />
                                    )}
                                </div>

                                <DialogFooter>
                                    <DialogClose asChild>
                                        <Button type="button" variant="outline">
                                            Go back
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                        data-test="confirm-no-show-button"
                                    >
                                        {processing && <Spinner />}
                                        Mark no-show
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>

            {!started && (
                <p id={hintId} className="text-xs text-muted-foreground">
                    Available from {formatClinicTime(appointment.startsAt)}.
                </p>
            )}
        </div>
    );
}
