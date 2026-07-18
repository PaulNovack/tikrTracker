import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { router, usePage } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { useState } from 'react';

interface Asset {
    id: number;
    symbol: string;
    asset_type: string;
    sector?: string;
    common_name?: string;
    description?: string;
}

interface Props {
    assets: Asset[];
    watchedAssets: Record<number, number>;
    maxWatches: number;
    currentWatchCount: number;
}

export default function WatchesSettings({
    assets,
    watchedAssets,
    maxWatches,
    currentWatchCount,
}: Props) {
    const [searchTerm, setSearchTerm] = useState('');
    const { flash } = usePage().props as any;
    const canAddMore = currentWatchCount < maxWatches;

    const filteredAssets = assets.filter(
        (asset) =>
            asset.symbol.toLowerCase().includes(searchTerm.toLowerCase()) ||
            (asset.common_name
                ?.toLowerCase()
                .includes(searchTerm.toLowerCase()) ??
                false),
    );

    const handleAddWatch = (assetId: number) => {
        router.post(
            '/watches',
            { asset_info_id: assetId },
            {
                preserveScroll: true,
            },
        );
    };

    const handleRemoveWatch = (watchId: number) => {
        router.delete(`/watches/${watchId}`, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Watches', href: '/watches' },
                { title: 'Add Stocks', href: '/watches/settings' },
            ]}
        >
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex items-center justify-between">
                    <Heading
                        title="Add Stocks to Watch"
                        description="Search and select stocks you want to monitor"
                    />
                    <div className="text-sm font-medium text-muted-foreground">
                        {currentWatchCount} / {maxWatches} watches
                    </div>
                </div>

                {!canAddMore && (
                    <Card className="border-orange-200 bg-orange-50 dark:border-orange-900 dark:bg-orange-950/30">
                        <CardContent className="pt-6">
                            <p className="text-sm text-orange-800 dark:text-orange-200">
                                You've reached the maximum limit of {maxWatches}{' '}
                                watches. Please remove a watch to add a new one.
                            </p>
                        </CardContent>
                    </Card>
                )}

                {flash?.message && (
                    <Card className="border-green-200 bg-green-50 dark:border-green-900 dark:bg-green-950/30">
                        <CardContent className="flex items-center gap-2 pt-6">
                            <CheckCircle2 className="h-5 w-5 flex-shrink-0 text-green-600" />
                            <p className="text-sm text-green-800 dark:text-green-200">
                                {flash.message}
                            </p>
                        </CardContent>
                    </Card>
                )}

                {flash?.error && (
                    <Card className="border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/30">
                        <CardContent className="flex items-center gap-2 pt-6">
                            <p className="text-sm text-red-800 dark:text-red-200">
                                {flash.error}
                            </p>
                        </CardContent>
                    </Card>
                )}

                <div className="relative">
                    <Input
                        placeholder="Search by symbol or company name..."
                        value={searchTerm}
                        onChange={(e) => setSearchTerm(e.target.value)}
                        className="text-base"
                    />
                </div>

                {filteredAssets.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 pt-6 text-center">
                            <p className="text-muted-foreground">
                                {searchTerm
                                    ? 'No stocks found matching your search'
                                    : 'No stocks available'}
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid grid-cols-1 gap-4">
                        {filteredAssets.map((asset) => {
                            const isWatched = asset.id in watchedAssets;
                            const watchId = watchedAssets[asset.id];
                            return (
                                <Card
                                    key={asset.id}
                                    className={
                                        isWatched
                                            ? 'border-blue-200 bg-blue-50 dark:border-blue-900 dark:bg-blue-950/30'
                                            : ''
                                    }
                                >
                                    <CardContent className="pt-6">
                                        <div className="flex items-start justify-between gap-4">
                                            <div className="flex-1">
                                                <div className="flex items-center gap-2">
                                                    <h3 className="text-lg font-semibold">
                                                        {asset.symbol}
                                                    </h3>
                                                    {isWatched && (
                                                        <CheckCircle2 className="h-5 w-5 flex-shrink-0 text-blue-600" />
                                                    )}
                                                </div>
                                                <p className="text-sm text-muted-foreground">
                                                    {asset.asset_type}
                                                    {asset.sector &&
                                                        ` • ${asset.sector}`}
                                                </p>
                                                {asset.common_name && (
                                                    <p className="mt-2 text-sm font-medium">
                                                        {asset.common_name}
                                                    </p>
                                                )}
                                                {asset.description && (
                                                    <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                                                        {asset.description}
                                                    </p>
                                                )}
                                            </div>
                                            <Button
                                                variant={
                                                    isWatched
                                                        ? 'outline'
                                                        : 'default'
                                                }
                                                onClick={() =>
                                                    isWatched && watchId
                                                        ? handleRemoveWatch(
                                                              watchId,
                                                          )
                                                        : handleAddWatch(
                                                              asset.id,
                                                          )
                                                }
                                                disabled={
                                                    !isWatched && !canAddMore
                                                }
                                                className="flex-shrink-0"
                                            >
                                                {isWatched
                                                    ? 'Remove from Watch'
                                                    : 'Add to Watch'}
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
