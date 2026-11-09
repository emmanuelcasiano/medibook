import type { AppointmentStatus } from './appointments';
import type { BookingSlot } from './booking';

/** One booking in a doctor's column of the staff schedule. Only the patient's name is sent about them. */
export type ScheduleAppointment = {
    ulid: string;
    /** ISO 8601 UTC. */
    startsAt: string;
    endsAt: string;
    /** Minutes from clinic midnight, so a block can be placed without time zone math in the browser. */
    offsetMinutes: number;
    durationMinutes: number;
    patientName: string;
    serviceName: string;
    /** Never `cancelled`: a cancelled booking frees the slot and is not shown. */
    status: Exclude<AppointmentStatus, 'cancelled'>;
    statusLabel: string;
    /** ISO 8601 UTC. When the patient was checked in at the front desk, or `null` while they have not been. */
    checkedInAt: string | null;
    // The next two are sent to staff only, never to an admin (the keys are missing, not false). They are yes/no facts
    // and nothing more: no intake answers reach the schedule.
    /** The booking is still `booked` and the patient has not filled in the intake form. */
    intakeMissing?: boolean;
    /** The intake form had an emergency word (set from Day 8). */
    emergencyFlag?: boolean;
};

/** One column of the schedule. */
export type ScheduleDoctor = {
    id: number;
    name: string;
    specialty: string;
    /** False for a deactivated doctor who still has a booking that day. */
    isActive: boolean;
    /** False when the doctor has no working hours on this weekday. */
    isWorking: boolean;
    /** In time order. */
    appointments: ScheduleAppointment[];
};

/**
 * The booking whose side panel is open, and what the signed-in user may do with it. Its name, time, doctor and
 * service are already in the schedule, so only the extra facts are sent. `null` on the page means no panel.
 *
 * The server never sends `pending`. The browser sets it on a placeholder, so the panel can open before the server has
 * answered (see `OpenPanelLink`). The placeholder has every `can` flag false: it can only show what the schedule holds,
 * and it never grants a button. The server's answer replaces it.
 */
export type SelectedAppointment = {
    ulid: string;
    /** `true` only on the browser's placeholder, until the server's answer arrives. */
    pending?: true;
    /** From the server's clock. A no-show is refused before the start. */
    hasStarted: boolean;
    /** The server hides a button the Policy or the status would refuse, and refuses it again on submit. */
    can: {
        checkIn: boolean;
        markNoShow: boolean;
        cancel: boolean;
        move: boolean;
    };
};

/**
 * What the Move picker offers for the open booking. The page does not get it with the first load: the Move section
 * asks for it (a partial reload of `move`) once staff open it, and again when they change the doctor or the day.
 */
export type MoveOptions = {
    /** Bookable doctors who offer the booking's service, by name. Includes the booking's own doctor. */
    doctors: Array<{ id: number; name: string }>;
    /** Free start times of the picked doctor and day, earliest first. Empty until both are picked. */
    slots: BookingSlot[];
};

/** One clinic day, as the staff schedule page receives it. Dates are clinic dates ('Y-m-d'), with no time zone. */
export type DaySchedule = {
    date: string;
    previousDate: string;
    nextDate: string;
    today: string;
    /** The hours the grid covers, as minutes from clinic midnight. Both are on the hour. */
    startMinute: number;
    endMinute: number;
    /** Minutes per grid row. */
    stepMinutes: number;
    doctors: ScheduleDoctor[];
};

/** One search result in the walk-in dialog. The server sends these three fields and nothing else about a patient. */
export type WalkInPatient = {
    id: number;
    name: string;
    email: string;
};

/**
 * What the walk-in dialog can offer so far. It asks the server (a JSON request, no page change) when it opens and
 * whenever the visit type or the doctor changes, so every rule of the slot finders applies and the browser works out
 * nothing.
 */
export type WalkInOptions = {
    /** Visit types that can be booked now. */
    services: Array<{ id: number; name: string; durationMinutes: number }>;
    /** Bookable doctors who offer the picked visit type. Empty until one is picked. */
    doctors: Array<{ id: number; name: string }>;
    /** Today's nearest free start times from now, earliest first. Empty until a visit type and a doctor are picked. */
    slots: BookingSlot[];
};
