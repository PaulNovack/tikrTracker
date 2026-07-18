import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import TradeAlertNotification from '@/components/TradeAlertNotification';
import { type BreadcrumbItem } from '@/types';
import { router } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type PropsWithChildren, useEffect, useState } from 'react';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: PropsWithChildren<{ breadcrumbs?: BreadcrumbItem[] }>) {
    const [isLoading, setIsLoading] = useState(false);

    useEffect(() => {
        let loadingTimer: NodeJS.Timeout | null = null;

        const handleStart = (event: any) => {
            // Skip prefetch requests and silent background refreshes
            if (event.detail?.visit?.prefetch || event.detail?.visit?.showProgress === false) {
                return;
            }
            
            loadingTimer = setTimeout(() => {
                setIsLoading(true);
            }, 150);
        };

        const handleFinish = () => {
            if (loadingTimer) {
                clearTimeout(loadingTimer);
                loadingTimer = null;
            }
            setIsLoading(false);
        };

        const handleError = () => {
            if (loadingTimer) {
                clearTimeout(loadingTimer);
                loadingTimer = null;
            }
            setIsLoading(false);
        };

        const removeStart = router.on('start', handleStart);
        const removeFinish = router.on('finish', handleFinish);
        const removeError = router.on('error', handleError);

        return () => {
            if (loadingTimer) {
                clearTimeout(loadingTimer);
            }
            removeStart();
            removeFinish();
            removeError();
        };
    }, []);

    return (
        <div className="relative">
            <AppShell variant="sidebar">
                <AppSidebar />
                <AppContent variant="sidebar" className="overflow-x-hidden">
                    <AppSidebarHeader breadcrumbs={breadcrumbs} />
                    {children}
                </AppContent>
            </AppShell>
            
            {/* Trade Alert Notifications */}
            <TradeAlertNotification />
            
            {/* Loading overlay positioned over content area only */}
            {isLoading && (
                <div className="fixed inset-0 z-[9999] flex items-center justify-center bg-black/50 backdrop-blur-sm">
                    <div className="flex flex-col items-center gap-3 rounded-lg bg-white px-6 py-4 shadow-lg dark:bg-gray-900">
                        <LoaderCircle className="h-8 w-8 animate-spin text-blue-600 dark:text-blue-400" />
                        <p className="text-sm font-medium text-gray-700 dark:text-gray-300">
                            Loading...
                        </p>
                    </div>
                </div>
            )}
        </div>
    );
}
