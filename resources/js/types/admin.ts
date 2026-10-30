import type { Role } from '@/types/auth';

/** One row of the admin users list, as the page receives it. */
export type AdminUserListItem = {
    id: number;
    name: string;
    email: string;
    role: Role;
    roleLabel: string;
    isActive: boolean;
    twoFactorEnabled: boolean;
    can: { deactivate: boolean };
};

export type RoleOption = {
    value: Role;
    label: string;
};

/** One row of the admin services list, as the page receives it. */
export type AdminServiceListItem = {
    id: number;
    name: string;
    description: string | null;
    durationMinutes: number;
    bufferMinutes: number;
    isActive: boolean;
    doctorNames: string[];
    appointmentsCount: number;
    can: { update: boolean; delete: boolean };
};

/** The fields of the service form. */
export type AdminServiceFormValues = {
    id: number;
    name: string;
    description: string | null;
    durationMinutes: number;
    bufferMinutes: number;
    isActive: boolean;
};

/** The parts of Laravel's paginator that the pages use. */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
