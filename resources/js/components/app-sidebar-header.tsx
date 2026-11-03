import { usePage } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Button } from '@/components/ui/button';
import { SidebarTrigger, useSidebar } from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { navGroupsFor, navItemMatch } from '@/lib/nav-groups';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { auth } = usePage().props;
    const { isMobile, toggleSidebar } = useSidebar();
    const { isCurrentOrParentUrl } = useCurrentUrl();

    // The page title is the `h1` from `PageHeader`. On a phone the bar also names the page, so the user knows where
    // they are while the menu is closed: the last breadcrumb, else the menu item of the current section.
    const currentItem = navGroupsFor(auth.user.role)
        .flatMap((group) => group.items)
        .find((item) => isCurrentOrParentUrl(navItemMatch(item)));
    const pageName = breadcrumbs.at(-1)?.title ?? currentItem?.title;

    return (
        <header className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex min-w-0 items-center gap-2">
                {isMobile ? (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={toggleSidebar}
                            className="-ml-2 h-11 shrink-0 gap-2 px-3"
                        >
                            <Menu aria-hidden />
                            Menu
                        </Button>
                        {pageName && (
                            <span className="truncate text-sm font-semibold">
                                {pageName}
                            </span>
                        )}
                    </>
                ) : (
                    <>
                        <SidebarTrigger className="-ml-1" />
                        {/* One level is just the page itself, and its title is already on the page. */}
                        {breadcrumbs.length > 1 && (
                            <Breadcrumbs breadcrumbs={breadcrumbs} />
                        )}
                    </>
                )}
            </div>
        </header>
    );
}
