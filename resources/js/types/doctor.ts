import type { AppointmentStatus } from './appointments';
import type { SavedIntake } from './intake';
import type { VitalsReadings } from './vitals';

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
    /** Whether the nurse has taken vitals. A yes/no mark only, never a reading. */
    vitalsTaken: boolean;
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
    /** Computed on the server. The Policy and the Action check again on submit. */
    can: { complete: boolean };
    /** What the doctor asked for when they finished the visit. null when they did not. The note is never sent back. */
    followUp: RequestedFollowUp | null;
};

/** The follow-up of a visit, as the doctor's appointment page shows it. Dates are clinic dates ('Y-m-d'). */
export type RequestedFollowUp = {
    statusLabel: string;
    startsOn: string;
    endsOn: string;
    sameDoctor: boolean;
};

/** A service the doctor offers, for the "ask to come back" choice. */
export type FollowUpService = {
    id: number;
    name: string;
    durationMinutes: number;
};

/** The intake the doctor reads: the saved answers plus the "how long" choice in words. Health text. */
export type DoctorIntake = SavedIntake & {
    /** null when the patient left "how long" blank. */
    durationLabel: string | null;
};

/** The nurse's vital signs for this visit, read-only. Health data: only the treating doctor ever gets it. */
export type DoctorVitals = {
    readings: VitalsReadings;
    /** The nurse who took (or last corrected) them. */
    recordedByName: string;
    /** ISO 8601 UTC. When they were last saved. */
    recordedAt: string;
};

/** The doctor's own private note. Health text: show it as plain text only. */
export type VisitNote = {
    body: string;
    /** ISO 8601 UTC. */
    updatedAt: string;
};

export type SummaryStatus = 'pending' | 'ready' | 'failed' | 'skipped';

/** The AI draft's text. AI text: show it as plain text only. */
export type SummaryDraft = {
    /** One line: the complaint and its timing. No medicines or allergies. */
    headline: string;
    timeline: string[];
    /** A plain English version of non-English answers. null when every answer is in English. */
    inEnglish: string | null;
    /** What the patient did not say. The label comes from the server, one fixed label per key. */
    gaps: { key: string; label: string }[];
    /** Answers that disagree, naming both. Empty when nothing disagrees. */
    checkWithPatient: string[];
};

/** The AI summary row. `summary` is only set when `status` is 'ready'. */
export type IntakeSummary = {
    status: SummaryStatus;
    statusLabel: string;
    summary: SummaryDraft | null;
    model: string | null;
    /** ISO 8601 UTC. null until the doctor marks it reviewed. */
    reviewedAt: string | null;
    /** Token of this exact draft. "Mark reviewed" sends it back, so a draft that changed since is refused. */
    version: string;
    /** Computed on the server. The Policy and the Action check again on submit. */
    can: { review: boolean };
};
