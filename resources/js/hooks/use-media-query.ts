import { useSyncExternalStore } from 'react';

/** Whether a CSS media query matches now, and again whenever it changes (for example `(min-width: 96rem)`). */
export function useMediaQuery(query: string): boolean {
    return useSyncExternalStore(
        (callback) => {
            const list = window.matchMedia(query);

            list.addEventListener('change', callback);

            return () => list.removeEventListener('change', callback);
        },
        () => window.matchMedia(query).matches,
        () => false,
    );
}
