import { cn } from '@/lib/utils';
import type { AppointmentStatus, SummaryStatus } from '@/types';

export type StatusKind = AppointmentStatus | SummaryStatus;

/**
 * One color per meaning, from `app.css`: blue = booked, teal = checked in, grey = completed, amber = cancelled,
 * violet = no-show, indigo = the AI draft. Red is kept for danger and the emergency flag, so it is not used here
 * except for a failed summary.
 */
const tones: Record<StatusKind, string> = {
    booked: 'bg-info-soft text-info-ink',
    checked_in: 'bg-teal-soft text-teal-ink',
    completed: 'bg-done-soft text-done-ink',
    cancelled: 'bg-amber-soft text-amber-ink',
    no_show: 'bg-violet-soft text-violet-ink',
    pending: 'bg-ai-soft text-ai-ink',
    ready: 'bg-ai-soft text-ai-ink',
    skipped: 'bg-amber-soft text-amber-ink',
    failed: 'bg-danger-soft text-danger-ink',
};

type Props = {
    status: StatusKind;
    /** The label the server sends. It is always shown, so the status never depends on color alone. */
    label: string;
    className?: string;
};

export default function StatusBadge({ status, label, className }: Props) {
    return (
        <span
            data-slot="status-badge"
            className={cn(
                'inline-flex w-fit shrink-0 items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap',
                tones[status],
                className,
            )}
        >
            <span aria-hidden className="size-1.5 rounded-full bg-current" />
            {label}
        </span>
    );
}
