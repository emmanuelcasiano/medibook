import { Link, usePage } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import RoleBadge from '@/components/role-badge';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { navGroupsFor } from '@/lib/nav-groups';
import { dashboard } from '@/routes';

export function AppSidebar() {
    const { auth } = usePage().props;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem className="flex items-center gap-2">
                        {/* `/dashboard` sends each role to its own screen. */}
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                        {/* A patient only ever sees their own screens, so the badge is for the staff roles. */}
                        {auth.user.role !== 'patient' && (
                            <RoleBadge
                                role={auth.user.role}
                                className="group-data-[collapsible=icon]:hidden"
                            />
                        )}
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {/* What each role sees. This only hides links: the role middleware and Policies on the server are the real lock. */}
                <NavMain groups={navGroupsFor(auth.user.role)} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
