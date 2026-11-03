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
};

/** A labelled block of menu items in the sidebar. */
export type NavGroup = {
    label: string;
    items: NavItem[];
};
