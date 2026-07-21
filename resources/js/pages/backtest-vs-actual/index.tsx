import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { CheckCircle, XCircle, ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem } from '@/types';

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

interface Trade {
    signal_date: string;
    signal_time: string | null;
    fill_time: string | null;
    minutes_stale: number | null;
    signal_age_at_order: number | null;
    symbol: string;
    version: string | null;
    ml_pct: number | null;
    signal_entry: number | null;
    signal_exit: number | null;
    signal_pnl_pct: number | null;
    signal_position_size: number | null;
    actual_fill: number;
    actual_exit_fill: number;
    actual_qty: number;
    actual_position_size: number;
    entry_slippage_pct: number | null;
    actual_pnl_pct: number | null;
    backtest_pnl_dollar: number | null;
    actual_pnl_dollar: number;
    has_signal: boolean;
}

interface PaginatedTrades {
    data: Trade[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

interface Summary {
    total_trades: number;
    avg_entry_slippage_pct: number | null;
    backtest_total_dollar: number;
    actual_total_dollar: number;
}

interface Filters {
    start_date: string;
    end_date: string;
    ml_threshold: number;
    version: string | null;
    max_slippage: number | null;
}

interface Props {
    trades: PaginatedTrades;
    summary: Summary;
    versions: { value: string; label: string }[];
    filters: Filters;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Alpaca', href: '/alpaca-orders' },
    { title: 'Backtest vs Actual', href: '/backtest-vs-actual' },
];

function fmtDollar(value: number | null, showSign = true): string {
    if (value === null) return '—';
    const abs = Math.abs(value);
    const sign = value >= 0 ? (showSign ? '+' : '') : '-';
    return `${sign}$${abs.toFixed(2)}`;
}

function fmtPct(value: number | null): string {
    if (value === null) return '—';
    const sign = value >= 0 ? '+' : '';
    return `${sign}${value.toFixed(2)}%`;
}

function plClass(value: number | null): string {
    if (value === null) return 'text-foreground';
    return value >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400';
}

export default function BacktestVsActual({ trades, summary, versions, filters }: Props) {
    const [startDate, setStartDate] = useState(filters.start_date || '');
    const [endDate, setEndDate] = useState(filters.end_date || '');
    const [mlThreshold, setMlThreshold] = useState(String(filters.ml_threshold ?? 0));
    const [version, setVersion] = useState(filters.version || '');
    const [maxSlippage, setMaxSlippage] = useState(
        filters.max_slippage != null ? String(filters.max_slippage) : ''
    );

    const handleFilter = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(
            '/backtest-vs-actual',
            { start_date: startDate, end_date: endDate, ml_threshold: mlThreshold, version: version || undefined, max_slippage: maxSlippage || undefined },
            { preserveState: true },
        );
    };

    const handlePageChange = (page: number) => {
        router.get(
            '/backtest-vs-actual',
            { start_date: startDate, end_date: endDate, ml_threshold: mlThreshold, version: version || undefined, max_slippage: maxSlippage || undefined, page },
            { preserveState: true, preserveScroll: false },
        );
    };

    const handleClear = () => {
        setStartDate('');
        setEndDate('');
        setMlThreshold('0');
        setVersion('');
        setMaxSlippage('');
        router.get('/backtest-vs-actual', {}, { preserveState: true });
    };

    const navigateDay = (direction: 'back' | 'forward') => {
        const base = startDate || endDate || estToday();
        const [year, month, day] = base.split('-').map(Number);
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
        setStartDate(newDate);
        setEndDate(newDate);
        router.get(
            '/backtest-vs-actual',
            { start_date: newDate, end_date: newDate, ml_threshold: mlThreshold, version: version || undefined, max_slippage: maxSlippage || undefined },
            { preserveState: true },
        );
    };

    const difference = summary.actual_total_dollar - summary.backtest_total_dollar;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Backtest vs Actual" />

            <div className="mx-auto max-w-[1800px] px-4 py-6 sm:px-6 lg:px-8">
                <div className="space-y-6">
                    {/* Header */}
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900 dark:text-white">Backtest vs Actual</h1>
                            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Compare ML signal backtest performance against actual Alpaca trade results
                            </p>
                        </div>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                onClick={() => navigateDay('back')}
                                className="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                            >
                                ← Prior Day
                            </button>
                            <button
                                type="button"
                                onClick={() => navigateDay('forward')}
                                className="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                            >
                                Next Day →
                            </button>
                        </div>
                    </div>

                    {/* Filters */}
                    <form onSubmit={handleFilter} className="rounded-lg border bg-card p-4">
                        <div className="flex flex-wrap items-end gap-4">
                            <div className="min-w-[160px] flex-1">
                                <label className="mb-1 block text-sm font-medium">Start Date</label>
                                <input
                                    type="date"
                                    value={startDate}
                                    onChange={(e) => setStartDate(e.target.value)}
                                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                />
                            </div>
                            <div className="min-w-[160px] flex-1">
                                <label className="mb-1 block text-sm font-medium">End Date</label>
                                <input
                                    type="date"
                                    value={endDate}
                                    onChange={(e) => setEndDate(e.target.value)}
                                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                />
                            </div>
                            <div className="min-w-[130px]">
                                <label className="mb-1 block text-sm font-medium">Min ML%</label>
                                <select
                                    value={mlThreshold}
                                    onChange={(e) => setMlThreshold(e.target.value)}
                                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                >
                                    <option value="0">All ML%</option>
                                    <option value="0.60">60%+</option>
                                    <option value="0.65">65%+</option>
                                    <option value="0.70">70%+</option>
                                    <option value="0.75">75%+</option>
                                    <option value="0.80">80%+</option>
                                </select>
                            </div>
                            <div className="min-w-[160px]">
                                <label className="mb-1 block text-sm font-medium">Version</label>
                                <select
                                    value={version}
                                    onChange={(e) => setVersion(e.target.value)}
                                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                >
                                    <option value="">All versions</option>
                                    {versions.map((v) => (
                                        <option key={v.value} value={v.value}>
                                            {v.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="min-w-[150px]">
                                <label className="mb-1 block text-sm font-medium">Max Entry Slip</label>
                                <select
                                    value={maxSlippage}
                                    onChange={(e) => setMaxSlippage(e.target.value)}
                                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                >
                                    <option value="">All slippage</option>
                                    <option value="0">0% (no slip)</option>
                                    <option value="0.25">≤ 0.25%</option>
                                    <option value="0.5">≤ 0.5%</option>
                                    <option value="0.75">≤ 0.75%</option>
                                    <option value="1">≤ 1%</option>
                                    <option value="1.5">≤ 1.5%</option>
                                    <option value="2">≤ 2%</option>
                                </select>
                            </div>
                            <div className="flex gap-2">
                                <button
                                    type="submit"
                                    className="rounded-md bg-primary px-4 py-2 text-primary-foreground transition-colors hover:bg-primary/90"
                                >
                                    Filter
                                </button>
                                <button
                                    type="button"
                                    onClick={handleClear}
                                    className="rounded-md border bg-background px-4 py-2 transition-colors hover:bg-muted"
                                >
                                    Clear
                                </button>
                            </div>
                        </div>
                    </form>

                    {/* Summary Cards */}
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div className="rounded-lg border bg-card p-4">
                            <p className="text-sm text-muted-foreground">Matched Trades</p>
                            <p className="mt-1 text-2xl font-bold">{summary.total_trades}</p>
                            <p className="mt-0.5 text-xs text-muted-foreground">signal + actual</p>
                        </div>
                        <div className="rounded-lg border bg-card p-4">
                            <p className="text-sm text-muted-foreground">Avg Entry Slippage</p>
                            <p className={`mt-1 text-2xl font-bold ${summary.avg_entry_slippage_pct === null ? '' : plClass(-summary.avg_entry_slippage_pct)}`}>
                                {summary.avg_entry_slippage_pct !== null ? fmtPct(summary.avg_entry_slippage_pct) : '—'}
                            </p>
                            <p className="mt-0.5 text-xs text-muted-foreground">actual fill vs signal price</p>
                        </div>
                        <div className="rounded-lg border bg-card p-4">
                            <p className="text-sm text-muted-foreground">Signal BT P&L</p>
                            <p className={`mt-1 text-2xl font-bold ${plClass(summary.backtest_total_dollar)}`}>
                                {fmtDollar(summary.backtest_total_dollar)}
                            </p>
                            <p className="mt-0.5 text-xs text-muted-foreground">backtest estimate</p>
                        </div>
                        <div className="rounded-lg border bg-card p-4">
                            <p className="text-sm text-muted-foreground">Actual P&L</p>
                            <p className={`mt-1 text-2xl font-bold ${plClass(summary.actual_total_dollar)}`}>
                                {fmtDollar(summary.actual_total_dollar)}
                            </p>
                            <p className={`mt-0.5 text-xs ${plClass(difference)}`}>
                                {fmtDollar(difference)} vs signal BT
                            </p>
                        </div>
                    </div>

                    {/* Table */}
                    <div className="overflow-hidden rounded-lg border bg-card">
                        <div className="border-b p-4">
                            <h2 className="font-semibold">Signal Comparison</h2>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="border-b bg-muted/50 text-xs font-medium text-muted-foreground">
                                        <th className="px-3 py-2 text-left">Date</th>
                                        <th className="px-3 py-2 text-left">Symbol</th>
                                        <th className="px-3 py-2 text-left">Ver</th>
                                        <th className="px-3 py-2 text-right">ML%</th>
                                        <th className="px-3 py-2 text-right">Signal Time</th>
                                        <th className="px-3 py-2 text-right">Fill Time</th>
                                        <th className="px-3 py-2 text-right">Min Stale</th>
                                        <th className="px-3 py-2 text-right" title="Signal age when order was placed (staleness filter runs here)">Age@Order</th>
                                        <th className="px-3 py-2 text-right">Signal Entry</th>
                                        <th className="px-3 py-2 text-right">Actual Fill</th>
                                        <th className="px-3 py-2 text-right">Entry Slip%</th>
                                        <th className="px-3 py-2 text-right">Signal Exit</th>
                                        <th className="px-3 py-2 text-right">Actual Exit</th>
                                        <th className="px-3 py-2 text-right">BT P&L%</th>
                                        <th className="px-3 py-2 text-right">Act P&L%</th>
                                        <th className="px-3 py-2 text-right">BT Pos $</th>
                                        <th className="px-3 py-2 text-right">Act Pos $</th>
                                        <th className="px-3 py-2 text-right">BT $</th>
                                        <th className="px-3 py-2 text-right">Act $</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {trades.data.map((trade, i) => (
                                        <tr
                                            key={i}
                                            className="transition-colors hover:bg-muted/30"
                                        >
                                            <td className="px-3 py-1.5 font-mono text-xs text-foreground/60">{trade.signal_date}</td>
                                            <td className="px-3 py-1.5 font-semibold text-foreground">{trade.symbol}</td>
                                            <td className="px-3 py-1.5 font-mono text-xs text-foreground/60">{trade.version ?? '—'}</td>
                                            <td className="px-3 py-1.5 text-right font-mono text-xs text-foreground">{trade.ml_pct !== null ? `${trade.ml_pct}%` : '—'}</td>
                                            <td className="px-3 py-1.5 text-right font-mono text-xs text-foreground/60">{trade.signal_time ?? '—'}</td>
                                            <td className="px-3 py-1.5 text-right font-mono text-xs text-foreground/60">{trade.fill_time ?? '—'}</td>
                                            <td className={`px-3 py-1.5 text-right font-mono text-xs font-medium ${trade.minutes_stale !== null && trade.minutes_stale > 10 ? 'text-amber-500' : 'text-foreground'}`}>
                                                {trade.minutes_stale !== null ? `+${trade.minutes_stale}m` : '—'}
                                            </td>
                                            <td className={`px-3 py-1.5 text-right font-mono text-xs font-medium ${trade.signal_age_at_order !== null && trade.signal_age_at_order > 15 ? 'text-amber-500' : trade.signal_age_at_order !== null && trade.signal_age_at_order > 10 ? 'text-yellow-500/80' : 'text-foreground'}`}>
                                                {trade.signal_age_at_order !== null ? `+${trade.signal_age_at_order}m` : '—'}
                                            </td>
                                            <td className="px-3 py-1.5 text-right font-mono text-xs text-foreground">{trade.signal_entry !== null ? `$${trade.signal_entry.toFixed(4)}` : '—'}</td>
                                            <td className="px-3 py-1.5 text-right font-mono text-xs text-foreground">${trade.actual_fill.toFixed(4)}</td>
                                            <td className={`px-3 py-1.5 text-right font-mono text-xs ${plClass(trade.entry_slippage_pct !== null ? -trade.entry_slippage_pct : null)}`}>
                                                {fmtPct(trade.entry_slippage_pct)}
                                            </td>
                                            <td className="px-3 py-1.5 text-right font-mono text-xs text-foreground">{trade.signal_exit !== null ? `$${trade.signal_exit.toFixed(4)}` : '—'}</td>
                                            <td className="px-3 py-1.5 text-right font-mono text-xs text-foreground">${trade.actual_exit_fill.toFixed(4)}</td>
                                            <td className={`px-3 py-1.5 text-right font-mono text-xs ${plClass(trade.signal_pnl_pct)}`}>
                                                {fmtPct(trade.signal_pnl_pct)}
                                            </td>
                                            <td className={`px-3 py-1.5 text-right font-mono text-xs ${plClass(trade.actual_pnl_pct)}`}>
                                                {fmtPct(trade.actual_pnl_pct)}
                                            </td>
                                            <td className="px-3 py-1.5 text-right font-mono text-xs text-foreground">
                                                {trade.signal_position_size !== null ? `$${Math.round(trade.signal_position_size).toLocaleString()}` : '—'}
                                            </td>
                                            <td className="px-3 py-1.5 text-right font-mono text-xs text-foreground">
                                                ${Math.round(trade.actual_position_size).toLocaleString()}
                                            </td>
                                            <td className={`px-3 py-1.5 text-right font-mono text-xs ${plClass(trade.backtest_pnl_dollar)}`}>
                                                {fmtDollar(trade.backtest_pnl_dollar)}
                                            </td>
                                            <td className={`px-3 py-1.5 text-right font-mono text-xs ${plClass(trade.actual_pnl_dollar)}`}>
                                                {fmtDollar(trade.actual_pnl_dollar)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {trades.data.length === 0 && (
                                <div className="p-8 text-center text-muted-foreground">No signals found for this date range.</div>
                            )}
                        </div>
                    </div>

                    {/* Pagination */}
                    {trades.last_page > 1 && (
                        <div className="flex items-center justify-between gap-4">
                            <p className="text-sm text-muted-foreground">
                                Showing {trades.from ?? 0} to {trades.to ?? 0} of {trades.total} trades
                            </p>
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => handlePageChange(trades.current_page - 1)}
                                    disabled={trades.current_page === 1}
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    Previous
                                </Button>
                                <span className="text-sm text-muted-foreground">
                                    Page {trades.current_page} of {trades.last_page}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => handlePageChange(trades.current_page + 1)}
                                    disabled={trades.current_page === trades.last_page}
                                >
                                    Next
                                    <ChevronRight className="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    )}

                    {/* Legend */}
                    <div className="flex flex-wrap gap-6 text-xs text-muted-foreground">
                        <span>Entry Slip% = positive means paid more than signal price</span>
                        <span>Signal BT P&L uses backtest position sizing to estimate dollar P&L</span>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
