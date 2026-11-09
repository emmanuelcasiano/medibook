import { Link, usePage } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { navItemMatch } from '@/lib/nav-groups';
import type { NavGroup } from '@/types';

export function NavMain({ groups }: { groups: NavGroup[] }) {
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
    const { openFollowUps, followUpsToCall } = usePage().props;

    return (
        <>
            {groups.map((group) => (
                <SidebarGroup key={group.label} className="px-2 py-1">
                    <SidebarGroupLabel>{group.label}</SidebarGroupLabel>
                    <SidebarMenu>
                        {group.items.map((item) => {
                            const isActive = item.exact
                                ? isCurrentUrl(navItemMatch(item))
                                : isCurrentOrParentUrl(navItemMatch(item));

                            return (
                                <SidebarMenuItem key={item.title}>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isActive}
                                        tooltip={{ children: item.title }}
                                        className="data-[active=true]:bg-teal-soft data-[active=true]:font-bold data-[active=true]:text-teal-ink max-md:h-11"
                                    >
                                        <Link
                                            href={item.href}
                                            prefetch
                                            aria-current={
                                                isActive ? 'page' : undefined
                                            }
                                        >
                                            {item.icon && <item.icon />}
                                            <span>{item.title}</span>
                                            {item.badge === 'followUps' &&
                                                openFollowUps > 0 && (
                                                    <span className="ml-auto rounded-full border border-amber-line bg-amber-soft px-1.5 text-xs font-semibold text-amber-ink group-data-[collapsible=icon]:hidden">
                                                        {openFollowUps}
                                                        <span className="sr-only">
                                                            {' '}
                                                            follow-up to book
                                                        </span>
                                                    </span>
                                                )}
                                            {item.badge === 'followUpsToCall' &&
                                                followUpsToCall > 0 && (
                                                    <span className="ml-auto rounded-full border border-danger-line bg-danger-soft px-1.5 text-xs font-semibold text-danger-ink group-data-[collapsible=icon]:hidden">
                                                        {followUpsToCall}
                                                        <span className="sr-only">
                                                            {' '}
                                                            follow-ups need a
                                                            call
                                                        </span>
                                                    </span>
                                                )}
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            );
                        })}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}
