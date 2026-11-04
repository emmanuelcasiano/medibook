import { Clock } from 'lucide-react';
import { formatWait } from '@/lib/schedule-time';
import { cn } from '@/lib/utils';

type Props = {
    /** How late the booking is. Shown after the word ("Late 25 min") when given, e.g. in the list and the panel. */
    minutes?: number;
    className?: string;
};

/**
 * "Late" with a clock icon, for a booking that is still `booked` well after its start. Words and an icon, never the
 * color alone. It has its own light background, so the amber text stays readable on every status color of a grid block
 * and in dark mode.
 */
export default function LateLabel({ minutes, className }: Props) {
    return (
        <span
            data-test="late-label"
            className={cn(
                'inline-flex shrink-0 items-center gap-1 rounded border border-amber-300 bg-amber-100 px-1 text-[0.7rem] leading-4 font-medium whitespace-nowrap text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200',
                className,
            )}
        >
            <Clock aria-hidden className="size-3" />
            Late{minutes !== undefined && ` ${formatWait(minutes)}`}
        </span>
    );
}
