/** One patient in the nurse's triage queue. Times are ISO 8601 UTC. No reading, intake or health text is ever sent. */
export type VitalsQueueRow = {
    ulid: string;
    startsAt: string;
    /** When the front desk checked the patient in, which sets the order of the queue. */
    checkedInAt: string | null;
    patientName: string;
    doctorName: string;
    serviceName: string;
};

/** Today's checked-in patients in two groups, each oldest check-in first. */
export type VitalsQueue = {
    /** No vitals on record yet. */
    needsVitals: VitalsQueueRow[];
    /** Vitals taken, and the patient is waiting for the doctor. Kept so a typo can be corrected. */
    vitalsTaken: VitalsQueueRow[];
};

/** The appointment on the vitals form: enough to confirm the patient, and nothing else. */
export type VitalsFormAppointment = {
    ulid: string;
    startsAt: string;
    patientName: string;
    doctorName: string;
    serviceName: string;
};

/** The five vital signs (blood pressure is two numbers), as saved. */
export type VitalsReadings = {
    /** mmHg */
    systolic: number;
    /** mmHg */
    diastolic: number;
    /** beats per minute */
    pulse: number;
    /** °C, one decimal */
    temperature: number;
    /** % */
    oxygen_saturation: number;
    /** kg, one decimal */
    weight: number;
};
