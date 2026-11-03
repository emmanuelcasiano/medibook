import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import BookingController from '@/actions/App/Http/Controllers/Booking/BookingController';
import SlotController from '@/actions/App/Http/Controllers/Booking/SlotController';
import AlertError from '@/components/alert-error';
import BookingSteps from '@/components/booking/booking-steps';
import BookingSummary from '@/components/booking/booking-summary';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { BookingDraft } from '@/types';

type Props = BookingDraft;

export default function BookingConfirm({
    service,
    doctor,
    startsAt,
    endsAt,
    cancelUntil,
}: Props) {
    const { errors } = usePage().props;
    const doctorLabel = doctor === null ? 'Any available doctor' : doctor.name;
    const backHref =
        doctor === null
            ? SlotController.any(service)
            : SlotController.doctor({ service, doctor });

    return (
        <>
            <Head title="Confirm your booking" />

            <div className="space-y-6">
                <BookingSteps current={4} />

                <PageHeader
                    title="Check and confirm"
                    description="All times are Manila time. Nothing is booked until you press Confirm."
                />

                {/* Patient limits, a doctor who stopped taking bookings and a time that was just taken come back
                    here. It sits above the summary so a phone shows it before anything else. */}
                <div role="alert">
                    {(errors.starts_at ||
                        errors.doctor_id ||
                        errors.service_id) && (
                        <AlertError
                            errors={[
                                errors.starts_at,
                                errors.doctor_id,
                                errors.service_id,
                            ].flatMap((error) =>
                                typeof error === 'string' ? [error] : [],
                            )}
                            title="This booking can't be made."
                        />
                    )}
                </div>

                {/* The summary comes first in the page, so a phone reads it above the form. From `lg` it moves to the
                    right and stays in view while the form is read. */}
                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
                    <BookingSummary
                        heading="Your booking"
                        service={service}
                        doctorName={doctorLabel}
                        doctorSpecialty={doctor?.specialty}
                        startsAt={startsAt}
                        endsAt={endsAt}
                        cancelUntil={cancelUntil}
                        className="lg:sticky lg:top-6 lg:col-start-2 lg:row-start-1"
                    />

                    <Form
                        {...BookingController.store.form(service)}
                        className="space-y-6 lg:col-start-1 lg:row-start-1"
                    >
                        {({ processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="starts_at"
                                    value={startsAt}
                                />
                                {doctor !== null && (
                                    <input
                                        type="hidden"
                                        name="doctor_id"
                                        value={doctor.id}
                                    />
                                )}

                                {doctor === null && (
                                    <p className="text-sm text-muted-foreground">
                                        We pick a doctor who is free at this
                                        time and show you who it is after you
                                        confirm.
                                    </p>
                                )}

                                <p className="flex items-start gap-3 rounded-xl border border-info-line bg-info-soft p-4 text-sm text-info-ink">
                                    <ClipboardList
                                        aria-hidden
                                        className="mt-0.5 size-4 shrink-0"
                                    />
                                    <span>
                                        After you confirm, you can fill in a
                                        short intake form so your doctor can
                                        read it before your visit.
                                    </span>
                                </p>

                                <div className="flex flex-wrap gap-3">
                                    <Button
                                        type="submit"
                                        size="lg"
                                        className="w-full sm:w-auto"
                                        disabled={processing}
                                        data-test="confirm-booking-button"
                                    >
                                        {processing && <Spinner />}
                                        Confirm booking
                                    </Button>
                                    <Button
                                        asChild
                                        variant="outline"
                                        size="lg"
                                        className="w-full sm:w-auto"
                                    >
                                        <Link href={backHref}>
                                            Pick another time
                                        </Link>
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </div>
            </div>
        </>
    );
}
