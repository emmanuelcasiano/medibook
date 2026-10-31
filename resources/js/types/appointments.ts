export type AppointmentStatus =
    | 'booked'
    | 'checked_in'
    | 'completed'
    | 'cancelled'
    | 'no_show';

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
    /** Computed on the server. React only shows or hides the button; the Policy checks again on submit. */
    can: { cancel: boolean };
};
