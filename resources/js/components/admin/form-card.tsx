import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** The card every admin form sits in, so add and edit screens look the same. */
export default function FormCard({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'rounded-xl border bg-card p-5 shadow-xs md:p-6',
                className,
            )}
        >
            {children}
        </div>
    );
}
