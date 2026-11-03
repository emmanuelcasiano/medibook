import { useSyncExternalStore } from 'react';
import { useMediaQuery } from '@/hooks/use-media-query';

export type ScheduleView = 'grid' | 'list' | 'doctor';

/** The two choices of the toggle on this screen size. The grid needs room, so a phone gets "By doctor" instead. */
export const viewOptions: Record<
    'wide' | 'narrow',
    Array<{ view: ScheduleView; label: string }>
> = {
    wide: [
        { view: 'grid', label: 'Grid' },
        { view: 'list', label: 'List' },
    ],
    narrow: [
        { view: 'list', label: 'List' },
        { view: 'doctor', label: 'By doctor' },
    ],
};

const storageKey = 'medibook.schedule-view';

// The choice made on this page, for the case where storage is blocked: it still applies until the page is closed.
let chosenHere: ScheduleView | null = null;
const listeners = new Set<() => void>();

// Storage can be blocked (private window, site data off) and can throw on read and on write. The page works without it.
function readStored(): ScheduleView | null {
    if (chosenHere !== null) {
        return chosenHere;
    }

    try {
        const value = window.localStorage.getItem(storageKey);

        return value === 'grid' || value === 'list' || value === 'doctor'
            ? value
            : null;
    } catch {
        return null;
    }
}

function subscribe(callback: () => void): () => void {
    listeners.add(callback);
    // Another tab changed the choice.
    window.addEventListener('storage', callback);

    return () => {
        listeners.delete(callback);
        window.removeEventListener('storage', callback);
    };
}

function remember(view: ScheduleView) {
    chosenHere = view;

    try {
        window.localStorage.setItem(storageKey, view);
    } catch {
        // Not remembered for next time.
    }

    listeners.forEach((listener) => listener());
}

/**
 * Which view of the day to show: the grid, the arrivals list, or (on a phone) one list per doctor. Nothing stored
 * means the default: the list for staff, the grid for an admin on a wide screen, and the list on a phone. A stored
 * choice that does not fit this screen (the grid on a phone) falls back to the list instead of an empty page.
 *
 * The stored choice is read as an external store, so the server render and the first render in the browser both see
 * "nothing stored" and match. The stored choice then takes over.
 */
export function useScheduleView(viewOnly: boolean) {
    const wide = useMediaQuery('(min-width: 48rem)');
    const stored = useSyncExternalStore(subscribe, readStored, () => null);

    let view: ScheduleView;

    if (wide) {
        view =
            stored === 'grid' || stored === 'list'
                ? stored
                : viewOnly
                  ? 'grid'
                  : 'list';
    } else {
        view = stored === 'doctor' ? 'doctor' : 'list';
    }

    return {
        view,
        choose: remember,
        options: wide ? viewOptions.wide : viewOptions.narrow,
    };
}
