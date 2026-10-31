import { Form, Head, Link } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import BookingController from '@/actions/App/Http/Controllers/Booking/BookingController';
import SlotController from '@/actions/App/Http/Controllers/Booking/SlotController';
import AlertError from '@/components/alert-error';
import BookingSteps from '@/components/booking/booking-steps';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatClinicDateTime, formatClinicTime } from '@/lib/clinic-time';
import type { BookingDraft } from '@/types';

type Props = BookingDraft;

export default function BookingConfirm({
    service,
    doctor,
    startsAt,
    endsAt,
    cancelUntil,
}: Props) {
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

                <div className="space-y-2">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Check and confirm
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        All times are Manila time. Nothing is booked until you
                        press Confirm.
                    </p>
                </div>

                <Form
                    {...BookingController.store.form(service)}
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            {/* Patient limits and a doctor who stopped taking bookings come back here. A time that is
                                gone sends the patient back to the time picker instead. */}
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
                                            typeof error === 'string'
                                                ? [error]
                                                : [],
                                        )}
                                        title="This booking can't be made."
                                    />
                                )}
                            </div>

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

                            <section
                                aria-labelledby="visit-heading"
                                className="rounded-xl border"
                            >
                                <div className="space-y-1 p-5">
                                    <h2
                                        id="visit-heading"
                                        className="text-lg font-semibold"
                                    >
                                        {formatClinicDateTime(startsAt)} to{' '}
                                        {formatClinicTime(endsAt)}
                                    </h2>
                                    <p>
                                        {service.name} ·{' '}
                                        {service.durationMinutes} min
                                    </p>
                                    <p className="text-muted-foreground">
                                        {doctorLabel}
                                        {doctor !== null &&
                                            ` · ${doctor.specialty}`}
                                    </p>
                                    {doctor === null && (
                                        <p className="text-sm text-muted-foreground">
                                            We pick a doctor who is free at this
                                            time and show you who it is after
                                            you confirm.
                                        </p>
                                    )}
                                </div>
                                <p className="rounded-b-xl border-t bg-muted/50 px-5 py-3 text-sm text-muted-foreground">
                                    Free to cancel until{' '}
                                    <strong className="text-foreground">
                                        {formatClinicDateTime(cancelUntil)}
                                    </strong>
                                    .
                                </p>
                            </section>

                            <p className="flex items-start gap-2 rounded-xl border bg-muted/50 p-4 text-sm">
                                <ClipboardList
                                    aria-hidden
                                    className="mt-0.5 size-4 shrink-0"
                                />
                                <span>
                                    After you confirm, you can fill in a short
                                    intake form so your doctor can read it
                                    before your visit.
                                </span>
                            </p>

                            <div className="flex flex-wrap gap-3">
                                <Button
                                    type="submit"
                                    size="lg"
                                    disabled={processing}
                                    data-test="confirm-booking-button"
                                >
                                    {processing && <Spinner />}
                                    Confirm booking
                                </Button>
                                <Button asChild variant="outline" size="lg">
                                    <Link href={backHref}>
                                        Pick another time
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
