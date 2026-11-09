import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /** A path that keeps the item active on deeper pages, when its own `href` is not the start of them. */
    activePrefix?: string;
    /** Active on its own address only, for an item whose address is the start of a sibling's (`/staff`, `/staff/follow-ups`). */
    exact?: boolean;
    /** Marks an item that shows a count badge from a shared page prop, when that count is above zero. */
    badge?: 'followUps' | 'followUpsToCall';
};

/** A labelled block of menu items in the sidebar. */
export type NavGroup = {
    label: string;
    items: NavItem[];
};
