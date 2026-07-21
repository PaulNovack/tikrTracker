import { useState, useEffect, Fragment } from 'react';
import { Head, router } from '@inertiajs/react';
import { ChevronRight, ChevronDown } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

interface Trade {
    id: number;
    side: string;
    ml_win_prob: number | null;
    version: string | null;
    qty: number;
    price: number;
    amount: number;
    submitted_at: string | null;
    filled_at: string | null;
}

interface Symbol {
    symbol: string;
    ml_win_prob: number | null;
    buy_qty: number;
    sell_qty: number;
    buy_cost: number;
    sell_proceeds: number;
    realized_pl: number;
    unrealized_pl: number;
    total_pl: number;
    pl_pct: number;
    status: string;
    trades: Trade[];
}

interface DailyPerformance {
    date: string;
    date_formatted: string;
    total_pl: number;
    realized_pl: number;
    unrealized_pl: number;
    pl_pct: number;
    total_buy_cost: number;
    trade_count: number;
    symbol_count: number;
    win_count: number;
    loss_count: number;
    win_rate: number;
    symbols: Symbol[];
}

interface Summary {
    total_pl: number;
    realized_pl: number;
    unrealized_pl: number;
    total_trades: number;
    total_days: number;
    winning_symbols: number;
    losing_symbols: number;
    win_rate: number;
    avg_daily_pl: number;
}

interface Props {
    dailyPerformance: DailyPerformance[];
    summary: Summary;
    filters?: {
        start_date?: string;
        end_date?: string;
        ml_threshold?: number | null;
        mode?: string;
        pipeline?: string;
    };
    pipelineVersions: Record<string, string>;
}

export default function Index({ dailyPerformance, summary, filters, pipelineVersions }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Alpaca', href: '/alpaca-orders' },
        { title: 'Daily Performance', href: '/alpaca-daily-performance' },
    ];

    const [expandedDays, setExpandedDays] = useState<Set<string>>(new Set());
    const [expandedSymbols, setExpandedSymbols] = useState<Set<string>>(new Set());
    const [startDate, setStartDate] = useState(filters?.start_date || '');
    const [endDate, setEndDate] = useState(filters?.end_date || '');
    const [mlThreshold, setMlThreshold] = useState<string>(
        filters?.ml_threshold != null ? String(filters.ml_threshold) : ''
    );
    const [mode, setMode] = useState<string>(filters?.mode || 'live');
    const [pipeline, setPipeline] = useState<string>(filters?.pipeline || '');

    function estToday(): string {
        return new Intl.DateTimeFormat('en-CA', {
            timeZone: 'America/New_York',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(new Date());
    }

    // Auto-refresh data every 60 seconds
    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({
                only: ['dailyPerformance', 'summary'],
                preserveState: true,
                preserveScroll: true,
            });
        }, 60000); // 60 seconds

        return () => clearInterval(interval);
    }, []);

    const handleFilter = () => {
        router.get('/alpaca-daily-performance', {
            start_date: startDate || undefined,
            end_date: endDate || undefined,
            ml_threshold: mlThreshold !== '' ? mlThreshold : undefined,
            mode,
            pipeline: pipeline || undefined,
        });
    };

    const toggleDay = (date: string) => {
        setExpandedDays(prev => {
            const newSet = new Set(prev);
            if (newSet.has(date)) {
                newSet.delete(date);
            } else {
                newSet.add(date);
            }
            return newSet;
        });
    };

    const toggleSymbol = (key: string) => {
        setExpandedSymbols(prev => {
            const newSet = new Set(prev);
            if (newSet.has(key)) {
                newSet.delete(key);
            } else {
                newSet.add(key);
            }
            return newSet;
        });
    };

    const formatCurrency = (value: number) => {
        const sign = value >= 0 ? '+' : '';
        return `${sign}$${value.toFixed(2)}`;
    };

    const formatPercent = (value: number) => {
        const sign = value >= 0 ? '+' : '';
        return `${sign}${value.toFixed(2)}%`;
    };

    const getColorClass = (value: number) => {
        if (value > 0) return 'text-green-600 dark:text-green-400';
        if (value < 0) return 'text-red-600 dark:text-red-400';
        return 'text-gray-600 dark:text-gray-400';
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Alpaca Daily Performance" />

            <div className="py-6">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900 dark:text-white">Alpaca Daily Performance</h1>
                            <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                Daily profit and loss breakdown showing realized and unrealized P&L by symbol and trading day
                            </p>
                        </div>
                    </div>

                    {/* Date Filter */}
                    <div className="rounded-lg border bg-card p-4">
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
                            <div className="flex-1 min-w-[160px]">
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
                                    <option value="-1">.env (per pipeline threshold)</option>
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
                            <div className="flex-1 min-w-[160px]">
                                <label htmlFor="pipeline" className="block text-sm font-medium mb-1">
                                    Pipeline
                                </label>
                                <select
                                    id="pipeline"
                                    value={pipeline}
                                    onChange={(e) => setPipeline(e.target.value)}
                                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                >
                                    <option value="">All Pipelines</option>
                                    {(() => {
                                        const allPipelines = ['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','X','BIASED1','EXTERNAL'];
                                        return allPipelines.map((p) => (
                                            <option key={p} value={p}>
                                                {pipelineVersions?.[p] ? `${p} (${pipelineVersions[p]})` : p}
                                            </option>
                                        ));
                                    })()}
                                </select>
                            </div>
                            <div>
                                <label className="block text-sm font-medium mb-1">Mode</label>
                                <div className="flex items-center rounded-md border overflow-hidden">
                                    {(['live', 'paper', 'all'] as const).map((m) => (
                                        <button
                                            key={m}
                                            type="button"
                                            onClick={() => setMode(m)}
                                            className={`px-3 py-2 text-sm capitalize transition-colors ${
                                                mode === m
                                                    ? 'bg-primary text-primary-foreground'
                                                    : 'bg-background hover:bg-muted'
                                            }`}
                                        >
                                            {m}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            <div className="flex gap-2">
                                <button
                                    onClick={handleFilter}
                                    className="px-4 py-2 rounded-md bg-primary text-primary-foreground hover:bg-primary/90 transition-colors"
                                >
                                    Filter
                                </button>
                                {(startDate || endDate || mlThreshold || pipeline || mode !== 'live') && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setStartDate('');
                                            setEndDate('');
                                            setMlThreshold('');
                                            setPipeline('');
                                            setMode('live');
                                            router.get('/alpaca-daily-performance');
                                        }}
                                        className="px-4 py-2 rounded-md border bg-background hover:bg-muted transition-colors"
                                    >
                                        Clear
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Summary Cards */}
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                            <div className="text-sm text-gray-500 dark:text-gray-400">Total P&L</div>
                            <div className={`text-2xl font-bold ${getColorClass(summary.total_pl)}`}>
                                {formatCurrency(summary.total_pl)}
                            </div>
                            <div className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Realized: {formatCurrency(summary.realized_pl)}
                            </div>
                        </div>

                        <div className="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                            <div className="text-sm text-gray-500 dark:text-gray-400">Avg Daily P&L</div>
                            <div className={`text-2xl font-bold ${getColorClass(summary.avg_daily_pl)}`}>
                                {formatCurrency(summary.avg_daily_pl)}
                            </div>
                            <div className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {summary.total_days} trading days
                            </div>
                        </div>

                        <div className="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                            <div className="text-sm text-gray-500 dark:text-gray-400">Win Rate</div>
                            <div className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                {summary.win_rate.toFixed(1)}%
                            </div>
                            <div className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {summary.winning_symbols}W / {summary.losing_symbols}L
                            </div>
                        </div>

                        <div className="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                            <div className="text-sm text-gray-500 dark:text-gray-400">Total Trades</div>
                            <div className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                {summary.total_trades}
                            </div>
                            <div className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Across {summary.total_days} days
                            </div>
                        </div>
                    </div>

                    {/* Daily Performance Table */}
                    <div className="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                        <div className="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                Daily Breakdown
                            </h3>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead className="bg-gray-50 dark:bg-gray-900">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                            Date
                                        </th>
                                        <th className="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                            P&L $
                                        </th>
                                        <th className="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                            P&L %
                                        </th>
                                        <th className="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                            Symbols
                                        </th>
                                        <th className="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                            Win Rate
                                        </th>
                                        <th className="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                            Trades
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                    {dailyPerformance.map((day) => (
                                        <Fragment key={day.date}>
                                            <tr
                                                className="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700"
                                                onClick={() => toggleDay(day.date)}
                                            >
                                                <td className="px-4 py-3 whitespace-nowrap">
                                                    <div className="flex items-center gap-2">
                                                        {expandedDays.has(day.date) ? (
                                                            <ChevronDown className="h-4 w-4 text-gray-500" />
                                                        ) : (
                                                            <ChevronRight className="h-4 w-4 text-gray-500" />
                                                        )}
                                                        <span className="font-medium text-gray-900 dark:text-gray-100">
                                                            {day.date_formatted}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className={`px-4 py-3 text-right font-semibold whitespace-nowrap ${getColorClass(day.total_pl)}`}>
                                                    {formatCurrency(day.total_pl)}
                                                </td>
                                                <td className={`px-4 py-3 text-right font-semibold whitespace-nowrap ${getColorClass(day.pl_pct)}`}>
                                                    {formatPercent(day.pl_pct)}
                                                </td>
                                                <td className="px-4 py-3 text-center text-gray-600 dark:text-gray-400">
                                                    {day.symbol_count}
                                                </td>
                                                <td className="px-4 py-3 text-center text-gray-600 dark:text-gray-400">
                                                    {day.win_rate.toFixed(0)}% ({day.win_count}W/{day.loss_count}L)
                                                </td>
                                                <td className="px-4 py-3 text-center text-gray-600 dark:text-gray-400">
                                                    {day.trade_count}
                                                </td>
                                            </tr>

                                            {/* Expanded Symbol Details */}
                                            {expandedDays.has(day.date) && (
                                                <tr>
                                                    <td colSpan={6} className="px-4 py-2 bg-gray-50 dark:bg-gray-900">
                                                        <div className="space-y-2">
                                                            {day.symbols.map((symbol) => {
                                                                const symbolKey = `${day.date}-${symbol.symbol}`;
                                                                return (
                                                                    <div key={symbolKey} className="border-l-4 border-blue-500 pl-4">
                                                                        <div
                                                                            className="flex items-center justify-between cursor-pointer py-2"
                                                                            onClick={() => toggleSymbol(symbolKey)}
                                                                        >
                                                                            <div className="flex items-center gap-3">
                                                                                {expandedSymbols.has(symbolKey) ? (
                                                                                    <ChevronDown className="h-4 w-4 text-gray-500" />
                                                                                ) : (
                                                                                    <ChevronRight className="h-4 w-4 text-gray-500" />
                                                                                )}
                                                                                <span className="font-semibold text-gray-900 dark:text-gray-100">
                                                                                    {symbol.symbol}
                                                                                </span>
                                                                                <span className={`text-sm px-2 py-1 rounded ${
                                                                                    symbol.status === 'open'
                                                                                        ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'
                                                                                        : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'
                                                                                }`}>
                                                                                    {symbol.status}
                                                                                </span>
                                                                            </div>
                                                                            <div className="flex items-center gap-6 text-sm">
                                                                                {symbol.ml_win_prob !== null && (
                                                                                    <span className="text-blue-600 dark:text-blue-400 font-medium">
                                                                                        ML: {(symbol.ml_win_prob * 100).toFixed(1)}%
                                                                                    </span>
                                                                                )}
                                                                                <span className="text-gray-600 dark:text-gray-400">
                                                                                    Qty: {symbol.buy_qty}/{symbol.sell_qty}
                                                                                </span>
                                                                                <span className={`font-semibold ${getColorClass(symbol.total_pl)}`}>
                                                                                    {formatCurrency(symbol.total_pl)}
                                                                                </span>
                                                                                <span className={`font-semibold ${getColorClass(symbol.pl_pct)}`}>
                                                                                    {formatPercent(symbol.pl_pct)}
                                                                                </span>
                                                                            </div>
                                                                        </div>

                                                                        {/* Expanded Trade Details */}
                                                                        {expandedSymbols.has(symbolKey) && (
                                                                            <div className="ml-8 mt-2 space-y-1">
                                                                                {symbol.trades.map((trade) => (
                                                                                    <div
                                                                                        key={trade.id}
                                                                                        className="flex items-center justify-between text-xs py-1 px-3 bg-white dark:bg-gray-800 rounded"
                                                                                    >
                                                                                        <span className={`font-semibold uppercase ${
                                                                                            trade.side === 'buy'
                                                                                                ? 'text-green-600 dark:text-green-400'
                                                                                                : 'text-red-600 dark:text-red-400'
                                                                                        }`}>
                                                                                            {trade.side}
                                                                                        </span>
                                                                                        {trade.side === 'buy' && trade.version && (
                                                                                            <span className="text-purple-600 dark:text-purple-400 font-medium">
                                                                                                {trade.version}
                                                                                            </span>
                                                                                        )}
                                                                                        <span className="text-gray-600 dark:text-gray-400">
                                                                                            {trade.ml_win_prob !== null ? `${(trade.ml_win_prob * 100).toFixed(1)}%` : 'N/A'}
                                                                                        </span>
                                                                                        <span className="text-gray-600 dark:text-gray-400">
                                                                                            {trade.qty} shares @ ${trade.price.toFixed(2)}
                                                                                        </span>
                                                                                        <span className="text-gray-900 dark:text-gray-100">
                                                                                            ${trade.amount.toFixed(2)}
                                                                                        </span>
                                                                                        <div className="flex flex-col items-end text-gray-500 dark:text-gray-500">
                                                                                            <span title="Order submitted">📤 {trade.submitted_at ?? '—'}</span>
                                                                                            {trade.filled_at && trade.filled_at !== trade.submitted_at && (
                                                                                                <span title="Order filled" className="text-green-600 dark:text-green-400">✅ {trade.filled_at}</span>
                                                                                            )}
                                                                                        </div>
                                                                                    </div>
                                                                                ))}
                                                                            </div>
                                                                        )}
                                                                    </div>
                                                                );
                                                            })}
                                                        </div>
                                                    </td>
                                                </tr>
                                            )}
                                        </Fragment>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {dailyPerformance.length === 0 && (
                            <div className="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                No trading activity yet
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
