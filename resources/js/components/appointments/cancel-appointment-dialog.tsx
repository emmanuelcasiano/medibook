import { Form } from '@inertiajs/react';
import { useRef } from 'react';
import AppointmentController from '@/actions/App/Http/Controllers/Patient/AppointmentController';
import AlertError from '@/components/alert-error';
import InputError from '@/components/input-error';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatClinicDateTime } from '@/lib/clinic-time';
import type { MyAppointment } from '@/types';

type Props = {
    appointment: MyAppointment;
};

/**
 * The cancel button and its confirmation dialog. The dialog traps focus and closes on Esc. The server decides: it
 * may still refuse (too late, already cancelled), and then the message shows here and the dialog stays open.
 */
export default function CancelAppointmentDialog({ appointment }: Props) {
    const when = formatClinicDateTime(appointment.startsAt);
    const reasonId = `cancel-reason-${appointment.ulid}`;
    const cancelled = useRef(false);

    // Normally the dialog hands focus back to its button. After a successful cancel that card is gone, so focus
    // would drop to the top of the page. The list heading is marked as the place to go instead.
    function returnFocus(event: Event) {
        if (!cancelled.current) {
            return;
        }

        event.preventDefault();
        document
            .querySelector<HTMLElement>('[data-after-cancel-focus]')
            ?.focus();
    }

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test="cancel-appointment-button"
                >
                    Cancel
                    <span className="sr-only"> appointment on {when}</span>
                </Button>
            </DialogTrigger>

            <DialogContent onCloseAutoFocus={returnFocus}>
                <Form
                    {...AppointmentController.cancel.form(appointment.ulid)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        cancelled.current = true;
                    }}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Cancel this appointment?
                                </DialogTitle>
                                <DialogDescription>
                                    {appointment.service.name} with{' '}
                                    {appointment.doctor.name} on {when} (Manila
                                    time). The time becomes free for other
                                    patients.
                                </DialogDescription>
                            </DialogHeader>

                            <div role="alert">
                                {errors.appointment && (
                                    <AlertError
                                        errors={[errors.appointment]}
                                        title="This appointment was not cancelled."
                                    />
                                )}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor={reasonId}>
                                    Reason (optional)
                                </Label>
                                <Input
                                    id={reasonId}
                                    name="reason"
                                    maxLength={255}
                                    autoComplete="off"
                                    aria-invalid={!!errors.reason}
                                    aria-describedby={
                                        errors.reason
                                            ? `${reasonId}-error`
                                            : undefined
                                    }
                                />
                                <InputError
                                    id={`${reasonId}-error`}
                                    message={errors.reason}
                                />
                            </div>

                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Keep appointment
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
