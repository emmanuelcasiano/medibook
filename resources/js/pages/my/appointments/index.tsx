import { Head, Link } from '@inertiajs/react';
import { CalendarDays, Info, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AppointmentCard from '@/components/appointments/appointment-card';
import FollowUpCard from '@/components/appointments/follow-up-card';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { services as bookingServices } from '@/routes/booking';
import { home as myHome } from '@/routes/my';
import type { MyAppointment, MyFollowUp, Paginated } from '@/types';

/** How many past visits show before "Show all on this page". The server's paging is not changed. */
const PAST_PREVIEW = 5;

type Props = {
    /** Visits a doctor asked the patient to book. Empty when there is none. */
    followUps: MyFollowUp[];
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
    followUps,
    upcoming,
    past,
    cancelHours,
    maxUpcoming,
    canBook,
}: Props) {
    // Remember which page was expanded, so paging to another page of past visits starts short again.
    const [expandedPage, setExpandedPage] = useState<number | null>(null);
    const showAllPast = expandedPage === past.current_page;

    // The "Show all" button removes itself when pressed, so focus would fall back to the top of the page. After the
    // press it moves to the first card that was just revealed. Only a press sets the flag, so paging back to an
    // expanded page does not steal focus.
    const pastList = useRef<HTMLUListElement>(null);
    const focusRevealed = useRef(false);

    useEffect(() => {
        if (!focusRevealed.current) {
            return;
        }

        focusRevealed.current = false;

        const firstRevealed = pastList.current?.children[PAST_PREVIEW];

        if (firstRevealed instanceof HTMLElement) {
            firstRevealed.tabIndex = -1;
            firstRevealed.focus();
        }
    }, [expandedPage]);

    const hiddenPast = showAllPast
        ? 0
        : Math.max(0, past.data.length - PAST_PREVIEW);
    const visiblePast = showAllPast
        ? past.data
        : past.data.slice(0, PAST_PREVIEW);

    return (
        <>
            <Head title="My appointments" />

            <div className="space-y-8 p-4">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <PageHeader
                        title="My appointments"
                        description={`All times are Manila time. You can cancel for free up to ${cancelHours} hours before a visit.`}
                    />
                    <div className="flex flex-wrap items-center gap-3">
                        {!canBook && (
                            <p
                                id="book-limit-note"
                                className="flex max-w-xs items-start gap-2 rounded-lg border border-amber-line bg-amber-soft px-3 py-2 text-sm text-amber-ink"
                            >
                                <Info
                                    aria-hidden
                                    className="mt-0.5 size-4 shrink-0"
                                />
                                You have {maxUpcoming} upcoming appointments.
                                Cancel one to book another.
                            </p>
                        )}
                        {canBook ? (
                            <Button asChild>
                                <Link href={bookingServices()}>
                                    <Plus aria-hidden />
                                    Book an appointment
                                </Link>
                            </Button>
                        ) : (
                            <Button disabled aria-describedby="book-limit-note">
                                <Plus aria-hidden />
                                Book an appointment
                            </Button>
                        )}
                    </div>
                </div>

                {followUps.length > 0 && (
                    <section
                        aria-labelledby="follow-ups-heading"
                        className="space-y-3"
                    >
                        <h2
                            id="follow-ups-heading"
                            className="text-lg font-semibold"
                        >
                            Follow-ups to book{' '}
                            <span className="text-muted-foreground">
                                ({followUps.length})
                            </span>
                        </h2>
                        <ul className="space-y-3">
                            {followUps.map((followUp) => (
                                <FollowUpCard
                                    key={followUp.ulid}
                                    followUp={followUp}
                                    canBook={canBook}
                                />
                            ))}
                        </ul>
                    </section>
                )}

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
                    {/* Built from the props the page already has. The text carries the meaning; the bars are for the eye. */}
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <span aria-hidden className="flex gap-1">
                            {Array.from({ length: maxUpcoming }, (_, index) => (
                                <span
                                    key={index}
                                    className={cn(
                                        'h-1.5 w-6 rounded-full',
                                        index < upcoming.length
                                            ? 'bg-teal'
                                            : 'bg-muted',
                                    )}
                                />
                            ))}
                        </span>
                        {upcoming.length} of {maxUpcoming} upcoming
                    </p>
                    {upcoming.length > 0 ? (
                        <ul className="space-y-3">
                            {upcoming.map((appointment, index) => (
                                <AppointmentCard
                                    key={appointment.ulid}
                                    appointment={appointment}
                                    upcoming
                                    next={index === 0}
                                />
                            ))}
                        </ul>
                    ) : (
                        <div className="flex flex-col items-center gap-3 rounded-xl border bg-card p-8 text-center">
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
                        <>
                            <ul ref={pastList} className="space-y-3">
                                {visiblePast.map((appointment) => (
                                    <AppointmentCard
                                        key={appointment.ulid}
                                        appointment={appointment}
                                        upcoming={false}
                                    />
                                ))}
                            </ul>
                            {hiddenPast > 0 && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        focusRevealed.current = true;
                                        setExpandedPage(past.current_page);
                                    }}
                                >
                                    Show all on this page
                                    <span className="text-muted-foreground">
                                        ({hiddenPast} more)
                                    </span>
                                </Button>
                            )}
                        </>
                    ) : (
                        <p className="rounded-xl border bg-card p-6 text-center text-sm text-muted-foreground">
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
