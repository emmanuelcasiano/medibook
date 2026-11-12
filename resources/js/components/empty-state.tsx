import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    icon: LucideIcon;
    /** One short sentence that says what is missing and, when it helps, what to do next. */
    children: ReactNode;
    /** The one next step, usually a `Button` (`asChild` around a `Link`). Leave it out when there is nothing to do. */
    action?: ReactNode;
    className?: string;
};

/**
 * What a list shows when it has nothing to list: an icon, one sentence and at most one action. Every list page uses
 * this, so an empty screen looks the same everywhere and never looks broken.
 */
export default function EmptyState({
    icon: Icon,
    children,
    action,
    className,
}: Props) {
    return (
        <div
            className={cn(
                'flex flex-col items-center gap-3 rounded-xl border bg-card p-8 text-center',
                className,
            )}
        >
            <Icon aria-hidden className="size-7 text-muted-foreground" />
            <p className="max-w-prose font-medium">{children}</p>
            {action}
        </div>
    );
}
