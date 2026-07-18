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
import { useState } from 'react';

interface TimeInterval {
    timestamp: string;
    label: string;
    minutesAgo: number;
}

interface IntervalData {
    timestamp: string;
    price: number | null;
    percentChange: number | null;
    volume: number | null;
}

interface RisingHourStock {
    symbol: string;
    asset_id: number | null;
    asset_type: 'stock';
    company_name: string;
    baselinePrice: number;
    currentPrice: number;
    totalPercentChange: number;
    intervalData: IntervalData[];
    totalVolume: number;
    highPrice: number;
    lowPrice: number;
}

interface RisingHourProps {
    stocks: RisingHourStock[];
    timeIntervals: TimeInterval[];
    timestamp: string | null;
    timestampEst: string | null;
    assetTypeFilter: 'stock' | 'all';
    totalAnalyzed: number;
    dataFreshness: string;
}

export default function RisingHour({
    stocks,
    timeIntervals,
    timestamp,
    timestampEst,
    assetTypeFilter,
    totalAnalyzed,
    dataFreshness,
}: RisingHourProps) {
    const [showAsInteger, setShowAsInteger] = useState(false);

    const handleFilterChange = (newFilter: 'stock' | 'all') => {
        router.visit(`/rising-hour?filter=${newFilter}`, {
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
        
        if (showAsInteger) {
            return `${percent >= 0 ? '+' : ''}${Math.round(percent * 100)}`;
        }
        
        return `${percent >= 0 ? '+' : ''}${percent.toFixed(2)}%`;
    };

    const formatPrice = (price: number | null | undefined) => {
        if (price === null || price === undefined || typeof price !== 'number') return '-';
        return `$${price.toFixed(2)}`;
    };

    const formatVolume = (volume: number | null | undefined) => {
        if (volume === null || volume === undefined || typeof volume !== 'number') return '-';
        if (volume >= 1000000) {
            return `${(volume / 1000000).toFixed(1)}M`;
        } else if (volume >= 1000) {
            return `${(volume / 1000).toFixed(1)}K`;
        }
        return volume.toString();
    };

    const getMomentumIndicator = (stock: RisingHourStock) => {
        const intervals = stock.intervalData;
        if (intervals.length < 5) {
            return { color: 'text-gray-400', symbol: '–', title: 'Insufficient data' };
        }

        // Get the 5 most recent intervals with data for smoothed comparison
        const validIntervals = intervals
            .filter(interval => interval.percentChange !== null)
            .slice(-5); // Get last 5 intervals

        if (validIntervals.length < 4) {
            return { color: 'text-gray-400', symbol: '–', title: 'Insufficient data' };
        }

        // Smoothed momentum: Compare averages to reduce 5-min volatility noise
        // Recent: average of last 2 intervals
        // Previous: average of 2 intervals before that (with 1 interval gap as buffer)
        const recentAvg = (validIntervals[validIntervals.length - 1].percentChange! + 
                          validIntervals[validIntervals.length - 2].percentChange!) / 2;
        
        const previousAvg = (validIntervals[validIntervals.length - 4].percentChange! + 
                            validIntervals[validIntervals.length - 5].percentChange!) / 2;

        // Compare smoothed averages for more stable momentum signal
        if (recentAvg > previousAvg) {
            return { 
                color: 'text-green-600 dark:text-green-400', 
                symbol: '▲', 
                title: `Accelerating: ${recentAvg.toFixed(1)}% avg > ${previousAvg.toFixed(1)}% avg (smoothed)` 
            };
        } else {
            return { 
                color: 'text-orange-600 dark:text-orange-400', 
                symbol: '▼', 
                title: `Decelerating: ${recentAvg.toFixed(1)}% avg < ${previousAvg.toFixed(1)}% avg (smoothed)` 
            };
        }
    };

    return (
        <>
            <Head title="Rising In Hour - Top Moving Stocks" />
            <AppLayout breadcrumbs={[{ title: 'Rising In Hour', href: '/rising-hour' }]}>
                <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between">
                        <Heading
                            title="Rising In Hour"
                            description="Top 100 rising stocks based on hourly performance with smoothed 5-minute momentum indicators that average recent intervals to reduce volatility noise and identify true acceleration/deceleration trends"
                        />
                    </div>

                    {timestamp && (
                        <Card>
                            <CardContent className="pt-6">
                                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                    <div>
                                        <span className="text-muted-foreground">Data as of:</span>
                                        <div className="font-medium">{timestampEst}</div>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Total Analyzed:</span>
                                        <div className="font-medium">{totalAnalyzed.toLocaleString()}</div>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Rising Stocks:</span>
                                        <div className="font-medium">{stocks.length}</div>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Freshness:</span>
                                        <Badge variant="outline" className="ml-1">
                                            {dataFreshness}
                                        </Badge>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Rising Stocks ({stocks.length})
                                {assetTypeFilter === 'all' && `Rising Assets (${stocks.length})`}
                            </CardTitle>
                            <CardDescription>
                                5-minute interval analysis showing percentage changes over the last hour
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
                                    <Button
                                        variant={assetTypeFilter === 'all' ? 'default' : 'outline'}
                                        onClick={() => handleFilterChange('all')}
                                    >
                                        All Assets
                                    </Button>
                                </div>
                                
                                {/* Display Format Toggle */}
                                <Button
                                    variant={showAsInteger ? 'default' : 'outline'}
                                    onClick={() => setShowAsInteger(!showAsInteger)}
                                    className="ml-auto"
                                >
                                    Show As Integer
                                </Button>
                            </div>

                            {stocks.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No rising stocks found in the last hour for {assetTypeFilter === 'all' ? 'all assets' : assetTypeFilter}.
                                </p>
                            ) : (
                                <div className="overflow-hidden">
                                    <table className="w-full table-fixed">
                                        <thead className="border-b">
                                            <tr className="text-muted-foreground">
                                                <th className="w-16 px-2 py-2 text-left font-semibold">Symbol</th>
                                                <th className="w-20 px-2 py-2 text-right font-semibold">Total %</th>
                                                <th className="w-16 px-2 py-2 text-center font-semibold">
                                                    <span title="Momentum indicator using smoothed interval averaging to reduce noise: ▲ accelerating, ▼ decelerating">
                                                        Trend
                                                    </span>
                                                </th>
                                                <th className="w-20 px-2 py-2 text-right font-semibold">Current</th>
                                                {timeIntervals.map((interval, index) => (
                                                    <th key={index} className="w-16 px-1 py-2 text-center font-semibold text-sm">
                                                        {interval.label}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {stocks.map((stock, index) => (
                                                <tr key={stock.symbol} className="border-b hover:bg-muted/50">
                                                    <td className="w-16 px-2 py-3 font-medium truncate">
                                                        <Link
                                                            href={stock.asset_id ? `/market-data/assets/${stock.asset_id}` : `/market-data/assets?search=${stock.symbol}`}
                                                            className="text-blue-600 hover:text-blue-800 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
                                                            title={stock.symbol}
                                                        >
                                                            {stock.symbol}
                                                        </Link></td>
                                                    <td className={`w-20 px-2 py-3 text-right font-mono font-bold ${getChangeColor(stock.totalPercentChange)}`}>
                                                        {formatPercent(stock.totalPercentChange)}
                                                    </td>
                                                    <td className="w-16 px-2 py-3 text-center">
                                                        {(() => {
                                                            const momentum = getMomentumIndicator(stock);
                                                            return (
                                                                <span
                                                                    className={`text-lg font-semibold ${momentum.color}`}
                                                                    title={momentum.title}
                                                                >
                                                                    {momentum.symbol}
                                                                </span>
                                                            );
                                                        })()}
                                                    </td>
                                                    <td className="w-20 px-2 py-3 text-right font-mono text-sm">
                                                        {formatPrice(stock.currentPrice)}
                                                    </td>
                                                    {stock.intervalData.map((interval, intervalIndex) => (
                                                        <td key={intervalIndex} className="w-16 px-1 py-3 text-center">
                                                            <div className={`text-sm font-mono ${getChangeColor(interval.percentChange)}`}>
                                                                {formatPercent(interval.percentChange)}
                                                            </div>
                                                        </td>
                                                    ))}
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
        </>
    );
}