import AppLayout from '@/layouts/app-layout';
import Heading from '@/components/heading';
import { Head, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Activity, Calendar, CalendarDays, ChevronDown, ChevronRight, Filter, Layers3, TrendingUp } from 'lucide-react';
import { Fragment, useState } from 'react';

interface DailyCount {
    date: string;
    trade_count: number;
}

interface DailyBreakdown extends DailyCount {
    winning_trades: number;
    losing_trades: number;
    invested_10k: number;
    total_profit_10k: number;
    pnl_percent: number;
    trades: TradeDetail[];
}

interface TradeDetail {
    symbol: string;
    pipeline_run: string;
    entry_type: string;
    signal_type: string;
    entry_ts_est: string | null;
    exit_ts_est: string | null;
    ml_win_prob: number;
    ml_threshold: number;
    pnl_percent: number | null;
    profit_10k: number | null;
}

interface Summary {
    start_date: string;
    end_date: string;
    total_trades: number;
    total_invested_10k: number;
    total_profit_10k: number;
    avg_profit_percent: number;
    total_wins: number;
    total_losses: number;
    win_rate: number;
    active_days: number;
    avg_trades_per_day: number;
    peak_day: string | null;
    peak_day_trades: number;
}

interface TradesPerDayProps {
    summary: Summary;
    dailyBreakdowns: DailyBreakdown[];
    dailyCounts: DailyCount[];
    pipelineThresholds: Record<string, number>;
    filters: {
        start_date: string;
        end_date: string;
    };
}

export default function TradesPerDay({ summary, dailyBreakdowns, pipelineThresholds, filters }: TradesPerDayProps) {
    const [startDate, setStartDate] = useState(filters.start_date);
    const [endDate, setEndDate] = useState(filters.end_date);
    const [expandedDate, setExpandedDate] = useState<string | null>(null);

    const normalizeNumber = (value: unknown): number => {
        const numericValue = typeof value === 'number' ? value : Number(value);

        return Number.isFinite(numericValue) ? numericValue : 0;
    };

    const totalInvested10k = normalizeNumber(summary.total_invested_10k);
    const totalProfit10k = normalizeNumber(summary.total_profit_10k);
    const avgProfitPercent = normalizeNumber(summary.avg_profit_percent);

    const applyFilter = () => {
        router.get('/analysis/trades-per-day', {
            start_date: startDate || undefined,
            end_date: endDate || undefined,
        }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const resetFilter = () => {
        setStartDate(filters.start_date);
        setEndDate(filters.end_date);
        router.get('/analysis/trades-per-day', {}, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const thresholdEntries = Object.entries(pipelineThresholds).sort(([left], [right]) => left.localeCompare(right));

    const formatTimeOnly = (value: string | null) => {
        if (!value) {
            return '—';
        }

        const timePart = value.includes(' ') ? value.split(' ')[1] : value;

        return timePart?.slice(0, 8) ?? value;
    };

    const formatPercent = (value: number) => `${(value * 100).toFixed(1)}%`;

    const formatSignedPercent = (value: number) => `${value >= 0 ? '+' : ''}${value.toFixed(2)}%`;

    const formatCurrency = (value: number) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(value);
    };

    const toggleDay = (date: string) => {
        setExpandedDate((current) => (current === date ? null : date));
    };

    return (
        <>
            <Head title="Trades Per Day - Analysis" />
            <AppLayout>
                <div className="flex flex-col gap-6 p-6">
                    <Heading
                        title="Trades Per Day"
                        description="Trade alerts grouped by day after applying the configured ML thresholds. The default view shows the last 30 days."
                    />

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Total Trades</CardTitle>
                                <Activity className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{summary.total_trades.toLocaleString()}</div>
                                <p className="text-xs text-muted-foreground">Alerts that meet the active ML thresholds</p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Days With Trades</CardTitle>
                                <Calendar className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{summary.active_days.toLocaleString()}</div>
                                <p className="text-xs text-muted-foreground">Trading days with at least one qualifying alert</p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Invested at $10K</CardTitle>
                                <TrendingUp className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{formatCurrency(totalInvested10k)}</div>
                                <p className="text-xs text-muted-foreground">{summary.total_trades.toLocaleString()} trades at $10,000 each</p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Total P/L at $10K</CardTitle>
                                <Layers3 className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className={`text-2xl font-bold ${totalProfit10k >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                    {formatCurrency(totalProfit10k)}
                                </div>
                                <p className="text-xs text-muted-foreground">Net across all qualifying trades</p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Avg P/L per Trade</CardTitle>
                                <Layers3 className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className={`text-2xl font-bold ${avgProfitPercent >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                    {formatSignedPercent(avgProfitPercent)}
                                </div>
                                <p className="text-xs text-muted-foreground">Average percent P/L across all deduped trades</p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Win Rate</CardTitle>
                                <TrendingUp className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{summary.win_rate.toFixed(1)}%</div>
                                <p className="text-xs text-muted-foreground">
                                    {summary.total_wins.toLocaleString()} wins / {summary.total_losses.toLocaleString()} losses
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Average / Active Day</CardTitle>
                                <TrendingUp className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{summary.avg_trades_per_day.toFixed(2)}</div>
                                <p className="text-xs text-muted-foreground">Average qualifying trades per trading day</p>
                            </CardContent>
                        </Card>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Filter className="h-4 w-4" />
                                Date Filter
                            </CardTitle>
                            <CardDescription>
                                Adjust the range to inspect daily trade counts after ML-threshold filtering.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-wrap items-end gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="start-date">Start Date</Label>
                                    <Input
                                        id="start-date"
                                        type="date"
                                        value={startDate}
                                        onChange={(event) => setStartDate(event.target.value)}
                                        className="w-48"
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="end-date">End Date</Label>
                                    <Input
                                        id="end-date"
                                        type="date"
                                        value={endDate}
                                        onChange={(event) => setEndDate(event.target.value)}
                                        className="w-48"
                                    />
                                </div>
                                <Button onClick={applyFilter}>Apply Filter</Button>
                                <Button variant="outline" onClick={resetFilter}>Reset to Last 30 Days</Button>
                            </div>
                            <p className="mt-2 text-xs text-muted-foreground">
                                Current range: <span className="font-medium">{summary.start_date}</span> to <span className="font-medium">{summary.end_date}</span>
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <CalendarDays className="h-4 w-4" />
                                ML Thresholds in Effect
                            </CardTitle>
                            <CardDescription>
                                The page counts only alerts whose ML win probability meets the configured threshold for each pipeline.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {thresholdEntries.length === 0 ? (
                                <div className="text-sm text-muted-foreground">No pipeline thresholds found.</div>
                            ) : (
                                <div className="flex flex-wrap gap-2">
                                    {thresholdEntries.map(([pipeline, threshold]) => (
                                        <div key={pipeline} className="rounded-lg border bg-muted/40 px-3 py-2 text-sm">
                                            <span className="font-mono font-medium">{pipeline}</span>
                                            <span className="ml-2 text-muted-foreground">{(threshold * 100).toFixed(1)}%</span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Trades By Day</CardTitle>
                            <CardDescription>
                                Daily counts of trade alerts that pass the configured ML threshold filter.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {dailyBreakdowns.length === 0 ? (
                                <div className="py-8 text-center text-muted-foreground">No qualifying trade alerts found for the selected range.</div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Date</TableHead>
                                                <TableHead className="text-right">Trades</TableHead>
                                                <TableHead className="text-right">Wins</TableHead>
                                                <TableHead className="text-right">Losses</TableHead>
                                                <TableHead className="text-right">Win %</TableHead>
                                                <TableHead className="text-right">Invested</TableHead>
                                                <TableHead className="text-right">P/L at $10K</TableHead>
                                                <TableHead className="text-right">P/L %</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {dailyBreakdowns.map((row) => {
                                                const isExpanded = expandedDate === row.date;

                                                return (
                                                    <Fragment key={row.date}>
                                                        <TableRow
                                                            className="cursor-pointer hover:bg-muted/50"
                                                            onClick={() => toggleDay(row.date)}
                                                            tabIndex={0}
                                                            onKeyDown={(event) => {
                                                                if (event.key === 'Enter' || event.key === ' ') {
                                                                    event.preventDefault();
                                                                    toggleDay(row.date);
                                                                }
                                                            }}
                                                        >
                                                            <TableCell className="font-mono font-medium">
                                                                <div className="flex items-center gap-2">
                                                                    {isExpanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                                                                    <span>{row.date}</span>
                                                                </div>
                                                            </TableCell>
                                                            <TableCell className="text-right font-mono">{row.trade_count.toLocaleString()}</TableCell>
                                                            <TableCell className="text-right font-mono text-green-600">{row.winning_trades.toLocaleString()}</TableCell>
                                                            <TableCell className="text-right font-mono text-red-600">{row.losing_trades.toLocaleString()}</TableCell>
                                                            <TableCell className="text-right font-mono">
                                                                {row.winning_trades + row.losing_trades > 0
                                                                    ? `${((row.winning_trades / (row.winning_trades + row.losing_trades)) * 100).toFixed(1)}%`
                                                                    : '0.0%'}
                                                            </TableCell>
                                                            <TableCell className="text-right font-mono">{formatCurrency(row.invested_10k)}</TableCell>
                                                            <TableCell className={`text-right font-mono ${normalizeNumber(row.total_profit_10k) >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                                                {formatCurrency(normalizeNumber(row.total_profit_10k))}
                                                            </TableCell>
                                                            <TableCell className={`text-right font-mono ${normalizeNumber(row.pnl_percent) >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                                                {formatSignedPercent(normalizeNumber(row.pnl_percent))}
                                                            </TableCell>
                                                        </TableRow>
                                                        {isExpanded && (
                                                            <TableRow>
                                                                <TableCell colSpan={8} className="bg-muted/30 p-0">
                                                                    <div className="border-t px-4 py-3">
                                                                        <div className="mb-3 text-sm font-medium">Trades for {row.date}</div>
                                                                        <div className="overflow-x-auto">
                                                                            <Table>
                                                                                <TableHeader>
                                                                                    <TableRow>
                                                                                        <TableHead>Symbol</TableHead>
                                                                                        <TableHead>Pipeline</TableHead>
                                                                                        <TableHead>Entry Type</TableHead>
                                                                                        <TableHead className="text-right">ML %</TableHead>
                                                                                        <TableHead className="text-right">P/L %</TableHead>
                                                                                        <TableHead className="text-right">P/L at $10K</TableHead>
                                                                                        <TableHead className="text-right">Threshold</TableHead>
                                                                                        <TableHead>Entry Time</TableHead>
                                                                                        <TableHead>Exit Time</TableHead>
                                                                                    </TableRow>
                                                                                </TableHeader>
                                                                                <TableBody>
                                                                                    {row.trades.map((trade) => (
                                                                                        <TableRow key={`${row.date}-${trade.symbol}-${trade.entry_ts_est ?? trade.entry_type}`} className="bg-background/80">
                                                                                            <TableCell className="font-mono font-medium">{trade.symbol}</TableCell>
                                                                                            <TableCell className="font-mono">{trade.pipeline_run}</TableCell>
                                                                                            <TableCell className="text-sm text-muted-foreground">{trade.entry_type || trade.signal_type || '—'}</TableCell>
                                                                                            <TableCell className="text-right font-mono">{formatPercent(trade.ml_win_prob)}</TableCell>
                                                                                            <TableCell className={`text-right font-mono ${trade.pnl_percent === null ? 'text-muted-foreground' : trade.pnl_percent >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                                                                                {trade.pnl_percent === null ? '—' : formatSignedPercent(trade.pnl_percent)}
                                                                                            </TableCell>
                                                                                            <TableCell className={`text-right font-mono ${trade.profit_10k === null ? 'text-muted-foreground' : trade.profit_10k >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                                                                                {trade.profit_10k === null ? '—' : formatCurrency(trade.profit_10k)}
                                                                                            </TableCell>
                                                                                            <TableCell className="text-right font-mono text-muted-foreground">{formatPercent(trade.ml_threshold)}</TableCell>
                                                                                            <TableCell className="font-mono text-sm text-muted-foreground">{formatTimeOnly(trade.entry_ts_est)}</TableCell>
                                                                                            <TableCell className="font-mono text-sm text-muted-foreground">{formatTimeOnly(trade.exit_ts_est)}</TableCell>
                                                                                        </TableRow>
                                                                                    ))}
                                                                                </TableBody>
                                                                            </Table>
                                                                        </div>
                                                                    </div>
                                                                </TableCell>
                                                            </TableRow>
                                                        )}
                                                    </Fragment>
                                                );
                                            })}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </AppLayout>
        </>
    );
}