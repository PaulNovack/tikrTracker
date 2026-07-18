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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { useState, useEffect } from 'react';

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

interface ToppingAnalysis {
    symbol: string;
    asset_type: string;
    lookback_minutes: number;
    last_ts: string;
    last_close: number;
    change_15m_pct: number | null;
    change_30m_pct: number | null;
    change_60m_pct: number | null;
    extension_from_low_pct: number | null;
    avg_volume_lookback: number | null;
    last_volume: number | null;
    flags: {
        is_extended: boolean;
        possible_top: boolean;
    };
    reasons: string[];
    error?: string;
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
    toppingAnalysis: ToppingAnalysis;
}

interface CheckTopPageProps {
    title: string;
    description: string;
    stocks: RisingHourStock[];
    timeIntervals: TimeInterval[];
    timestamp: string | null;
    timestampEst: string | null;
    assetTypeFilter: 'stock';
    actualAssetType?: 'stock';
    totalAnalyzed: number;
    dataFreshness: string;
    isStaleData?: boolean;
    dataAge?: string | null;
    backtestTime?: string;
    isBacktesting?: boolean;
}

export default function CheckTopIndex({
    title,
    description,
    stocks,
    timeIntervals,
    timestamp,
    timestampEst,
    assetTypeFilter,
    actualAssetType,
    totalAnalyzed,
    dataFreshness,
    isStaleData = false,
    dataAge,
    backtestTime,
    isBacktesting = false,
}: CheckTopPageProps) {
    console.log('CHECK-TOP DEBUG: Component loaded with null-safe fixes', { stocks, timeIntervals });
    const [showAsInteger, setShowAsInteger] = useState(false);
    const [hideTopped, setHideTopped] = useState(false);
    const [selectedTime, setSelectedTime] = useState(backtestTime || '');

    // Auto-refresh every 2 minutes (only for live data, not backtesting)
    useEffect(() => {
        if (isBacktesting) return; // Don't auto-refresh when backtesting
        
        const interval = setInterval(() => {
            router.reload();
        }, 2 * 60 * 1000); // 2 minutes in milliseconds

        return () => clearInterval(interval);
    }, [isBacktesting]);

    const handleFilterChange = (newFilter: 'stock') => {
        const params = new URLSearchParams();
        params.set('filter', newFilter);
        if (selectedTime) {
            params.set('backtest_time', selectedTime);
        }
        
        router.visit(`/check-top?${params.toString()}`, {
            preserveScroll: true,
        });
    };

    const handleTimeChange = (time: string) => {
        setSelectedTime(time);
        
        const params = new URLSearchParams();
        params.set('filter', assetTypeFilter);
        if (time) {
            params.set('backtest_time', time);
        }
        
        router.visit(`/check-top?${params.toString()}`, {
            preserveScroll: true,
        });
    };

    const handleResetToLive = () => {
        setSelectedTime('');
        router.visit(`/check-top?filter=${assetTypeFilter}`, {
            preserveScroll: true,
        });
    };

    // Get today's date for the datetime-local input max attribute
    const getTodayDateTime = () => {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        return `${year}-${month}-${day}T${hours}:${minutes}`;
    };

    // Filter stocks based on hideTopped setting
    const filteredStocks = hideTopped 
        ? stocks.filter(stock => !stock.toppingAnalysis?.flags?.possible_top)
        : stocks;

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
        if (!intervals || intervals.length < 5) {
            return { color: 'text-gray-400', symbol: '–', title: 'Insufficient data' };
        }

        // Get the 5 most recent intervals with data for smoothed comparison
        const validIntervals = intervals
            .filter((interval): interval is IntervalData & { percentChange: number } => 
                interval !== null && 
                interval !== undefined && 
                interval.percentChange !== null && 
                interval.percentChange !== undefined &&
                typeof interval.percentChange === 'number'
            )
            .slice(-5); // Get last 5 intervals

        if (validIntervals.length < 5) {
            return { color: 'text-gray-400', symbol: '–', title: 'Insufficient data' };
        }

        // Smoothed momentum: Compare averages to reduce 5-min volatility noise
        // Recent: average of last 2 intervals
        // Previous: average of 2 intervals before that (with 1 interval gap as buffer)
        const recentAvg = (validIntervals[validIntervals.length - 1].percentChange + 
                          validIntervals[validIntervals.length - 2].percentChange) / 2;
        
        const previousAvg = (validIntervals[validIntervals.length - 4].percentChange + 
                            validIntervals[validIntervals.length - 5].percentChange) / 2;

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
        <AppLayout>
            <Head title="Last 15 Minutes Rising Stock Analysis" />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <Heading 
                    title={title}
                    description={description}
                />

                {/* Timeframe Notice Banner */}
                {isBacktesting && (
                    <div className="p-4 bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 rounded-lg">
                        <div className="flex items-start gap-3">
                            <span className="text-purple-600 dark:text-purple-400 text-lg">🕰️</span>
                            <div>
                                <h3 className="font-semibold text-purple-900 dark:text-purple-100 mb-1">
                                    Historical Analysis Mode
                                </h3>
                                <p className="text-sm text-purple-800 dark:text-purple-200">
                                    Viewing data as it appeared at <strong>{backtestTime}</strong>. This shows what stocks 
                                    would have been identified as rising at that point in time.
                                </p>
                            </div>
                        </div>
                    </div>
                )}

                <div className="p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                    <div className="flex items-start gap-3">
                        <span className="text-blue-600 dark:text-blue-400 text-lg">⚡</span>
                        <div>
                            <h3 className="font-semibold text-blue-900 dark:text-blue-100 mb-1">
                                15-Minute Performance Analysis
                            </h3>
                            <p className="text-sm text-blue-800 dark:text-blue-200">
                                This analysis shows stocks that have <strong>risen in the last 15 minutes</strong> for rapid 
                                momentum detection. Perfect for catching emerging trends early - stocks appear here much faster 
                                than traditional hourly analysis.
                            </p>
                        </div>
                    </div>
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
                                    <div className="font-medium">
                                        {filteredStocks.length}
                                        {hideTopped && filteredStocks.length < stocks.length && (
                                            <span className="text-xs text-muted-foreground ml-1">
                                                (of {stocks.length})
                                            </span>
                                        )}
                                    </div>
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
                            {(actualAssetType || assetTypeFilter) === 'stock' && `Last 15 Min Rising Stocks (${filteredStocks.length})`}</CardTitle>
                        <CardDescription>
                            <div className="space-y-1">
                                <div>5-minute interval analysis showing 15-minute performance changes</div>
                                <div className="text-xs text-orange-600 dark:text-orange-400 font-medium">
                                    ⚡ This shows LAST 15 MINUTES performance for rapid momentum detection
                                </div>
                            </div>
                        </CardDescription>
                        
                        {/* Data Source Notice */}
                        {actualAssetType && actualAssetType !== assetTypeFilter && (
                            <div className="mt-2 p-3 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-md">
                                <div className="flex items-center gap-2">
                                    <span className="text-yellow-600 dark:text-yellow-400 font-medium">ℹ️</span>
                                    <span className="text-sm text-yellow-800 dark:text-yellow-200"> Stock data not available or outdated.</span>
                                </div>
                            </div>
                        )}
                        
                        {/* Stale Data Notice */}
                        {isStaleData && actualAssetType === assetTypeFilter && (
                            <div className="mt-2 p-3 bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-md">
                                <div className="flex items-center gap-2">
                                    <span className="text-orange-600 dark:text-orange-400 font-medium">⚠️</span>
                                    <span className="text-sm text-orange-800 dark:text-orange-200">
                                        Data is from a previous trading session ({dataAge}). Current session data may not be available yet.
                                    </span>
                                </div>
                            </div>
                        )}
                    </CardHeader>
                    <CardContent>
                        {/* Time Travel Controls */}
                        <div className="mb-6 p-4 bg-gray-50 dark:bg-gray-900/50 rounded-lg border">
                            <div className="flex flex-col sm:flex-row gap-4 items-start sm:items-end">
                                <div className="flex-1 min-w-64">
                                    <Label htmlFor="backtest-time" className="text-sm font-medium">
                                        🕰️ Time Travel: View Historical Analysis
                                    </Label>
                                    <Input
                                        id="backtest-time"
                                        type="datetime-local"
                                        value={selectedTime}
                                        max={getTodayDateTime()}
                                        onChange={(e) => setSelectedTime(e.target.value)}
                                        className="mt-1"
                                        placeholder="Select time to analyze"
                                    />
                                    <p className="text-xs text-muted-foreground mt-1">
                                        Select a time from today to see what stocks were rising then
                                    </p>
                                </div>
                                <div className="flex gap-2">
                                    <Button 
                                        onClick={() => handleTimeChange(selectedTime)}
                                        disabled={!selectedTime}
                                        variant="default"
                                    >
                                        View Historical
                                    </Button>
                                    {isBacktesting && (
                                        <Button 
                                            onClick={handleResetToLive}
                                            variant="outline"
                                        >
                                            Back to Live
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </div>

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
                            
                            {/* Display Format Toggle */}
                            <div className="flex gap-2 ml-auto">
                                <Button
                                    variant={hideTopped ? 'default' : 'outline'}
                                    onClick={() => setHideTopped(!hideTopped)}
                                >
                                    Hide Topped
                                </Button>
                                <Button
                                    variant={showAsInteger ? 'default' : 'outline'}
                                    onClick={() => setShowAsInteger(!showAsInteger)}
                                >
                                    Show As Integer
                                </Button>
                            </div>
                        </div>

                        {filteredStocks.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No rising stocks found in the last 15 minutes.
                            </p>
                        ) : (
                            <div className="overflow-hidden">
                                <table className="w-full table-fixed">
                                    <thead className="border-b">
                                        <tr className="text-muted-foreground">
                                            <th className="w-16 px-2 py-2 text-left font-semibold">Symbol</th>
                                            <th className="w-20 px-2 py-2 text-right font-semibold">
                                                <span title="Total percentage change over the last 15 minutes">15-Min %</span>
                                            </th>
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
                                        {filteredStocks.map((stock, index) => (
                                            <>
                                                <tr key={stock.symbol} className="border-b hover:bg-muted/50">
                                                    <td className="w-16 px-2 py-3 font-medium truncate">
                                                        <a
                                                            href={stock.asset_id ? `/market-data/assets/${stock.asset_id}` : `/market-data/assets?search=${stock.symbol}`}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="text-blue-600 hover:text-blue-800 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
                                                            title={stock.symbol}
                                                        >
                                                            {stock.symbol}
                                                        </a></td>
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
                                                    {stock.intervalData?.filter(interval => interval !== null && interval !== undefined).map((interval, intervalIndex) => (
                                                        <td key={intervalIndex} className="w-16 px-1 py-3 text-center">
                                                            <div className={`text-sm font-mono ${interval?.percentChange !== null && interval?.percentChange !== undefined ? getChangeColor(interval.percentChange) : 'text-gray-400'}`}>
                                                                {interval?.percentChange !== null && interval?.percentChange !== undefined ? formatPercent(interval.percentChange) : '–'}
                                                            </div>
                                                        </td>
                                                    )) || []}
                                                </tr>
                                                {/* Topping Analysis Row */}
                                                <tr key={`${stock.symbol}-analysis`} className="border-b bg-gray-50 dark:bg-gray-800/50">
                                                    <td colSpan={4 + timeIntervals.length} className="px-2 py-2">
                                                        <div className="text-xs space-y-1">
                                                            <div className="font-medium text-gray-700 dark:text-gray-300">
                                                                📊 Topping Analysis:
                                                            </div>
                                                            {stock.toppingAnalysis?.error ? (
                                                                <div className="text-red-600 dark:text-red-400">
                                                                    ❌ {stock.toppingAnalysis.error}
                                                                </div>
                                                            ) : stock.toppingAnalysis ? (
                                                                <div className="flex flex-wrap gap-4 text-gray-600 dark:text-gray-400">
                                                                    <div>
                                                                        <span className="font-medium">Extension:</span> {
                                                                            stock.toppingAnalysis.extension_from_low_pct 
                                                                                ? `${stock.toppingAnalysis.extension_from_low_pct.toFixed(2)}%`
                                                                                : 'N/A'
                                                                        }
                                                                    </div>
                                                                    <div>
                                                                        <span className="font-medium">Extended:</span>{' '}
                                                                        <span className={stock.toppingAnalysis?.flags?.is_extended ? 'text-orange-600 dark:text-orange-400' : 'text-green-600 dark:text-green-400'}>
                                                                            {stock.toppingAnalysis?.flags?.is_extended ? '⚠️ YES' : '✅ NO'}
                                                                        </span>
                                                                    </div>
                                                                    <div>
                                                                        <span className="font-medium">Possible Top:</span>{' '}
                                                                        <span className={stock.toppingAnalysis?.flags?.possible_top ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'}>
                                                                            {stock.toppingAnalysis?.flags?.possible_top ? '🔴 YES' : '✅ NO'}
                                                                        </span>
                                                                    </div>
                                                                    {stock.toppingAnalysis?.reasons && stock.toppingAnalysis.reasons.length > 0 && (
                                                                        <div className="w-full mt-1">
                                                                            <div className="font-medium">Signals:</div>
                                                                            <div className="mt-1 space-y-1">
                                                                                {stock.toppingAnalysis.reasons.map((reason, idx) => (
                                                                                    <div key={idx} className="text-xs text-gray-600 dark:text-gray-400 pl-2">
                                                                                        • {reason}
                                                                                    </div>
                                                                                ))}
                                                                            </div>
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            ) : (
                                                                <div className="text-gray-500 dark:text-gray-400">
                                                                    No topping analysis available
                                                                </div>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            </>
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