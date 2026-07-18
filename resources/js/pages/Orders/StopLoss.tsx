import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Orders',
        href: '/orders/buy',
    },
    {
        title: 'Set Stop Loss',
        href: '/orders/stop-loss',
    },
];

export default function StopLoss() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Set Stop Loss" />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight text-gray-900 dark:text-gray-100">
                        Set Stop Loss
                    </h1>
                    <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        Set stop loss orders for your positions using Webull API
                    </p>
                </div>

                {/* Stop loss form content will go here */}
                <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
                    <p className="text-sm text-gray-500 dark:text-gray-400">
                        Stop loss form coming soon...
                    </p>
                </div>
            </div>
        </AppLayout>
    );
}
