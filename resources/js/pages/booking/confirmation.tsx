import { Head, Link } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import IntakeController from '@/actions/App/Http/Controllers/Patient/IntakeController';
import { Button } from '@/components/ui/button';
import { formatClinicDateTime, formatClinicTime } from '@/lib/clinic-time';
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
                <div className="space-y-3 text-center">
                    <CircleCheck
                        aria-hidden
                        className="mx-auto size-10 text-primary"
                    />
                    {/* Focus is not moved here: Inertia puts it at the top of the new page, and this heading is the first thing read. */}
                    <h1 className="text-3xl font-semibold tracking-tight">
                        You're booked
                    </h1>
                    <p className="text-muted-foreground">
                        All times are Manila time.
                    </p>
                </div>

                <section
                    aria-labelledby="booked-visit-heading"
                    className="rounded-xl border"
                >
                    <div className="space-y-1 p-5">
                        <h2
                            id="booked-visit-heading"
                            className="text-lg font-semibold"
                        >
                            {formatClinicDateTime(startsAt)} to{' '}
                            {formatClinicTime(endsAt)}
                        </h2>
                        <p>
                            {service.name} · {service.durationMinutes} min
                        </p>
                        <p className="text-muted-foreground">
                            {doctor.name} · {doctor.specialty}
                        </p>
                    </div>
                    <p className="rounded-b-xl border-t bg-muted/50 px-5 py-3 text-sm text-muted-foreground">
                        Free to cancel until{' '}
                        <strong className="text-foreground">
                            {formatClinicDateTime(cancelUntil)}
                        </strong>
                        .
                    </p>
                </section>

                <section
                    aria-labelledby="intake-heading"
                    className="space-y-3 rounded-xl border bg-muted/50 p-5"
                >
                    <h2 id="intake-heading" className="text-lg font-semibold">
                        Next: tell your doctor why you're coming in
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        The intake form takes about 3 minutes. Only your doctor
                        reads your answers. You can change them until you check
                        in.
                    </p>
                    <div className="flex flex-wrap gap-3">
                        <Button asChild>
                            <Link
                                href={IntakeController.edit(appointment.ulid)}
                            >
                                Fill in your intake form
                            </Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href={myHome()}>Go to my appointments</Link>
                        </Button>
                    </div>
                </section>
            </div>
        </>
    );
}
