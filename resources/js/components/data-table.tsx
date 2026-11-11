import { Link } from '@inertiajs/react';
import type { ComponentProps, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

type TableProps = {
    /** Read by screen readers only. Say what the table lists. */
    caption: string;
    children: ReactNode;
    className?: string;
};

/**
 * The shared look of an admin list: a card that scrolls sideways on its own, so a wide table never makes the page
 * scroll. Put a `<thead>` and a `<tbody>` inside, built from `Th` and `Td`.
 */
export function DataTable({ caption, children, className }: TableProps) {
    return (
        <div
            className={cn(
                'overflow-x-auto rounded-xl border bg-card shadow-xs',
                className,
            )}
        >
            <table className="w-full text-sm [&_tbody_tr]:transition-colors [&_tbody_tr:hover]:bg-muted/40">
                <caption className="sr-only">{caption}</caption>
                {children}
            </table>
        </div>
    );
}

type ThProps = ComponentProps<'th'> & {
    /** An actions column has no visible title, so the title is for screen readers only. */
    srOnly?: boolean;
};

export function Th({ srOnly, className, children, ...props }: ThProps) {
    return (
        <th
            scope="col"
            className={cn(
                'bg-muted/50 px-4 py-3 text-left text-xs font-semibold tracking-wide whitespace-nowrap text-muted-foreground uppercase',
                srOnly && 'text-right',
                className,
            )}
            {...props}
        >
            {srOnly ? <span className="sr-only">{children}</span> : children}
        </th>
    );
}

export function Td({ className, ...props }: ComponentProps<'td'>) {
    return (
        <td
            className={cn(
                'border-t px-4 py-3 align-middle first:border-t-0',
                className,
            )}
            {...props}
        />
    );
}

/** The cell that holds a row's buttons, kept to the right. Buttons are the default 44 px size, not `sm`. */
export function ActionsTd({ children }: { children: ReactNode }) {
    return (
        <Td>
            <div className="flex flex-wrap justify-end gap-2">{children}</div>
        </Td>
    );
}

type PagerProps = {
    paginator: Paginated<unknown>;
    /** Shown when the list is empty, for example "No services yet". */
    empty: string;
};

/** "Showing 1 to 10 of 24" with Previous and Next. The links keep the scroll position. */
export function Pager({ paginator, empty }: PagerProps) {
    return (
        <nav
            className="flex flex-wrap items-center justify-between gap-3 text-sm"
            aria-label="Pagination"
        >
            <p className="text-muted-foreground">
                {paginator.from !== null
                    ? `Showing ${paginator.from} to ${paginator.to} of ${paginator.total}`
                    : empty}
            </p>
            <div className="flex gap-2">
                {paginator.prev_page_url ? (
                    <Button asChild variant="outline">
                        <Link href={paginator.prev_page_url} preserveScroll>
                            Previous
                        </Link>
                    </Button>
                ) : null}
                {paginator.next_page_url ? (
                    <Button asChild variant="outline">
                        <Link href={paginator.next_page_url} preserveScroll>
                            Next
                        </Link>
                    </Button>
                ) : null}
            </div>
        </nav>
    );
}
