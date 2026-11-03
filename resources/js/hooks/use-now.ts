import { useSyncExternalStore } from 'react';

const MINUTE = 60_000;

/** The browser clock, cut down to the start of its minute: it only changes once a minute, so it is a stable value. */
function currentMinute(): number {
    return Math.floor(Date.now() / MINUTE) * MINUTE;
}

function subscribe(callback: () => void): () => void {
    let timer: ReturnType<typeof setTimeout>;

    function tick() {
        callback();
        timer = setTimeout(tick, MINUTE - (Date.now() % MINUTE));
    }

    // A browser slows the timers of a tab in the background, so the list would be out of date when the tab returns.
    function onVisible() {
        if (document.visibilityState === 'visible') {
            callback();
        }
    }

    timer = setTimeout(tick, MINUTE - (Date.now() % MINUTE));
    document.addEventListener('visibilitychange', onVisible);

    return () => {
        clearTimeout(timer);
        document.removeEventListener('visibilitychange', onVisible);
    };
}

/**
 * The start of the current minute on the browser clock, in milliseconds, renewed every minute (the arrivals list only
 * changes by the minute). `null` on the server and during the first render in the browser: the server has no
 * clock that matches the person's, and a time that differs between the two would break hydration.
 */
export function useNow(): number | null {
    return useSyncExternalStore(subscribe, currentMinute, () => null);
}
