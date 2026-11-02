import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    /** The one `h1` of the page. */
    title: ReactNode;
    description?: ReactNode;
    /** The page's primary action (and its quiet neighbours), shown on the right and under the title on a phone. */
    actions?: ReactNode;
    className?: string;
};

/**
 * The top of a page: a single `h1`, a short line under it, and the actions. Use it once per page, so every page has
 * the same first thing to read and screen reader users land on one clear title.
 */
export default function PageHeader({
    title,
    description,
    actions,
    className,
}: Props) {
    return (
        <header
            className={cn(
                'flex flex-wrap items-end justify-between gap-x-4 gap-y-3',
                className,
            )}
        >
            <div className="min-w-0 space-y-1">
                <h1 className="flex flex-wrap items-center gap-x-3 gap-y-1 text-2xl font-semibold tracking-tight md:text-[28px] md:leading-[34px]">
                    {title}
                </h1>
                {description && (
                    <div className="max-w-prose space-y-1 text-sm text-muted-foreground">
                        {typeof description === 'string' ? (
                            <p>{description}</p>
                        ) : (
                            description
                        )}
                    </div>
                )}
            </div>
            {actions && (
                <div className="flex flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </header>
    );
}
