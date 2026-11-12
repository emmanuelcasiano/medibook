import { Head, Link, usePage } from '@inertiajs/react';
import { CalendarX } from 'lucide-react';
import { useRef, useState } from 'react';
import DoctorController from '@/actions/App/Http/Controllers/Booking/DoctorController';
import SlotController from '@/actions/App/Http/Controllers/Booking/SlotController';
import BookingController from '@/actions/App/Http/Controllers/Booking/BookingController';
import FollowUpBookingController from '@/actions/App/Http/Controllers/Patient/FollowUpBookingController';
import AlertError from '@/components/alert-error';
import BookingSteps from '@/components/booking/booking-steps';
import DayPicker from '@/components/booking/day-picker';
import SlotGrid from '@/components/booking/slot-grid';
import EmptyState from '@/components/empty-state';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { formatClinicDate, formatClinicTime } from '@/lib/clinic-time';
import type {
    BookableDoctor,
    BookableService,
    BookingDay,
    BookingFollowUp,
    BookingSlot,
} from '@/types';

type Props = {
    service: BookableService;
    /** null means "Any doctor". */
    doctor: BookableDoctor | null;
    days: BookingDay[];
    selectedDate: string;
    slots: BookingSlot[];
    /** Set when the visit is booked from a follow-up. The service and doctor are then fixed, and the days are its window. */
    followUp?: BookingFollowUp;
};

export default function BookingSlots({
    service,
    doctor,
    days,
    selectedDate,
    slots,
    followUp,
}: Props) {
    const { auth, errors } = usePage().props;

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
        followUp !== undefined
            ? FollowUpBookingController.slots(followUp.ulid, {
                  query: { date },
              })
            : doctor === null
              ? SlotController.any(service, { query: { date } })
              : SlotController.doctor({ service, doctor }, { query: { date } });

    const confirmHref = (startsAt: string) => {
        if (followUp !== undefined) {
            return FollowUpBookingController.confirm(followUp.ulid, {
                query: { starts_at: startsAt },
            });
        }

        return BookingController.confirm(service, {
            query:
                doctor === null
                    ? { starts_at: startsAt }
                    : { starts_at: startsAt, doctor_id: doctor.id },
        });
    };

    const doctorLabel = doctor === null ? 'Any available doctor' : doctor.name;

    // After a day is picked, bring its times into view. On a phone they sit below the week strip; on a wide screen
    // they are already beside it, so nothing moves.
    const timesRef = useRef<HTMLElement>(null);
    const scrollToTimes = () => {
        const times = timesRef.current;

        if (times === null) {
            return;
        }

        const { top } = times.getBoundingClientRect();

        if (top >= 0 && top < window.innerHeight * 0.6) {
            return;
        }

        times.scrollIntoView({
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)')
                .matches
                ? 'auto'
                : 'smooth',
            block: 'start',
        });
    };

    return (
        <>
            <Head title={`Choose a time for ${service.name}`} />

            <div className="space-y-6">
                {/* A follow-up has no service or doctor to choose, so the steps for them are left out. */}
                {followUp === undefined && <BookingSteps current={3} />}

                <PageHeader
                    title={
                        followUp === undefined
                            ? 'Pick a day and time'
                            : 'Book your follow-up'
                    }
                    description={
                        <>
                            <p>
                                {service.name} · {service.durationMinutes} min ·{' '}
                                {doctorLabel}{' '}
                                {followUp === undefined && (
                                    <Link
                                        href={DoctorController.index(service)}
                                        className="text-foreground underline underline-offset-4"
                                    >
                                        Change doctor
                                    </Link>
                                )}
                            </p>
                            {followUp !== undefined && (
                                <p>
                                    Your doctor asked you to come back between{' '}
                                    {formatClinicDate(followUp.windowStartsOn)}{' '}
                                    and{' '}
                                    {formatClinicDate(followUp.windowEndsOn)}.
                                </p>
                            )}
                            <p>All times are Manila time.</p>
                        </>
                    }
                />

                {/* Shown when the date in the address was not a day of the booking window. */}
                <div role="alert">
                    {errors.date && (
                        <AlertError
                            errors={[errors.date]}
                            title="That day can't be shown."
                        />
                    )}
                    {/* Sent back from the confirm step: the time was taken or is not offered any more. */}
                    {(errors.starts_at || errors.doctor_id) && (
                        <AlertError
                            errors={[
                                errors.starts_at,
                                errors.doctor_id,
                            ].flatMap((error) =>
                                typeof error === 'string' ? [error] : [],
                            )}
                            title="That time can't be booked."
                        />
                    )}
                </div>

                <div className="grid items-start gap-4 md:grid-cols-[minmax(0,27rem)_1fr] md:gap-6">
                    <section
                        aria-labelledby="days-heading"
                        className="space-y-3 rounded-xl border bg-card p-4"
                    >
                        <h2 id="days-heading" className="font-semibold">
                            Day
                        </h2>
                        <DayPicker
                            days={days}
                            selectedDate={selectedDate}
                            hrefFor={hrefFor}
                            onPicked={scrollToTimes}
                        />
                        {/* The same fact as each day's label for screen readers, in words for everyone else. */}
                        <p className="text-sm text-muted-foreground">
                            Dashed days say "None": they are closed or fully
                            booked.
                        </p>
                    </section>

                    <section
                        ref={timesRef}
                        aria-labelledby="times-heading"
                        className="scroll-mt-4 space-y-3 rounded-xl border bg-card p-4"
                    >
                        <h2 id="times-heading" className="font-semibold">
                            Open times on {formatClinicDate(selectedDate)}
                        </h2>

                        {slots.length === 0 ? (
                            <EmptyState
                                icon={CalendarX}
                                className="border-dashed bg-transparent p-6"
                            >
                                No open times on this day. Pick another day from
                                the week above.
                            </EmptyState>
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
                            className="rounded-lg bg-muted/60 p-4 text-sm"
                        >
                            {selectedSlot === null
                                ? 'Pick a time to see your visit here.'
                                : `Your visit: ${formatClinicDate(selectedDate)} at ${formatClinicTime(selectedSlot)}, ${doctorLabel}.`}
                        </p>

                        {auth.user && auth.user.role !== 'patient' ? (
                            <p className="text-sm text-muted-foreground">
                                You are signed in as {auth.user.role}. Only
                                patients can book an appointment.
                            </p>
                        ) : (
                            <div className="space-y-2">
                                {selectedSlot === null ? (
                                    <Button disabled>Continue</Button>
                                ) : (
                                    <Button asChild>
                                        <Link href={confirmHref(selectedSlot)}>
                                            Continue
                                        </Link>
                                    </Button>
                                )}
                                {!auth.user && (
                                    <p className="text-sm text-muted-foreground">
                                        Next you log in or create an account.
                                        Nothing is booked until you confirm.
                                    </p>
                                )}
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}
