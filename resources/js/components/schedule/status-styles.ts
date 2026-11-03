import type { ScheduleAppointment } from '@/types';

type ScheduleStatus = ScheduleAppointment['status'];

/**
 * The look of a booking by status, from the shared status colors in `app.css` (the same ones `StatusBadge` uses).
 * Color is only a hint: the status is always written out as text next to it (in the block, in the list and in the
 * legend).
 */
export const statusStyles: Record<ScheduleStatus, string> = {
    booked: 'border-info-line bg-info-soft text-info-ink',
    checked_in: 'border-teal-line bg-teal-soft text-teal-ink',
    completed: 'border-done-line bg-done-soft text-done-ink',
    no_show: 'border-violet-line bg-violet-soft text-violet-ink',
};

/** The block whose side panel is open: a dark border and a ring of the same color, so it stands out on every status. */
export const selectedStyle = 'border-foreground ring-1 ring-foreground';

export const statusLabels: Record<ScheduleStatus, string> = {
    booked: 'Booked',
    checked_in: 'Checked in',
    completed: 'Completed',
    no_show: 'No-show',
};
