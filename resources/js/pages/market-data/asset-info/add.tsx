import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Market Data',
        href: '/market-data/assets',
    },
    {
        title: 'Assets',
        href: '/market-data/assets',
    },
    {
        title: 'Add Symbol',
        href: '/market-data/assets/add',
    },
];

export default function AddSymbol() {
    const [assetType, setAssetType] = useState('stock');
    const [showSuccess, setShowSuccess] = useState(false);

    useEffect(() => {
        if (showSuccess) {
            const timer = setTimeout(() => {
                router.visit('/market-data/assets');
            }, 1500);
            return () => clearTimeout(timer);
        }
    }, [showSuccess]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add Symbol - Market Data" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <div>
                    <Link
                        href="/market-data/assets"
                        className="mb-4 flex items-center gap-2 text-sm text-blue-600 hover:text-blue-800"
                    >
                        <ArrowLeft size={16} />
                        Back to Assets
                    </Link>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Add New Symbol
                    </h1>
                    <p className="text-muted-foreground">
                        Add a new stock or cryptocurrency to track. Market data
                        will be fetched automatically.
                    </p>
                </div>

                <div className="mx-auto max-w-2xl">
                    <Form
                        method="post"
                        action="/market-data/assets"
                        resetOnSuccess
                    >
                        {({ errors, processing, hasErrors, wasSuccessful }) => {
                            if (wasSuccessful && !showSuccess) {
                                setShowSuccess(true);
                            }

                            return (
                                <div className="space-y-6 rounded-lg border bg-white p-6">
                                    {showSuccess && (
                                        <div className="mb-4 rounded-lg border border-green-200 bg-green-50 p-4 text-green-800">
                                            Symbol added successfully!
                                            Redirecting to assets list...
                                        </div>
                                    )}

                                    {hasErrors && !showSuccess && (
                                        <div className="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-red-800">
                                            <p className="mb-2 font-semibold">
                                                Unable to add symbol
                                            </p>
                                            <p className="text-sm">
                                                Please fix the errors below and
                                                try again.
                                            </p>
                                        </div>
                                    )}
                                    {/* Symbol */}
                                    <div>
                                        <label
                                            htmlFor="symbol"
                                            className="block text-sm font-medium text-gray-900"
                                        >
                                            Symbol{' '}
                                            <span className="text-red-500">
                                                *
                                            </span>
                                        </label>
                                        <Input
                                            id="symbol"
                                            name="symbol"
                                            type="text"
                                            placeholder="e.g., AAPL, BTC"
                                            className="mt-2"
                                            required
                                        />
                                        <p className="mt-2 text-xs text-gray-600">
                                            <strong>Tip:</strong> Use exact
                                            Yahoo Finance ticker symbols.
                                            International stocks need exchange
                                            suffix:
                                            <br />•{' '}
                                            <span className="font-mono">
                                                .TO
                                            </span>{' '}
                                            for Toronto (e.g.,{' '}
                                            <span className="font-mono">
                                                TD.TO
                                            </span>
                                            ) •{' '}
                                            <span className="font-mono">
                                                .L
                                            </span>{' '}
                                            for London •{' '}
                                            <span className="font-mono">
                                                .AS
                                            </span>{' '}
                                            for Amsterdam
                                            <br />
                                            Search "Symbol yahoo finance" if
                                            unsure.
                                        </p>
                                        {errors.symbol && (
                                            <p className="mt-1 text-sm text-red-600">
                                                {errors.symbol}
                                            </p>
                                        )}
                                    </div>

                                    {/* Asset Type */}
                                    <div>
                                        <label
                                            htmlFor="asset_type"
                                            className="block text-sm font-medium text-gray-900"
                                        >
                                            Asset Type{' '}
                                            <span className="text-red-500">
                                                *
                                            </span>
                                        </label>
                                        <select
                                            id="asset_type"
                                            name="asset_type"
                                            value={assetType}
                                            onChange={(e) =>
                                                setAssetType(e.target.value)
                                            }
                                            className="mt-2 w-full rounded-md border border-gray-300 px-3 py-2"
                                            required
                                        >
                                            <option value="stock">Stock</option></select>
                                        {errors.asset_type && (
                                            <p className="mt-1 text-sm text-red-600">
                                                {errors.asset_type}
                                            </p>
                                        )}
                                    </div>

                                    {/* Common Name */}
                                    <div>
                                        <label
                                            htmlFor="common_name"
                                            className="block text-sm font-medium text-gray-900"
                                        >
                                            Common Name{' '}
                                            <span className="text-red-500">
                                                *
                                            </span>
                                        </label>
                                        <Input
                                            id="common_name"
                                            name="common_name"
                                            type="text"
                                            placeholder="e.g., Apple Inc., Bitcoin"
                                            className="mt-2"
                                            required
                                        />
                                        {errors.common_name && (
                                            <p className="mt-1 text-sm text-red-600">
                                                {errors.common_name}
                                            </p>
                                        )}
                                    </div>

                                    {/* Sector (optional for stocks) */}
                                    {assetType === 'stock' && (
                                        <div>
                                            <label
                                                htmlFor="sector"
                                                className="block text-sm font-medium text-gray-900"
                                            >
                                                Sector
                                            </label>
                                            <Input
                                                id="sector"
                                                name="sector"
                                                type="text"
                                                placeholder="e.g., Technology, Finance"
                                                className="mt-2"
                                            />
                                            {errors.sector && (
                                                <p className="mt-1 text-sm text-red-600">
                                                    {errors.sector}
                                                </p>
                                            )}
                                        </div>
                                    )}

                                    {/* Description (optional) */}
                                    <div>
                                        <label
                                            htmlFor="description"
                                            className="block text-sm font-medium text-gray-900"
                                        >
                                            Description
                                        </label>
                                        <p className="mb-2 text-sm text-gray-500">
                                            Leave empty to fetch from Wikipedia
                                            automatically
                                        </p>
                                        <textarea
                                            id="description"
                                            name="description"
                                            placeholder="Enter description or leave empty for automatic fetch"
                                            className="mt-2 w-full rounded-md border border-gray-300 px-3 py-2"
                                            rows={4}
                                        />
                                        {errors.description && (
                                            <p className="mt-1 text-sm text-red-600">
                                                {errors.description}
                                            </p>
                                        )}
                                    </div>

                                    {/* Actions */}
                                    <div className="flex gap-4 pt-4">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            className="flex-1"
                                        >
                                            {processing
                                                ? 'Adding Symbol...'
                                                : 'Add Symbol'}
                                        </Button>
                                        <Link href="/market-data/assets">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="flex-1"
                                            >
                                                Cancel
                                            </Button>
                                        </Link>
                                    </div>
                                </div>
                            );
                        }}
                    </Form>

                    <div className="mt-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
                        <h3 className="mb-2 font-semibold">
                            What happens next?
                        </h3>
                        <ul className="list-inside list-disc space-y-1">
                            <li>The symbol will be added to the system</li>
                            <li>
                                If no description is provided, it will be
                                fetched from Wikipedia
                            </li>
                            <li>
                                Market data fetching will be queued for this
                                symbol only
                            </li>
                            <li>
                                It won't affect data for other existing symbols
                            </li>
                            <li className="text-blue-700">
                                If the symbol isn't found, admins will be
                                notified with suggestions
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
