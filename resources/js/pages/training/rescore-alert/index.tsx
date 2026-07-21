import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function RescoreAlert() {
    return (
        <AppLayout>
            <Head title="Rescore Alert" />
            <div className="flex min-h-[60vh] items-center justify-center px-6 py-12">
                <p className="text-lg font-medium text-gray-700 dark:text-gray-300">
                    Coming soon.....
                </p>
            </div>
        </AppLayout>
    );
}