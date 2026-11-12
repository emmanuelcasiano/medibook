import { Form } from '@inertiajs/react';
import { Check, Siren } from 'lucide-react';
import { useRef, useState } from 'react';
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
    /** `panel`: the full-width button of the side panel. `row`: the small button of a search result. */
    variant: 'panel' | 'row';
    /**
     * Called after the check-in is saved. The button is gone by then (the booking has a new status), so the caller
     * decides where the focus goes next.
     */
    onCheckedIn: () => void;
};

/**
 * The Check in button, for every place staff can check a booking in. The caller renders it only when the Policy
 * allows it (not for an admin, and only for a `booked` booking); the server checks both again on submit.
 *
 * Checking in locks the intake form, so a booking with no intake asks first. This is guidance for the front desk and
 * not a security control: the server still allows a check-in at any time while `booked`. The emergency flag is
 * information and never blocks.
 */
export default function CheckInButton({
    appointment,
    variant,
    onCheckedIn,
}: Props) {
    const [open, setOpen] = useState(false);
    const saved = useRef(false);
    const compact = variant === 'row';
    const time = formatClinicTime(appointment.startsAt);

    const buttonProps = {
        className: compact ? 'w-full md:w-auto' : 'w-full',
        'aria-label': compact
            ? `Check in ${appointment.patientName} at ${time}`
            : undefined,
        'data-test': compact ? 'search-check-in-button' : 'check-in-button',
        // The search moves focus to the first of these when Enter is pressed in its field.
        'data-search-action': compact ? '' : undefined,
    };

    function onOpenChange(next: boolean) {
        if (next) {
            saved.current = false;
        }

        setOpen(next);
    }

    // Normally the dialog hands focus back to its button ("Go back", Esc). After a successful check-in that button is
    // gone, so the caller's focus target takes it instead.
    function returnFocus(event: Event) {
        if (!saved.current) {
            return;
        }

        event.preventDefault();
        onCheckedIn();
    }

    return (
        <div className={cn('space-y-2', compact && 'w-full md:w-auto')}>
            {appointment.emergencyFlag && <EmergencyNotice compact={compact} />}

            {appointment.intakeMissing ? (
                <Dialog open={open} onOpenChange={onOpenChange}>
                    <DialogTrigger asChild>
                        <Button type="button" {...buttonProps}>
                            <Check aria-hidden />
                            Check in
                        </Button>
                    </DialogTrigger>

                    <DialogContent onCloseAutoFocus={returnFocus}>
                        <Form
                            {...StaffAppointmentController.checkIn.form(
                                appointment.ulid,
                            )}
                            options={{ preserveScroll: true }}
                            onSuccess={() => {
                                saved.current = true;
                                setOpen(false);
                            }}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>
                                            No intake form yet
                                        </DialogTitle>
                                        <DialogDescription>
                                            After check-in the patient can no
                                            longer fill it in. Ask them to open
                                            My appointments on their phone and
                                            fill it in first, or check in
                                            without it.
                                        </DialogDescription>
                                    </DialogHeader>

                                    <div role="alert">
                                        {errors.appointment && (
                                            <AlertError
                                                errors={[errors.appointment]}
                                                title="The patient was not checked in."
                                            />
                                        )}
                                    </div>

                                    <DialogFooter>
                                        {/* The first button in the dialog takes the focus: the safe choice. */}
                                        <DialogClose asChild>
                                            <Button
                                                type="button"
                                                variant="outline"
                                            >
                                                Go back
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            data-test="confirm-check-in-button"
                                        >
                                            {processing && <Spinner />}
                                            Check in without intake
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            ) : (
                <Form
                    {...StaffAppointmentController.checkIn.form(
                        appointment.ulid,
                    )}
                    options={{ preserveScroll: true }}
                    onSuccess={onCheckedIn}
                    className={compact ? 'space-y-1' : 'space-y-2'}
                >
                    {({ errors, processing }) => (
                        <>
                            <Button
                                type="submit"
                                disabled={processing}
                                {...buttonProps}
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <Check aria-hidden />
                                )}
                                Check in
                            </Button>
                            <div role="alert">
                                {errors.appointment &&
                                    (compact ? (
                                        <p className="text-xs text-destructive-foreground">
                                            {errors.appointment}
                                        </p>
                                    ) : (
                                        <AlertError
                                            errors={[errors.appointment]}
                                            title="The patient was not checked in."
                                        />
                                    ))}
                            </div>
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}

/** The emergency flag is shown in words and with an icon, never by the color alone. It does not stop the check-in. */
function EmergencyNotice({ compact }: { compact: boolean }) {
    return (
        <p
            className={cn(
                'flex items-start gap-2 font-medium text-danger-ink',
                compact
                    ? 'text-xs'
                    : 'rounded-md border border-danger-line bg-danger-soft p-3 text-sm',
            )}
        >
            <Siren
                aria-hidden
                className={cn('shrink-0', compact ? 'size-3.5' : 'size-4')}
            />
            The intake has emergency words. Tell the doctor now.
        </p>
    );
}
