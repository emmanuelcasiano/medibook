import { Form, useHttp } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import WalkInController from '@/actions/App/Http/Controllers/Staff/WalkInController';
import AlertError from '@/components/alert-error';
import SlotGrid from '@/components/booking/slot-grid';
import WalkInPatientPicker from '@/components/schedule/walk-in-patient-picker';
import type { PatientChoice } from '@/components/schedule/walk-in-patient-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { formatClinicTime } from '@/lib/clinic-time';
import type { WalkInOptions } from '@/types';

type Props = {
    /** The clinic date today ('Y-m-d'), from the server's clock. */
    today: string;
};

/** The day before $date ('Y-m-d'), for the latest birth date a field may hold. Date math in UTC, so no zone can move it. */
function dayBefore(date: string): string {
    const day = new Date(`${date}T00:00:00Z`);
    day.setUTCDate(day.getUTCDate() - 1);

    return day.toISOString().slice(0, 10);
}

function patientIsComplete(choice: PatientChoice): boolean {
    if (choice === null) {
        return false;
    }

    if (choice.kind === 'existing') {
        return true;
    }

    const { name, email, birthDate } = choice.fields;

    return name.trim() !== '' && email.trim() !== '' && birthDate.trim() !== '';
}

/**
 * The "New walk-in" button and its dialog: a patient at the desk with no booking, seen today. Staff find the patient (or
 * add one), pick the visit type and a doctor (or "Any doctor"), and take one of today's nearest free times. "Check in
 * now" is on by default, because a walk-in is usually seen at once.
 *
 * The lists come from the server (a JSON request each time the visit type or the doctor changes), so every rule of the
 * slot finders applies and the browser works out nothing. The server checks the choice again on submit: the time may
 * have been taken since, and a refusal comes back as a message next to the field and refreshes the list. The Policy
 * refuses an admin; the caller renders this only for staff.
 */
export default function WalkInDialog({ today }: Props) {
    const serviceFieldId = useId();
    const doctorFieldId = useId();
    const timeLabelId = useId();
    const checkInId = useId();
    const checkInHintId = useId();
    const optionsHttp = useHttp<
        { service: string; doctor: string },
        WalkInOptions
    >({ service: '', doctor: '' });
    const [open, setOpen] = useState(false);
    const [choice, setChoice] = useState<PatientChoice>(null);
    const [options, setOptions] = useState<WalkInOptions | null>(null);
    const [serviceId, setServiceId] = useState('');
    const [doctor, setDoctor] = useState('');
    const [startsAt, setStartsAt] = useState<string | null>(null);
    const [checkIn, setCheckIn] = useState(true);
    const [loading, setLoading] = useState(false);
    const [loadFailed, setLoadFailed] = useState(false);
    // Only the newest request may write the lists: a slow answer for the old doctor must not replace the new one's.
    const latest = useRef(0);

    function load(service: string, doctorChoice: string) {
        const request = ++latest.current;

        setLoading(true);
        setLoadFailed(false);
        optionsHttp.setData({
            service,
            doctor: service === '' ? '' : doctorChoice,
        });
        optionsHttp
            .submit(WalkInController.options())
            .then((response) => {
                if (request !== latest.current) {
                    return;
                }

                setOptions(response);
                // The nearest time is the one a walk-in wants; staff can pick another.
                setStartsAt(response.slots[0]?.startsAt ?? null);
            })
            .catch(() => {
                if (request === latest.current) {
                    setLoadFailed(true);
                }
            })
            .finally(() => {
                if (request === latest.current) {
                    setLoading(false);
                }
            });
    }

    function reset() {
        latest.current += 1;
        setChoice(null);
        setOptions(null);
        setServiceId('');
        setDoctor('');
        setStartsAt(null);
        setCheckIn(true);
        setLoading(false);
        setLoadFailed(false);
    }

    function onOpenChange(next: boolean) {
        setOpen(next);

        if (next) {
            load('', '');
        } else {
            reset();
        }
    }

    function changeService(value: string) {
        setServiceId(value);
        setStartsAt(null);
        // "Any doctor" is the quickest answer for a walk-in, so it is the starting point.
        setDoctor(value === '' ? '' : 'any');
        load(value, 'any');
    }

    function changeDoctor(value: string) {
        setDoctor(value);
        setStartsAt(null);
        load(serviceId, value);
    }

    const slots = options?.slots ?? [];
    const timeStatus = loading
        ? 'Looking for free times.'
        : loadFailed
          ? 'The times could not be loaded. Pick the visit type again.'
          : serviceId === '' || doctor === ''
            ? 'Pick a visit type to see the times.'
            : slots.length === 0
              ? 'No free times left today for this choice. Try another doctor or visit type.'
              : `${slots.length} free ${slots.length === 1 ? 'time' : 'times'} today, from the nearest.`;

    const canBook =
        patientIsComplete(choice) &&
        serviceId !== '' &&
        doctor !== '' &&
        startsAt !== null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogTrigger asChild>
                <Button type="button" data-test="new-walk-in-button">
                    <UserPlus aria-hidden />
                    New walk-in
                </Button>
            </DialogTrigger>

            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <Form
                    {...WalkInController.store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    // The refusal may be a time that was just taken: show the list as it is now.
                    onError={() => load(serviceId, doctor)}
                    className="space-y-5"
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>New walk-in</DialogTitle>
                                <DialogDescription>
                                    For a patient at the desk with no
                                    appointment. Times are today, Manila time.
                                </DialogDescription>
                            </DialogHeader>

                            <WalkInPatientPicker
                                choice={choice}
                                onChoose={setChoice}
                                errors={errors}
                                latestBirthDate={dayBefore(today)}
                            />

                            <div className="grid gap-2">
                                <Label htmlFor={serviceFieldId}>
                                    Visit type
                                </Label>
                                <NativeSelect
                                    id={serviceFieldId}
                                    value={serviceId}
                                    onChange={(event) =>
                                        changeService(event.target.value)
                                    }
                                    disabled={options === null}
                                    aria-invalid={!!errors.service_id}
                                    aria-describedby={
                                        errors.service_id
                                            ? `${serviceFieldId}-error`
                                            : undefined
                                    }
                                    data-test="walk-in-service"
                                >
                                    <option value="">
                                        Choose a visit type
                                    </option>
                                    {(options?.services ?? []).map(
                                        (service) => (
                                            <option
                                                key={service.id}
                                                value={service.id}
                                            >
                                                {service.name} (
                                                {service.durationMinutes} min)
                                            </option>
                                        ),
                                    )}
                                </NativeSelect>
                                {errors.service_id && (
                                    <p
                                        id={`${serviceFieldId}-error`}
                                        className="text-sm text-destructive-foreground"
                                    >
                                        {errors.service_id}
                                    </p>
                                )}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor={doctorFieldId}>Doctor</Label>
                                <NativeSelect
                                    id={doctorFieldId}
                                    value={doctor}
                                    onChange={(event) =>
                                        changeDoctor(event.target.value)
                                    }
                                    disabled={serviceId === ''}
                                    aria-invalid={!!errors.doctor}
                                    aria-describedby={
                                        errors.doctor
                                            ? `${doctorFieldId}-error`
                                            : undefined
                                    }
                                    data-test="walk-in-doctor"
                                >
                                    {serviceId === '' && (
                                        <option value="">
                                            Pick a visit type first
                                        </option>
                                    )}
                                    {serviceId !== '' && (
                                        <option value="any">Any doctor</option>
                                    )}
                                    {(options?.doctors ?? []).map((option) => (
                                        <option
                                            key={option.id}
                                            value={option.id}
                                        >
                                            {option.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                                {errors.doctor && (
                                    <p
                                        id={`${doctorFieldId}-error`}
                                        className="text-sm text-destructive-foreground"
                                    >
                                        {errors.doctor}
                                    </p>
                                )}
                            </div>

                            {/* A group with a name, so a screen reader says what the time buttons below belong to. */}
                            <div
                                role="group"
                                aria-labelledby={timeLabelId}
                                className="space-y-2"
                            >
                                <p
                                    id={timeLabelId}
                                    className="text-sm font-medium"
                                >
                                    Time
                                </p>
                                <p
                                    aria-live="polite"
                                    className="flex items-center gap-2 text-sm text-muted-foreground"
                                >
                                    {loading && <Spinner />}
                                    {timeStatus}
                                </p>
                                {!loading && slots.length > 0 && (
                                    <SlotGrid
                                        slots={slots}
                                        selected={startsAt}
                                        onSelect={setStartsAt}
                                        className="sm:grid-cols-4"
                                    />
                                )}
                                {errors.starts_at && (
                                    <p
                                        role="alert"
                                        className="text-sm text-destructive-foreground"
                                    >
                                        {errors.starts_at}
                                    </p>
                                )}
                            </div>

                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id={checkInId}
                                    checked={checkIn}
                                    onCheckedChange={(value) =>
                                        setCheckIn(value === true)
                                    }
                                    aria-describedby={checkInHintId}
                                    className="mt-0.5"
                                    data-test="walk-in-check-in"
                                />
                                <div className="grid gap-1">
                                    <Label htmlFor={checkInId}>
                                        Check in now
                                    </Label>
                                    <p
                                        id={checkInHintId}
                                        className="text-xs text-muted-foreground"
                                    >
                                        A checked-in patient can no longer fill
                                        in the intake form.
                                    </p>
                                </div>
                            </div>

                            {/* The browser sends these; the server looks every one up again. */}
                            <input
                                type="hidden"
                                name="service_id"
                                value={serviceId}
                            />
                            <input type="hidden" name="doctor" value={doctor} />
                            <input
                                type="hidden"
                                name="starts_at"
                                value={startsAt ?? ''}
                            />
                            <input
                                type="hidden"
                                name="check_in"
                                value={checkIn ? '1' : '0'}
                            />

                            <div role="alert">
                                {errors.check_in && (
                                    <AlertError
                                        errors={[errors.check_in]}
                                        title="The walk-in was not booked."
                                    />
                                )}
                            </div>

                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing || !canBook}
                                    data-test="confirm-walk-in-button"
                                >
                                    {processing && <Spinner />}
                                    {startsAt === null
                                        ? 'Book walk-in'
                                        : `Book walk-in at ${formatClinicTime(startsAt)}`}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
