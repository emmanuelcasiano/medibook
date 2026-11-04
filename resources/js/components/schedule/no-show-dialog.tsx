import { Form } from '@inertiajs/react';
import { useRef } from 'react';
import StaffAppointmentController from '@/actions/App/Http/Controllers/Staff/AppointmentController';
import AlertError from '@/components/alert-error';
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
import { formatClinicTime } from '@/lib/clinic-time';
import { cn } from '@/lib/utils';
import type { ScheduleAppointment } from '@/types';

type Props = {
    appointment: ScheduleAppointment;
    started: boolean;
    /** `panel`: the full-width button of the side panel. `row`: the small button of an arrivals list row. */
    variant: 'panel' | 'row';
    /** Called after the no-show is saved, because the button that opened the dialog is gone by then. */
    onDone: () => void;
};

/**
 * The no-show button and its confirmation, for every place staff can mark a no-show. A no-show frees the slot and
 * can't be undone from here, so it always asks first: one click never changes a booking. Until the start time the
 * button is off, and a line says when it turns on. The caller renders it only when the Policy allows it (not for an
 * admin, and only for a `booked` booking); the Action checks both again, and the start time, on submit.
 */
export default function NoShowDialog({
    appointment,
    started,
    variant,
    onDone,
}: Props) {
    const saved = useRef(false);
    const hintId = `no-show-hint-${appointment.ulid}`;
    const compact = variant === 'row';
    const time = formatClinicTime(appointment.startsAt);

    // Normally the dialog hands focus back to its button. After a successful no-show that button is gone, so the
    // caller's focus target takes it instead.
    function returnFocus(event: Event) {
        if (!saved.current) {
            return;
        }

        event.preventDefault();
        onDone();
    }

    return (
        <div className={cn('space-y-1', compact && 'w-full md:w-auto')}>
            <Dialog>
                <DialogTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        className={compact ? 'w-full md:w-auto' : 'w-full'}
                        disabled={!started}
                        aria-describedby={started ? undefined : hintId}
                        aria-label={
                            compact
                                ? `Mark ${appointment.patientName} at ${time} as a no-show`
                                : undefined
                        }
                        data-test={
                            compact ? 'row-no-show-button' : 'no-show-button'
                        }
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
                                        for {time}. The time becomes free for
                                        other patients.
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
                    Available from {time}.
                </p>
            )}
        </div>
    );
}
