import { cn } from '@/lib/utils';
import type { FollowUpState } from '@/types';

/**
 * One tone per meaning, from `app.css`: danger = needs a call, amber = open, grey = not started or closed, teal = booked.
 * The state is always written out next to the dot (`label`), so it never depends on color alone.
 */
const tones: Record<FollowUpState, string> = {
    call: 'bg-danger-soft text-danger-ink',
    open: 'bg-amber-soft text-amber-ink',
    waiting: 'bg-done-soft text-done-ink',
    booked: 'bg-teal-soft text-teal-ink',
    dismissed: 'bg-done-soft text-done-ink',
};

type Props = {
    state: FollowUpState;
    /** The words the server sends, for example "Open now · 3 days left". */
    label: string;
    className?: string;
};

export default function FollowUpStateBadge({ state, label, className }: Props) {
    return (
        <span
            className={cn(
                'inline-flex w-fit items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                tones[state],
                className,
            )}
        >
            <span
                aria-hidden
                className="size-1.5 shrink-0 rounded-full bg-current"
            />
            {label}
        </span>
    );
}
