import type { AppointmentStatus } from './appointments';

/** One row of the doctor's "Today" list, as the page receives it. Times are ISO 8601 UTC. */
export type TodayAppointment = {
    ulid: string;
    startsAt: string;
    endsAt: string;
    status: AppointmentStatus;
    statusLabel: string;
    /** First name only. The surname, email and birth date are never sent. */
    patientFirstName: string;
    serviceName: string;
    /** Whether an intake form was saved for this visit. Never the answers. */
    intakeFilled: boolean;
    /** null when there is no intake, or an intake with no summary row. Never the summary text. */
    summaryStatus: 'pending' | 'ready' | 'failed' | 'skipped' | null;
    /** The summary column in words, so it never depends on color alone. */
    summaryLabel: string;
};
