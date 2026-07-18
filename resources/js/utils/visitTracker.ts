/**
 * Visit tracking utility for disclaimer enforcement
 */

interface VisitData {
    startTime: number;
    pageVisits: number;
    timeThresholdTriggered: boolean;
}

class VisitTracker {
    private storageKey = 'disclaimer_visit_tracker';
    private timeThreshold = 30000; // 30 seconds in milliseconds
    private visitLimit = 5;
    private checkInterval: number | null = null;

    constructor() {
        this.initializeTracking();
    }

    private initializeTracking(): void {
        // Only run on client side
        if (typeof window === 'undefined') return;

        // Initialize or get existing visit data
        const visitData = this.getVisitData();

        // Start time tracking if not already triggered
        if (!visitData.timeThresholdTriggered) {
            this.startTimeTracking();
        }

        // Track current page visit if on root page
        if (window.location.pathname === '/') {
            this.incrementPageVisit();
        }
    }

    private getVisitData(): VisitData {
        const stored = localStorage.getItem(this.storageKey);
        if (stored) {
            try {
                return JSON.parse(stored);
            } catch {
                // Fall through to create new data
            }
        }

        // Create new visit data
        const newData: VisitData = {
            startTime: Date.now(),
            pageVisits: 0,
            timeThresholdTriggered: false,
        };

        localStorage.setItem(this.storageKey, JSON.stringify(newData));
        return newData;
    }

    private saveVisitData(data: VisitData): void {
        localStorage.setItem(this.storageKey, JSON.stringify(data));
    }

    private startTimeTracking(): void {
        // Check every second if time threshold is reached
        this.checkInterval = window.setInterval(() => {
            const visitData = this.getVisitData();
            const timeElapsed = Date.now() - visitData.startTime;

            if (timeElapsed >= this.timeThreshold && !visitData.timeThresholdTriggered) {
                visitData.timeThresholdTriggered = true;
                this.saveVisitData(visitData);
                this.triggerDisclaimerCheck();
                this.stopTimeTracking();
            }
        }, 1000);
    }

    private stopTimeTracking(): void {
        if (this.checkInterval) {
            clearInterval(this.checkInterval);
            this.checkInterval = null;
        }
    }

    private incrementPageVisit(): void {
        const visitData = this.getVisitData();
        visitData.pageVisits += 1;
        this.saveVisitData(visitData);

        // Check if visit limit reached
        if (visitData.pageVisits >= this.visitLimit && !visitData.timeThresholdTriggered) {
            visitData.timeThresholdTriggered = true;
            this.saveVisitData(visitData);
            this.triggerDisclaimerCheck();
            this.stopTimeTracking();
        }
    }

    private triggerDisclaimerCheck(): void {
        // Force a page reload to trigger server-side disclaimer check
        // The middleware will handle redirecting to disclaimer if needed
        window.location.reload();
    }

    public shouldShowDisclaimer(): boolean {
        const visitData = this.getVisitData();
        const timeElapsed = Date.now() - visitData.startTime;

        return (
            visitData.timeThresholdTriggered ||
            visitData.pageVisits >= this.visitLimit ||
            timeElapsed >= this.timeThreshold
        );
    }

    public getStatus(): { 
        timeRemaining: number; 
        visitsRemaining: number; 
        shouldTrigger: boolean 
    } {
        const visitData = this.getVisitData();
        const timeElapsed = Date.now() - visitData.startTime;
        const timeRemaining = Math.max(0, this.timeThreshold - timeElapsed);
        const visitsRemaining = Math.max(0, this.visitLimit - visitData.pageVisits);

        return {
            timeRemaining: Math.ceil(timeRemaining / 1000), // Convert to seconds
            visitsRemaining,
            shouldTrigger: this.shouldShowDisclaimer(),
        };
    }

    public reset(): void {
        localStorage.removeItem(this.storageKey);
        this.stopTimeTracking();
    }

    public cleanup(): void {
        this.stopTimeTracking();
    }
}

// Export singleton instance
export const visitTracker = new VisitTracker();

// Export class for testing
export { VisitTracker };