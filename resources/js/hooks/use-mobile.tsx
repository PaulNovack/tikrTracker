import { useSyncExternalStore } from 'react';

const MOBILE_BREAKPOINT = 768;

// Get the media query list, or null on the server
const getMql = () => {
    if (typeof window === 'undefined') {
        return null;
    }
    return window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT - 1}px)`);
};

function mediaQueryListener(callback: (event: MediaQueryListEvent) => void) {
    const mql = getMql();
    if (!mql) return () => {};

    mql.addEventListener('change', callback);

    return () => {
        mql.removeEventListener('change', callback);
    };
}

function isSmallerThanBreakpoint() {
    const mql = getMql();
    return mql?.matches ?? false;
}

export function useIsMobile() {
    return useSyncExternalStore(mediaQueryListener, isSmallerThanBreakpoint);
}
