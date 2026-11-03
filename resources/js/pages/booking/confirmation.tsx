import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Check, ClipboardList } from 'lucide-react';
import IntakeController from '@/actions/App/Http/Controllers/Patient/IntakeController';
import BookingSummary from '@/components/booking/booking-summary';
import { Button } from '@/components/ui/button';
import { home as myHome } from '@/routes/my';
import type { BookedAppointment } from '@/types';

type Props = {
    appointment: BookedAppointment;
};

export default function BookingConfirmation({ appointment }: Props) {
    const { service, doctor, startsAt, endsAt, cancelUntil } = appointment;

    return (
        <>
            <Head title="You're booked" />

            <div className="mx-auto max-w-2xl space-y-6">
                <div className="flex flex-col items-center gap-3 text-center">
                    <span
                        aria-hidden
                        className="flex size-14 items-center justify-center rounded-full bg-teal-soft text-teal-ink"
                    >
                        <Check className="size-7" strokeWidth={2.5} />
                    </span>
                    {/* Focus is not moved here: Inertia puts it at the top of the new page, and this heading is the first thing read. */}
                    <h1 className="text-3xl font-semibold tracking-tight">
                        You're booked
                    </h1>
                    <p className="text-muted-foreground">
                        All times are Manila time.
                    </p>
                </div>

                <BookingSummary
                    heading="Your visit"
                    service={service}
                    doctorName={doctor.name}
                    doctorSpecialty={doctor.specialty}
                    startsAt={startsAt}
                    endsAt={endsAt}
                    cancelUntil={cancelUntil}
                />

                {/* The one dark-teal card of the screen: the next step to take. */}
                <section
                    aria-labelledby="intake-heading"
                    className="space-y-4 rounded-xl bg-deep p-5 text-deep-foreground sm:p-6"
                >
                    <div className="flex items-start gap-3">
                        <span
                            aria-hidden
                            className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-deep-line text-mint"
                        >
                            <ClipboardList className="size-5" />
                        </span>
                        <div className="space-y-1.5">
                            <h2
                                id="intake-heading"
                                className="text-lg font-semibold text-white"
                            >
                                Next: tell your doctor why you're coming in
                            </h2>
                            <p className="text-sm leading-6 text-deep-muted">
                                The intake form takes about 3 minutes. Only your
                                doctor reads your answers. You can change them
                                until you check in.
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-3">
                        <Button
                            asChild
                            size="lg"
                            className="w-full bg-mint text-deep hover:bg-mint/90 sm:w-auto"
                        >
                            <Link
                                href={IntakeController.edit(appointment.ulid)}
                            >
                                Fill in your intake form
                                <ArrowRight aria-hidden />
                            </Link>
                        </Button>
                        <Button
                            asChild
                            variant="outline"
                            size="lg"
                            className="w-full border-deep-line bg-transparent text-white hover:bg-white/10 hover:text-white sm:w-auto"
                        >
                            <Link href={myHome()}>View my appointments</Link>
                        </Button>
                    </div>
                </section>
            </div>
        </>
    );
}
