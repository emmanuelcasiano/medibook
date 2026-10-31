import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import ScheduleController from '@/actions/App/Http/Controllers/Staff/ScheduleController';
import ScheduleDoctorList from '@/components/schedule/schedule-doctor-list';
import ScheduleGrid from '@/components/schedule/schedule-grid';
import {
    statusLabels,
    statusStyles,
} from '@/components/schedule/status-styles';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatClinicDate } from '@/lib/clinic-time';
import { cn } from '@/lib/utils';
import type { DaySchedule } from '@/types';

type Props = {
    schedule: DaySchedule;
};

const legendStatuses = Object.keys(statusLabels) as Array<
    keyof typeof statusLabels
>;

/** Earlier years are only a step on the way to typing a real one, such as the "0202" on the way to "2026". */
const FIRST_YEAR = 2000;

/** A full 'Y-m-d' date in a plausible year. */
function isCompleteDate(value: string): boolean {
    return (
        /^\d{4}-\d{2}-\d{2}$/.test(value) &&
        Number(value.slice(0, 4)) >= FIRST_YEAR
    );
}

export default function StaffSchedule({ schedule }: Props) {
    const { auth } = usePage().props;
    const viewOnly = auth.user?.role === 'admin';

    const appointments = schedule.doctors.flatMap(
        (doctor) => doctor.appointments,
    );
    const count = (status: (typeof appointments)[number]['status']) =>
        appointments.filter((appointment) => appointment.status === status)
            .length;

    // What the person has typed in the date field, until the page for it has loaded. null means "show the day of the
    // page". A date field reports a valid date after every digit of the year ("0002-11-02", "0020-11-02", ...), so
    // the page must not follow each change: it would reload on the first digit and the person could not finish typing.
    const [draft, setDraft] = useState<string | null>(null);

    function changeDate(value: string) {
        setDraft(value);

        if (!isCompleteDate(value)) {
            return;
        }

        router.get(
            ScheduleController.index.url({ query: { date: value } }),
            {},
            { preserveScroll: true, onFinish: () => setDraft(null) },
        );
    }

    return (
        <>
            <Head title="Day schedule" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="flex flex-wrap items-center gap-2 text-2xl font-semibold tracking-tight">
                            Day schedule
                            {viewOnly && (
                                <Badge variant="outline">View only</Badge>
                            )}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {formatClinicDate(schedule.date)}. All times are
                            Manila time.
                        </p>
                        {/* Read out when the day changes. */}
                        <p
                            aria-live="polite"
                            className="text-sm text-muted-foreground"
                        >
                            {appointments.length}{' '}
                            {appointments.length === 1
                                ? 'appointment'
                                : 'appointments'}{' '}
                            · {count('booked')} booked · {count('checked_in')}{' '}
                            checked in · {count('completed')} completed ·{' '}
                            {count('no_show')} no-show
                        </p>
                    </div>

                    <nav
                        aria-label="Choose a day"
                        className="flex flex-wrap items-center gap-2"
                    >
                        <Button asChild variant="outline" size="icon">
                            <Link
                                href={ScheduleController.index({
                                    query: { date: schedule.previousDate },
                                })}
                                preserveScroll
                                aria-label="Previous day"
                            >
                                <ChevronLeft aria-hidden />
                            </Link>
                        </Button>
                        <div>
                            <label htmlFor="schedule-date" className="sr-only">
                                Date
                            </label>
                            {/* No `key` on this field: rebuilding it after each load would take the focus away. */}
                            <Input
                                id="schedule-date"
                                type="date"
                                value={draft ?? schedule.date}
                                onChange={(event) =>
                                    changeDate(event.target.value)
                                }
                                // Leaving a half-typed date puts the field back to the day on the page.
                                onBlur={() => setDraft(null)}
                                className="w-auto font-medium"
                            />
                        </div>
                        <Button asChild variant="outline" size="icon">
                            <Link
                                href={ScheduleController.index({
                                    query: { date: schedule.nextDate },
                                })}
                                preserveScroll
                                aria-label="Next day"
                            >
                                <ChevronRight aria-hidden />
                            </Link>
                        </Button>
                        <Button asChild variant="secondary">
                            <Link href={ScheduleController.index()}>Today</Link>
                        </Button>
                    </nav>
                </div>

                <ul
                    aria-label="What the colors mean"
                    className="hidden flex-wrap gap-x-4 gap-y-1 text-xs md:flex"
                >
                    {legendStatuses.map((status) => (
                        <li key={status} className="flex items-center gap-1.5">
                            <span
                                aria-hidden
                                className={cn(
                                    'size-3 rounded-sm border',
                                    statusStyles[status],
                                )}
                            />
                            {statusLabels[status]}
                        </li>
                    ))}
                </ul>

                {schedule.doctors.length > 0 ? (
                    <>
                        <ScheduleGrid schedule={schedule} />
                        <ScheduleDoctorList doctors={schedule.doctors} />
                    </>
                ) : (
                    <p className="rounded-xl border p-8 text-center text-sm text-muted-foreground">
                        There are no doctors to show.
                    </p>
                )}
            </div>
        </>
    );
}

StaffSchedule.layout = {
    breadcrumbs: [
        {
            title: 'Day schedule',
            href: ScheduleController.index(),
        },
    ],
};
