import { Head, Link } from '@inertiajs/react';
import { CalendarDays, Plus } from 'lucide-react';
import AppointmentCard from '@/components/appointments/appointment-card';
import { Button } from '@/components/ui/button';
import { services as bookingServices } from '@/routes/booking';
import { home as myHome } from '@/routes/my';
import type { MyAppointment, Paginated } from '@/types';

type Props = {
    upcoming: MyAppointment[];
    past: Paginated<MyAppointment>;
    /** How many hours before a visit a patient can still cancel. */
    cancelHours: number;
    /** How many upcoming appointments one patient may hold. */
    maxUpcoming: number;
    /** False at the limit. The server still refuses a booking over the limit. */
    canBook: boolean;
};

export default function MyAppointments({
    upcoming,
    past,
    cancelHours,
    maxUpcoming,
    canBook,
}: Props) {
    return (
        <>
            <Head title="My appointments" />

            <div className="space-y-8 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            My appointments
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            All times are Manila time. You can cancel for free
                            up to {cancelHours} hours before a visit.
                        </p>
                    </div>
                    <div className="space-y-1 sm:text-right">
                        {canBook ? (
                            <Button asChild>
                                <Link href={bookingServices()}>
                                    <Plus aria-hidden />
                                    Book an appointment
                                </Link>
                            </Button>
                        ) : (
                            <>
                                <Button
                                    disabled
                                    aria-describedby="book-limit-note"
                                >
                                    <Plus aria-hidden />
                                    Book an appointment
                                </Button>
                                <p
                                    id="book-limit-note"
                                    className="text-sm text-muted-foreground"
                                >
                                    You have {maxUpcoming} upcoming
                                    appointments. Cancel one to book another.
                                </p>
                            </>
                        )}
                    </div>
                </div>

                <section
                    aria-labelledby="upcoming-heading"
                    className="space-y-3"
                >
                    {/* Focus lands here after a cancel, because the card the patient was on is gone. */}
                    <h2
                        id="upcoming-heading"
                        tabIndex={-1}
                        data-after-cancel-focus
                        className="text-lg font-semibold outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        Upcoming{' '}
                        <span className="text-muted-foreground">
                            ({upcoming.length})
                        </span>
                    </h2>
                    {upcoming.length > 0 ? (
                        <ul className="space-y-3">
                            {upcoming.map((appointment) => (
                                <AppointmentCard
                                    key={appointment.ulid}
                                    appointment={appointment}
                                    upcoming
                                />
                            ))}
                        </ul>
                    ) : (
                        <div className="flex flex-col items-center gap-3 rounded-xl border p-8 text-center">
                            <CalendarDays
                                aria-hidden
                                className="size-7 text-muted-foreground"
                            />
                            <p className="font-medium">
                                No upcoming appointments.
                            </p>
                            <Button asChild variant="outline">
                                <Link href={bookingServices()}>
                                    Book an appointment
                                </Link>
                            </Button>
                        </div>
                    )}
                </section>

                <section aria-labelledby="past-heading" className="space-y-3">
                    <h2 id="past-heading" className="text-lg font-semibold">
                        Past{' '}
                        <span className="text-muted-foreground">
                            ({past.total})
                        </span>
                    </h2>
                    {past.data.length > 0 ? (
                        <ul className="space-y-3">
                            {past.data.map((appointment) => (
                                <AppointmentCard
                                    key={appointment.ulid}
                                    appointment={appointment}
                                    upcoming={false}
                                />
                            ))}
                        </ul>
                    ) : (
                        <p className="rounded-xl border p-6 text-center text-sm text-muted-foreground">
                            No past visits yet.
                        </p>
                    )}

                    {(past.prev_page_url || past.next_page_url) && (
                        <nav
                            className="flex items-center justify-between text-sm"
                            aria-label="Past appointments pages"
                        >
                            <p className="text-muted-foreground">
                                Showing {past.from} to {past.to} of {past.total}
                            </p>
                            <div className="flex gap-2">
                                {past.prev_page_url && (
                                    <Button asChild variant="outline" size="sm">
                                        <Link
                                            href={past.prev_page_url}
                                            preserveScroll
                                        >
                                            Previous
                                        </Link>
                                    </Button>
                                )}
                                {past.next_page_url && (
                                    <Button asChild variant="outline" size="sm">
                                        <Link
                                            href={past.next_page_url}
                                            preserveScroll
                                        >
                                            Next
                                        </Link>
                                    </Button>
                                )}
                            </div>
                        </nav>
                    )}
                </section>
            </div>
        </>
    );
}

MyAppointments.layout = {
    breadcrumbs: [
        {
            title: 'My appointments',
            href: myHome(),
        },
    ],
};
