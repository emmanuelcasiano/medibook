import { Form } from '@inertiajs/react';
import { CalendarClock, CheckCheck, Lock } from 'lucide-react';
import { useRef, useState } from 'react';
import DoctorAppointmentController from '@/actions/App/Http/Controllers/Doctor/AppointmentController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { addClinicDays, clinicToday } from '@/lib/clinic-time';
import type { FollowUpService } from '@/types';

const NOTE_MAX_LENGTH = 200;

/** Quick picks for "when". A window is five days long and starts two days before the chosen day, as in the design. */
const WHEN_CHOICES = [
    { label: 'In 1 week', days: 7 },
    { label: 'In 2 weeks', days: 14 },
    { label: 'In 1 month', days: 30 },
] as const;

type Props = {
    appointmentUlid: string;
    patientFirstName: string;
    /** The active services this doctor offers. The follow-up is for one of them. */
    services: FollowUpService[];
    /** Called after the visit is finished, once the button is gone. */
    onFinished: () => void;
};

/**
 * "Finish visit" for a checked-in appointment, with an optional "ask the patient to come back". Shown only when
 * `can.complete` is true. Both go to the server in one request: if the follow-up is refused, the visit is not
 * finished and the errors show next to the fields.
 */
export default function FinishVisitForm({
    appointmentUlid,
    patientFirstName,
    services,
    onFinished,
}: Props) {
    const [open, setOpen] = useState(false);
    const finished = useRef(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="lg" data-test="finish-visit-button">
                    <CheckCheck aria-hidden />
                    Finish visit
                </Button>
            </DialogTrigger>
            <DialogContent
                // With the follow-up fields open the dialog is taller than a laptop screen. Cap it to the screen and
                // let it scroll inside, or the title and the submit button end up out of reach.
                className="max-h-[calc(100dvh-2rem)] overflow-y-auto"
                onCloseAutoFocus={(event) => {
                    // The button is gone once the visit is finished, so there is nothing to give focus back to.
                    if (finished.current) {
                        event.preventDefault();
                        onFinished();
                    }
                }}
            >
                <DialogHeader>
                    <DialogTitle>Finish this visit</DialogTitle>
                    <DialogDescription>
                        The visit is marked completed. You can still edit your
                        visit note afterwards.
                    </DialogDescription>
                </DialogHeader>
                <FinishFields
                    appointmentUlid={appointmentUlid}
                    patientFirstName={patientFirstName}
                    services={services}
                    onClose={() => setOpen(false)}
                    onSaved={() => {
                        finished.current = true;
                        setOpen(false);
                    }}
                />
            </DialogContent>
        </Dialog>
    );
}

function FinishFields({
    appointmentUlid,
    patientFirstName,
    services,
    onClose,
    onSaved,
}: {
    appointmentUlid: string;
    patientFirstName: string;
    services: FollowUpService[];
    onClose: () => void;
    onSaved: () => void;
}) {
    // These live here and not in the page, so closing the dialog starts the next one from a clean form.
    const [askBack, setAskBack] = useState(false);
    const [startsOn, setStartsOn] = useState(() =>
        addClinicDays(clinicToday(), 12),
    );
    const [endsOn, setEndsOn] = useState(() =>
        addClinicDays(clinicToday(), 16),
    );
    const [noteLength, setNoteLength] = useState(0);

    const today = clinicToday();
    const canAsk = services.length > 0;

    function pickWhen(days: number) {
        const from = addClinicDays(clinicToday(), days - 2);

        setStartsOn(from);
        setEndsOn(addClinicDays(from, 4));
    }

    return (
        <Form
            {...DoctorAppointmentController.complete.form(appointmentUlid)}
            options={{ preserveScroll: true }}
            onSuccess={onSaved}
            className="space-y-5"
        >
            {({ errors, processing }) => (
                <>
                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="ask-back"
                            checked={askBack}
                            onCheckedChange={(value) =>
                                setAskBack(value === true)
                            }
                            disabled={!canAsk}
                            aria-describedby="ask-back-hint"
                            className="mt-0.5"
                        />
                        <div className="space-y-1">
                            <Label
                                htmlFor="ask-back"
                                className="text-base font-semibold"
                            >
                                Ask {patientFirstName} to come back
                            </Label>
                            <p
                                id="ask-back-hint"
                                className="text-sm text-muted-foreground"
                            >
                                {canAsk
                                    ? 'They get a request to book. Nothing is booked for them.'
                                    : 'You have no active services, so a follow-up can not be requested.'}
                            </p>
                        </div>
                    </div>

                    {/* Rendered only when asked for, so a plain finish sends no follow-up fields at all. */}
                    {askBack && (
                        <div className="space-y-4 rounded-lg border bg-muted/30 p-4">
                            <div className="grid gap-2">
                                <Label htmlFor="follow-up-service">
                                    Visit type
                                </Label>
                                <NativeSelect
                                    id="follow-up-service"
                                    name="follow_up[service_id]"
                                    required
                                    defaultValue={services[0]?.id}
                                    aria-invalid={
                                        errors['follow_up.service_id']
                                            ? true
                                            : undefined
                                    }
                                    aria-describedby={
                                        errors['follow_up.service_id']
                                            ? 'follow-up-service-error'
                                            : undefined
                                    }
                                >
                                    {services.map((service) => (
                                        <option
                                            key={service.id}
                                            value={service.id}
                                        >
                                            {service.name} ·{' '}
                                            {service.durationMinutes} min
                                        </option>
                                    ))}
                                </NativeSelect>
                                <div id="follow-up-service-error" role="alert">
                                    <InputError
                                        message={errors['follow_up.service_id']}
                                    />
                                </div>
                            </div>

                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm leading-none font-medium">
                                    When
                                </legend>
                                <div className="flex flex-wrap gap-2">
                                    {WHEN_CHOICES.map((choice) => (
                                        <Button
                                            key={choice.days}
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                pickWhen(choice.days)
                                            }
                                        >
                                            {choice.label}
                                        </Button>
                                    ))}
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="follow-up-starts-on">
                                            From
                                        </Label>
                                        <Input
                                            id="follow-up-starts-on"
                                            type="date"
                                            name="follow_up[starts_on]"
                                            required
                                            min={today}
                                            value={startsOn}
                                            onChange={(event) =>
                                                setStartsOn(event.target.value)
                                            }
                                            aria-invalid={
                                                errors['follow_up.starts_on']
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                errors['follow_up.starts_on']
                                                    ? 'follow-up-starts-on-error'
                                                    : undefined
                                            }
                                        />
                                        <div
                                            id="follow-up-starts-on-error"
                                            role="alert"
                                        >
                                            <InputError
                                                message={
                                                    errors[
                                                        'follow_up.starts_on'
                                                    ]
                                                }
                                            />
                                        </div>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="follow-up-ends-on">
                                            To
                                        </Label>
                                        <Input
                                            id="follow-up-ends-on"
                                            type="date"
                                            name="follow_up[ends_on]"
                                            required
                                            min={startsOn || today}
                                            value={endsOn}
                                            onChange={(event) =>
                                                setEndsOn(event.target.value)
                                            }
                                            aria-invalid={
                                                errors['follow_up.ends_on']
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                errors['follow_up.ends_on']
                                                    ? 'follow-up-ends-on-error'
                                                    : undefined
                                            }
                                        />
                                        <div
                                            id="follow-up-ends-on-error"
                                            role="alert"
                                        >
                                            <InputError
                                                message={
                                                    errors['follow_up.ends_on']
                                                }
                                            />
                                        </div>
                                    </div>
                                </div>
                                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <CalendarClock
                                        aria-hidden
                                        className="size-4"
                                    />
                                    All dates are Manila time.
                                </p>
                            </fieldset>

                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm leading-none font-medium">
                                    With
                                </legend>
                                <div className="grid gap-2 sm:grid-cols-2">
                                    <Label className="flex cursor-pointer items-start gap-3 rounded-lg border bg-card p-3 font-normal has-[:checked]:border-primary has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring">
                                        <input
                                            type="radio"
                                            name="follow_up[same_doctor]"
                                            value="1"
                                            defaultChecked
                                            className="mt-1"
                                        />
                                        <span>
                                            <span className="block font-medium">
                                                Me
                                            </span>
                                            <span className="block text-sm text-muted-foreground">
                                                Best for checking progress
                                            </span>
                                        </span>
                                    </Label>
                                    <Label className="flex cursor-pointer items-start gap-3 rounded-lg border bg-card p-3 font-normal has-[:checked]:border-primary has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring">
                                        <input
                                            type="radio"
                                            name="follow_up[same_doctor]"
                                            value="0"
                                            className="mt-1"
                                        />
                                        <span>
                                            <span className="block font-medium">
                                                Any doctor
                                            </span>
                                            <span className="block text-sm text-muted-foreground">
                                                More times to choose from
                                            </span>
                                        </span>
                                    </Label>
                                </div>
                                <div role="alert">
                                    <InputError
                                        message={
                                            errors['follow_up.same_doctor']
                                        }
                                    />
                                </div>
                            </fieldset>

                            <div className="grid gap-2">
                                <Label htmlFor="follow-up-note">
                                    Note for {patientFirstName}{' '}
                                    <span className="font-normal text-muted-foreground">
                                        (optional)
                                    </span>
                                </Label>
                                <Textarea
                                    id="follow-up-note"
                                    name="follow_up[note]"
                                    rows={3}
                                    maxLength={NOTE_MAX_LENGTH}
                                    onChange={(event) =>
                                        setNoteLength(event.target.value.length)
                                    }
                                    aria-invalid={
                                        errors['follow_up.note']
                                            ? true
                                            : undefined
                                    }
                                    aria-describedby="follow-up-note-hint follow-up-note-error"
                                />
                                <div className="flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground">
                                    <span
                                        id="follow-up-note-hint"
                                        className="flex items-center gap-1.5"
                                    >
                                        <Lock
                                            aria-hidden
                                            className="size-3.5"
                                        />
                                        Encrypted. Never emailed, never shown to
                                        staff.
                                    </span>
                                    <span>
                                        {noteLength} / {NOTE_MAX_LENGTH}
                                    </span>
                                </div>
                                <div id="follow-up-note-error" role="alert">
                                    <InputError
                                        message={errors['follow_up.note']}
                                    />
                                </div>
                            </div>
                        </div>
                    )}

                    {/* A refusal about the visit itself (for example, it was cancelled while the page was open). */}
                    <div role="alert">
                        <InputError message={errors.appointment} />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing}
                            data-test="finish-visit-submit"
                        >
                            {processing ? (
                                <Spinner />
                            ) : (
                                <CheckCheck aria-hidden />
                            )}
                            {askBack
                                ? 'Finish and request follow-up'
                                : 'Finish visit'}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}
