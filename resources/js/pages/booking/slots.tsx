import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import DoctorController from '@/actions/App/Http/Controllers/Booking/DoctorController';
import SlotController from '@/actions/App/Http/Controllers/Booking/SlotController';
import AlertError from '@/components/alert-error';
import BookingSteps from '@/components/booking/booking-steps';
import DayPicker from '@/components/booking/day-picker';
import SlotGrid from '@/components/booking/slot-grid';
import { formatClinicDate, formatClinicTime } from '@/lib/clinic-time';
import type {
    BookableDoctor,
    BookableService,
    BookingDay,
    BookingSlot,
} from '@/types';

type Props = {
    service: BookableService;
    /** null means "Any doctor". */
    doctor: BookableDoctor | null;
    days: BookingDay[];
    selectedDate: string;
    slots: BookingSlot[];
};

export default function BookingSlots({
    service,
    doctor,
    days,
    selectedDate,
    slots,
}: Props) {
    const { errors } = usePage().props;

    // The chosen time is remembered with its day. When another day is opened, the old choice no longer matches
    // `selectedDate`, so it is dropped without an effect.
    const [chosen, setChosen] = useState<{
        date: string;
        startsAt: string;
    } | null>(null);
    const selectedSlot =
        chosen !== null &&
        chosen.date === selectedDate &&
        slots.some((slot) => slot.startsAt === chosen.startsAt)
            ? chosen.startsAt
            : null;

    const hrefFor = (date: string) =>
        doctor === null
            ? SlotController.any(service, { query: { date } })
            : SlotController.doctor({ service, doctor }, { query: { date } });

    const doctorLabel = doctor === null ? 'Any available doctor' : doctor.name;

    return (
        <>
            <Head title={`Choose a time for ${service.name}`} />

            <div className="space-y-6">
                <BookingSteps current={3} />

                <div className="space-y-2">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Pick a day and time
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {service.name} · {service.durationMinutes} min ·{' '}
                        {doctorLabel}{' '}
                        <Link
                            href={DoctorController.index(service)}
                            className="text-foreground underline underline-offset-4"
                        >
                            Change doctor
                        </Link>
                    </p>
                    <p className="text-sm text-muted-foreground">
                        All times are Manila time.
                    </p>
                </div>

                {/* Shown when the date in the address was not a day of the booking window. */}
                <div role="alert">
                    {errors.date && (
                        <AlertError
                            errors={[errors.date]}
                            title="That day can't be shown."
                        />
                    )}
                </div>

                <div className="grid gap-8 md:grid-cols-[minmax(0,22rem)_1fr]">
                    <section
                        aria-labelledby="days-heading"
                        className="space-y-3"
                    >
                        <h2 id="days-heading" className="font-medium">
                            Day
                        </h2>
                        <DayPicker
                            days={days}
                            selectedDate={selectedDate}
                            hrefFor={hrefFor}
                        />
                        {/* The same fact as each day's label for screen readers, in words for everyone else. */}
                        <p className="text-sm text-muted-foreground">
                            Days with a border have open times. Faded days are
                            closed or fully booked.
                        </p>
                    </section>

                    <section
                        aria-labelledby="times-heading"
                        className="space-y-3"
                    >
                        <h2 id="times-heading" className="font-medium">
                            Open times on {formatClinicDate(selectedDate)}
                        </h2>

                        {slots.length === 0 ? (
                            <p className="rounded-xl border p-6 text-sm text-muted-foreground">
                                No open times on this day. Pick another day from
                                the calendar.
                            </p>
                        ) : (
                            <SlotGrid
                                slots={slots}
                                selected={selectedSlot}
                                onSelect={(startsAt) =>
                                    setChosen({ date: selectedDate, startsAt })
                                }
                            />
                        )}

                        <p
                            aria-live="polite"
                            className="rounded-xl border bg-muted/50 p-4 text-sm"
                        >
                            {selectedSlot === null
                                ? 'Pick a time to see your visit here.'
                                : `Your visit: ${formatClinicDate(selectedDate)} at ${formatClinicTime(selectedSlot)}, ${doctorLabel}.`}
                        </p>
                    </section>
                </div>
            </div>
        </>
    );
}
