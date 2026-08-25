import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TrendingUp, Zap } from 'lucide-react';
import { useEffect } from 'react';

interface MomentumMoverStock {
    symbol: string;
    asset_id: number | null;
    name: string;
    type: string;
    last_close: number | null;
    change_15m: number;
    change_30m: number;
    change_60m: number;
    change_90m: number;
    momentum: number;
    extension_from_low_pct: number;
    avg_daily_volume: number | null;
    last_volume: number | null;
    reasons: string[];
}

interface DataFreshness {
    minutes_old: number | null;
    status: string;
    last_update: string | null;
}

interface MomentumMoversProps {
    title: string;
    description: string;
    stocks: MomentumMoverStock[];
    timestamp: string | null;
    timestampEst: string | null;
    assetTypeFilter: string;
    totalAnalyzed: number;
    totalMomentumMovers: number;
    dataFreshness: DataFreshness;
}

const changeColor = (value: number | null | undefined) => {
    if (value === null || value === undefined || value === 0) {
        return 'text-gray-500 dark:text-gray-400';
    }
    return value > 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400';
};

const formatPercent = (value: number | null | undefined) => {
    if (value === null || value === undefined || typeof value !== 'number') return '-';
    return `${value >= 0 ? '+' : ''}${value.toFixed(2)}%`;
};

const formatPrice = (value: number | null | undefined) => {
    if (value === null || value === undefined || typeof value !== 'number') return '-';
    return `$${value.toFixed(2)}`;
};

const getDataFreshnessColor = (status: string) => {
    switch (status) {
        case 'fresh':
            return 'text-green-600';
        case 'moderate':
            return 'text-yellow-600';
        case 'stale':
            return 'text-red-600';
        default:
            return 'text-gray-500';
    }
};

export default function MomentumMovers({
    title,
    description,
    stocks,
    timestampEst,
    assetTypeFilter,
    totalAnalyzed,
    totalMomentumMovers,
    dataFreshness,
}: MomentumMoversProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Analysis', href: '/analysis/vwap-status' },
        { title: 'Momentum Movers', href: '/analysis/momentum-movers' },
    ];

    const handleFilterChange = (newFilter: string) => {
        router.visit(`/analysis/momentum-movers?filter=${newFilter}`, {
            preserveScroll: true,
        });
    };

    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({ preserveUrl: true });
        }, 5 * 60 * 1000);

        return () => clearInterval(interval);
    }, []);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex flex-col gap-6 p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-semibold text-gray-900 dark:text-gray-100">
                            <Zap className="h-6 w-6 text-yellow-500" />
                            {title}
                        </h1>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{description}</p>
                    </div>
                </div>

                {timestampEst && (
                    <Card>
                        <CardContent className="pt-6">
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
                                <div>
                                    <span className="text-muted-foreground">Total Analyzed:</span>
                                    <div className="text-2xl font-bold">{totalAnalyzed}</div>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Momentum Movers:</span>
                                    <div className="text-2xl font-bold text-green-600">{totalMomentumMovers}</div>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Last Updated:</span>
                                    <div className="text-sm">
                                        {timestampEst}
                                        {dataFreshness.minutes_old !== null && (
                                            <span className={`ml-2 ${getDataFreshnessColor(dataFreshness.status)}`}>
                                                ({Math.max(1, Math.abs(Math.round(dataFreshness.minutes_old)))}m old)
                                            </span>
                                        )}
                                    </div>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Data Status:</span>
                                    <Badge variant="outline" className={`ml-1 ${getDataFreshnessColor(dataFreshness.status)}`}>
                                        {dataFreshness.status}
                                    </Badge>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <TrendingUp className="h-5 w-5 text-green-600" />
                            Continuing Momentum ({stocks.length})
                        </CardTitle>
                        <CardDescription>
                            Stocks still building momentum, ranked by blended continuation score
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="mb-4 flex flex-wrap gap-2">
                            <Button
                                variant={assetTypeFilter === 'stock' ? 'default' : 'outline'}
                                onClick={() => handleFilterChange('stock')}
                            >
                                Stocks
                            </Button>
                        </div>

                        {stocks.length === 0 ? (
                            <div className="py-8 text-center text-muted-foreground">
                                No {assetTypeFilter}s are currently building momentum.
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full table-auto">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="p-2 text-left font-semibold">Symbol</th>
                                            <th className="p-2 text-left font-semibold">Name</th>
                                            <th className="p-2 text-center font-semibold">15m</th>
                                            <th className="p-2 text-center font-semibold">30m</th>
                                            <th className="p-2 text-center font-semibold">60m</th>
                                            <th className="p-2 text-center font-semibold">90m</th>
                                            <th className="p-2 text-center font-semibold">Continuation</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {stocks.map((stock, index) => (
                                            <tr
                                                key={stock.symbol}
                                                className={`border-b ${index % 2 === 0 ? 'bg-gray-50/50 dark:bg-gray-900/50' : ''}`}
                                            >
                                                <td className="p-2 font-mono font-semibold">
                                                    <Link
                                                        href={
                                                            stock.asset_id
                                                                ? `/market-data/assets/${stock.asset_id}`
                                                                : `/market-data/assets?search=${stock.symbol}`
                                                        }
                                                        className="text-blue-600 hover:text-blue-800 hover:underline dark:text-blue-400 dark:hover:text-blue-200"
                                                    >
                                                        {stock.symbol}
                                                    </Link>
                                                </td>
                                                <td className="p-2">
                                                    <div className="max-w-xs truncate" title={stock.name}>
                                                        {stock.name}
                                                    </div>
                                                    <div className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                        Last: {formatPrice(stock.last_close)} · Avg Vol:{' '}
                                                        {stock.avg_daily_volume?.toLocaleString() || 'N/A'}
                                                    </div>
                                                </td>
                                                <td className={`p-2 text-center ${changeColor(stock.change_15m)}`}>
                                                    {formatPercent(stock.change_15m)}
                                                </td>
                                                <td className={`p-2 text-center ${changeColor(stock.change_30m)}`}>
                                                    {formatPercent(stock.change_30m)}
                                                </td>
                                                <td className={`p-2 text-center ${changeColor(stock.change_60m)}`}>
                                                    {formatPercent(stock.change_60m)}
                                                </td>
                                                <td className={`p-2 text-center ${changeColor(stock.change_90m)}`}>
                                                    {formatPercent(stock.change_90m)}
                                                </td>
                                                <td className="p-2 text-center">
                                                    <Badge variant="outline" className="border-blue-600 text-blue-600">
                                                        {stock.momentum.toFixed(2)}
                                                    </Badge>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
