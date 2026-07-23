import { index } from '@/actions/App/Http/Controllers/AssetInfoController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import CandlestickChart from '@/components/candlestick-chart';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Check, Plus, Search, TrendingDown, TrendingUp, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
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
    price: string;
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

interface Props {
    asset: AssetInfo;
    latestPrice: DailyPrice | FiveMinutePrice | null;
    chartData: {
        'Last Open Day': PricePoint[];
        '5D': PricePoint[];
        '1M': PricePoint[];
        '3M': PricePoint[];
        '6M': PricePoint[];
        '1Y': PricePoint[];
    };
    priceStats: {
        '1D'?: PriceStat;
        'Last Open Day'?: PriceStat;
        '5D'?: PriceStat;
    };
    stats: Stats;
    hasEnoughHourlyData: boolean;
    todayMarketStatus?: {
        date: string;
        market_type: string;
        status: string;
        reason?: string;
        is_early_close: boolean;
        formatted_date?: string;
    } | null;
    lastOpenDay?: string | null;
    isWatched: boolean;
    customDate?: string | null;
    newsLink: string;
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

export default function AssetInfoShow({
    asset,
    latestPrice,
    chartData,
    priceStats,
    stats,
    hasEnoughHourlyData,
    todayMarketStatus,
    lastOpenDay,
    isWatched,
    customDate,
    newsLink,
}: Props) {
    const defaultRange = useMemo<TimeRange>(() => {
        if (chartData['1D'] && chartData['1D'].length > 0) {
            return '1D';
        }
        if (chartData['Last Open Day'] && chartData['Last Open Day'].length > 0) {
            return 'Last Open Day';
        }
        if (chartData['5D'] && chartData['5D'].length > 0) {
            return '5D';
        }        return '1Y';
    }, [chartData]);

    const [selectedRange, setSelectedRange] =
        useState<TimeRange>(defaultRange);
    const [maxChartData, setMaxChartData] = useState<PricePoint[] | null>(null);
    const [loadingMax, setLoadingMax] = useState(false);
    const [watchedState, setWatchedState] = useState(isWatched);
    const [processingWatch, setProcessingWatch] = useState(false);
    const [selectedDate, setSelectedDate] = useState<string>(customDate || '');
    const [customDateChartData, setCustomDateChartData] = useState<PricePoint[] | null>(null);
    const [customDatePriceStats, setCustomDatePriceStats] = useState<PriceStat | null>(null);
    const [loadingCustomDate, setLoadingCustomDate] = useState(false);
    const [showCandles, setShowCandles] = useState(true);
    const [candlestickData, setCandlestickData] = useState<CandleBar[] | null>(null);
    const [loadingCandles, setLoadingCandles] = useState(false);
    const [liveQuote, setLiveQuote] = useState<LiveQuote | null>(null);
    const [loadingLiveQuote, setLoadingLiveQuote] = useState(false);

    useEffect(() => {
        setSelectedRange(defaultRange);
        setMaxChartData(null);
        setCustomDateChartData(null);
        setCustomDatePriceStats(null);
    }, [asset.id, defaultRange]);

    // Search state
    const [searchTerm, setSearchTerm] = useState('');
    const [suggestions, setSuggestions] = useState<AssetSearchResult[]>([]);
    const [showSuggestions, setShowSuggestions] = useState(false);
    const [isLoadingSearch, setIsLoadingSearch] = useState(false);
    const [selectedIndex, setSelectedIndex] = useState(-1);
    const searchRef = useRef<HTMLDivElement>(null);
    const dropdownRef = useRef<HTMLDivElement>(null);

    // Check if user is a guest (not logged in or has guest role)
    const { auth } = usePage().props;
    const isGuest = (auth as { isGuest: boolean }).isGuest;

    // Load custom date chart data on mount if date parameter is present
    useEffect(() => {
        if (customDate && customDate.trim() !== '') {
            const loadCustomDateData = async () => {
                setLoadingCustomDate(true);
                setSelectedRange('1D');
                
                try {
                    const response = await fetch(
                        `/market-data/assets/${asset.id}/custom-date-chart?date=${customDate}`,
                    );
                    const data = await response.json();
                    setCustomDateChartData(data.chartData);
                    setCustomDatePriceStats(data.priceStats);
                } catch (error) {
                    console.error('Failed to load custom date chart data:', error);
                } finally {
                    setLoadingCustomDate(false);
                }
            };
            
            loadCustomDateData();
        }
    }, [customDate, asset.id]);

    useEffect(() => {
        if (!showCandles) {
            return;
        }

        const controller = new AbortController();

        const loadCandles = async () => {
            setLoadingCandles(true);

            try {
                const params = new URLSearchParams({
                    range: selectedRange,
                });

                if (selectedDate) {
                    params.set('date', selectedDate);
                } else if (selectedRange === 'Last Open Day' && lastOpenDay) {
                    params.set('date', lastOpenDay);
                }

                const response = await fetch(
                    `/market-data/assets/${asset.id}/candlestick-chart?${params.toString()}`,
                    { signal: controller.signal },
                );
                const data = await response.json();

                setCandlestickData(Array.isArray(data.data) ? data.data : []);
            } catch (error) {
                if (!controller.signal.aborted) {
                    console.error('Failed to load candlestick chart data:', error);
                    setCandlestickData([]);
                }
            } finally {
                if (!controller.signal.aborted) {
                    setLoadingCandles(false);
                }
            }
        };

        loadCandles();

        return () => controller.abort();
    }, [asset.id, lastOpenDay, selectedDate, selectedRange, showCandles]);

    useEffect(() => {
        if (asset.asset_type !== 'stock') {
            setLiveQuote(null);
            return;
        }

        let active = true;
        const controller = new AbortController();

        const loadLiveQuote = async () => {
            setLoadingLiveQuote(true);

            try {
                const requestUrl = `/market-data/assets/${asset.id}/live-quote?ts=${Date.now()}`;
                const response = await fetch(
                    requestUrl,
                    {
                        signal: controller.signal,
                        cache: 'no-store',
                        headers: {
                            Accept: 'application/json',
                        },
                    },
                );

                const data = await response.json();

                if (active) {
                    setLiveQuote(data.quote ?? null);
                }
            } catch (error) {
                if (active && !controller.signal.aborted) {
                    console.error('Failed to load live quote:', error);
                    setLiveQuote(null);
                }
            } finally {
                if (active && !controller.signal.aborted) {
                    setLoadingLiveQuote(false);
                }
            }
        };

        void loadLiveQuote();
        const intervalId = window.setInterval(() => {
            void loadLiveQuote();
        }, 10000);

        return () => {
            active = false;
            controller.abort();
            window.clearInterval(intervalId);
        };
    }, [asset.asset_type, asset.id]);

    // Safe formatting functions
    const formatPrice = (value: string | number | null | undefined): string => {
        if (value === null || value === undefined) {
            return '$0.00';
        }
        
        const numericValue = typeof value === 'number' ? value : parseFloat(String(value));
        
        if (isNaN(numericValue)) {
            return '$0.00';
        }
        
        return `$${numericValue.toFixed(2)}`;
    };

    const formatNumber = (
        value: string | number | null | undefined,
        decimals: number = 2,
    ): string => {
        if (value === null || value === undefined) {
            return '0.00';
        }
        
        const numericValue = typeof value === 'number' ? value : parseFloat(String(value));
        
        if (isNaN(numericValue)) {
            return '0.00';
        }
        
        return numericValue.toFixed(decimals);
    };

    const formatSize = (value: string | number | null | undefined): string => {
        if (value === null || value === undefined || value === '') {
            return '—';
        }

        const numericValue = typeof value === 'number' ? value : parseInt(String(value), 10);

        if (Number.isNaN(numericValue)) {
            return '—';
        }

        return numericValue.toLocaleString();
    };

    const formatETTimeWithSeconds = (value: string | null | undefined): string => {
        if (!value) {
            return '—';
        }

        const timestamp = value.endsWith('Z') ? value : `${value}Z`;
        const date = new Date(timestamp);

        return date.toLocaleTimeString('en-US', {
            timeZone: 'America/New_York',
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit',
            hour12: true,
        });
    };

    const handleWatchToggle = async () => {
        if (processingWatch) return;

        setProcessingWatch(true);

        try {
            if (watchedState) {
                // Remove from watches - need to find the watch ID first
                // We'll implement this by redirecting to watches settings page for now
                router.visit('/watches/settings', { preserveState: true });
            } else {
                // Add to watches
                router.post(
                    '/watches',
                    { asset_info_id: asset.id },
                    {
                        preserveScroll: true,
                        onSuccess: () => {
                            setWatchedState(true);
                        },
                        onError: (errors) => {
                            console.error('Failed to add to watches:', errors);
                        },
                        onFinish: () => {
                            setProcessingWatch(false);
                        },
                    },
                );
                return;
            }
        } catch (error) {
            console.error('Error toggling watch:', error);
        }

        setProcessingWatch(false);
    };

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Market Data',
            href: '#',
        },
        {
            title: 'Assets',
            href: index().url,
        },
        {
            title: asset.symbol,
            href: '#',
        },
    ];

    // Handle clicks outside of search dropdown
    useEffect(() => {
        function handleClickOutside(event: MouseEvent) {
            if (searchRef.current && !searchRef.current.contains(event.target as Node)) {
                setShowSuggestions(false);
            }
        }

        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    // Fetch suggestions with debouncing
    useEffect(() => {
        if (!searchTerm.trim()) {
            setSuggestions([]);
            setShowSuggestions(false);
            setSelectedIndex(-1);
            return;
        }

        const timer = setTimeout(async () => {
            setIsLoadingSearch(true);
            try {
                const response = await fetch(
                    `/market-data/assets/search?search=${encodeURIComponent(searchTerm)}`,
                );
                const data = await response.json();
                setSuggestions(data);
                setShowSuggestions(true);
                setSelectedIndex(-1);
            } catch (error) {
                console.error('Failed to fetch suggestions:', error);
            } finally {
                setIsLoadingSearch(false);
            }
        }, 300);

        return () => clearTimeout(timer);
    }, [searchTerm]);

    // Auto-scroll to keep selected item visible
    useEffect(() => {
        if (selectedIndex >= 0 && dropdownRef.current) {
            const selectedElement = dropdownRef.current.children[selectedIndex] as HTMLElement;
            if (selectedElement) {
                selectedElement.scrollIntoView({
                    block: 'nearest',
                    behavior: 'smooth'
                });
            }
        }
    }, [selectedIndex]);

    const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (!showSuggestions || suggestions.length === 0) return;

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                setSelectedIndex(prev => 
                    prev < suggestions.length - 1 ? prev + 1 : prev
                );
                break;
            case 'ArrowUp':
                e.preventDefault();
                setSelectedIndex(prev => prev > 0 ? prev - 1 : -1);
                break;
            case 'Enter':
                e.preventDefault();
                if (selectedIndex >= 0 && selectedIndex < suggestions.length) {
                    handleSymbolSelect(suggestions[selectedIndex].id);
                }
                break;
            case 'Escape':
                e.preventDefault();
                setShowSuggestions(false);
                setSelectedIndex(-1);
                break;
        }
    };

    const handleSymbolSelect = (symbolId: number) => {
        router.visit(`/market-data/assets/${symbolId}`);
    };

    const handleClearSearch = () => {
        setSearchTerm('');
        setSuggestions([]);
        setShowSuggestions(false);
        setSelectedIndex(-1);
    };

    // Handle custom date selection
    const handleDateChange = async (date: string) => {
        if (!date) {
            setSelectedDate('');
            setCustomDateChartData(null);
            setCustomDatePriceStats(null);
            // Update URL to remove date parameter
            router.visit(`/market-data/assets/${asset.id}`, {
                preserveState: true,
                preserveScroll: true,
            });
            return;
        }

        setSelectedDate(date);
        setSelectedRange('1D'); // Switch to 1D view for custom dates
        setLoadingCustomDate(true);

        try {
            const response = await fetch(
                `/market-data/assets/${asset.id}/custom-date-chart?date=${date}`,
            );
            const data = await response.json();
            setCustomDateChartData(data.chartData);
            setCustomDatePriceStats(data.priceStats);
            
            // Update URL with date parameter
            router.visit(`/market-data/assets/${asset.id}?date=${date}`, {
                preserveState: true,
                preserveScroll: true,
            });
        } catch (error) {
            console.error('Failed to load custom date chart data:', error);
        } finally {
            setLoadingCustomDate(false);
        }
    };

    // Handle time range changes with lazy loading for MAX
    const handleTimeRangeChange = async (range: TimeRange) => {
        if (range === 'MAX' && !maxChartData) {
            setLoadingMax(true);
            try {
                const response = await fetch(
                    `/market-data/assets/${asset.id}/max-chart-data`,
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
        // Clear custom date when switching ranges
        if (selectedDate) {
            setSelectedDate('');
            setCustomDateChartData(null);
            setCustomDatePriceStats(null);
            router.visit(`/market-data/assets/${asset.id}`, {
                preserveState: true,
                preserveScroll: true,
            });
        }
    };

    const currentData =
        customDateChartData && selectedDate
            ? customDateChartData
            : selectedRange === 'MAX' && maxChartData
              ? maxChartData
              : chartData[selectedRange as keyof typeof chartData] || [];
    const currentStats =
        customDatePriceStats && selectedDate
            ? customDatePriceStats
            : selectedRange === 'MAX'
            ? priceStats.MAX
            : priceStats[selectedRange as keyof typeof priceStats];

    // Format chart data for recharts
    // Filter out null prices for price calculations, but keep them in the chart data
    const formattedChartData = currentData.map((point: PricePoint) => ({
        time: point.time,
        price: point.price ? parseFloat(point.price) : null,
    }));

    // Determine if price is up or down
    const isPositive = currentStats?.changePercent
        ? currentStats.changePercent >= 0
        : true;

    // Calculate Y-axis domain with padding (only from non-null prices)
    const prices = formattedChartData
        .filter((d) => d.price !== null)
        .map((d) => d.price as number);
    const minPrice = prices.length > 0 ? Math.min(...prices) : 0;
    const maxPrice = prices.length > 0 ? Math.max(...prices) : 0;
    const padding = (maxPrice - minPrice) * 0.1;

    const formatXAxis = (time: string) => {
        // Parse UTC time from database - ensure it's treated as UTC by appending 'Z' if missing
        const timeStr = time.endsWith('Z') ? time : time + 'Z';
        const dateUTC = new Date(timeStr);

        // Show time for 5-minute and hourly data
        // 1D, Last Open Day, 5D always have 5-minute data (show time)
        // 1M, 3M, 6M show time only if hourly data is available
        const shouldShowTime =
            ['1D', 'Last Open Day', '5D'].includes(selectedRange) ||
            (hasEnoughHourlyData && ['1M', '3M', '6M'].includes(selectedRange));

        if (shouldShowTime) {
            return dateUTC.toLocaleTimeString('en-US', {
                timeZone: 'America/New_York',
                hour: 'numeric',
                minute: '2-digit',
            });
        }
        return dateUTC.toLocaleDateString('en-US', {
            timeZone: 'America/New_York',
            month: 'short',
            day: 'numeric',
        });
    };

    const CustomTooltip = ({
        active,
        payload,
    }: {
        active?: boolean;
        payload?: Array<{ payload: { price?: string | null; time: string } }>;
    }) => {
        if (active && payload && payload.length) {
            const data = payload[0].payload;

            // Don't show tooltip for null prices (missing data)
            if (data.price === null) {
                return null;
            }

            // Parse UTC time from database - ensure it's treated as UTC by appending 'Z' if missing
            const timeStr = data.time.endsWith('Z') ? data.time : data.time + 'Z';
            const dateUTC = new Date(timeStr);

            // Show time for 5-minute and hourly data
            // 1D, Last Open Day, 5D always have 5-minute data (show time)
            // 1M, 3M, 6M show time only if hourly data is available
            const showTime =
                ['1D', 'Last Open Day', '5D'].includes(selectedRange) ||
                (hasEnoughHourlyData &&
                    ['1M', '3M', '6M'].includes(selectedRange));

            return (
                <div className="rounded-lg border bg-card p-3 shadow-lg">
                    <div className="text-sm text-muted-foreground">
                        {dateUTC.toLocaleDateString('en-US', {
                            timeZone: 'America/New_York',
                            weekday: 'short',
                            year: 'numeric',
                            month: 'short',
                            day: 'numeric',
                            ...(showTime
                                ? { hour: 'numeric', minute: '2-digit' }
                                : {}),
                        })}
                        {showTime && <span className="ml-1">ET</span>}
                    </div>
                    <div className="text-lg font-bold">
                        {formatPrice(data.price)}
                    </div>
                </div>
            );
        }
        return null;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${asset.symbol} - ${asset.common_name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-6">
                {/* Header Section */}
                <div className="flex items-start justify-between">
                    <div className="flex-1">
                        <div className="mb-2 flex items-center gap-3">
                            <h1 className="text-4xl font-bold tracking-tight">
                                {asset.symbol}
                            </h1>
                            <Badge
                                variant={
                                    asset.asset_type === 'stock'
                                        ? 'default'
                                        : 'outline'
                                }
                            >
                                {asset.asset_type.toUpperCase()}
                            </Badge>
                        </div>
                        <h2 className="text-xl text-muted-foreground">
                            {asset.common_name}
                        </h2>
                        {asset.sector && (
                            <div className="mt-2">
                                <Badge variant="secondary">
                                    {asset.sector}
                                </Badge>
                            </div>
                        )}
                    </div>

                    {/* Symbol Search */}
                    <div className="mx-6 w-80" ref={searchRef}>
                        <div className="relative">
                            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                type="text"
                                placeholder="Jump to another symbol..."
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                onKeyDown={handleKeyDown}
                                onFocus={() => searchTerm.length > 0 && setShowSuggestions(true)}
                                className="pl-9 pr-9"
                                autoComplete="off"
                            />
                            {searchTerm && (
                                <button
                                    onClick={handleClearSearch}
                                    className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                >
                                    <X className="h-4 w-4" />
                                </button>
                            )}
                        </div>

                        {/* Suggestions Dropdown */}
                        {showSuggestions && (
                            <div className="absolute z-50 mt-1 w-full rounded-md border bg-popover shadow-lg max-h-80 overflow-hidden">
                                {isLoadingSearch ? (
                                    <div className="p-4 text-center text-sm text-muted-foreground">
                                        Loading...
                                    </div>
                                ) : suggestions.length > 0 ? (
                                    <>
                                        <div className="px-3 py-2 text-xs text-muted-foreground border-b bg-muted/50">
                                            {suggestions.length} result{suggestions.length !== 1 ? 's' : ''} found
                                        </div>
                                        <div 
                                            ref={dropdownRef}
                                            className="max-h-72 overflow-y-auto overscroll-contain"
                                        >
                                            {suggestions.map((suggestion, index) => (
                                                <button
                                                    key={suggestion.id}
                                                    onClick={() => handleSymbolSelect(suggestion.id)}
                                                    className={`flex w-full items-center gap-3 border-b px-4 py-2 text-left hover:bg-accent last:border-b-0 focus:bg-accent focus:outline-none ${
                                                        index === selectedIndex ? 'bg-accent' : ''
                                                    }`}
                                                >
                                                    <span className="font-semibold">{suggestion.symbol}</span>
                                                    <span className="flex-1 text-sm text-muted-foreground truncate">
                                                        {suggestion.common_name}
                                                    </span>
                                                    <Badge
                                                        variant={suggestion.asset_type === 'stock' ? 'default' : 'outline'}
                                                        className="text-xs shrink-0"
                                                    >
                                                        {suggestion.asset_type.toUpperCase()}
                                                    </Badge>
                                                </button>
                                            ))}
                                        </div>
                                    </>
                                ) : (
                                    <div className="p-4 text-center text-sm text-muted-foreground">
                                        No symbols found
                                    </div>
                                )}
                            </div>
                        )}
                    </div>

                    {/* Watch Button - Only visible to non-guest users */}
                    {!isGuest && (
                        <div className="flex items-center gap-2">
                            <Button
                                onClick={handleWatchToggle}
                                disabled={processingWatch}
                                variant={watchedState ? 'outline' : 'default'}
                                className="flex items-center gap-2"
                            >
                                {processingWatch ? (
                                    <>
                                        <div className="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                        {watchedState ? 'Removing...' : 'Adding...'}
                                    </>
                                ) : watchedState ? (
                                    <>
                                        <Check className="h-4 w-4" />
                                        In Watches
                                    </>
                                ) : (
                                    <>
                                        <Plus className="h-4 w-4" />
                                        Add to Watches
                                    </>
                                )}
                            </Button>
                        </div>
                    )}
                </div>

                {/* Price and Chart Section */}
                {latestPrice && (
                    <div className="rounded-xl border bg-card">
                        <div className="p-6">
                            {/* Current Price */}
                            <div className="mb-6">
                                <div className="mb-2 flex items-end gap-4">
                                    <div className="text-5xl font-bold">
                                        {formatPrice(latestPrice.price)}
                                    </div>
                                    {currentStats && (
                                        <div
                                            className={`mb-1 flex items-center gap-1 text-lg font-semibold ${isPositive ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'}`}
                                        >
                                            {isPositive ? (
                                                <TrendingUp className="size-5" />
                                            ) : (
                                                <TrendingDown className="size-5" />
                                            )}
                                            {isPositive ? '+' : ''}
                                            {formatNumber(currentStats.change, 2)} (
                                            {formatNumber(currentStats.changePercent, 2)}
                                            %)
                                        </div>
                                    )}
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                                    <div className="rounded-lg border bg-muted/30 p-4">
                                        <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                            Bid
                                        </div>
                                        <div className="mt-1 text-lg font-semibold">
                                            {loadingLiveQuote && !liveQuote
                                                ? 'Loading...'
                                                : formatPrice(liveQuote?.bid_price)}
                                        </div>
                                    </div>
                                    <div className="rounded-lg border bg-muted/30 p-4">
                                        <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                            Ask
                                        </div>
                                        <div className="mt-1 text-lg font-semibold">
                                            {loadingLiveQuote && !liveQuote
                                                ? 'Loading...'
                                                : formatPrice(liveQuote?.ask_price)}
                                        </div>
                                    </div>
                                    <div className="rounded-lg border bg-muted/30 p-4">
                                        <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                            Bid Size
                                        </div>
                                        <div className="mt-1 text-lg font-semibold">
                                            {loadingLiveQuote && !liveQuote
                                                ? 'Loading...'
                                                : formatSize(liveQuote?.bid_size)}
                                        </div>
                                    </div>
                                    <div className="rounded-lg border bg-muted/30 p-4">
                                        <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                            Ask Size
                                        </div>
                                        <div className="mt-1 text-lg font-semibold">
                                            {loadingLiveQuote && !liveQuote
                                                ? 'Loading...'
                                                : formatSize(liveQuote?.ask_size)}
                                        </div>
                                    </div>
                                    <div className="rounded-lg border bg-muted/30 p-4">
                                        <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                            Spread
                                        </div>
                                        <div className="mt-1 text-lg font-semibold">
                                            {loadingLiveQuote && !liveQuote
                                                ? 'Loading...'
                                                : liveQuote && liveQuote.bid_price && liveQuote.ask_price
                                                  ? (() => {
                                                        const bid = parseFloat(liveQuote.bid_price ?? '0');
                                                        const ask = parseFloat(liveQuote.ask_price ?? '0');
                                                        const spread = ask - bid;
                                                        const midpoint = (ask + bid) / 2;
                                                        const spreadPct = midpoint > 0 ? (spread / midpoint) * 100 : 0;

                                                        return `${formatPrice(spread)} (${formatNumber(spreadPct, 4)}%)`;
                                                    })()
                                                  : '—'}
                                        </div>
                                    </div>
                                </div>
                                <div className="mt-1 text-xs text-muted-foreground">
                                    Server quote time{' '}
                                    {formatETTimeWithSeconds(
                                        liveQuote?.quote_ts_utc ??
                                            liveQuote?.received_at_utc,
                                    )}{' '}
                                    ET
                                </div>
                                <div className="text-sm text-muted-foreground">
                                    {selectedRange} •{' '}
                                    {(() => {
                                        // For multi-day ranges, show date range of the chart
                                        if (
                                            [
                                                '5D',
                                                '1M',
                                                '3M',
                                                '6M',
                                                '1Y',
                                                'MAX',
                                            ].includes(selectedRange)
                                        ) {
                                            const validPrices =
                                                formattedChartData.filter(
                                                    (d) => d.price !== null,
                                                );
                                            if (validPrices.length > 0) {
                                                // Parse dates properly handling both ISO and date strings
                                                const parseChartDate = (
                                                    dateStr: string,
                                                ) => {
                                                    // If it's an ISO datetime, use it directly
                                                    if (dateStr.includes('T')) {
                                                        return new Date(
                                                            dateStr,
                                                        );
                                                    }
                                                    // If it's a date string (YYYY-MM-DD), parse as local date
                                                    const parts =
                                                        dateStr.split('-');
                                                    return new Date(
                                                        parts[0] +
                                                            '/' +
                                                            parts[1] +
                                                            '/' +
                                                            parts[2],
                                                    );
                                                };

                                                const firstDate =
                                                    parseChartDate(
                                                        validPrices[0].time,
                                                    );
                                                const lastDate = parseChartDate(
                                                    validPrices[
                                                        validPrices.length - 1
                                                    ].time,
                                                );

                                                const formatDate = (
                                                    date: Date,
                                                ) =>
                                                    date.toLocaleDateString(
                                                        'en-US',
                                                        {
                                                            weekday: 'long',
                                                            year: 'numeric',
                                                            month: 'long',
                                                            day: 'numeric',
                                                        },
                                                    );

                                                return `${formatDate(firstDate)} to ${formatDate(lastDate)}`;
                                            }
                                        }

                                        // For 1D and Last Open Day, show single date
                                        if (selectedRange === '1D') {
                                            // Use server formatted date if available
                                            if (
                                                todayMarketStatus?.formatted_date
                                            ) {
                                                // Parse the clean date string (YYYY-MM-DD)
                                                const [year, month, day] =
                                                    todayMarketStatus.formatted_date.split(
                                                        '-',
                                                    );
                                                const serverDate = new Date(
                                                    parseInt(year),
                                                    parseInt(month) - 1,
                                                    parseInt(day),
                                                );
                                                return serverDate.toLocaleDateString(
                                                    'en-US',
                                                    {
                                                        weekday: 'long',
                                                        year: 'numeric',
                                                        month: 'long',
                                                        day: 'numeric',
                                                    },
                                                );
                                            }

                                            // Fallback to current date
                                            return new Date().toLocaleDateString(
                                                'en-US',
                                                {
                                                    weekday: 'long',
                                                    year: 'numeric',
                                                    month: 'long',
                                                    day: 'numeric',
                                                },
                                            );
                                        }

                                        return new Date(
                                            selectedRange === 'Last Open Day' &&
                                            lastOpenDay
                                                ? lastOpenDay
                                                      .split('-')
                                                      .join('/')
                                                : 'date' in latestPrice
                                                  ? latestPrice.date
                                                  : latestPrice.ts,
                                        ).toLocaleDateString('en-US', {
                                            weekday: 'long',
                                            year: 'numeric',
                                            month: 'long',
                                            day: 'numeric',
                                        });
                                    })()}
                                    {selectedRange === '1D' &&
                                        todayMarketStatus &&
                                        (todayMarketStatus.status ===
                                            'holiday' ||
                                            todayMarketStatus.status ===
                                                'closed') && (
                                            <span className="ml-2 inline-block rounded bg-yellow-100 px-2 py-1 text-xs font-medium text-yellow-800 dark:bg-yellow-900 dark:text-yellow-100">
                                                Market{' '}
                                                {todayMarketStatus.status ===
                                                'holiday'
                                                    ? 'Holiday'
                                                    : 'Closed'}{' '}
                                                -{' '}
                                                {todayMarketStatus.reason ||
                                                    'No Trading'}
                                            </span>
                                        )}
                                </div>
                            </div>

                            <div className="mb-4 flex items-center justify-between gap-3">
                                <div className="text-sm text-muted-foreground">
                                    {showCandles ? 'Candlestick view' : 'Line view'}
                                </div>
                                <Button
                                    type="button"
                                    variant={showCandles ? 'default' : 'outline'}
                                    size="sm"
                                    onClick={() => setShowCandles((current) => !current)}
                                >
                                    Show Candles
                                </Button>
                            </div>

                            {/* News Link */}
                            {newsLink && asset.symbol && (
                                <div className="mb-4">
                                    <a
                                        href={newsLink.replace('<SYMBOL>', asset.symbol)}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-medium text-blue-700 transition-colors hover:bg-blue-100 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-400 dark:hover:bg-blue-950/60"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z" />
                                        </svg>
                                        News for {asset.symbol}
                                    </a>
                                </div>
                            )}

                            {/* Chart */}
                            {showCandles ? (
                                <div className="mb-4 h-96">
                                    {loadingCandles ? (
                                        <div className="flex h-full items-center justify-center rounded-lg border bg-muted/20 text-sm text-muted-foreground">
                                            Loading candlesticks...
                                        </div>
                                    ) : (
                                        <CandlestickChart
                                            data={candlestickData || []}
                                            height={384}
                                            isPositive={isPositive}
                                            timeRange={selectedRange}
                                            hasEnoughHourlyData={hasEnoughHourlyData}
                                        />
                                    )}
                                </div>
                            ) : formattedChartData.length > 0 ? (
                                <div className="mb-4 h-96">
                                    <ResponsiveContainer
                                        width="100%"
                                        height="100%"
                                    >
                                        <AreaChart data={formattedChartData}>
                                            <defs>
                                                <linearGradient
                                                    id="colorPrice"
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
                                                    formatPrice(value)
                                                }
                                            />
                                            <Tooltip
                                                content={<CustomTooltip />}
                                            />
                                            <Area
                                                type="linear"
                                                dataKey="price"
                                                stroke={
                                                    isPositive
                                                        ? '#10b981'
                                                        : '#ef4444'
                                                }
                                                strokeWidth={2}
                                                fill="url(#colorPrice)"
                                                connectNulls={false}
                                            />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                </div>
                            ) : null}

                            {/* Time Range Selector */}
                            <div className="flex items-center gap-2">
                                <div className="flex gap-2">
                                    {([
                                        '1D',
                                        'Last Open Day',
                                        '5D',
                                        '1M',
                                        '3M',
                                        '6M',
                                        '1Y',
                                        'MAX',
                                    ] as TimeRange[]).map((range) => (
                                        <Button
                                            key={range}
                                            variant={
                                                selectedRange === range && !selectedDate
                                                    ? 'default'
                                                    : 'ghost'
                                            }
                                            size="sm"
                                            onClick={() =>
                                                handleTimeRangeChange(range)
                                            }
                                            disabled={
                                                range === 'MAX'
                                                    ? loadingMax
                                                    : !chartData[range] ||
                                                      chartData[range].length === 0
                                            }
                                        >
                                            {range === 'MAX' && loadingMax
                                                ? 'Loading...'
                                                : range}
                                        </Button>
                                    ))}
                                </div>
                                
                                {/* Custom Date Picker */}
                                <div className="flex items-center gap-2 ml-4 border-l pl-4">
                                    <input
                                        type="date"
                                        value={selectedDate}
                                        onChange={(e) => handleDateChange(e.target.value)}
                                        disabled={loadingCustomDate}
                                        className="px-3 py-1.5 text-sm border rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring disabled:opacity-50"
                                        max={new Date().toISOString().split('T')[0]}
                                    />
                                    {selectedDate && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => handleDateChange('')}
                                            disabled={loadingCustomDate}
                                        >
                                            Clear
                                        </Button>
                                    )}
                                    {loadingCustomDate && (
                                        <span className="text-xs text-muted-foreground">Loading...</span>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* Statistics Grid */}
                {stats && (
                    <div className="grid gap-6 md:grid-cols-2">
                        {/* Key Statistics */}
                        <div className="rounded-lg border bg-card p-6">
                            <h3 className="mb-4 text-lg font-semibold">
                                Key Statistics
                            </h3>
                            <div className="space-y-3">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Previous Close
                                    </span>
                                    <span className="font-medium">
                                        {stats.previousClose
                                            ? formatPrice(stats.previousClose)
                                            : '—'}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Day's Range
                                    </span>
                                    <span className="font-medium">
                                        {stats.dayLow && stats.dayHigh
                                            ? `${formatPrice(stats.dayLow)} - ${formatPrice(stats.dayHigh)}`
                                            : '—'}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        52 Week Range
                                    </span>
                                    <span className="font-medium">
                                        {stats['52WeekLow'] &&
                                        stats['52WeekHigh']
                                            ? `${formatPrice(stats['52WeekLow'])} - ${formatPrice(stats['52WeekHigh'])}`
                                            : '—'}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Volume
                                    </span>
                                    <span className="font-medium">
                                        {stats.volume
                                            ? stats.volume.toLocaleString()
                                            : '—'}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Avg Volume (30d)
                                    </span>
                                    <span className="font-medium">
                                        {stats.avgVolume
                                            ? Math.round(
                                                  stats.avgVolume,
                                              ).toLocaleString()
                                            : '—'}
                                    </span>
                                </div>
                            </div>
                        </div>

                        {/* Performance */}
                        <div className="rounded-lg border bg-card p-6">
                            <h3 className="mb-4 text-lg font-semibold">
                                Performance
                            </h3>
                            <div className="space-y-3">
                                {/* Range for selected timeframe */}
                                {(() => {
                                    const validPrices = formattedChartData
                                        .filter((d) => d.price !== null)
                                        .map((d) => d.price as number);

                                    if (validPrices.length > 0) {
                                        const rangeHigh = Math.max(
                                            ...validPrices,
                                        );
                                        const rangeLow = Math.min(
                                            ...validPrices,
                                        );

                                        return (
                                            <div className="flex items-center justify-between">
                                                <span className="text-muted-foreground">
                                                    {selectedRange} Range
                                                </span>
                                                <span className="font-medium">
                                                    {formatPrice(rangeLow)} - {formatPrice(rangeHigh)}
                                                </span>
                                            </div>
                                        );
                                    }
                                })()}

                                {Object.entries(priceStats).map(
                                    ([period, stat]) => (
                                        <div
                                            key={period}
                                            className="flex items-center justify-between"
                                        >
                                            <span className="text-muted-foreground">
                                                {period}
                                            </span>
                                            <div className="flex items-center gap-2">
                                                <div
                                                    className={`flex items-center gap-1 font-medium ${stat.changePercent >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'}`}
                                                >
                                                    {stat.changePercent >= 0 ? (
                                                        <TrendingUp className="size-4" />
                                                    ) : (
                                                        <TrendingDown className="size-4" />
                                                    )}
                                                    {stat.changePercent >= 0
                                                        ? '+'
                                                        : ''}
                                                    {formatNumber(stat.changePercent, 2)}
                                                    %
                                                </div>
                                                <span className="text-sm text-muted-foreground">
                                                    (
                                                    {stat.change >= 0
                                                        ? '+'
                                                        : ''}
                                                    ${formatNumber(stat.change, 2)})
                                                </span>
                                            </div>
                                        </div>
                                    ),
                                )}
                            </div>
                        </div>
                    </div>
                )}

                {/* About Section */}
                <div className="rounded-lg border bg-card p-6">
                    <h3 className="mb-3 text-lg font-semibold">
                        About {asset.common_name}
                    </h3>
                    <p className="whitespace-pre-line text-muted-foreground">
                        {asset.description}
                    </p>
                    {asset.description &&
                        !asset.description.startsWith('S&P 500 component') && (
                            <p className="mt-3 text-xs text-muted-foreground/70">
                                Source:{' '}
                                <a
                                    href={`https://en.wikipedia.org/wiki/${encodeURIComponent(asset.common_name)}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="underline hover:text-foreground"
                                >
                                    Wikipedia
                                </a>{' '}
                                (Licensed under{' '}
                                <a
                                    href="https://creativecommons.org/licenses/by-sa/3.0/"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="underline hover:text-foreground"
                                >
                                    CC BY-SA 3.0
                                </a>
                                )
                            </p>
                        )}
                </div>
            </div>
        </AppLayout>
    );
}
