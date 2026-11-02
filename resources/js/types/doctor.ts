import type { AppointmentStatus } from './appointments';
import type { SavedIntake } from './intake';

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
    summaryStatus: SummaryStatus | null;
    /** The summary column in words, so it never depends on color alone. */
    summaryLabel: string;
};

/** The header of the doctor's appointment page. Times are ISO 8601 UTC. */
export type DoctorAppointment = {
    ulid: string;
    startsAt: string;
    endsAt: string;
    status: AppointmentStatus;
    statusLabel: string;
    /** First name only. The surname, email and birth date are never sent. */
    patientFirstName: string;
    /** Whole years. null when there is no birth date. The birth date itself is never sent. */
    patientAge: number | null;
    serviceName: string;
    /** True when the intake had an emergency phrase. A yes/no only, never the words. */
    emergency: boolean;
};

/** The intake the doctor reads: the saved answers plus the "how long" choice in words. Health text. */
export type DoctorIntake = SavedIntake & {
    /** null when the patient left "how long" blank. */
    durationLabel: string | null;
};

export type SummaryStatus = 'pending' | 'ready' | 'failed' | 'skipped';

/** The AI draft's text. AI text: show it as plain text only. */
export type SummaryDraft = {
    chiefComplaint: string;
    keyPoints: string[];
    allergiesAndMeds: string;
    questionsForDoctor: string[];
};

/** The AI summary row. `summary` is only set when `status` is 'ready'. */
export type IntakeSummary = {
    status: SummaryStatus;
    statusLabel: string;
    summary: SummaryDraft | null;
    model: string | null;
    /** ISO 8601 UTC. null until the doctor marks it reviewed (step 10.3). */
    reviewedAt: string | null;
};
