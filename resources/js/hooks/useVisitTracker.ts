import { useEffect, useState } from 'react';
import { visitTracker, VisitTracker } from '@/utils/visitTracker';

interface VisitStatus {
    timeRemaining: number;
    visitsRemaining: number;
    shouldTrigger: boolean;
}

/**
 * React hook for visit tracking and disclaimer enforcement
 */
export function useVisitTracker() {
    const [status, setStatus] = useState<VisitStatus>({
        timeRemaining: 30,
        visitsRemaining: 5,
        shouldTrigger: false,
    });

    useEffect(() => {
        // Update status immediately
        setStatus(visitTracker.getStatus());

        // Set up interval to update status every second
        const interval = setInterval(() => {
            setStatus(visitTracker.getStatus());
        }, 1000);

        // Cleanup on unmount
        return () => {
            clearInterval(interval);
        };
    }, []);

    // Cleanup function for manual reset
    const resetTracking = () => {
        visitTracker.reset();
        setStatus({
            timeRemaining: 30,
            visitsRemaining: 5,
            shouldTrigger: false,
        });
    };

    return {
        status,
        resetTracking,
        shouldShowDisclaimer: status.shouldTrigger,
    };
}