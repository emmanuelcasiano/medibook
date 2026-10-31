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
