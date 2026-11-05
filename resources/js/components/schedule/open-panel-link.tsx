import { Link, router } from '@inertiajs/react';
import type { ComponentProps, MouseEvent } from 'react';
import ScheduleController from '@/actions/App/Http/Controllers/Staff/ScheduleController';
import type { SelectedAppointment } from '@/types';

type Props = Omit<
    ComponentProps<typeof Link>,
    'href' | 'onClick' | 'preserveScroll' | 'preserveState'
> & {
    /** The clinic date of the schedule on screen ('Y-m-d'). */
    date: string;
    ulid: string;
};

/**
 * What the panel shows until the server has answered: only the `ulid`, so the panel finds the booking in the schedule
 * the page already holds. Every `can` flag is false, so it grants no button. Permissions come from the server alone.
 */
function pendingSelection(ulid: string): SelectedAppointment {
    return {
        ulid,
        pending: true,
        hasStarted: false,
        can: { checkIn: false, markNoShow: false, cancel: false, move: false },
    };
}

/** A click that would follow the link in this tab. A middle or Ctrl click is left to the browser. */
function isPlainLeftClick(event: MouseEvent): boolean {
    return (
        !event.defaultPrevented &&
        event.button === 0 &&
        !event.altKey &&
        !event.ctrlKey &&
        !event.metaKey &&
        !event.shiftKey
    );
}

/**
 * The link that opens a booking's side panel, for every place on the staff schedule that has one.
 *
 * It is still a real link (Tab and Enter work, a new tab opens the page with the panel, the address can be shared).
 * On a plain click the panel opens at once from the placeholder, while the visit asks the server for the real
 * `selected`. The server's answer replaces the placeholder, and a failed visit puts the props back, so the panel closes.
 *
 * Why not an Inertia instant visit (`component` + `pageProps`): it always starts the page afresh, which would remount
 * the schedule and lose the chosen doctors, "show off" and the date field on every open. An optimistic update changes
 * the props of the page that is already there.
 *
 * `async: false` makes a newer visit cancel an older one still in flight, so a slow "open" answer cannot bring back a
 * panel that was already closed.
 */
export default function OpenPanelLink({ date, ulid, ...props }: Props) {
    const query = { date, appointment: ulid };

    return (
        <Link
            {...props}
            href={ScheduleController.index({ query })}
            preserveScroll
            preserveState
            onClick={(event) => {
                if (!isPlainLeftClick(event)) {
                    return;
                }

                event.preventDefault();

                router
                    .optimistic(() => ({ selected: pendingSelection(ulid) }))
                    .get(
                        ScheduleController.index.url({ query }),
                        {},
                        {
                            preserveScroll: true,
                            preserveState: true,
                            async: false,
                        },
                    );
            }}
        />
    );
}
