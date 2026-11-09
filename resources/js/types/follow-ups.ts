/**
 * The staff Follow-ups page. What staff see of a follow-up is names, dates and a state, never the doctor's note
 * (it is health text and the server does not send it).
 */

/** Worked out on the server from the status and the window days: it is not stored. In list order. */
export type FollowUpState =
    | 'call'
    | 'open'
    | 'waiting'
    | 'booked'
    | 'dismissed';

export type StaffFollowUpRow = {
    ulid: string;
    patientName: string;
    serviceName: string;
    /** The doctor who asked, even when any doctor will do. */
    doctorName: string;
    sameDoctor: boolean;
    /** Clinic days ('Y-m-d') with no time part. */
    windowStartsOn: string;
    windowEndsOn: string;
    state: FollowUpState;
    /** The state in words, for example "Not booked · ends tomorrow". Always shown, so color is never the only signal. */
    stateLabel: string;
};

export type FollowUpHistoryItem = {
    id: number;
    what: string;
    /** ISO 8601 UTC instant. */
    at: string;
};

/** The follow-up whose side panel is open. */
export type StaffFollowUpPanel = StaffFollowUpRow & {
    patientEmail: string;
    durationMinutes: number;
    /** ISO 8601 UTC instant of the visit that led to this request. */
    sourceVisitAt: string;
    /** True for staff on an open follow-up. An admin only looks. */
    canManage: boolean;
    history: FollowUpHistoryItem[];
};

export type FollowUpCounts = Record<FollowUpState, number>;

/** The Book picker's days and times. It is not part of the first load: the panel's Book section asks for it. */
export type FollowUpBookingOptions = {
    days: Array<{ date: string; slotsCount: number }>;
    selectedDate: string | null;
    slots: Array<{ startsAt: string }>;
    /** Why nothing can be offered (a doctor or visit type switched off, or the follow-up is closed), else null. */
    unavailable: string | null;
};
