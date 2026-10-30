const clinicDateTime = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila',
    dateStyle: 'medium',
    timeStyle: 'short',
});

/** Show an ISO 8601 UTC string from the server in clinic time (Asia/Manila). */
export function formatClinicDateTime(iso: string): string {
    return clinicDateTime.format(new Date(iso));
}
