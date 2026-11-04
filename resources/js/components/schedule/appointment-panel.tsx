import { Form } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import StaffAppointmentController from '@/actions/App/Http/Controllers/Staff/AppointmentController';
import AlertError from '@/components/alert-error';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AppointmentFlags from '@/components/schedule/appointment-flags';
import CheckInButton from '@/components/schedule/check-in-button';
import LateLabel from '@/components/schedule/late-label';
import MoveSection from '@/components/schedule/move-section';
import NoShowDialog from '@/components/schedule/no-show-dialog';
import StatusBadge from '@/components/status-badge';
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
import { Label } from '@/components/ui/label';
import { SheetTitle } from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useNow } from '@/hooks/use-now';
import { formatClinicDateTime, formatClinicTime } from '@/lib/clinic-time';
import { lateMinutes } from '@/lib/schedule-time';
import type {
    DaySchedule,
    MoveOptions,
    ScheduleAppointment,
    ScheduleDoctor,
    SelectedAppointment,
} from '@/types';

type Props = {
    schedule: DaySchedule;
    selected: SelectedAppointment;
    /** The Move picker's data, which the Move section asks the server for. See `MoveOptions`. */
    move: MoveOptions | null | undefined;
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
 * The content of the staff schedule's drawer: one booking, and the buttons staff may use on it. It is always the
 * content of a slide-over `Sheet`, on every screen width, so it covers the page and is hard to miss whatever opened it.
 * The sheet brings the card, the close button and the focus trap, and the heading becomes the sheet's title.
 *
 * What the buttons do is decided by the server: `selected.can` hides a button the Policy or the status would refuse,
 * and the Actions refuse again on submit. Only the patient's name is shown (staff and admins don't read health data).
 */
export default function AppointmentPanel({
    schedule,
    selected,
    move,
    viewOnly,
}: Props) {
    const found = findAppointment(schedule, selected.ulid);
    const headingRef = useRef<HTMLHeadingElement>(null);
    const started = useHasStarted(
        found?.appointment.startsAt ?? '',
        selected.hasStarted,
    );
    const now = useNow();

    // Move to the panel when it opens, so a keyboard or screen reader user lands on what they asked for.
    useEffect(() => {
        headingRef.current?.focus();
    }, [selected.ulid]);

    if (!found) {
        return null;
    }

    const { appointment, doctor } = found;
    const canAct =
        selected.can.checkIn ||
        selected.can.markNoShow ||
        selected.can.cancel ||
        selected.can.move;

    // After a button works, the button is gone (the booking has a new status), so focus would drop to the top of the
    // page. The panel heading is the place to go instead.
    const focusHeading = () => headingRef.current?.focus();

    const late = lateMinutes(
        appointment,
        now,
        schedule.date === schedule.today,
    );
    const hasFlags =
        appointment.emergencyFlag || appointment.intakeMissing || late !== null;

    // The sheet labels itself with its title, so the heading needs no id.
    const heading = (
        <h2
            ref={headingRef}
            tabIndex={-1}
            className="text-lg font-semibold wrap-break-word outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
            {appointment.patientName}
        </h2>
    );

    // In sections: who and what status, when and with whom, flags, then the buttons.
    return (
        <div className="divide-y">
            {/* Right padding leaves room for the sheet's own close button. */}
            <div className="flex items-start justify-between gap-3 p-4 pr-12">
                <div className="min-w-0 space-y-2">
                    <StatusBadge
                        status={appointment.status}
                        label={appointment.statusLabel}
                    />
                    <SheetTitle asChild>{heading}</SheetTitle>
                </div>
            </div>

            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 p-4 text-sm">
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

            {hasFlags && (
                <div className="space-y-1.5 p-4">
                    {late !== null && <LateLabel minutes={late} />}
                    <AppointmentFlags
                        appointment={appointment}
                        variant="text"
                    />
                </div>
            )}

            <section aria-label="Actions" className="space-y-3 p-4">
                {selected.can.checkIn && (
                    <CheckInButton
                        appointment={appointment}
                        variant="panel"
                        onCheckedIn={focusHeading}
                    />
                )}

                {selected.can.markNoShow && (
                    <NoShowDialog
                        appointment={appointment}
                        started={started}
                        variant="panel"
                        onDone={focusHeading}
                    />
                )}

                {selected.can.move && (
                    <MoveSection
                        appointment={appointment}
                        doctor={doctor}
                        schedule={schedule}
                        move={move}
                    />
                )}

                {selected.can.cancel && (
                    <CancelDialog appointment={appointment} />
                )}

                {!canAct && (
                    <p className="text-sm text-muted-foreground">
                        {viewOnly
                            ? 'You can look at the schedule. Only front-desk staff can make changes.'
                            : `No actions for a ${appointment.statusLabel.toLowerCase()} appointment.`}
                    </p>
                )}
            </section>
        </div>
    );
}

/** `appointments.cancel_reason` is a VARCHAR(255). The server checks the length again. */
const MAX_REASON_LENGTH = 255;

/**
 * The cancel button and its reason form. Staff must give a reason. On success the booking leaves the grid and the page
 * comes back without a panel, so the dialog goes with it and there is no focus to hand back.
 */
function CancelDialog({ appointment }: { appointment: ScheduleAppointment }) {
    const reasonId = `cancel-reason-${appointment.ulid}`;

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="destructive-outline"
                    className="w-full"
                    data-test="cancel-button"
                >
                    Cancel appointment
                </Button>
            </DialogTrigger>

            <DialogContent>
                <Form
                    {...StaffAppointmentController.cancel.form(
                        appointment.ulid,
                    )}
                    options={{ preserveScroll: true }}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Cancel this appointment?
                                </DialogTitle>
                                <DialogDescription>
                                    {appointment.patientName} will lose the{' '}
                                    {formatClinicTime(appointment.startsAt)}{' '}
                                    {appointment.serviceName} booking and the
                                    time becomes free for other patients.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-2">
                                <Label htmlFor={reasonId}>Reason</Label>
                                <Textarea
                                    id={reasonId}
                                    name="reason"
                                    rows={3}
                                    required
                                    maxLength={MAX_REASON_LENGTH}
                                    aria-invalid={
                                        errors.reason ? true : undefined
                                    }
                                    aria-describedby={
                                        errors.reason
                                            ? `${reasonId}-error`
                                            : undefined
                                    }
                                    className="min-h-20"
                                />
                                <InputError
                                    id={`${reasonId}-error`}
                                    message={errors.reason}
                                />
                            </div>

                            <div role="alert">
                                {errors.appointment && (
                                    <AlertError
                                        errors={[errors.appointment]}
                                        title="The appointment was not cancelled."
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
                                    data-test="confirm-cancel-button"
                                >
                                    {processing && <Spinner />}
                                    Cancel appointment
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
