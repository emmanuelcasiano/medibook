/** A service as the public booking pages receive it. */
export type BookableService = {
    slug: string;
    name: string;
    description: string | null;
    durationMinutes: number;
};

/** A doctor as the public booking pages receive it. */
export type BookableDoctor = {
    id: number;
    name: string;
    specialty: string;
    bio: string | null;
};

/** One day of the booking window. `date` is a clinic date ('Y-m-d'), with no time zone. */
export type BookingDay = {
    date: string;
    slotsCount: number;
};

/** A free start time. `startsAt` is ISO 8601 UTC. It never says which doctor is free. */
export type BookingSlot = {
    startsAt: string;
};

/** The visit a patient is about to confirm. All times are ISO 8601 UTC. */
export type BookingDraft = {
    service: BookableService;
    /** null means "Any doctor": the doctor is picked when the booking is made. */
    doctor: BookableDoctor | null;
    startsAt: string;
    endsAt: string;
    /** The last moment the patient can cancel on their own. */
    cancelUntil: string;
    /** Set when the visit is booked from a follow-up the doctor asked for. */
    followUp?: { ulid: string };
};

/** The follow-up a patient is booking from. Dates are clinic dates ('Y-m-d'). Never the doctor's note. */
export type BookingFollowUp = {
    ulid: string;
    windowStartsOn: string;
    windowEndsOn: string;
};

/** A booking just made, as the "You're booked" page receives it. The doctor is known now, even after "Any doctor". */
export type BookedAppointment = {
    ulid: string;
    startsAt: string;
    endsAt: string;
    cancelUntil: string;
    service: BookableService;
    doctor: BookableDoctor;
};
