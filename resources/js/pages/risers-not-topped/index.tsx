import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { useState, useEffect } from 'react';

interface IntervalData {
    percentChange: number;
    isRising: boolean;
    hasTopped: boolean;
    startPrice: number;
    endPrice: number;
    dataPoints: number;
}

interface RiserStock {
    symbol: string;
    asset_id: number | null;
    name: string;
    type: 'stock';
    avgDailyVolume: number;
    intervals: {
        [key: string]: IntervalData | null;
    };
    isRising: boolean;
    hasTopped: boolean;
    qualifies: boolean;
}

interface RisersNotToppedPageProps {
    title: string;
    description: string;
    stocks: RiserStock[];
    timeIntervals: string[];
    timestamp: string | null;
    timestampEst: string | null;
    assetTypeFilter: 'stock';
    totalAnalyzed: number;
    totalRisersNotTopped: number;
    minDailyVolume?: number;
    dataFreshness: {
        minutes_old: number | null;
        seconds_old: number | null;
        status: string;
        last_update: string | null;
    };
}

export default function RisersNotToppedIndex({
    title,
    description,
    stocks,
    timeIntervals,
    timestamp,
    timestampEst,
    assetTypeFilter,
    totalAnalyzed,
    totalRisersNotTopped,
    minDailyVolume,
    dataFreshness,
}: RisersNotToppedPageProps) {
    const handleFilterChange = (newFilter: 'stock') => {
        router.visit(`/risers-not-topped?filter=${newFilter}`, {
            preserveScroll: true,
        });
    };

    const getChangeColor = (percent: number | null | undefined) => {
        if (percent === null || percent === undefined || percent === 0) return 'text-gray-500 dark:text-gray-400';
        return percent > 0 
            ? 'text-green-600 dark:text-green-400' 
            : 'text-red-600 dark:text-red-400';
    };

    const formatPercent = (percent: number | null | undefined) => {
        if (percent === null || percent === undefined || typeof percent !== 'number') return '-';
        return `${percent >= 0 ? '+' : ''}${percent.toFixed(2)}%`;
    };

    const formatPrice = (price: number | null | undefined) => {
        if (price === null || price === undefined || typeof price !== 'number') return '-';
        return `$${price.toFixed(2)}`;
    };

    const getDataFreshnessColor = (status: string) => {
        switch (status) {
            case 'fresh': return 'text-green-600';
            case 'moderate': return 'text-yellow-600';
            case 'stale': return 'text-red-600';
            default: return 'text-gray-500';
        }
    };

    const getRisingCount = (intervalKey: string) => {
        return stocks.reduce((count, stock) => {
            const intervalData = stock.intervals[intervalKey];
            return count + (intervalData?.isRising ? 1 : 0);
        }, 0);
    };

    const getBestInterval = (stock: RiserStock) => {
        const intervals = timeIntervals;
        let bestInterval = intervals[0];
        let bestChange = stock.intervals[intervals[0]]?.percentChange || 0;
        
        intervals.forEach(interval => {
            const change = stock.intervals[interval]?.percentChange || 0;
            if (change > bestChange) {
                bestChange = change;
                bestInterval = interval;
            }
        });
        
        return { interval: bestInterval, change: bestChange };
    };

    const formatDataAge = (minutesOld: number | null) => {
        if (minutesOld === null) return '';
        
        const roundedMinutes = Math.abs(Math.round(minutesOld));
        
        // Always show in minutes, minimum 1 minute
        const displayMinutes = Math.max(1, roundedMinutes);
        
        return `(${displayMinutes}m old)`;
    };

    // Auto-refresh every 5 minutes
    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({ preserveUrl: true });
        }, 5 * 60 * 1000); // 5 minutes in milliseconds

        return () => clearInterval(interval);
    }, []);

    return (
        <AppLayout>
            <Head title={title} />

            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <Heading
                    title={title}
                    description={description}
                />

                {/* Summary Card */}
                {timestampEst && (
                    <Card className="mb-6">
                        <CardContent className="pt-6">
                            <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
                                <div>
                                    <span className="text-muted-foreground">Total Analyzed:</span>
                                    <div className="text-2xl font-bold">{totalAnalyzed}</div>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Risers Not Topped:</span>
                                    <div className="text-2xl font-bold text-green-600">{totalRisersNotTopped}</div>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Min Volume:</span>
                                    <div className="text-2xl font-bold text-blue-600">{minDailyVolume?.toLocaleString() || 'N/A'}</div>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Last Updated:</span>
                                    <div className="text-sm">
                                        {timestampEst}
                                        {dataFreshness.minutes_old !== null && (
                                            <span className={`ml-2 ${getDataFreshnessColor(dataFreshness.status)}`}>
                                                {formatDataAge(dataFreshness.minutes_old)}
                                            </span>
                                        )}
                                    </div>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Data Status:</span>
                                    <Badge 
                                        variant="outline" 
                                        className={`ml-1 ${getDataFreshnessColor(dataFreshness.status)}`}
                                    >
                                        {dataFreshness.status}
                                    </Badge>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>
                            Rising Stocks Not Topped ({stocks.length})
                        </CardTitle>
                        <CardDescription>
                            Stocks with upward momentum that haven't topped out and may continue rising
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {/* Filter Buttons */}
                        <div className="mb-4 flex flex-wrap gap-2">
                            <div className="flex gap-2">
                                <Button
                                    variant={assetTypeFilter === 'stock' ? 'default' : 'outline'}
                                    onClick={() => handleFilterChange('stock')}
                                >
                                    Stocks
                                </Button>
                                </div>
                        </div>

                        {/* Interval Summary */}
                        <div className="mb-6 grid grid-cols-5 gap-4">
                            {timeIntervals.map((interval) => (
                                <div key={interval} className="text-center p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                                    <div className="font-semibold">{interval}</div>
                                    <div className="text-sm text-green-600 dark:text-green-400">
                                        {getRisingCount(interval)} rising
                                    </div>
                                </div>
                            ))}
                        </div>

                        {/* Stocks Table */}
                        {stocks.length === 0 ? (
                            <div className="text-center py-8 text-muted-foreground">
                                No rising {assetTypeFilter}s found that haven't topped out.
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full table-auto">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="text-left p-2 font-semibold">Symbol</th>
                                            <th className="text-left p-2 font-semibold">Name</th>
                                            {timeIntervals.map((interval) => (
                                                <th key={interval} className="text-center p-2 font-semibold">
                                                    {interval}
                                                </th>
                                            ))}
                                                            <th className="text-center p-2 font-semibold">Best Interval</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {stocks.map((stock, index) => (
                                            <tr key={stock.symbol} className={`border-b ${index % 2 === 0 ? 'bg-gray-50/50 dark:bg-gray-900/50' : ''}`}>
                                                <td className="p-2 font-mono font-semibold">
                                                    <Link
                                                        href={stock.asset_id ? `/market-data/assets/${stock.asset_id}` : `/market-data/assets?search=${stock.symbol}`}
                                                        className="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-200 hover:underline"
                                                    >
                                                        {stock.symbol}
                                                    </Link>
                                                </td>
                                                <td className="p-2">
                                                    <div className="max-w-xs truncate" title={stock.name}>
                                                        {stock.name}
                                                    </div>
                                                    <div className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                                        Average Daily Volume: ${stock.avgDailyVolume?.toLocaleString() || 'N/A'}
                                                    </div>
                                                </td>
                                                {timeIntervals.map((interval) => {
                                                    const intervalData = stock.intervals[interval];
                                                    if (!intervalData) {
                                                        return (
                                                            <td key={interval} className="p-2 text-center text-gray-400">
                                                                -
                                                            </td>
                                                        );
                                                    }
                                                    
                                                    return (
                                                        <td key={interval} className="p-2 text-center">
                                                            <div className={getChangeColor(intervalData.percentChange)}>
                                                                {formatPercent(intervalData.percentChange)}
                                                            </div>
                                                            <div className="text-xs text-muted-foreground">
                                                                {formatPrice(intervalData.startPrice)} → {formatPrice(intervalData.endPrice)}
                                                            </div>
                                                        </td>
                                                    );
                                                })}
                                                <td className="p-2 text-center">
                                                    {(() => {
                                                        const best = getBestInterval(stock);
                                                        return (
                                                            <div className="flex flex-col items-center">
                                                                <Badge variant="outline" className="text-blue-600 border-blue-600 mb-1">
                                                                    {best.interval}
                                                                </Badge>
                                                                <span className="text-xs text-green-600 font-medium">
                                                                    +{best.change.toFixed(2)}%
                                                                </span>
                                                            </div>
                                                        );
                                                    })()}
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