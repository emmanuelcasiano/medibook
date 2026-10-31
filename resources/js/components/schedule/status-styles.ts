import type { ScheduleAppointment } from '@/types';

type ScheduleStatus = ScheduleAppointment['status'];

/**
 * The look of a booking by status. Color is only a hint: the status is always written out as text next to it
 * (in the block, in the list and in the legend).
 */
export const statusStyles: Record<ScheduleStatus, string> = {
    booked: 'border-sky-300 bg-background dark:border-sky-700',
    checked_in:
        'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-950',
    completed: 'border-border bg-muted text-muted-foreground',
    no_show:
        'border-violet-300 bg-violet-50 dark:border-violet-700 dark:bg-violet-950',
};

export const statusLabels: Record<ScheduleStatus, string> = {
    booked: 'Booked',
    checked_in: 'Checked in',
    completed: 'Completed',
    no_show: 'No-show',
};
