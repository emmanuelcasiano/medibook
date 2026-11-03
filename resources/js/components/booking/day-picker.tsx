import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    clinicDayOfMonth,
    formatClinicDate,
    formatClinicMonth,
    formatClinicShortMonth,
    formatClinicShortWeekday,
} from '@/lib/clinic-time';
import { cn } from '@/lib/utils';
import type { BookingDay } from '@/types';

type Props = {
    days: BookingDay[];
    selectedDate: string;
    hrefFor: (date: string) => NonNullable<InertiaLinkProps['href']>;
    /** Called when the page for a picked day has loaded, for example to scroll its times into view. */
    onPicked?: () => void;
};

const DAYS_PER_PAGE = 7;

const pageOf = (days: BookingDay[], date: string) =>
    Math.max(
        0,
        Math.floor(days.findIndex((day) => day.date === date) / DAYS_PER_PAGE),
    );

/** "November 2026", or "Nov to Dec 2026" when the week crosses a month. */
function weekTitle(week: BookingDay[]): string {
    const first = week[0].date;
    const last = week[week.length - 1].date;

    if (first.slice(0, 7) === last.slice(0, 7)) {
        return formatClinicMonth(first);
    }

    return `${formatClinicShortMonth(first)} to ${formatClinicShortMonth(last)} ${last.slice(0, 4)}`;
}

/**
 * The booking window one week at a time. A phone has room for seven days in a row but not for a month grid, and the
 * times of the picked day are then close below. A day with no free time is shown dashed and can't be picked.
 */
export default function DayPicker({
    days,
    selectedDate,
    hrefFor,
    onPicked,
}: Props) {
    const [page, setPage] = useState(() => pageOf(days, selectedDate));
    const [seenDate, setSeenDate] = useState(selectedDate);

    // When the server shows a day on another week (for example after an error), follow it.
    if (seenDate !== selectedDate) {
        setSeenDate(selectedDate);
        setPage(pageOf(days, selectedDate));
    }

    const pageCount = Math.max(1, Math.ceil(days.length / DAYS_PER_PAGE));
    const week = days.slice(page * DAYS_PER_PAGE, (page + 1) * DAYS_PER_PAGE);

    if (week.length === 0) {
        return null;
    }

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between gap-2">
                <h3 className="text-base font-semibold">{weekTitle(week)}</h3>
                <div className="flex gap-1.5">
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="size-11"
                        aria-label="Earlier days"
                        disabled={page === 0}
                        onClick={() => setPage(page - 1)}
                    >
                        <ChevronLeft aria-hidden />
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="size-11"
                        aria-label="Later days"
                        disabled={page >= pageCount - 1}
                        onClick={() => setPage(page + 1)}
                    >
                        <ChevronRight aria-hidden />
                    </Button>
                </div>
            </div>

            <div className="grid grid-cols-7 gap-1">
                {week.map((day) => {
                    const isSelected = day.date === selectedDate;
                    const isOpen = day.slotsCount > 0;
                    const label = `${formatClinicDate(day.date)}, ${
                        isOpen
                            ? `${day.slotsCount} open ${day.slotsCount === 1 ? 'time' : 'times'}`
                            : 'no open times'
                    }`;
                    const cell =
                        'flex min-h-[4.25rem] flex-col items-center justify-center gap-0.5 rounded-lg border px-0.5 py-1.5 text-center leading-tight';
                    const content = (
                        <>
                            <span className="text-[11px] font-medium uppercase">
                                {formatClinicShortWeekday(day.date)}
                            </span>
                            <span className="text-lg font-semibold">
                                {clinicDayOfMonth(day.date)}
                            </span>
                            <span className="text-[11px]">
                                {isOpen ? `${day.slotsCount} open` : 'None'}
                            </span>
                        </>
                    );

                    // No free time: dashed, faded and not a link. "None" says why in text, the label in full.
                    if (!isOpen) {
                        return (
                            <span
                                key={day.date}
                                role="img"
                                aria-label={label}
                                aria-current={isSelected ? 'date' : undefined}
                                className={cn(
                                    cell,
                                    'border-dashed border-done-line text-subtle-foreground',
                                )}
                            >
                                {content}
                            </span>
                        );
                    }

                    return (
                        <Link
                            key={day.date}
                            href={hrefFor(day.date)}
                            preserveScroll
                            preserveState
                            onSuccess={onPicked}
                            aria-label={label}
                            aria-current={isSelected ? 'date' : undefined}
                            className={cn(
                                cell,
                                'border-input bg-card text-foreground outline-none hover:border-primary focus-visible:ring-[3px] focus-visible:ring-ring',
                                isSelected &&
                                    'border-primary bg-primary text-primary-foreground hover:bg-primary/90',
                            )}
                        >
                            {content}
                        </Link>
                    );
                })}
            </div>
        </div>
    );
}
