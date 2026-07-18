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

interface MyHourStock {
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

interface MyHourProps {
    stocks: MyHourStock[];
    timeIntervals: TimeInterval[];
    timestamp: string | null;
    timestampEst: string | null;
    assetTypeFilter: 'stock' | 'all';
    totalAnalyzed: number;
    dataFreshness: string;
}

export default function MyHour({
    stocks,
    timeIntervals,
    timestamp,
    timestampEst,
    assetTypeFilter,
    totalAnalyzed,
    dataFreshness,
}: MyHourProps) {
    const [showAsInteger, setShowAsInteger] = useState(false);

    const handleFilterChange = (newFilter: 'stock' | 'all') => {
        router.visit(`/my-hour?filter=${newFilter}`, {
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

    const getMomentumIndicator = (stock: MyHourStock) => {
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
            <Head title="My Hour - Watchlist 5-Minute Analysis" />
            <AppLayout breadcrumbs={[{ title: 'My Hour', href: '/my-hour' }]}>
                <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between">
                        <Heading
                            title="My Hour"
                            description="5-minute interval analysis of your watchlist assets with smoothed momentum indicators showing acceleration/deceleration trends"
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
                                        <span className="text-muted-foreground">Watchlist Items:</span>
                                        <div className="font-medium">{totalAnalyzed}</div>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">With Price Data:</span>
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
                                My Watchlist Stocks ({stocks.length})
                                {assetTypeFilter === 'all' && `My Watchlist Assets (${stocks.length})`}
                            </CardTitle>
                            <CardDescription>
                                5-minute interval analysis showing percentage changes over the last hour for your watchlist
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
                                <div className="text-center py-8">
                                    <p className="text-sm text-muted-foreground mb-4">
                                        {totalAnalyzed === 0 
                                            ? `No assets found in your watchlist${assetTypeFilter !== 'all' ? ` for ${assetTypeFilter}` : ''}.`
                                            : `No price data available for your watchlist ${assetTypeFilter === 'all' ? 'assets' : assetTypeFilter}.`
                                        }
                                    </p>
                                    {totalAnalyzed === 0 && (
                                        <Link 
                                            href="/watches"
                                            className="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 transition-colors"
                                        >
                                            Add Assets to Watchlist
                                        </Link>
                                    )}
                                </div>
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