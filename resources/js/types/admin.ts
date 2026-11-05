import type { SummaryStatus } from '@/types/doctor';
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

/** One working range as the page receives it. `weekday` is 0 (Sunday) to 6 (Saturday). Times are clinic-local `HH:mm`. */
export type AdminWorkingHourRange = {
    weekday: number;
    startsAt: string;
    endsAt: string;
};

/** The doctor the working hours page is about. */
export type AdminWorkingHoursDoctor = {
    id: number;
    name: string;
};

/** The doctor the time off page is about. */
export type AdminTimeOffDoctor = {
    id: number;
    name: string;
};

/** One upcoming time off as the page receives it. Times are ISO 8601 UTC. `bookingsInside` is a count, never patient data. */
export type AdminTimeOffListItem = {
    id: number;
    startsAt: string;
    endsAt: string;
    reason: string | null;
    bookingsInside: number;
};

/**
 * One audit log row as the page receives it. `createdAt` is ISO 8601 UTC. The actor is null for a guest or the system.
 * `details` is the safe metadata as plain text lines, never health text.
 */
export type AdminAuditLogListItem = {
    id: number;
    createdAt: string;
    actorName: string | null;
    actorRoleLabel: string | null;
    action: string;
    subject: string | null;
    details: string[];
};

/** The audit filters as typed in the form. An empty string means "not set". `date` is a clinic date, `YYYY-MM-DD`. */
export type AdminAuditLogFilters = {
    actor: string;
    action: string;
    subject_type: string;
    subject_id: string;
    date: string;
};

/** The choices in the audit filter lists: only what is in the log. */
export type AdminAuditLogOptions = {
    actors: { id: number; name: string }[];
    actions: string[];
    subjectTypes: string[];
};

/** One line of "summaries by status". All four statuses are always sent, with a count of 0 when there are none. */
export type AdminAiUsageStatus = {
    status: SummaryStatus;
    label: string;
    count: number;
};

/**
 * The AI usage screen as the page receives it: counts and sums only, never a summary, an intake answer or a patient name.
 * `month` is a clinic date (`YYYY-MM-DD`, the first of the month). `estimatedCost` is US dollars as a string with
 * 4 decimals, or null when the price is not set in config (the page says "Price not set", not 0).
 */
export type AdminAiUsage = {
    month: string;
    statuses: AdminAiUsageStatus[];
    tokens: { input: number; output: number; total: number };
    estimatedCost: string | null;
    cap: { daily: number; usedToday: number; reached: boolean };
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
