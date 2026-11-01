const clinicDateTime = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila',
    dateStyle: 'medium',
    timeStyle: 'short',
});

const clinicTime = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila',
    timeStyle: 'short',
});

// A clinic date ('2026-11-02') has no time zone. It is turned into midnight UTC and shown in UTC, so the browser's
// own time zone can never move it to the day before or after.
const clinicDay = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'UTC',
    weekday: 'long',
    day: 'numeric',
    month: 'long',
});

const clinicMonth = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'UTC',
    month: 'long',
    year: 'numeric',
});

function clinicDateAsUtc(date: string): Date {
    return new Date(`${date}T00:00:00Z`);
}

/** Show an ISO 8601 UTC string from the server in clinic time (Asia/Manila). */
export function formatClinicDateTime(iso: string): string {
    return clinicDateTime.format(new Date(iso));
}

/** Only the time of day of an ISO 8601 UTC string, in clinic time (for example "9:00 am"). */
export function formatClinicTime(iso: string): string {
    return clinicTime.format(new Date(iso));
}

/** A clinic date from the server ('Y-m-d') as "Monday 2 November". */
export function formatClinicDate(date: string): string {
    return clinicDay.format(clinicDateAsUtc(date));
}

/** A clinic date from the server ('Y-m-d') as "November 2026". */
export function formatClinicMonth(date: string): string {
    return clinicMonth.format(clinicDateAsUtc(date));
}

/** The weekday of a clinic date, 0 (Sunday) to 6 (Saturday). */
export function clinicWeekday(date: string): number {
    return clinicDateAsUtc(date).getUTCDay();
}

/** The day of the month of a clinic date ('Y-m-d'). */
export function clinicDayOfMonth(date: string): number {
    return clinicDateAsUtc(date).getUTCDate();
}

/** Earlier years are only a step on the way to typing a real one, such as the "0202" on the way to "2026". */
const FIRST_YEAR = 2000;

/**
 * A full 'Y-m-d' date in a plausible year. A date field reports a valid date after every digit of the year
 * ("0002-11-02", "0020-11-02", ...), so a page that loads data for the date must wait for this to be true.
 */
export function isCompleteDate(value: string): boolean {
    return (
        /^\d{4}-\d{2}-\d{2}$/.test(value) &&
        Number(value.slice(0, 4)) >= FIRST_YEAR
    );
}
