import { Head } from '@inertiajs/react';

import AppearanceTabs from '@/components/appearance-tabs';
import HeadingSmall from '@/components/heading-small';
import { type BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editAppearance } from '@/routes/appearance';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Appearance settings',
        href: editAppearance().url,
    },
];

export default function Appearance({ isGuest = false }: { isGuest?: boolean }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Appearance settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Appearance settings"
                        description="Update your account's appearance settings"
                    />

                    {isGuest && (
                        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
                            <div className="flex items-start gap-3">
                                <div className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/50">
                                    <span className="text-xs font-bold text-amber-600 dark:text-amber-400">
                                        !
                                    </span>
                                </div>
                                <div>
                                    <p className="font-semibold text-amber-900 dark:text-amber-100">
                                        Guest Mode - Appearance Settings
                                        Disabled
                                    </p>
                                    <p className="mt-1 text-sm text-amber-700 dark:text-amber-300">
                                        Appearance preferences cannot be saved
                                        in guest mode. To customize your
                                        appearance settings,{' '}
                                        <a
                                            href="/contact"
                                            className="font-medium underline"
                                        >
                                            request beta access
                                        </a>{' '}
                                        to create your own account.
                                    </p>
                                </div>
                            </div>
                        </div>
                    )}

                    <AppearanceTabs disabled={isGuest} />
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
