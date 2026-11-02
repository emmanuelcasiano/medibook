import { Form, router } from '@inertiajs/react';
import { CalendarClock } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import StaffAppointmentController from '@/actions/App/Http/Controllers/Staff/AppointmentController';
import AlertError from '@/components/alert-error';
import SlotGrid from '@/components/booking/slot-grid';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { formatClinicDateTime, isCompleteDate } from '@/lib/clinic-time';
import type {
    DaySchedule,
    MoveOptions,
    ScheduleAppointment,
    ScheduleDoctor,
} from '@/types';

type Props = {
    appointment: ScheduleAppointment;
    /** The doctor whose column the booking is in. The picker starts on them. */
    doctor: ScheduleDoctor;
    schedule: DaySchedule;
    /** `undefined` until the server has sent it, `null` when the user may not move this booking. */
    move: MoveOptions | null | undefined;
};

/**
 * The "Move" part of the staff side panel: pick a doctor and a day, then one of that day's free times. The doctors and
 * the times come from the server (a partial reload of the `move` prop), so every rule of the slot finder applies and
 * the browser works out nothing. The server checks the choice again on submit (the time may have been taken since).
 * Moving cancels the old booking and books the new one, so on success the page opens the panel of the new booking.
 */
export default function MoveSection({
    appointment,
    doctor,
    schedule,
    move,
}: Props) {
    const panelId = useId();
    const doctorFieldId = useId();
    const dateFieldId = useId();
    const [open, setOpen] = useState(false);
    const [doctorId, setDoctorId] = useState(String(doctor.id));
    // Today at the earliest: the finder offers nothing in the past.
    const [date, setDate] = useState(
        schedule.date < schedule.today ? schedule.today : schedule.date,
    );
    const [startsAt, setStartsAt] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    // The doctor and day the list on the page was asked for. A reload happens only for a new choice, or when the page
    // has no list: opening the section, or coming back from a refused move (the time that was just refused may be
    // gone from the list). When the answer arrives for the choice already asked, nothing more is requested.
    const asked = useRef<string | null>(null);

    useEffect(() => {
        if (!open) {
            // Closed: the next opening asks again, since the day may have moved on.
            asked.current = null;

            return;
        }

        if (!isCompleteDate(date)) {
            return;
        }

        const choice = `${doctorId}|${date}`;

        if (move !== undefined && asked.current === choice) {
            return;
        }

        asked.current = choice;

        router.reload({
            only: ['move'],
            data: { move_doctor: doctorId, move_date: date },
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    }, [open, doctorId, date, move]);

    const slots = move?.slots ?? [];
    const status = loading
        ? 'Looking for free times.'
        : move && slots.length === 0
          ? 'No free times on this day. Pick another day or doctor.'
          : move
            ? `${slots.length} free ${slots.length === 1 ? 'time' : 'times'}.`
            : '';

    return (
        <div className="space-y-3">
            <Button
                type="button"
                variant="outline"
                className="w-full"
                aria-expanded={open}
                aria-controls={panelId}
                onClick={() => setOpen((current) => !current)}
                data-test="move-button"
            >
                <CalendarClock aria-hidden />
                {open ? 'Keep this time' : 'Move to another time'}
            </Button>

            <div id={panelId} hidden={!open}>
                {open && (
                    <Form
                        {...StaffAppointmentController.move.form(
                            appointment.ulid,
                        )}
                        options={{ preserveScroll: true }}
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor={doctorFieldId}>
                                        Doctor
                                    </Label>
                                    <NativeSelect
                                        id={doctorFieldId}
                                        value={doctorId}
                                        onChange={(event) => {
                                            setDoctorId(event.target.value);
                                            setStartsAt(null);
                                        }}
                                    >
                                        {/* Until the list arrives, the current doctor is the only choice. */}
                                        {(
                                            move?.doctors ?? [
                                                {
                                                    id: doctor.id,
                                                    name: doctor.name,
                                                },
                                            ]
                                        ).map((option) => (
                                            <option
                                                key={option.id}
                                                value={option.id}
                                            >
                                                {option.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor={dateFieldId}>Day</Label>
                                    <Input
                                        id={dateFieldId}
                                        type="date"
                                        min={schedule.today}
                                        value={date}
                                        onChange={(event) => {
                                            setDate(event.target.value);
                                            setStartsAt(null);
                                        }}
                                    />
                                </div>

                                <div className="space-y-2">
                                    <p
                                        aria-live="polite"
                                        className="flex items-center gap-2 text-sm text-muted-foreground"
                                    >
                                        {loading && <Spinner />}
                                        {status}
                                    </p>

                                    {!loading && slots.length > 0 && (
                                        <div className="max-h-60 overflow-y-auto pr-1">
                                            <SlotGrid
                                                slots={slots}
                                                selected={startsAt}
                                                onSelect={setStartsAt}
                                                className="sm:grid-cols-3"
                                            />
                                        </div>
                                    )}
                                </div>

                                <input
                                    type="hidden"
                                    name="doctor_id"
                                    value={doctorId}
                                />
                                <input
                                    type="hidden"
                                    name="starts_at"
                                    value={startsAt ?? ''}
                                />

                                <div role="alert">
                                    {Object.keys(errors).length > 0 && (
                                        <AlertError
                                            errors={Object.values(errors)}
                                            title="The appointment was not moved."
                                        />
                                    )}
                                </div>

                                <Button
                                    type="submit"
                                    className="w-full"
                                    disabled={processing || startsAt === null}
                                    data-test="confirm-move-button"
                                >
                                    {processing && <Spinner />}
                                    {startsAt === null
                                        ? 'Pick a time to move to'
                                        : `Move to ${formatClinicDateTime(startsAt)}`}
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </div>
    );
}
