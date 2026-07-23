import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useState, useEffect } from 'react';

/** Today's date in America/New_York timezone as YYYY-MM-DD. */
function estToday(): string {
    const fmt = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/New_York',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    });
    return fmt.format(new Date());
}

interface AlpacaOrder {
    id: number;
    alpaca_order_id: string | null;
    client_order_id: string | null;
    symbol: string;
    side: 'buy' | 'sell';
    qty: string;
    filled_qty: string | null;
    filled_avg_price: string | null;
    order_type: string;
    status: string;
    stop_price: string | null;
    limit_price: string | null;
    time_in_force: string;
    submitted_at: string | null;
    filled_at: string | null;
    parent_alpaca_order_id: string | null;
    notes: string | null;
    created_at: string;
    asset_id: number | null;
}

interface PaginatedOrders {
    data: AlpacaOrder[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface CurrentPrice {
    price: string;
    timestamp: string;
}

interface Position {
    qty: number;
    qty_available: number;
}

interface AlpacaOrdersPageProps {
    orders: PaginatedOrders;
    currentPrices: Record<string, CurrentPrice>;
    positions: Record<string, Position>;
    assetIds: Record<string, number>;
    realizedSellPrices: Record<string, { price: string; qty: string }>;
    filters: { start_date?: string; end_date?: string; pipeline?: string; ml_threshold?: number | null };
    pipelineVersions: Record<string, string>;
    pipelineMlThresholds?: Record<string, number>;
}

export default function AlpacaOrdersIndex({
    orders,
    currentPrices,
    positions,
    assetIds,
    realizedSellPrices,
    filters,
    pipelineVersions,
    pipelineMlThresholds = {},
}: AlpacaOrdersPageProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Alpaca', href: '/alpaca-orders' },
        { title: 'View Orders', href: '/alpaca-orders' },
    ];

    const [startDate, setStartDate] = useState(filters.start_date || '');
    const [endDate, setEndDate] = useState(filters.end_date || '');
    const [pipeline, setPipeline] = useState(filters.pipeline || '');
    const [mlThreshold, setMlThreshold] = useState<string>(
        filters.ml_threshold != null ? String(filters.ml_threshold) : ''
    );
    const [sellingOrderId, setSellingOrderId] = useState<number | null>(null);
    const [cancellingOrderId, setCancellingOrderId] = useState<number | null>(null);
    const [hideCancelled, setHideCancelled] = useState(true);
    const [hideVwapBlocked, setHideVwapBlocked] = useState(filters.hide_vwap_blocked ?? false);
    const [isFetching, setIsFetching] = useState(false);

    useEffect(() => {
        const removeStart = router.on('start', (event) => {
            if (event.detail?.visit?.showProgress === false) {
                setIsFetching(true);
            }
        });
        const removeFinish = router.on('finish', () => setIsFetching(false));
        return () => {
            removeStart();
            removeFinish();
        };
    }, []);

    // Auto-refresh data every 60 seconds
    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({ 
                only: ['orders', 'currentPrices', 'positions', 'assetIds', 'realizedSellPrices'],
                preserveScroll: true,
                preserveState: true,
                showProgress: false,
            });
        }, 60000); // 60 seconds

        return () => clearInterval(interval);
    }, []);

    const navigateDate = (direction: 'back' | 'forward') => {
        const current = startDate || endDate || estToday();
        const [year, month, day] = current.split('-').map(Number);
        let ms = Date.UTC(year, month - 1, day);
        ms += (direction === 'forward' ? 1 : -1) * 86400000;
        const dow = new Date(ms).getUTCDay();
        if (direction === 'forward') {
            if (dow === 6) ms += 2 * 86400000;
            else if (dow === 0) ms += 1 * 86400000;
        } else {
            if (dow === 0) ms -= 2 * 86400000;
            else if (dow === 6) ms -= 1 * 86400000;
        }
        const d = new Date(ms);
        const newDate = [
            d.getUTCFullYear(),
            String(d.getUTCMonth() + 1).padStart(2, '0'),
            String(d.getUTCDate()).padStart(2, '0'),
        ].join('-');
        router.get('/alpaca-orders', { start_date: newDate, end_date: newDate }, {
            preserveState: false,
            preserveScroll: false,
            showProgress: false,
        });
    };

    const handleCancelBuy = (orderId: number) => {
        setCancellingOrderId(orderId);
        router.post(`/alpaca-orders/${orderId}/cancel-buy`, {}, {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => setCancellingOrderId(null),
        });
    };

    const handleSell = (orderId: number) => {
        setSellingOrderId(orderId);
        router.post(`/alpaca-orders/${orderId}/sell`, {}, {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => setSellingOrderId(null),
        });
    };

    const handleFilter = (e: FormEvent) => {
        e.preventDefault();
        router.get('/alpaca-orders', {
            start_date: startDate || undefined,
            end_date: endDate || undefined,
            pipeline: pipeline || undefined,
            ml_threshold: mlThreshold || undefined,
            hide_vwap_blocked: hideVwapBlocked || undefined,
        }, {
            preserveState: true,
            preserveScroll: true,
            showProgress: false,
        });
    };

    const handleClearFilters = () => {
        setStartDate('');
        setEndDate('');
        setPipeline('');
        setMlThreshold('');
        router.get('/alpaca-orders', {}, {
            preserveState: true,
            preserveScroll: true,
            showProgress: false,
        });
    };
    const getStatusColor = (status: string) => {
        switch (status) {
            case 'filled':
                return 'text-green-600 dark:text-green-400';
            case 'partially_filled':
                return 'text-yellow-600 dark:text-yellow-400';
            case 'canceled':
            case 'cancelled':
            case 'expired':
            case 'rejected':
                return 'text-red-600 dark:text-red-400';
            default:
                return 'text-blue-600 dark:text-blue-400';
        }
    };

    const getSideColor = (side: string) => {
        return side === 'buy'
            ? 'text-green-600 dark:text-green-400 font-medium'
            : 'text-red-600 dark:text-red-400 font-medium';
    };

    const calculatePL = (order: AlpacaOrder, currentPrice?: CurrentPrice) => {
        // Only calculate P/L for filled buy orders
        if (order.side !== 'buy' || !order.filled_avg_price || !order.filled_qty) {
            return null;
        }

        const avgPrice = parseFloat(order.filled_avg_price);
        const qty = parseFloat(order.filled_qty);

        // Use realized sell price if the stop fired, otherwise use current market price
        const realized = order.alpaca_order_id ? realizedSellPrices[order.alpaca_order_id] : null;
        if (realized) {
            const sellPrice = parseFloat(realized.price);
            const plPct = ((sellPrice - avgPrice) / avgPrice) * 100;
            const plDollar = (sellPrice - avgPrice) * qty;
            return { plPct, plDollar, isRealized: true };
        }

        if (!currentPrice) return null;
        const current = parseFloat(currentPrice.price);
        const plPct = ((current - avgPrice) / avgPrice) * 100;
        const plDollar = (current - avgPrice) * qty;
        return { plPct, plDollar, isRealized: false };
    };

    const getPLColor = (pl: { plPct: number; plDollar: number } | null) => {
        if (pl === null) return '';
        return pl.plPct >= 0
            ? 'text-green-600 dark:text-green-400 font-medium'
            : 'text-red-600 dark:text-red-400 font-medium';
    };

    const formatTimeEST = (dateString: string | null) => {
        if (!dateString) return '-';
        
        const date = new Date(dateString);
        const formatted = new Intl.DateTimeFormat('en-US', {
            timeZone: 'America/New_York',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false,
        }).format(date);
        
        return `${formatted} EST`;
    };

    const hasShares = (order: AlpacaOrder) => {
        // If this specific order is already realized (closed/sold), don't show Sell
        if (order.alpaca_order_id && realizedSellPrices[order.alpaca_order_id]) {
            return false;
        }
        // Check if we own any shares of this symbol (regardless of availability)
        const position = positions[order.symbol];
        return position && position.qty > 0;
    };

    const getSharesInfo = (order: AlpacaOrder) => {
        // If this specific order is already realized (closed/sold), don't show shares
        if (order.alpaca_order_id && realizedSellPrices[order.alpaca_order_id]) {
            return null;
        }
        const position = positions[order.symbol];
        if (!position) return null;
        
        // Show total owned and available (if different)
        if (position.qty === position.qty_available) {
            return `${position.qty} shares`;
        }
        return `${position.qty} shares (${position.qty_available} available)`;
    };

    const getSellButtonText = (order: AlpacaOrder, isSelling: boolean) => {
        if (isSelling) return 'Selling...';
        
        const position = positions[order.symbol];
        if (position && position.qty_available === 0 && position.qty > 0) {
            return 'Sell (stop active)';
        }
        return 'Sell';
    };

    const visibleOrders = orders.data.filter((order: AlpacaOrder) => {
        if (!hideCancelled) return true;
        if (['canceled', 'cancelled', 'expired', 'rejected'].includes(order.status)) {
            // Still show cancelled orders that had a partial fill — real shares were transacted
            return parseFloat(order.filled_qty ?? '0') > 0;
        }
        return true;
    });

    // Compute per-pipeline P&L from orders that have a tradeAlert with pipeline_run
    const pipelineSummary = (() => {
        const map: Record<string, { pl: number; trades: number; wins: number; losses: number; winPl: number; lossPl: number }> = {};
        for (const order of visibleOrders) {
            if (order.side !== 'buy' || !order.filled_avg_price || !order.filled_qty) continue;
            // @ts-expect-error tradeAlert may not be typed on the order
            const pipelineRun: string | undefined = (order as any).trade_alert?.pipeline_run;
            if (!pipelineRun) continue;
            const currentPrice = currentPrices[order.symbol];
            const pl = calculatePL(order, currentPrice);
            if (!pl) continue;
            if (!map[pipelineRun]) map[pipelineRun] = { pl: 0, trades: 0, wins: 0, losses: 0, winPl: 0, lossPl: 0 };
            map[pipelineRun].pl += pl.plDollar;
            map[pipelineRun].trades++;
            if (pl.plDollar >= 0) {
                map[pipelineRun].wins++;
                map[pipelineRun].winPl += pl.plDollar;
            } else {
                map[pipelineRun].losses++;
                map[pipelineRun].lossPl += pl.plDollar;
            }
        }
        return map;
    })();

    const plSummary = (() => {
        let totalPL = 0;
        let totalInvested = 0;
        let totalInvestedAll = 0;
        let totalWinPL = 0;
        let totalLossPL = 0;
        let wins = 0;
        let losses = 0;
        let open = 0;
        let counted = 0;

        for (const order of visibleOrders) {
            if (order.side !== 'buy' || !order.filled_avg_price || !order.filled_qty) continue;
            const currentPrice = currentPrices[order.symbol];
            const pl = calculatePL(order, currentPrice);
            if (!pl) continue;
            const cost = parseFloat(order.filled_avg_price) * parseFloat(order.filled_qty);
            totalPL += pl.plDollar;
            totalInvestedAll += cost;
            if (pl.plDollar >= 0) totalWinPL += pl.plDollar; else totalLossPL += pl.plDollar;
            counted++;
            if (pl.isRealized) {
                if (pl.plDollar >= 0) wins++; else losses++;
            } else {
                open++;
                if (positions[order.symbol]?.qty > 0) {
                    totalInvested += cost;
                }
            }
        }

        return { totalPL, totalInvested, totalInvestedAll, totalWinPL, totalLossPL, wins, losses, open, counted };
    })();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Alpaca Orders" />

            <div className="mx-auto px-4 py-6 sm:px-6 lg:px-8">
                <div className="space-y-6">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900 dark:text-white">Alpaca Orders</h1>
                            <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                View all orders from alpaca_orders database table (placed through Alpaca API)
                            </p>
                        </div>
                    </div>

                    {/* Date Navigation */}
                    <div className="flex justify-between">
                        <button
                            onClick={() => navigateDate('back')}
                            className="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:ring-2 focus:ring-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                        >
                            ← Prev Day
                        </button>
                        <button
                            onClick={() => navigateDate('forward')}
                            className="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:ring-2 focus:ring-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                        >
                            Next Day →
                        </button>
                    </div>

                    {/* Date Filter */}
                    <form onSubmit={handleFilter} className="rounded-lg border bg-card p-4">
                        <div className="flex flex-wrap items-end gap-4">
                            <div className="flex-1 min-w-[200px]">
                                <label htmlFor="start_date" className="block text-sm font-medium mb-1">
                                    Start Date
                                </label>
                                <input
                                    type="date"
                                    id="start_date"
                                    value={startDate}
                                    onChange={(e) => setStartDate(e.target.value)}
                                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                />
                            </div>
                            <div className="flex-1 min-w-[200px]">
                                <label htmlFor="end_date" className="block text-sm font-medium mb-1">
                                    End Date
                                </label>
                                <input
                                    type="date"
                                    id="end_date"
                                    value={endDate}
                                    onChange={(e) => setEndDate(e.target.value)}
                                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                />
                            </div>
                            <div className="flex-1 min-w-[200px]">
                                <label htmlFor="pipeline" className="block text-sm font-medium mb-1">
                                    Pipeline
                                </label>
                                <select
                                    id="pipeline"
                                    value={pipeline}
                                    onChange={(e) => {
                                        setPipeline(e.target.value);
                                        if (e.target.value) {
                                            router.get('/alpaca-orders', {
                                                start_date: startDate || undefined,
                                                end_date: endDate || undefined,
                                                ml_threshold: mlThreshold || undefined,
                                                pipeline: e.target.value,
                                            }, { preserveState: true, preserveScroll: true, showProgress: false });
                                        } else {
                                            router.get('/alpaca-orders', {
                                                start_date: startDate || undefined,
                                                end_date: endDate || undefined,
                                                ml_threshold: mlThreshold || undefined,
                                            }, { preserveState: true, preserveScroll: true, showProgress: false });
                                        }
                                    }}
                                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                >
                                    <option value="">All Pipelines</option>
                                    <option value="A">Pipeline A ({pipelineVersions?.A ?? 'v0.0'})</option>
                                    <option value="B">Pipeline B ({pipelineVersions?.B ?? 'v0.0'})</option>
                                    <option value="C">Pipeline C ({pipelineVersions?.C ?? 'v0.0'})</option>
                                    <option value="D">Pipeline D ({pipelineVersions?.D ?? 'v0.0'})</option>
                                    <option value="E">Pipeline E ({pipelineVersions?.E ?? 'v0.0'})</option>
                                    <option value="F">Pipeline F ({pipelineVersions?.F ?? 'v0.0'})</option>
                                    <option value="G">Pipeline G ({pipelineVersions?.G ?? 'v0.0'})</option>
                                    <option value="H">Pipeline H ({pipelineVersions?.H ?? 'v0.0'})</option>
                                    <option value="I">Pipeline I ({pipelineVersions?.I ?? 'v0.0'})</option>
                                    <option value="J">Pipeline J — Recent 4 Percent Plus Movers ({pipelineVersions?.J ?? 'v0.0'})</option>
                                    <option value="K">Pipeline K ({pipelineVersions?.K ?? 'v0.0'})</option>
                                    <option value="L">Pipeline L ({pipelineVersions?.L ?? 'v0.0'})</option>
                                    <option value="M">Pipeline M ({pipelineVersions?.M ?? 'v0.0'})</option>
                                    <option value="N">Pipeline N ({pipelineVersions?.N ?? 'v0.0'})</option>
                                    <option value="O">Pipeline O ({pipelineVersions?.O ?? 'v0.0'})</option>
                                    <option value="P">Pipeline P ({pipelineVersions?.P ?? 'v0.0'})</option>
                                    <option value="Q">Pipeline Q ({pipelineVersions?.Q ?? 'v0.0'})</option>
                                    <option value="R">Pipeline R ({pipelineVersions?.R ?? 'v0.0'})</option>
                                    <option value="S">Pipeline S ({pipelineVersions?.S ?? 'v0.0'})</option>
                                    <option value="X">Pipeline X ({pipelineVersions?.X ?? 'v0.0'})</option>
                                    <option value="BIASED1">Biased1 ({pipelineVersions?.BIASED1 ?? 'v0.0'})</option>
                                    <option value="EXTERNAL">External ({pipelineVersions?.EXTERNAL ?? 'v0.0'})</option>
                                    <option value="MANUAL">Manual ({pipelineVersions?.MANUAL ?? 'v0.0'})</option>
                                </select>
                            </div>
                            <div className="flex-1 min-w-[130px]">
                                <label htmlFor="ml_threshold" className="block text-sm font-medium mb-1">
                                    Min ML %
                                </label>
                                <select
                                    id="ml_threshold"
                                    value={mlThreshold}
                                    onChange={(e) => setMlThreshold(e.target.value)}
                                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                >
                                    <option value="">All ML %</option>
                                    <option value="-1">.env ({pipeline ? Math.round((pipelineMlThresholds[pipeline] ?? 0.65) * 100) : 'per pipeline'}%)</option>
                                    <option value="0.05">5%+</option>
                                    <option value="0.10">10%+</option>
                                    <option value="0.15">15%+</option>
                                    <option value="0.20">20%+</option>
                                    <option value="0.25">25%+</option>
                                    <option value="0.30">30%+</option>
                                    <option value="0.35">35%+</option>
                                    <option value="0.40">40%+</option>
                                    <option value="0.45">45%+</option>
                                    <option value="0.50">50%+</option>
                                    <option value="0.55">55%+</option>
                                    <option value="0.60">60%+</option>
                                    <option value="0.65">65%+</option>
                                    <option value="0.70">70%+</option>
                                    <option value="0.75">75%+</option>
                                    <option value="0.80">80%+</option>
                                    <option value="0.85">85%+</option>
                                    <option value="0.90">90%+</option>
                                    <option value="0.95">95%+</option>
                                </select>
                            </div>
                            <div className="flex items-center gap-3">
                                <label className="flex items-center gap-2 cursor-pointer select-none text-sm">
                                    <input
                                        type="checkbox"
                                        checked={hideVwapBlocked}
                                        onChange={(e) => {
                                            setHideVwapBlocked(e.target.checked);
                                            router.get('/alpaca-orders', {
                                                start_date: startDate || undefined,
                                                end_date: endDate || undefined,
                                                pipeline: pipeline || undefined,
                                                ml_threshold: mlThreshold || undefined,
                                                hide_vwap_blocked: e.target.checked || undefined,
                                            }, {
                                                preserveState: true,
                                                preserveScroll: true,
                                                showProgress: false,
                                            });
                                        }}
                                        className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                    />
                                    <span className="font-medium">Hide VWAP-blocked</span>
                                </label>
                            </div>
                            <div className="flex gap-2">
                                <button
                                    type="submit"
                                    className="px-4 py-2 rounded-md bg-primary text-primary-foreground hover:bg-primary/90 transition-colors"
                                >
                                    Filter
                                </button>
                                {isFetching && (
                                    <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
                                        <svg className="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                        </svg>
                                        Loading…
                                    </span>
                                )}
                                {(startDate || endDate || pipeline || mlThreshold) && (
                                    <button
                                        type="button"
                                        onClick={handleClearFilters}
                                        className="px-4 py-2 rounded-md border bg-background hover:bg-muted transition-colors"
                                    >
                                        Clear
                                    </button>
                                )}
                                <label className="flex items-center gap-2 cursor-pointer select-none">
                                    <span className="text-sm text-muted-foreground">Hide Cancelled</span>
                                    <button
                                        type="button"
                                        role="switch"
                                        aria-checked={hideCancelled}
                                        onClick={() => setHideCancelled(!hideCancelled)}
                                        className={`relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none ${
                                            hideCancelled ? 'bg-primary' : 'bg-muted'
                                        }`}
                                    >
                                        <span
                                            className={`pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow-sm ring-0 transition-transform duration-200 ease-in-out ${
                                                hideCancelled ? 'translate-x-5' : 'translate-x-0'
                                            }`}
                                        />
                                    </button>
                                </label>
                            </div>
                        </div>
                    </form>

                    {/* Pipeline P&L Summary */}
                    {Object.keys(pipelineSummary ?? {}).length > 0 && (
                        <div className="rounded-lg border bg-muted/50">
                            <div className="px-4 py-2 border-b">
                                <span className="text-sm font-semibold text-foreground uppercase tracking-wide">Pipeline Summary</span>
                            </div>
                            <div className="px-4 py-3 bg-card rounded-b-lg">
                                <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                                    {Object.entries(pipelineSummary ?? {}).map(([pp, data]) => (
                                        <div key={pp} className="rounded-md border p-2 px-3 text-center min-w-[120px]">
                                            <div className="text-sm text-muted-foreground font-semibold">
                                                {pipelineVersions?.[pp] ? `${pp} (${pipelineVersions[pp]})` : pp}
                                            </div>
                                            <div className={`text-base font-bold ${
                                                data.pl >= 0
                                                    ? 'text-green-600 dark:text-green-400'
                                                    : 'text-red-600 dark:text-red-400'
                                            }`}>
                                                {data.pl >= 0 ? '+' : ''}{data.pl.toLocaleString('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 })}
                                            </div>
                                            <div className="mt-0.5 space-y-0.5 text-xs">
                                                <div className="text-green-600 dark:text-green-400">
                                                    W: {data.wins} ({data.winPl >= 0 ? '+' : ''}{data.winPl.toLocaleString('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 })})
                                                </div>
                                                <div className="text-red-600 dark:text-red-400">
                                                    L: {data.losses} ({data.lossPl.toLocaleString('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 })})
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* P&L Summary */}
                    {plSummary.counted > 0 && (
                        <div className="rounded-lg border bg-muted/50">
                            <div className="px-4 py-2 border-b">
                                <span className="text-sm font-semibold text-foreground uppercase tracking-wide">Day Summary</span>
                            </div>
                            <div className="flex flex-wrap gap-6 items-center px-4 py-3 bg-card rounded-b-lg">
                                <div>
                                    <div className="text-xs text-muted-foreground mb-0.5">Total P/L</div>
                                    <div className={`text-2xl font-bold ${
                                        plSummary.totalPL >= 0
                                            ? 'text-green-600 dark:text-green-400'
                                            : 'text-red-600 dark:text-red-400'
                                    }`}>
                                        {plSummary.totalPL >= 0 ? '+' : ''}{plSummary.totalPL.toLocaleString('en-US', { style: 'currency', currency: 'USD' })}
                                    </div>
                                </div>
                                <div className="h-10 w-px bg-border" />
                                <div>
                                    <div className="text-xs text-muted-foreground mb-0.5">Open Invested</div>
                                    <div className="text-2xl font-bold">
                                        {plSummary.totalInvested.toLocaleString('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 })}
                                    </div>
                                </div>
                                <div className="h-10 w-px bg-border" />
                                <div>
                                    <div className="text-xs text-muted-foreground mb-0.5">Day Invested</div>
                                    <div className="text-2xl font-bold">
                                        {plSummary.totalInvestedAll.toLocaleString('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 })}
                                    </div>
                                </div>
                                <div className="h-10 w-px bg-border" />
                                <div>
                                    <div className="text-xs text-muted-foreground mb-0.5">Total Gains</div>
                                    <div className="text-2xl font-bold text-green-600 dark:text-green-400">
                                        +{plSummary.totalWinPL.toLocaleString('en-US', { style: 'currency', currency: 'USD' })}
                                    </div>
                                </div>
                                <div className="h-10 w-px bg-border" />
                                <div>
                                    <div className="text-xs text-muted-foreground mb-0.5">Total Losses</div>
                                    <div className="text-2xl font-bold text-red-600 dark:text-red-400">
                                        {plSummary.totalLossPL.toLocaleString('en-US', { style: 'currency', currency: 'USD' })}
                                    </div>
                                </div>
                                <div className="h-10 w-px bg-border" />
                                <div>
                                    <div className="text-xs text-muted-foreground mb-0.5">Closed Wins</div>
                                    <div className="text-lg font-semibold text-green-600 dark:text-green-400">{plSummary.wins}</div>
                                </div>
                                <div>
                                    <div className="text-xs text-muted-foreground mb-0.5">Closed Losses</div>
                                    <div className="text-lg font-semibold text-red-600 dark:text-red-400">{plSummary.losses}</div>
                                </div>
                                {plSummary.wins + plSummary.losses > 0 && (
                                    <div>
                                        <div className="text-xs text-muted-foreground mb-0.5">Win Rate</div>
                                        <div className="text-lg font-semibold">
                                            {Math.round(plSummary.wins / (plSummary.wins + plSummary.losses) * 100)}%
                                        </div>
                                    </div>
                                )}
                                {plSummary.open > 0 && (
                                    <div>
                                        <div className="text-xs text-muted-foreground mb-0.5">Open</div>
                                        <div className="text-lg font-semibold text-blue-600 dark:text-blue-400">{plSummary.open}</div>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                <div className="rounded-lg border bg-card">
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-1.5 text-left text-sm font-medium">
                                        Action
                                    </th>
                                    <th className="px-4 py-1.5 text-left text-sm font-medium">
                                        Symbol
                                    </th>
                                    <th className="px-4 py-1.5 text-left text-sm font-medium">
                                        Pipeline
                                    </th>
                                    <th className="px-4 py-1.5 text-left text-sm font-medium">
                                        ML %
                                    </th>
                                    <th className="px-4 py-1.5 text-left text-sm font-medium">
                                        Side
                                    </th>
                                    <th className="px-4 py-1.5 text-left text-sm font-medium">
                                        Type
                                    </th>
                                    <th className="px-4 py-1.5 text-right text-sm font-medium">
                                        Qty
                                    </th>
                                    <th className="px-4 py-1.5 text-right text-sm font-medium">
                                        Filled
                                    </th>
                                    <th className="px-4 py-1.5 text-right text-sm font-medium">
                                        Avg Price
                                    </th>
                                    <th className="px-4 py-1.5 text-right text-sm font-medium">
                                        Current Price
                                    </th>
                                    <th className="px-4 py-1.5 text-right text-sm font-medium">
                                        Stop Price
                                    </th>
                                    <th className="px-4 py-1.5 text-right text-sm font-medium">
                                        Position Size
                                    </th>
                                    <th className="px-4 py-1.5 text-right text-sm font-medium">
                                        P/L
                                        <div className="text-sm font-normal text-muted-foreground">✓ = Closed</div>
                                    </th>
                                    <th className="px-4 py-1.5 text-left text-sm font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-1.5 text-left text-sm font-medium">
                                        Time (EST)
                                        <div className="text-sm font-normal text-foreground">Placed</div>
                                        <div className="text-sm font-normal text-muted-foreground">Filled</div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {visibleOrders.map((order: AlpacaOrder) => (
                                    <tr
                                        key={order.id}
                                        className="hover:bg-muted/50 transition-colors"
                                    >
                                        <td className="px-4 py-1.5">
                                            {order.side === 'buy' && (order.status === 'filled' || order.status === 'partially_filled' || (['canceled', 'cancelled'].includes(order.status) && parseFloat(order.filled_qty ?? '0') > 0)) && order.filled_qty ? (
                                                (() => {
                                                    const sharesInfo = getSharesInfo(order);
                                                    const canSell = hasShares(order);
                                                    
                                                    if (!sharesInfo) {
                                                        return <span className="text-muted-foreground text-sm">{canSell ? 'No shares' : 'Position closed'}</span>;
                                                    }
                                                    
                                                    const isPartialFill =
                                                        order.status === 'partially_filled' ||
                                                        (['canceled', 'cancelled'].includes(order.status) &&
                                                            parseFloat(order.filled_qty ?? '0') > 0 &&
                                                            parseFloat(order.filled_qty ?? '0') < parseFloat(order.qty ?? '0'));

                                                    return (
                                                        <div className="flex flex-col gap-1">
                                                            {isPartialFill && (
                                                                <span className="text-xs font-medium text-amber-600 dark:text-amber-400">
                                                                    ⚠ Partial fill {Math.floor(parseFloat(order.filled_qty ?? '0'))}/{Math.floor(parseFloat(order.qty ?? '0'))}
                                                                </span>
                                                            )}
                                                            {canSell ? (
                                                                <button
                                                                    onClick={() => handleSell(order.id)}
                                                                    disabled={sellingOrderId === order.id}
                                                                    className="px-3 py-1 text-base rounded bg-red-600 text-white hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                                                                >
                                                                    {getSellButtonText(order, sellingOrderId === order.id)}
                                                                </button>
                                                            ) : (
                                                                <span className="text-xs text-muted-foreground italic">
                                                                    Position closed
                                                                </span>
                                                            )}
                                                            <span className="text-xs text-muted-foreground">
                                                                {sharesInfo}
                                                            </span>
                                                        </div>
                                                    );
                                                })()
                                            ) : order.side === 'buy' && ['new', 'partially_filled'].includes(order.status) && parseFloat(order.filled_qty ?? '0') === 0 ? (
                                                <button
                                                    onClick={() => handleCancelBuy(order.id)}
                                                    disabled={cancellingOrderId === order.id}
                                                    className="px-3 py-1 text-base rounded bg-amber-500 text-white hover:bg-amber-600 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                                                >
                                                    {cancellingOrderId === order.id ? 'Cancelling...' : 'Cancel'}
                                                </button>
                                            ) : (
                                                <span className="text-muted-foreground text-sm">-</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-1.5 font-mono font-medium">
                                            {assetIds[order.symbol] ? (
                                                <a 
                                                    href={`/market-data/assets/${assetIds[order.symbol]}`}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 hover:underline"
                                                >
                                                    {order.symbol}
                                                </a>
                                            ) : (
                                                order.symbol
                                            )}
                                        </td>
                                        <td className="px-4 py-1.5 text-sm">
                                            {(() => {
                                                // @ts-expect-error tradeAlert may not be typed
                                                const ta = (order as any).trade_alert;
                                                if (!ta?.pipeline_run) return <span className="text-muted-foreground">-</span>;
                                                const v = pipelineVersions?.[ta.pipeline_run];
                                                return (
                                                    <div className="leading-tight">
                                                        <div className="font-semibold">{ta.pipeline_run}</div>
                                                        {v && <div className="text-xs text-muted-foreground">{v}</div>}
                                                    </div>
                                                );
                                            })()}
                                        </td>
                                        <td className="px-4 py-1.5 text-sm">
                                            {(() => {
                                                // @ts-expect-error tradeAlert may not be typed
                                                const ta = (order as any).trade_alert;
                                                if (!ta?.ml_win_prob) return <span className="text-muted-foreground">-</span>;
                                                return <span>{(ta.ml_win_prob * 100).toFixed(0)}%</span>;
                                            })()}
                                        </td>
                                        <td className="px-4 py-1.5">
                                            <span
                                                className={getSideColor(
                                                    order.side
                                                )}
                                            >
                                                {order.side.toUpperCase()}
                                            </span>
                                        </td>
                                        <td className="px-4 py-1.5 text-sm text-muted-foreground">
                                            {order.order_type}
                                        </td>
                                        <td className="px-4 py-1.5 text-right font-mono">
                                            {parseFloat(order.qty).toFixed(2)}
                                        </td>
                                        <td className="px-4 py-1.5 text-right font-mono">
                                            {order.filled_qty
                                                ? parseFloat(
                                                      order.filled_qty
                                                  ).toFixed(2)
                                                : '-'}
                                        </td>
                                        <td className="px-4 py-1.5 text-right font-mono">
                                            {order.filled_avg_price
                                                ? `$${parseFloat(order.filled_avg_price).toFixed(2)}`
                                                : '-'}
                                        </td>
                                        <td className="px-4 py-1.5 text-right font-mono">
                                            {currentPrices[order.symbol]
                                                ? `$${parseFloat(currentPrices[order.symbol].price).toFixed(2)}`
                                                : '-'}
                                        </td>
                                        <td className="px-4 py-1.5 text-right font-mono">
                                            {order.stop_price
                                                ? `$${parseFloat(order.stop_price).toFixed(2)}`
                                                : '-'}
                                        </td>
                                        <td className="px-4 py-1.5 text-right font-mono">
                                            {order.side === 'buy' && order.filled_avg_price && order.filled_qty
                                                ? `$${(parseFloat(order.filled_avg_price) * parseFloat(order.filled_qty)).toLocaleString('en-US', { maximumFractionDigits: 0 })}`
                                                : '-'}
                                        </td>
                                        <td className="px-4 py-1.5 text-right font-mono">
                                            {(() => {
                                                const pl = calculatePL(order, currentPrices[order.symbol]);
                                                if (!pl) return <span className="text-muted-foreground">-</span>;
                                                return (
                                                    <div className={`flex flex-col items-end ${getPLColor(pl)}`}>
                                                        <span>{pl.plDollar >= 0 ? '+' : ''}${pl.plDollar.toFixed(2)}</span>
                                                        <span className="text-sm">
                                                            {pl.plPct >= 0 ? '+' : ''}{pl.plPct.toFixed(2)}%
                                                            {pl.isRealized ? <span className="text-base"> ✓</span> : ''}
                                                        </span>
                                                    </div>
                                                );
                                            })()}
                                        </td>
                                        <td className="px-4 py-1.5">
                                            <span
                                                className={`text-sm ${getStatusColor(order.status)}`}
                                            >
                                                {order.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-1.5 text-sm whitespace-nowrap">
                                            <div className="text-foreground">{formatTimeEST(order.submitted_at)}</div>
                                            {order.filled_at && (
                                                <div className="text-sm text-muted-foreground mt-0.5">
                                                    {formatTimeEST(order.filled_at)}
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {orders.data.length === 0 && (
                        <div className="p-8 text-center text-muted-foreground">
                            No orders found
                        </div>
                    )}
                </div>

                {/* Pagination */}
                {orders.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <div className="text-sm text-muted-foreground">
                            Showing {(orders.current_page - 1) * orders.per_page + 1} to{' '}
                            {Math.min(orders.current_page * orders.per_page, orders.total)} of{' '}
                            {orders.total} orders
                        </div>
                        <div className="flex gap-2">
                            {orders.current_page > 1 && (
                                <a
                                    href={`/alpaca-orders?page=${orders.current_page - 1}${startDate ? `&start_date=${startDate}` : ''}${endDate ? `&end_date=${endDate}` : ''}`}
                                    className="px-4 py-2 rounded-md border bg-background hover:bg-muted transition-colors"
                                >
                                    Previous
                                </a>
                            )}
                            {orders.current_page < orders.last_page && (
                                <a
                                    href={`/alpaca-orders?page=${orders.current_page + 1}${startDate ? `&start_date=${startDate}` : ''}${endDate ? `&end_date=${endDate}` : ''}`}
                                    className="px-4 py-2 rounded-md border bg-background hover:bg-muted transition-colors"
                                >
                                    Next
                                </a>
                            )}
                        </div>
                    </div>
                )}
                </div>
            </div>
        </AppLayout>
    );
}
