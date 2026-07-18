import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Clock, RefreshCw, TrendingUp } from 'lucide-react';
import { useState, useEffect } from 'react';
import { show as showAsset } from '@/routes/asset-info';

interface Last4BarsStock {
    symbol: string;
    asset_id: number | null;
    name: string;
    type: 'stock';
    lastPrice: number;
    pctLookback: number;
    pctLast2h: number;
    projectedPct8h: number;
    numBarsUsed: number;
    avgDailyVolume: number;
    actualClosePrice?: number;
    actualClosePct?: number;
    dailyHighPrice?: number;
    dailyHighPct?: number;
}

interface Last4BarsUpPageProps {
    title: string;
    description: string;
    stocks: Last4BarsStock[];
    timestamp: string | null;
    timestampEst: string | null;
    assetTypeFilter: 'stock';
    totalAnalyzed: number;
    totalFound: number;
    dataFreshness: {
        minutes_old: number | null;
        description: string;
    } | string;
    numBarsUsed: number;
    time?: string; // Optional time parameter from URL
    bars?: number; // Optional bars parameter from URL
}

export default function Last4BarsUpIndex({
    title,
    description,
    stocks,
    timestamp,
    timestampEst,
    assetTypeFilter,
    totalAnalyzed,
    totalFound,
    dataFreshness,
    numBarsUsed,
    time,
    bars,
}: Last4BarsUpPageProps) {
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [currentFilter, setCurrentFilter] = useState(assetTypeFilter);
    const [isLoading, setIsLoading] = useState(false);
    const [selectedBars, setSelectedBars] = useState(bars || numBarsUsed || 4);

    // Format current EST time as datetime-local value
    const getCurrentESTDateTime = () => {
        const now = new Date();
        // Convert to EST (UTC-5)
        const est = new Date(now.getTime() - (5 * 60 * 60 * 1000));
        return est.toISOString().slice(0, 16);
    };

    // Initialize with time from URL or current time
    const getInitialDateTime = () => {
        if (time) {
            // Convert from "YYYY-MM-DD HH:MM:SS" to "YYYY-MM-DDTHH:MM"
            return time.replace(' ', 'T').slice(0, 16);
        }
        return getCurrentESTDateTime();
    };

    const [selectedDateTime, setSelectedDateTime] = useState(getInitialDateTime());

    // Update datetime picker when time prop changes
    useEffect(() => {
        setSelectedDateTime(getInitialDateTime());
    }, [time]);

    const handleDateTimeChange = (event: React.ChangeEvent<HTMLInputElement>) => {
        setSelectedDateTime(event.target.value);
    };

    const handleAnalyze = () => {
        setIsLoading(true);
        // Convert datetime-local value back to YYYY-MM-DD HH:MM:SS format
        const formattedDateTime = selectedDateTime.replace('T', ' ') + ':00';
        
        router.get('/last-4-bars-up', { 
            time: formattedDateTime,
            filter: currentFilter,
            bars: selectedBars
        }, {
            preserveState: false,
            onFinish: () => setIsLoading(false)
        });
    };

    const handleResetToNow = () => {
        setSelectedDateTime(getCurrentESTDateTime());
        setIsLoading(true);
        router.get('/last-4-bars-up', { 
            filter: currentFilter,
            bars: selectedBars
        }, {
            preserveState: false,
            onFinish: () => setIsLoading(false)
        });
    };

    const handleBarsChange = (newBars: number) => {
        setSelectedBars(newBars);
        setIsLoading(true);
        const params: Record<string, string | number> = { 
            filter: currentFilter,
            bars: newBars
        };
        if (time) {
            params.time = time;
        }
        router.get('/last-4-bars-up', params, {
            preserveState: false,
            onFinish: () => setIsLoading(false)
        });
    };

    // Auto-refresh logic
    useEffect(() => {
        const interval = setInterval(() => {
            // Auto-refresh every 2 minutes for stocks, 5 minutes for crypto
            const refreshInterval = 120000;
            router.reload({ only: ['stocks', 'timestamp', 'timestampEst', 'totalAnalyzed', 'totalFound'] });
        });

        return () => clearInterval(interval);
    }, [currentFilter]);

    const handleRefresh = () => {
        setIsRefreshing(true);
        router.reload({
            only: ['stocks', 'timestamp', 'timestampEst', 'totalAnalyzed', 'totalFound'],
            onFinish: () => setIsRefreshing(false),
        });
    };

    const handleFilterChange = (filter: 'stock') => {
        setCurrentFilter(filter);
        const params: Record<string, string | number> = { 
            filter,
            bars: selectedBars
        };
        if (time) {
            params.time = time;
        }
        router.get('/last-4-bars-up', params, {
            preserveState: true,
            replace: true,
        });
    };

    return (
        <>
            <Head title={`Last ${selectedBars} Bars Up`} />
            <AppLayout breadcrumbs={[{ title: `Last ${selectedBars} Bars Up`, href: '/last-4-bars-up' }]}>
                <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between">
                        <Heading
                            title={`Last ${selectedBars} Bars Up`}
                            description={`Analysis of ${selectedBars} consecutive increasing price bars with 8-hour projections`}
                        />
                        <div className="flex items-center gap-4">
                            <div className="flex gap-2">
                                <Button
                                    variant={currentFilter === 'stock' ? 'default' : 'outline'}
                                    onClick={() => handleFilterChange('stock')}
                                    size="sm"
                                >
                                    Stocks
                                </Button>
                            </div>
                            <Button 
                                onClick={handleRefresh} 
                                disabled={isRefreshing}
                                size="sm"
                                variant="outline"
                            >
                                {isRefreshing ? 'Refreshing...' : 'Refresh'}
                            </Button>
                        </div>
                    </div>

                    {/* Number of Bars Selection */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <TrendingUp className="h-5 w-5 text-green-600" />
                                Number of Bars Selection
                            </CardTitle>
                            <CardDescription>
                                Select the number of consecutive increasing bars to analyze (minimum 2 bars)
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-wrap gap-2">
                                {[2, 3, 4, 5, 6, 8, 10, 12].map((barCount) => (
                                    <Button
                                        key={barCount}
                                        variant={selectedBars === barCount ? 'default' : 'outline'}
                                        onClick={() => handleBarsChange(barCount)}
                                        disabled={isLoading}
                                        size="sm"
                                    >
                                        {barCount} Bars
                                    </Button>
                                ))}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Date/Time Analysis Controls */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Clock className="h-5 w-5 text-blue-600" />
                                Analysis Time Selection
                            </CardTitle>
                            <CardDescription>
                                Select a specific date and time to analyze historical Last 4 Bars Up patterns, or use current time for real-time analysis
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-end">
                                <div className="grid flex-1 gap-2">
                                    <Label htmlFor="analysis-time">
                                        Analysis Date & Time (EST)
                                    </Label>
                                    <Input
                                        id="analysis-time"
                                        type="datetime-local"
                                        value={selectedDateTime}
                                        onChange={handleDateTimeChange}
                                        className="w-full"
                                    />
                                </div>
                                <div className="flex gap-2">
                                    <Button 
                                        onClick={handleAnalyze}
                                        disabled={isLoading}
                                        className="flex items-center gap-2"
                                    >
                                        {isLoading ? (
                                            <>
                                                <RefreshCw className="h-4 w-4 animate-spin" />
                                                Analyzing...
                                            </>
                                        ) : (
                                            <>
                                                <TrendingUp className="h-4 w-4" />
                                                Analyze
                                            </>
                                        )}
                                    </Button>
                                    <Button 
                                        onClick={handleResetToNow}
                                        variant="outline"
                                        disabled={isLoading}
                                        className="flex items-center gap-2"
                                    >
                                        <Clock className="h-4 w-4" />
                                        Now
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Summary Card */}
                    {timestamp && (
                        <Card className="bg-muted/50">
                            <CardContent className="pt-6">
                                <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                                    <div>
                                        <span className="text-muted-foreground">Total Found:</span>
                                        <div className="text-2xl font-bold text-green-600">{totalFound}</div>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Total Analyzed:</span>
                                        <div className="text-2xl font-bold">{totalAnalyzed}</div>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Last Updated:</span>
                                        <div className="text-sm">{timestampEst || timestamp}</div>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Data Status:</span>
                                        <div className="text-sm text-green-600">
                                            {typeof dataFreshness === 'string' 
                                                ? dataFreshness 
                                                : dataFreshness.description
                                            }
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Last {selectedBars} Bars Up ({stocks.length})
                            </CardTitle>
                            <CardDescription>
                                {description}
                            </CardDescription>
                        </CardHeader>

                        <CardContent>
                            {stocks.length === 0 ? (
                                <div className="text-center text-muted-foreground py-8">
                                    <p>No {currentFilter === 'stock' ? 'stocks' : 'crypto assets'} found with {selectedBars} consecutive increasing bars.</p>
                                    <p className="text-sm mt-2">Try refreshing, selecting fewer bars, or check back later.</p>
                                </div>
                            ) : (
                                <div className="space-y-4">
                                    <p className="text-sm text-muted-foreground">
                                        Found {stocks.length} {currentFilter === 'stock' ? 'stocks' : 'crypto assets'} with {selectedBars} consecutive increasing price bars.
                                    </p>
                                    
                                    {/* Results Table */}
                                    <div className="overflow-x-auto">
                                        <table className="w-full border-collapse">
                                            <thead>
                                                <tr className="border-b">
                                                    <th className="text-left py-3 px-4 font-semibold">Symbol</th>
                                                    <th className="text-left py-3 px-4 font-semibold">Name</th>
                                                    <th className="text-right py-3 px-4 font-semibold">Last Price</th>
                                                    <th className="text-right py-3 px-4 font-semibold">{selectedBars}-Bar %</th>
                                                    <th className="text-right py-3 px-4 font-semibold">2h %</th>
                                                    <th className="text-right py-3 px-4 font-semibold">8h Projection</th>
                                                    {time && (
                                                        <>
                                                            <th className="text-right py-3 px-4 font-semibold">Close $</th>
                                                            <th className="text-right py-3 px-4 font-semibold">Actual %</th>
                                                            <th className="text-right py-3 px-4 font-semibold">Days High</th>
                                                        </>
                                                    )}
                                                    <th className="text-right py-3 px-4 font-semibold">Volume</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {stocks.map((stock, index) => (
                                                    <tr key={stock.symbol} className="border-b hover:bg-muted/50">
                                                        <td className="py-3 px-4 font-medium">
                                                            {stock.asset_id ? (
                                                                <Link
                                                                    href={showAsset.url(stock.asset_id)}
                                                                    className="text-blue-600 hover:text-blue-700 hover:underline font-bold dark:text-blue-400 dark:hover:text-blue-300"
                                                                >
                                                                    {stock.symbol}
                                                                </Link>
                                                            ) : (
                                                                stock.symbol
                                                            )}
                                                        </td>
                                                        <td className="py-3 px-4 text-sm text-muted-foreground truncate max-w-[200px]">
                                                            {stock.name}
                                                        </td>
                                                        <td className="py-3 px-4 text-right font-mono">
                                                            ${stock.lastPrice.toFixed(2)}
                                                        </td>
                                                        <td className="py-3 px-4 text-right font-mono text-green-600">
                                                            +{stock.pctLookback.toFixed(2)}%
                                                        </td>
                                                        <td className={`py-3 px-4 text-right font-mono ${
                                                            stock.pctLast2h >= 0 ? 'text-green-600' : 'text-red-600'
                                                        }`}>
                                                            {stock.pctLast2h >= 0 ? '+' : ''}{stock.pctLast2h.toFixed(2)}%
                                                        </td>
                                                        <td className="py-3 px-4 text-right font-mono text-blue-600 font-semibold">
                                                            +{stock.projectedPct8h.toFixed(2)}%
                                                        </td>
                                                        {time && (
                                                            <>
                                                                <td className="py-3 px-4 text-right font-mono">
                                                                    {stock.actualClosePrice !== undefined 
                                                                        ? `$${stock.actualClosePrice.toFixed(2)}`
                                                                        : 'N/A'
                                                                    }
                                                                </td>
                                                                <td className={`py-3 px-4 text-right font-mono ${
                                                                    stock.actualClosePct !== undefined 
                                                                        ? (stock.actualClosePct >= 0 ? 'text-green-600' : 'text-red-600')
                                                                        : 'text-gray-400'
                                                                }`}>
                                                                    {stock.actualClosePct !== undefined 
                                                                        ? `${stock.actualClosePct >= 0 ? '+' : ''}${stock.actualClosePct.toFixed(2)}%`
                                                                        : 'N/A'
                                                                    }
                                                                </td>
                                                                <td className={`py-3 px-4 text-right font-mono ${
                                                                    stock.dailyHighPct !== undefined 
                                                                        ? 'text-green-600'
                                                                        : 'text-gray-400'
                                                                }`}>
                                                                    {stock.dailyHighPct !== undefined 
                                                                        ? `+${stock.dailyHighPct.toFixed(2)}%`
                                                                        : 'N/A'
                                                                    }
                                                                </td>
                                                            </>
                                                        )}
                                                        <td className="py-3 px-4 text-right text-sm text-muted-foreground">
                                                            {stock.avgDailyVolume ? stock.avgDailyVolume.toLocaleString() : 'N/A'}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </AppLayout>
        </>
    );
}