import type { BookableDoctor, BookableService } from './booking';

/**
 * The answers of an intake form. The keys are the form's field names, so the server's errors match them.
 * Health text: it comes only to the patient who owns the booking.
 */
export type IntakeAnswers = {
    reason: string;
    symptoms: string | null;
    /** An `IntakeDuration` value, or null when the patient left it blank. */
    duration: string | null;
    allergies: string | null;
    medicines: string | null;
    fever: boolean;
    pregnant: boolean;
    recent_surgery: boolean;
};

/** A saved intake, as the form page receives it. `submittedAt` is ISO 8601 UTC, the time of the last save. */
export type SavedIntake = {
    answers: IntakeAnswers;
    submittedAt: string;
};

/** One choice of the "how long" select. */
export type IntakeDurationOption = {
    value: string;
    label: string;
};

/** The visit an intake belongs to, as the form page receives it. All times are ISO 8601 UTC. */
export type IntakeAppointment = {
    ulid: string;
    startsAt: string;
    endsAt: string;
    service: BookableService;
    doctor: BookableDoctor;
};
