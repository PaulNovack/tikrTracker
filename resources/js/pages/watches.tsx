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
import { Trash2, TrendingDown, TrendingUp } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Area,
    AreaChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

interface PricePoint {
    time: string;
    price: string | number | null;
}

interface DailyPrice {
    id: number;
    date: string;
    price: string;
    open: string;
    high: string;
    low: string;
    volume: number;
}

interface AssetInfo {
    id: number;
    symbol: string;
    asset_type: 'stock';
    common_name: string;
    description: string;
    sector?: string;
}

interface Stats {
    '52WeekHigh': number;
    '52WeekLow': number;
    avgVolume: number;
    open: number;
    previousClose: number;
    dayHigh: number;
    dayLow: number;
    volume: number;
}

interface PriceStat {
    change: number;
    changePercent: number;
}

interface Watch {
    id: number;
    asset: AssetInfo;
    chartData: Record<string, PricePoint[]>;
    latestPrice: DailyPrice | null;
    stats: Stats;
    priceStats: Record<string, PriceStat>;
    hasEnoughHourlyData: boolean;
}

interface MarketStatus {
    isMarketDay: boolean;
    isMarketOpen: boolean;
    defaultTimeRange: TimeRange;
    stockMarketStatus: string;
    reason?: string;
}

interface Props {
    watches: Watch[];
    marketStatus: MarketStatus;
}

type TimeRange =
    | '1D'
    | 'Last Open Day'
    | '5D'
    | '1M'
    | '3M'
    | '6M'
    | '1Y'
    | 'MAX';

function WatchCard({
    watch,
    defaultTimeRange,
}: {
    watch: Watch;
    defaultTimeRange: TimeRange;
}) {
    const [selectedRange, setSelectedRange] =
        useState<TimeRange>(defaultTimeRange);
    const [maxChartData, setMaxChartData] = useState<PricePoint[] | null>(null);
    const [loadingMax, setLoadingMax] = useState(false);

    // When user clicks MAX, fetch the data if not already loaded
    const handleTimeRangeChange = async (range: TimeRange) => {
        if (range === 'MAX' && !maxChartData) {
            setLoadingMax(true);
            try {
                const response = await fetch(
                    `/watches/${watch.id}/max-chart-data`,
                );
                const data = await response.json();
                setMaxChartData(data.MAX);
            } catch (error) {
                console.error('Failed to load MAX chart data:', error);
            } finally {
                setLoadingMax(false);
            }
        }
        setSelectedRange(range);
    };

    const currentData =
        selectedRange === 'MAX' && maxChartData
            ? maxChartData
            : watch.chartData[selectedRange] || [];
    const currentStats = watch.priceStats[selectedRange];

    const formattedChartData = currentData.map((point) => ({
        time: point.time,
        price: point.price ? parseFloat(String(point.price)) : null,
    }));

    const isPositive = currentStats?.changePercent
        ? currentStats.changePercent >= 0
        : true;

    const prices = formattedChartData
        .filter((d) => d.price !== null)
        .map((d) => d.price as number);
    const minPrice = prices.length > 0 ? Math.min(...prices) : 0;
    const maxPrice = prices.length > 0 ? Math.max(...prices) : 0;
    const padding = (maxPrice - minPrice) * 0.1;

    const formatXAxis = (time: string) => {
        const dateUTC = new Date(time);
        const estOffset = -5 * 60;
        const dateEST = new Date(dateUTC.getTime() + estOffset * 60 * 1000);

        const shouldShowTime =
            ['1D', 'Last Open Day', '5D'].includes(selectedRange) ||
            (watch.hasEnoughHourlyData &&
                ['1M', '3M', '6M'].includes(selectedRange));

        if (shouldShowTime) {
            return dateEST.toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
            });
        }
        return dateEST.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
        });
    };

    const CustomTooltip = ({ active, payload }: any) => {
        if (active && payload && payload.length) {
            const data = payload[0].payload;
            if (data.price === null) {
                return null;
            }

            const dateUTC = new Date(data.time);
            const estOffset = -5 * 60;
            const dateEST = new Date(dateUTC.getTime() + estOffset * 60 * 1000);

            const showTime =
                ['1D', 'Last Open Day', '5D'].includes(selectedRange) ||
                (watch.hasEnoughHourlyData &&
                    ['1M', '3M', '6M'].includes(selectedRange));

            return (
                <div className="rounded-lg border bg-card p-3 shadow-lg">
                    <div className="text-sm text-muted-foreground">
                        {dateEST.toLocaleDateString('en-US', {
                            weekday: 'short',
                            year: 'numeric',
                            month: 'short',
                            day: 'numeric',
                            ...(showTime
                                ? { hour: 'numeric', minute: '2-digit' }
                                : {}),
                        })}
                        {showTime && <span className="ml-1">EST</span>}
                    </div>
                    <div className="text-lg font-bold">
                        ${data.price.toFixed(2)}
                    </div>
                </div>
            );
        }
        return null;
    };

    const handleRemove = () => {
        router.delete(`/watches/${watch.id}`, {
            preserveScroll: true,
        });
    };

    return (
        <Card className="flex flex-col">
            <CardHeader className="pb-3">
                <div className="flex items-start justify-between">
                    <div className="flex-1">
                        <div className="flex items-center gap-2">
                            <CardTitle className="text-2xl">
                                {watch.asset.symbol}
                            </CardTitle>
                            <Badge
                                variant={
                                    watch.asset.asset_type === 'stock'
                                        ? 'default'
                                        : 'outline'
                                }
                            >
                                {watch.asset.asset_type.toUpperCase()}
                            </Badge>
                        </div>
                        <CardDescription>
                            {watch.asset.common_name}
                        </CardDescription>
                        {watch.asset.sector && (
                            <Badge variant="secondary" className="mt-2">
                                {watch.asset.sector}
                            </Badge>
                        )}
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                        onClick={handleRemove}
                    >
                        <Trash2 className="h-4 w-4" />
                    </Button>
                </div>
            </CardHeader>

            <CardContent className="flex flex-1 flex-col gap-4">
                {/* Price and Chart Section */}
                {watch.latestPrice && (
                    <div className="space-y-4">
                        {/* Current Price */}
                        <div>
                            <div className="mb-2 flex items-end gap-4">
                                <div className="text-3xl font-bold">
                                    $
                                    {parseFloat(
                                        String(watch.latestPrice.price),
                                    ).toFixed(2)}
                                </div>
                                {currentStats && (
                                    <div
                                        className={`mb-1 flex items-center gap-1 text-sm font-semibold ${
                                            isPositive
                                                ? 'text-emerald-600 dark:text-emerald-400'
                                                : 'text-red-600 dark:text-red-400'
                                        }`}
                                    >
                                        {isPositive ? (
                                            <TrendingUp className="size-4" />
                                        ) : (
                                            <TrendingDown className="size-4" />
                                        )}
                                        {isPositive ? '+' : ''}
                                        {currentStats.change.toFixed(2)} (
                                        {currentStats.changePercent.toFixed(2)}
                                        %)
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Chart */}
                        {formattedChartData.length > 0 && (
                            <div className="h-48 w-full min-h-48">
                                <ResponsiveContainer width="100%" height="100%" minHeight={192}>
                                    <AreaChart data={formattedChartData}>
                                        <defs>
                                            <linearGradient
                                                id={`colorPrice-${watch.asset.id}`}
                                                x1="0"
                                                y1="0"
                                                x2="0"
                                                y2="1"
                                            >
                                                <stop
                                                    offset="5%"
                                                    stopColor={
                                                        isPositive
                                                            ? '#10b981'
                                                            : '#ef4444'
                                                    }
                                                    stopOpacity={0.3}
                                                />
                                                <stop
                                                    offset="95%"
                                                    stopColor={
                                                        isPositive
                                                            ? '#10b981'
                                                            : '#ef4444'
                                                    }
                                                    stopOpacity={0}
                                                />
                                            </linearGradient>
                                        </defs>
                                        <XAxis
                                            dataKey="time"
                                            tickFormatter={formatXAxis}
                                            stroke="#888888"
                                            fontSize={12}
                                            tickLine={false}
                                            axisLine={false}
                                        />
                                        <YAxis
                                            domain={[
                                                minPrice - padding,
                                                maxPrice + padding,
                                            ]}
                                            stroke="#888888"
                                            fontSize={12}
                                            tickLine={false}
                                            axisLine={false}
                                            tickFormatter={(value) =>
                                                `$${value.toFixed(2)}`
                                            }
                                        />
                                        <Tooltip content={<CustomTooltip />} />
                                        <Area
                                            type="linear"
                                            dataKey="price"
                                            stroke={
                                                isPositive
                                                    ? '#10b981'
                                                    : '#ef4444'
                                            }
                                            strokeWidth={2}
                                            fill={`url(#colorPrice-${watch.asset.id})`}
                                            connectNulls={false}
                                        />
                                    </AreaChart>
                                </ResponsiveContainer>
                            </div>
                        )}

                        {/* Time Range Selector */}
                        <div className="flex flex-wrap gap-1">
                            {(
                                [
                                    '1D',
                                    'Last Open Day',
                                    '5D',
                                    '1M',
                                    '3M',
                                    '6M',
                                    '1Y',
                                    'MAX',
                                ] as TimeRange[]
                            ).map((range) => (
                                <Button
                                    key={range}
                                    variant={
                                        selectedRange === range
                                            ? 'default'
                                            : 'ghost'
                                    }
                                    size="sm"
                                    onClick={() => handleTimeRangeChange(range)}
                                    disabled={
                                        range === 'MAX'
                                            ? loadingMax
                                            : !watch.chartData[range] ||
                                              watch.chartData[range].length ===
                                                  0
                                    }
                                >
                                    {range === 'MAX' && loadingMax
                                        ? 'Loading...'
                                        : range}
                                </Button>
                            ))}
                        </div>
                    </div>
                )}

                {/* Key Statistics and Performance Grid */}
                <div className="grid grid-cols-2 gap-4">
                    {/* Key Statistics */}
                    <div className="space-y-2 text-sm">
                        <h4 className="font-semibold">Key Statistics</h4>
                        <div className="flex justify-between text-xs">
                            <span className="text-muted-foreground">
                                Previous Close
                            </span>
                            <span className="font-medium">
                                {watch.stats.previousClose
                                    ? `$${parseFloat(String(watch.stats.previousClose)).toFixed(2)}`
                                    : '—'}
                            </span>
                        </div>
                        <div className="flex justify-between text-xs">
                            <span className="text-muted-foreground">
                                Day's Range
                            </span>
                            <span className="font-medium">
                                $
                                {parseFloat(String(watch.stats.dayLow)).toFixed(
                                    2,
                                )}{' '}
                                - $
                                {parseFloat(
                                    String(watch.stats.dayHigh),
                                ).toFixed(2)}
                            </span>
                        </div>
                        <div className="flex justify-between text-xs">
                            <span className="text-muted-foreground">
                                52 Week Range
                            </span>
                            <span className="font-medium">
                                $
                                {parseFloat(
                                    String(watch.stats['52WeekLow']),
                                ).toFixed(2)}{' '}
                                - $
                                {parseFloat(
                                    String(watch.stats['52WeekHigh']),
                                ).toFixed(2)}
                            </span>
                        </div>
                        <div className="flex justify-between text-xs">
                            <span className="text-muted-foreground">
                                Volume
                            </span>
                            <span className="font-medium">
                                {watch.stats.volume
                                    ? watch.stats.volume.toLocaleString()
                                    : '—'}
                            </span>
                        </div>
                        <div className="flex justify-between text-xs">
                            <span className="text-muted-foreground">
                                Avg Volume
                            </span>
                            <span className="font-medium">
                                {watch.stats.avgVolume
                                    ? Math.round(
                                          watch.stats.avgVolume,
                                      ).toLocaleString()
                                    : '—'}
                            </span>
                        </div>
                    </div>

                    {/* Performance */}
                    <div className="space-y-2 text-sm">
                        <h4 className="font-semibold">Performance</h4>
                        {Object.entries(watch.priceStats).map(
                            ([period, stat]) => (
                                <div
                                    key={period}
                                    className="flex items-center justify-between text-xs"
                                >
                                    <span className="text-muted-foreground">
                                        {period}
                                    </span>
                                    <div className="flex items-center gap-1">
                                        <div
                                            className={`flex items-center gap-1 font-medium ${
                                                stat.changePercent >= 0
                                                    ? 'text-emerald-600 dark:text-emerald-400'
                                                    : 'text-red-600 dark:text-red-400'
                                            }`}
                                        >
                                            {stat.changePercent >= 0 ? (
                                                <TrendingUp className="size-3" />
                                            ) : (
                                                <TrendingDown className="size-3" />
                                            )}
                                            {stat.changePercent >= 0 ? '+' : ''}
                                            {stat.changePercent.toFixed(2)}%
                                        </div>
                                    </div>
                                </div>
                            ),
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

export default function Watches({ watches, marketStatus }: Props) {
    const [watchesData, setWatchesData] = useState<Watch[]>(watches);

    useEffect(() => {
        // Poll the /watches endpoint every 5 minutes (300000 ms)
        const pollInterval = setInterval(
            () => {
                router.get(
                    '/watches',
                    {},
                    {
                        onSuccess: (page) => {
                            const newWatches =
                                (page.props as any).watches || [];
                            setWatchesData(newWatches);
                        },
                        preserveScroll: true,
                        preserveState: true,
                    },
                );
            },
            5 * 60 * 1000,
        ); // 5 minutes

        return () => clearInterval(pollInterval);
    }, []);

    // Update local state when props change (from initial load or manual refresh)
    useEffect(() => {
        setWatchesData(watches);
    }, [watches]);

    return (
        <AppLayout breadcrumbs={[{ title: 'Watches', href: '/watches' }]}>
            <Head title="TikrTracker - Show Watches" />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex items-center justify-between">
                    <Heading
                        title="Watched Stocks"
                        description="Monitor the stocks you want to track - real-time day ranges (market open to close) from 5-minute price data"
                    />
                    <Link href="/watches/settings">
                        <Button>Add Stock</Button>
                    </Link>
                </div>

                {watchesData.length === 0 ? (
                    <Card className="py-12 text-center">
                        <CardContent className="flex flex-col items-center justify-center gap-4">
                            <div className="text-5xl">👀</div>
                            <div>
                                <CardTitle>No watched stocks</CardTitle>
                                <CardDescription className="mt-1">
                                    Start building your watch list to track
                                    stocks you're interested in
                                </CardDescription>
                            </div>
                            <Link href="/watches/settings">
                                <Button className="mt-4">
                                    Add Your First Stock
                                </Button>
                            </Link>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-3">
                        {watchesData.map((watch) => (
                            <WatchCard
                                key={watch.id}
                                watch={watch}
                                defaultTimeRange={marketStatus.defaultTimeRange}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
