import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { TrendingUp, TrendingDown } from 'lucide-react';
import { type BreadcrumbItem } from '@/types';

interface Bucket {
    bucket_label: string;
    bucket_minutes: number;
    total_pl: number;
    total_wins: number;
    total_losses: number;
    trade_count: number;
    win_count: number;
    loss_count: number;
    win_rate: number | null;
}

interface Filters {
    start_date: string;
    end_date: string;
    mode: string;
}

interface Props {
    buckets: Bucket[];
    filters: Filters;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Alpaca', href: '/alpaca-orders' },
    { title: 'P & L by Entry Time', href: '/alpaca-pl-by-entry-time' },
];

function formatPL(amount: number): string {
    const abs = Math.abs(amount);
    return (amount >= 0 ? '+' : '-') + '$' + abs.toFixed(2);
}

export default function AlpacaPLByEntryTime({ buckets, filters }: Props) {
    const [startDate, setStartDate] = useState(filters.start_date || '');
    const [endDate, setEndDate] = useState(filters.end_date || '');
    const [mode, setMode] = useState(filters.mode || 'all');

    const handleFilter = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/alpaca-pl-by-entry-time', { start_date: startDate, end_date: endDate, mode }, { preserveState: true });
    };

    const handleClear = () => {
        setStartDate('');
        setEndDate('');
        setMode('all');
        router.get('/alpaca-pl-by-entry-time', { mode: 'all' }, { preserveState: true });
    };

    const totalPL = buckets.reduce((sum, b) => sum + b.total_pl, 0);
    const totalTrades = buckets.reduce((sum, b) => sum + b.trade_count, 0);
    const maxAbsPL = Math.max(...buckets.map((b) => Math.abs(b.total_pl)), 1);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="P & L by Entry Time" />

            <div className="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
                <div className="space-y-6">
                    {/* Header */}
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 dark:text-white">P &amp; L by Entry Time</h1>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Realized P &amp; L grouped by 15-minute entry time intervals (market open = 9:30 AM ET)
                        </p>
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
                                    type="submit"
                                    className="px-4 py-2 rounded-md bg-primary text-primary-foreground hover:bg-primary/90 transition-colors"
                                >
                                    Filter
                                </button>
                                {(startDate || endDate) && (
                                    <button
                                        type="button"
                                        onClick={handleClear}
                                        className="px-4 py-2 rounded-md border bg-background hover:bg-muted transition-colors"
                                    >
                                        Clear
                                    </button>
                                )}
                            </div>
                        </div>
                    </form>

                    {/* Summary */}
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div className="rounded-lg border bg-card p-4">
                            <p className="text-sm text-muted-foreground">Net P &amp; L</p>
                            <p className={`text-2xl font-bold mt-1 ${totalPL >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}>
                                {formatPL(totalPL)}
                            </p>
                        </div>
                        <div className="rounded-lg border bg-card p-4">
                            <p className="text-sm text-muted-foreground">Total Wins</p>
                            <p className="text-2xl font-bold mt-1 text-green-600 dark:text-green-400">
                                +${buckets.reduce((s, b) => s + b.total_wins, 0).toFixed(2)}
                            </p>
                            <p className="text-xs text-muted-foreground mt-0.5">
                                {buckets.reduce((s, b) => s + b.win_count, 0)} trades
                            </p>
                        </div>
                        <div className="rounded-lg border bg-card p-4">
                            <p className="text-sm text-muted-foreground">Total Losses</p>
                            <p className="text-2xl font-bold mt-1 text-red-600 dark:text-red-400">
                                {formatPL(buckets.reduce((s, b) => s + b.total_losses, 0))}
                            </p>
                            <p className="text-xs text-muted-foreground mt-0.5">
                                {buckets.reduce((s, b) => s + b.loss_count, 0)} trades
                            </p>
                        </div>
                        <div className="rounded-lg border bg-card p-4">
                            <p className="text-sm text-muted-foreground">Best Interval</p>
                            {(() => {
                                const best = buckets.reduce<Bucket | null>((max, b) => (!max || b.total_pl > max.total_pl ? b : max), null);
                                return best ? (
                                    <p className="text-2xl font-bold mt-1 text-green-600 dark:text-green-400">{best.bucket_label}</p>
                                ) : <p className="text-2xl font-bold mt-1">—</p>;
                            })()}
                        </div>
                    </div>

                    {/* Bucket chart / table */}
                    <div className="rounded-lg border bg-card overflow-hidden">
                        <div className="p-4 border-b">
                            <h2 className="font-semibold">P &amp; L by 15-Minute Interval</h2>
                        </div>
                        {/* Column headings */}
                        <div className="flex items-center gap-4 px-4 py-2 border-b bg-muted/50 text-xs font-medium text-muted-foreground">
                            <div className="w-44 shrink-0 text-right">Time</div>
                            <div className="flex-1">Bar</div>
                            <div className="w-24 shrink-0 text-right">Net P &amp; L</div>
                            <div className="w-56 shrink-0 text-right">Wins / Losses</div>
                            <div className="w-24 shrink-0 text-right">Trades</div>
                            <div className="w-16 shrink-0 text-right">Win %</div>
                        </div>
                        <div className="divide-y">
                            {buckets.map((bucket) => {
                                const barWidth = maxAbsPL > 0 ? (Math.abs(bucket.total_pl) / maxAbsPL) * 100 : 0;
                                const isPositive = bucket.total_pl >= 0;
                                const hasActivity = bucket.trade_count > 0;

                                return (
                                    <div
                                        key={bucket.bucket_minutes}
                                        className={`flex items-center gap-4 px-4 py-1.5 ${!hasActivity ? 'opacity-40' : ''}`}
                                    >
                                        {/* Time label */}
                                        <div className="w-44 shrink-0 text-sm font-mono text-muted-foreground text-right whitespace-nowrap">
                                            {(() => {
                                                const startTotal = 9 * 60 + 30 + bucket.bucket_minutes;
                                                const endTotal = startTotal + 15;
                                                const fmt = (mins: number) => {
                                                    const h = Math.floor(mins / 60);
                                                    const m = mins % 60;
                                                    const ampm = h >= 12 ? 'PM' : 'AM';
                                                    const h12 = h > 12 ? h - 12 : h === 0 ? 12 : h;
                                                    return `${h12}:${String(m).padStart(2, '0')} ${ampm}`;
                                                };
                                                return `${fmt(startTotal)} - ${fmt(endTotal)}`;
                                            })()}
                                        </div>

                                        {/* Bar */}
                                        <div className="flex-1 relative h-8 flex items-center">
                                            <div className="absolute inset-y-0 left-0 right-0 flex items-center">
                                                <div
                                                    className={`h-6 rounded transition-all ${isPositive ? 'bg-green-500/70 dark:bg-green-600/70' : 'bg-red-500/70 dark:bg-red-600/70'}`}
                                                    style={{ width: `${barWidth}%`, minWidth: hasActivity ? '2px' : '0' }}
                                                />
                                            </div>
                                        </div>

                                        {/* P&L */}
                                        <div className={`w-24 shrink-0 text-right text-sm font-mono font-medium ${isPositive ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}>
                                            {hasActivity ? (
                                                <span className="flex items-center justify-end gap-1">
                                                    {isPositive
                                                        ? <TrendingUp className="w-3.5 h-3.5" />
                                                        : <TrendingDown className="w-3.5 h-3.5" />
                                                    }
                                                    {formatPL(bucket.total_pl)}
                                                </span>
                                            ) : '—'}
                                        </div>

                                        {/* Wins / Losses */}
                                        <div className="w-56 shrink-0 text-right text-xs font-mono">
                                            {hasActivity ? (
                                                <span className="flex flex-col items-end gap-0.5">
                                                    {bucket.win_count > 0 && (
                                                        <span className="whitespace-nowrap text-green-600 dark:text-green-400">+${bucket.total_wins.toFixed(2)} ({bucket.win_count} {bucket.win_count === 1 ? 'Win' : 'Wins'})</span>
                                                    )}
                                                    {bucket.loss_count > 0 && (
                                                        <span className="whitespace-nowrap text-red-600 dark:text-red-400">{formatPL(bucket.total_losses)} ({bucket.loss_count} {bucket.loss_count === 1 ? 'Loss' : 'Losses'})</span>
                                                    )}
                                                </span>
                                            ) : ''}
                                        </div>

                                        {/* Trades */}
                                        <div className="w-24 shrink-0 text-right text-sm text-muted-foreground">
                                            {hasActivity ? `${bucket.trade_count} ${bucket.trade_count === 1 ? 'Trade' : 'Trades'}` : ''}
                                        </div>

                                        {/* Win rate */}
                                        <div className={`w-16 shrink-0 text-right text-sm ${bucket.win_rate !== null ? (bucket.win_rate >= 40 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400') : 'text-muted-foreground'}`}>
                                            {bucket.win_rate !== null ? `${bucket.win_rate}%` : ''}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                        {totalTrades === 0 && (
                            <div className="p-8 text-center text-muted-foreground">
                                No closed trades found for this date range.
                            </div>
                        )}
                    </div>

                    {/* Legend */}
                    <div className="flex gap-6 text-xs text-muted-foreground">
                        <span><span className="inline-block w-3 h-3 rounded bg-green-500/70 mr-1" />Profit</span>
                        <span><span className="inline-block w-3 h-3 rounded bg-red-500/70 mr-1" />Loss</span>
                        <span>Win rate shown only for intervals with both wins and losses</span>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
