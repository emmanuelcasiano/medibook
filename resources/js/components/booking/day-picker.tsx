import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import {
    clinicDayOfMonth,
    clinicWeekday,
    formatClinicDate,
    formatClinicMonth,
} from '@/lib/clinic-time';
import { cn } from '@/lib/utils';
import type { BookingDay } from '@/types';

type Props = {
    days: BookingDay[];
    selectedDate: string;
    hrefFor: (date: string) => NonNullable<InertiaLinkProps['href']>;
};

const weekdayHeads = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** The days of the booking window as a month calendar. A day with no free time is shown but can't be picked. */
export default function DayPicker({ days, selectedDate, hrefFor }: Props) {
    const months = days.reduce<Record<string, BookingDay[]>>((groups, day) => {
        const key = day.date.slice(0, 7);

        return { ...groups, [key]: [...(groups[key] ?? []), day] };
    }, {});

    return (
        <div className="space-y-6">
            {Object.entries(months).map(([key, monthDays]) => (
                <section key={key} aria-labelledby={`month-${key}`}>
                    <h3
                        id={`month-${key}`}
                        className="mb-2 text-sm font-medium"
                    >
                        {formatClinicMonth(monthDays[0].date)}
                    </h3>

                    <div className="grid grid-cols-7 gap-1">
                        {weekdayHeads.map((head) => (
                            <span
                                key={head}
                                aria-hidden
                                className="pb-1 text-center text-xs text-muted-foreground"
                            >
                                {head}
                            </span>
                        ))}

                        {/* Blank cells push the first day under its weekday. */}
                        {Array.from({
                            length: clinicWeekday(monthDays[0].date),
                        }).map((_, index) => (
                            <span key={`blank-${index}`} aria-hidden />
                        ))}

                        {monthDays.map((day) => {
                            const isSelected = day.date === selectedDate;
                            const isOpen = day.slotsCount > 0;
                            const label = `${formatClinicDate(day.date)}, ${
                                isOpen
                                    ? `${day.slotsCount} open ${day.slotsCount === 1 ? 'time' : 'times'}`
                                    : 'no open times'
                            }`;
                            const cell =
                                'flex min-h-11 items-center justify-center rounded-md text-sm';

                            // No free time: shown faded, without a border, and not a link.
                            if (!isOpen) {
                                return (
                                    <span
                                        key={day.date}
                                        role="img"
                                        aria-label={label}
                                        aria-current={
                                            isSelected ? 'date' : undefined
                                        }
                                        className={cn(
                                            cell,
                                            'text-muted-foreground/50',
                                            isSelected && 'ring-1 ring-border',
                                        )}
                                    >
                                        {clinicDayOfMonth(day.date)}
                                    </span>
                                );
                            }

                            return (
                                <Link
                                    key={day.date}
                                    href={hrefFor(day.date)}
                                    preserveScroll
                                    preserveState
                                    aria-label={label}
                                    aria-current={
                                        isSelected ? 'date' : undefined
                                    }
                                    className={cn(
                                        cell,
                                        'border font-medium outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                        isSelected &&
                                            'border-primary bg-primary text-primary-foreground hover:bg-primary/90',
                                    )}
                                >
                                    {clinicDayOfMonth(day.date)}
                                </Link>
                            );
                        })}
                    </div>
                </section>
            ))}
        </div>
    );
}
