import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** The same soft colors as `StatusBadge`, for admin states that are not appointment or summary statuses. */
const tones = {
    good: 'bg-teal-soft text-teal-ink',
    neutral: 'bg-done-soft text-done-ink',
    warning: 'bg-amber-soft text-amber-ink',
} as const;

type Props = {
    tone: keyof typeof tones;
    /** The text is always shown, so the state never depends on color alone. */
    children: ReactNode;
    /** A small badge for a secondary fact, such as "2FA on". It has no dot. */
    small?: boolean;
    className?: string;
};

/** A pill like `StatusBadge`: "Active", "Not bookable", "Account inactive", "2FA off". */
export default function StatePill({ tone, children, small, className }: Props) {
    return (
        <span
            data-slot="state-pill"
            className={cn(
                'inline-flex w-fit shrink-0 items-center gap-1.5 rounded-full font-semibold whitespace-nowrap',
                small ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-0.5 text-xs',
                tones[tone],
                className,
            )}
        >
            {!small && (
                <span
                    aria-hidden
                    className="size-1.5 rounded-full bg-current"
                />
            )}
            {children}
        </span>
    );
}
