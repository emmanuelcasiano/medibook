export type AppointmentStatus =
    | 'booked'
    | 'checked_in'
    | 'completed'
    | 'cancelled'
    | 'no_show';

/** A visit a doctor asked the patient to book, as "My appointments" receives it. Dates are clinic dates ('Y-m-d'). */
export type MyFollowUp = {
    ulid: string;
    service: { name: string; durationMinutes: number };
    /** null means any doctor will do. */
    doctor: { name: string; specialty: string } | null;
    windowStartsOn: string;
    windowEndsOn: string;
    /** The doctor's message to this patient, if they wrote one. */
    note: string | null;
};

/** One row of "My appointments", as the page receives it. All times are ISO 8601 UTC. */
export type MyAppointment = {
    ulid: string;
    startsAt: string;
    endsAt: string;
    /** The last moment the patient can cancel on their own. */
    cancelUntil: string;
    status: AppointmentStatus;
    statusLabel: string;
    /** Only set on a cancelled appointment. */
    cancelReason: string | null;
    /** True when the clinic cancelled it, not the patient. */
    cancelledByClinic: boolean;
    service: { name: string; durationMinutes: number };
    doctor: { name: string; specialty: string };
    /** Whether an intake form was saved for this visit. Never the answers. */
    intakeFilled: boolean;
    /** Computed on the server. React only shows or hides the buttons; the Policy checks again on submit. */
    can: {
        cancel: boolean;
        /** The intake form is still open for this visit (their own booking, until check-in). */
        fillIntake: boolean;
    };
};
