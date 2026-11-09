import {
    CalendarDays,
    CalendarPlus,
    ClipboardList,
    Clock,
    ScrollText,
    ShieldCheck,
    Sparkles,
    Stethoscope,
    Users,
} from 'lucide-react';
import AiUsageController from '@/actions/App/Http/Controllers/Admin/AiUsageController';
import AuditLogController from '@/actions/App/Http/Controllers/Admin/AuditLogController';
import DoctorController from '@/actions/App/Http/Controllers/Admin/DoctorController';
import ServiceController from '@/actions/App/Http/Controllers/Admin/ServiceController';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import { services } from '@/routes/booking';
import { home as doctorHome } from '@/routes/doctor';
import { home as myHome } from '@/routes/my';
import { edit as profileEdit } from '@/routes/profile';
import { home as staffHome } from '@/routes/staff';
import type { NavGroup, NavItem, Role } from '@/types';

// Settings (profile, security, appearance) are all under /settings, so one item stays active on each of them.
const accountGroup: NavGroup = {
    label: 'Account',
    items: [
        {
            title: 'Profile and security',
            href: profileEdit(),
            icon: ShieldCheck,
            activePrefix: '/settings',
        },
    ],
};

/**
 * What each role sees in the sidebar. This only hides links: the role middleware and Policies on the server are the
 * real lock.
 */
const roleNavGroups: Record<Role, NavGroup[]> = {
    patient: [
        {
            label: 'Appointments',
            items: [
                {
                    title: 'My appointments',
                    href: myHome(),
                    icon: CalendarDays,
                    badge: 'followUps',
                },
                {
                    title: 'Book an appointment',
                    href: services(),
                    icon: CalendarPlus,
                },
            ],
        },
        accountGroup,
    ],
    staff: [
        {
            label: 'Front desk',
            items: [
                {
                    title: 'Day schedule',
                    href: staffHome(),
                    icon: CalendarDays,
                },
            ],
        },
        accountGroup,
    ],
    doctor: [
        {
            label: 'Visits',
            items: [{ title: 'Today', href: doctorHome(), icon: Clock }],
        },
        accountGroup,
    ],
    admin: [
        {
            label: 'Clinic setup',
            items: [
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
        },
        {
            label: 'Oversight',
            items: [
                {
                    title: 'Audit log',
                    href: AuditLogController.index(),
                    icon: ScrollText,
                },
                {
                    title: 'AI usage',
                    href: AiUsageController.index(),
                    icon: Sparkles,
                },
                // An admin can look at the schedule but not act on it, so the page says "View only" too.
                {
                    title: 'Schedule (view only)',
                    href: staffHome(),
                    icon: CalendarDays,
                },
            ],
        },
        accountGroup,
    ],
};

export function navGroupsFor(role: Role): NavGroup[] {
    return roleNavGroups[role];
}

/** The part of the address that makes an item the current one: its `activePrefix`, else its `href`. */
export function navItemMatch(item: NavItem): NonNullable<NavItem['href']> {
    return item.activePrefix ?? item.href;
}
