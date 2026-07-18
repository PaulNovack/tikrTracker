import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import { Link, router } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type PropsWithChildren, useEffect, useState } from 'react';

interface AuthLayoutProps {
    name?: string;
    title?: string;
    description?: string;
}

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: PropsWithChildren<AuthLayoutProps>) {
    const [isLoading, setIsLoading] = useState(false);

    useEffect(() => {
        let loadingTimer: NodeJS.Timeout | null = null;

        const handleStart = (event: any) => {
            // Only skip prefetch requests, allow all actual navigation
            if (event.detail?.visit?.prefetch) {
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
            <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
                <div className="w-full max-w-sm">
                    <div className="flex flex-col gap-8">
                        <div className="flex flex-col items-center gap-4">
                            <Link
                                href={home()}
                                className="flex flex-col items-center gap-2 font-medium"
                            >
                                <div className="mb-1 flex h-9 w-9 items-center justify-center rounded-md">
                                    <AppLogoIcon className="size-9 fill-current text-[var(--foreground)] dark:text-white" />
                                </div>
                                <span className="sr-only">{title}</span>
                            </Link>

                            <div className="space-y-2 text-center">
                                <h1 className="text-xl font-medium">{title}</h1>
                                <p className="text-center text-sm text-muted-foreground">
                                    {description}
                                </p>
                            </div>
                        </div>
                        {children}
                    </div>
                </div>
            </div>
            
            {/* Loading overlay */}
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
