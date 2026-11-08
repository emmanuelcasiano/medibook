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

// The same words for an instant (an ISO 8601 UTC string), read in clinic time.
const clinicInstantDay = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila',
    weekday: 'long',
    day: 'numeric',
    month: 'long',
});

// A day of the week strip: "Mon" and "2 Nov".
const clinicShortWeekday = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'UTC',
    weekday: 'short',
});

const clinicShortMonth = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'UTC',
    month: 'short',
});

const clinicMonth = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'UTC',
    month: 'long',
    year: 'numeric',
});

// The month and the day of the month of an instant, for the date box on a card.
const clinicInstantShortMonth = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila',
    month: 'short',
});

const clinicInstantDayNumber = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila',
    day: 'numeric',
});

// 'en-CA' writes a date as 'Y-m-d', which is the form the server and a date field use.
const clinicTodayFormat = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
});

function clinicDateAsUtc(date: string): Date {
    return new Date(`${date}T00:00:00Z`);
}

/** Today's date at the clinic ('Y-m-d'), whatever time zone the browser is in. */
export function clinicToday(): string {
    return clinicTodayFormat.format(new Date());
}

/** A clinic date ('Y-m-d') moved by whole days. Works in UTC, so a daylight-saving change can't shift it. */
export function addClinicDays(date: string, days: number): string {
    const moved = clinicDateAsUtc(date);

    moved.setUTCDate(moved.getUTCDate() + days);

    return moved.toISOString().slice(0, 10);
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

/** The day of an ISO 8601 UTC string, in clinic time, as "Monday 2 November". */
export function formatClinicInstantDay(iso: string): string {
    return clinicInstantDay.format(new Date(iso));
}

/** The month of an ISO 8601 UTC string, in clinic time, as "Nov". */
export function formatClinicInstantShortMonth(iso: string): string {
    return clinicInstantShortMonth.format(new Date(iso));
}

/** The day of the month of an ISO 8601 UTC string, in clinic time, as "2". */
export function formatClinicInstantDayNumber(iso: string): string {
    return clinicInstantDayNumber.format(new Date(iso));
}

/** A clinic date from the server ('Y-m-d') as "Mon". */
export function formatClinicShortWeekday(date: string): string {
    return clinicShortWeekday.format(clinicDateAsUtc(date));
}

/** A clinic date from the server ('Y-m-d') as "Nov". */
export function formatClinicShortMonth(date: string): string {
    return clinicShortMonth.format(clinicDateAsUtc(date));
}

/** A clinic date from the server ('Y-m-d') as "November 2026". */
export function formatClinicMonth(date: string): string {
    return clinicMonth.format(clinicDateAsUtc(date));
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
