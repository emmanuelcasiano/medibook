import type { AppointmentStatus } from './appointments';

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
 */
export type SelectedAppointment = {
    ulid: string;
    /** From the server's clock. A no-show is refused before the start. */
    hasStarted: boolean;
    /** The server hides a button the Policy or the status would refuse, and refuses it again on submit. */
    can: {
        checkIn: boolean;
        markNoShow: boolean;
    };
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
