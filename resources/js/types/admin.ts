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

/** One row of the admin doctors list, as the page receives it. */
export type AdminDoctorListItem = {
    id: number;
    name: string;
    specialty: string;
    servicesCount: number;
    isActive: boolean;
    can: { update: boolean };
};

/** The fields of the doctor form. `serviceIds` are the services the doctor offers now. */
export type AdminDoctorFormValues = {
    id: number;
    name: string;
    specialty: string;
    bio: string | null;
    serviceIds: number[];
};

/** One service in the doctor form's checklist. Switched-off services are listed too. */
export type AdminDoctorServiceOption = {
    id: number;
    name: string;
    durationMinutes: number;
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
