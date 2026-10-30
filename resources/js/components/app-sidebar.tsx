import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    CalendarDays,
    ClipboardList,
    FolderGit2,
    LayoutGrid,
    Stethoscope,
    Users,
} from 'lucide-react';
import DoctorController from '@/actions/App/Http/Controllers/Admin/DoctorController';
import ServiceController from '@/actions/App/Http/Controllers/Admin/ServiceController';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { home as doctorHome } from '@/routes/doctor';
import { home as myHome } from '@/routes/my';
import { home as staffHome } from '@/routes/staff';
import type { NavItem, Role } from '@/types';

const dashboardItem: NavItem = {
    title: 'Dashboard',
    href: dashboard(),
    icon: LayoutGrid,
};

// What each role sees. This only hides links: the role middleware and Policies on the server are the real lock.
const roleNavItems: Record<Role, NavItem[]> = {
    patient: [{ title: 'My appointments', href: myHome(), icon: CalendarDays }],
    staff: [{ title: 'Schedule', href: staffHome(), icon: CalendarDays }],
    doctor: [{ title: 'Today', href: doctorHome(), icon: Stethoscope }],
    admin: [
        { title: 'Users', href: UserController.index(), icon: Users },
        {
            title: 'Services',
            href: ServiceController.index(),
            icon: ClipboardList,
        },
        {
            title: 'Doctors',
            href: DoctorController.index(),
            icon: Stethoscope,
        },
    ],
};

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const { auth } = usePage().props;
    const mainNavItems = [dashboardItem, ...roleNavItems[auth.user.role]];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
