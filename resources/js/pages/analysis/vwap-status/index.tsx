import AppLayout from '@/layouts/app-layout';
import HeadingSmall from '@/components/heading-small';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Activity, ArrowDown, ArrowUp, BarChart, CalendarDays } from 'lucide-react';
import { useState } from 'react';

interface VwapBar {
    ts_est: string;
    time: string;
    price: number;
    vwap: number;
    above_vwap: boolean;
    vwap_dist_pct: number;
    below_high_pct: number | null;
    gate_would_block: boolean;
    block_reason: string | null;
}

interface Props {
    date: string;
    benchmarkSymbol: string;
    gateEnabled: boolean;
    maxPctBelowHigh: number | null;
    pipelineOverrides: Record<string, boolean | null>;
    bars: VwapBar[];
    intradayHigh: number;
    blockedCount: number;
    passedCount: number;
    totalBars: number;
}

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

export default function VwapStatusIndex({ date, benchmarkSymbol, gateEnabled, maxPctBelowHigh, pipelineOverrides, bars, intradayHigh, blockedCount, passedCount, totalBars }: Props) {
    const [selectedDate, setSelectedDate] = useState(date);
    const maxDate = estToday();
    const isToday = selectedDate === maxDate;

    const handleDateChange = (newDate: string) => {
        setSelectedDate(newDate);
        router.get('/analysis/vwap-status', { date: newDate }, { preserveState: false, preserveScroll: false });
    };

    const navigateDay = (direction: 'prev' | 'next') => {
        const [y, m, d] = selectedDate.split('-').map(Number);
        const dt = new Date(Date.UTC(y, m - 1, d) + (direction === 'prev' ? -86400000 : 86400000));
        // Skip weekends
        const dow = dt.getUTCDay();
        if (direction === 'next' && dow === 6) dt.setUTCDate(dt.getUTCDate() + 2);
        else if (direction === 'next' && dow === 0) dt.setUTCDate(dt.getUTCDate() + 1);
        else if (direction === 'prev' && dow === 0) dt.setUTCDate(dt.getUTCDate() - 2);
        else if (direction === 'prev' && dow === 6) dt.setUTCDate(dt.getUTCDate() - 1);
        const nextDate = dt.toISOString().slice(0, 10);
        if (direction === 'next' && nextDate > maxDate) return;
        handleDateChange(nextDate);
    };

    const blockPct = totalBars > 0 ? ((blockedCount / totalBars) * 100).toFixed(0) : '0';

    return (
        <>
            <Head title={`5-Min VWAP Status — ${benchmarkSymbol} — ${date}`} />
            <AppLayout>
                <div className="flex flex-col gap-6 p-6">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h1 className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                                5-Min VWAP Status
                            </h1>
                            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Each 5-minute bar's position relative to <span className="font-medium">{benchmarkSymbol}</span>'s intraday VWAP
                            </p>
                        </div>
                    </div>

                    {/* Date Navigator */}
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex flex-wrap items-center gap-4">
                                <button
                                    onClick={() => navigateDay('prev')}
                                    className="rounded-md border px-3 py-2 text-sm hover:bg-muted"
                                >
                                    « Prev
                                </button>
                                <CalendarDays className="h-5 w-5 text-muted-foreground" />
                                <input
                                    type="date"
                                    value={selectedDate}
                                    max={maxDate}
                                    onChange={(e) => handleDateChange(e.target.value)}
                                    className="rounded-md border bg-background px-3 py-2 text-sm"
                                />
                                <button
                                    onClick={() => navigateDay('next')}
                                    disabled={isToday}
                                    className="rounded-md border px-3 py-2 text-sm hover:bg-muted disabled:opacity-40"
                                >
                                    Next »
                                </button>
                                {isToday && (
                                    <Badge variant="outline" className="text-xs">Today</Badge>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Summary */}
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Total Bars</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{totalBars}</div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    <span className="flex items-center gap-1.5">
                                        <ArrowUp className="h-4 w-4 text-green-500" /> Passed
                                    </span>
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold text-green-600">{passedCount}</div>
                                <p className="text-xs text-muted-foreground">
                                    {totalBars > 0 ? ((passedCount / totalBars) * 100).toFixed(0) : 0}% of bars
                                </p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    <span className="flex items-center gap-1.5">
                                        <ArrowDown className="h-4 w-4 text-red-500" /> Blocked
                                    </span>
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold text-red-600">{blockedCount}</div>
                                <p className="text-xs text-muted-foreground">
                                    {blockPct}% of bars
                                </p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Gate Status</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <Badge variant={gateEnabled ? 'destructive' : 'secondary'} className="text-sm">
                                    {gateEnabled ? 'Active' : 'Inactive'}
                                </Badge>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {gateEnabled
                                        ? `Blocking ${blockPct}% of bars`
                                        : `Would block ${blockPct}% of bars if enabled`}
                                </p>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Bar-by-bar table */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <BarChart className="h-5 w-5" />
                                <CardTitle>
                                    {benchmarkSymbol} 5-Min Bars — {selectedDate}
                                </CardTitle>
                            </div>
                            <CardDescription>
                                Intraday high: <span className="font-mono font-medium">{Number(intradayHigh).toFixed(2)}</span>
                                {maxPctBelowHigh !== null && (
                                    <> — Secondary check: ≤{maxPctBelowHigh}% below high</>
                                )}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-border">
                                            <th className="pb-2 pr-3 text-left font-medium text-muted-foreground">Time (ET)</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Price</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">VWAP</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Dist %</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Below High %</th>
                                            <th className="pb-2 text-center font-medium text-muted-foreground">Gate</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {bars.length === 0 && (
                                            <tr>
                                                <td colSpan={6} className="py-12 text-center text-muted-foreground">
                                                    {selectedDate > maxDate
                                                        ? 'Cannot view future dates.'
                                                        : `No 5-minute bars found for ${benchmarkSymbol} on ${selectedDate}. This may be a non-trading day.`}
                                                </td>
                                            </tr>
                                        )}
                                        {bars.map((bar) => (
                                            <tr
                                                key={bar.ts_est}
                                                className={
                                                    bar.gate_would_block
                                                        ? 'bg-red-50 dark:bg-red-950/20'
                                                        : ''
                                                }
                                            >
                                                <td className="py-1.5 pr-3 font-mono font-medium">{bar.time}</td>
                                                <td className="py-1.5 pr-3 text-right font-mono">{bar.price.toFixed(2)}</td>
                                                <td className="py-1.5 pr-3 text-right font-mono">{bar.vwap.toFixed(2)}</td>
                                                <td
                                                    className={`py-1.5 pr-3 text-right font-mono ${
                                                        bar.vwap_dist_pct >= 0
                                                            ? 'text-green-600 dark:text-green-400'
                                                            : 'text-red-600 dark:text-red-400'
                                                    }`}
                                                >
                                                    {bar.vwap_dist_pct >= 0 ? '+' : ''}
                                                    {bar.vwap_dist_pct.toFixed(3)}%
                                                </td>
                                                <td className="py-1.5 pr-3 text-right font-mono text-muted-foreground">
                                                    {bar.below_high_pct !== null ? `${bar.below_high_pct.toFixed(2)}%` : '—'}
                                                </td>
                                                <td className="py-1.5 text-center">
                                                    {bar.gate_would_block ? (
                                                        <Badge variant="destructive" className="text-xs">
                                                            {bar.block_reason || 'Blocked'}
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="secondary" className="text-xs bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                                            Pass
                                                        </Badge>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Gate Configuration Summary */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <Activity className="h-5 w-5" />
                                <CardTitle>VWAP Gate Configuration Reference</CardTitle>
                            </div>
                            <CardDescription>
                                Current settings as shown on the Trading Settings page. Active pipeline overrides are highlighted.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <div>
                                    <span className="text-xs font-medium text-muted-foreground">Benchmark Symbol</span>
                                    <p className="font-mono text-lg font-semibold">{benchmarkSymbol}</p>
                                </div>
                                <div>
                                    <span className="text-xs font-medium text-muted-foreground">Gate Enabled</span>
                                    <p>
                                        <Badge variant={gateEnabled ? 'destructive' : 'secondary'}>
                                            {gateEnabled ? 'Yes' : 'No'}
                                        </Badge>
                                    </p>
                                </div>
                                <div>
                                    <span className="text-xs font-medium text-muted-foreground">Max % Below High</span>
                                    <p className="font-mono text-lg font-semibold">
                                        {maxPctBelowHigh !== null ? `${maxPctBelowHigh}%` : 'Disabled'}
                                    </p>
                                </div>
                            </div>
                            <div className="mt-4">
                                <span className="text-xs font-medium text-muted-foreground">Pipeline Overrides</span>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {Object.entries(pipelineOverrides).map(([p, val]) => (
                                        <Badge
                                            key={p}
                                            variant={val === true ? 'destructive' : val === false ? 'secondary' : 'outline'}
                                            className="text-xs"
                                        >
                                            {p.toUpperCase()}: {val === true ? 'ON' : val === false ? 'OFF' : 'Default'}
                                        </Badge>
                                    ))}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </AppLayout>
        </>
    );
}
