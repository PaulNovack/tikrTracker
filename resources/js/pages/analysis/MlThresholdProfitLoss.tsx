import AppLayout from '@/layouts/app-layout';
import Heading from '@/components/heading';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { BarChart3, CalendarDays, ChevronDown, ChevronRight, Filter, Layers3, TrendingDown, TrendingUp } from 'lucide-react';
import { Fragment, useState } from 'react';

interface TradeRow {
    trade_id: string;
    symbol: string;
    pipeline_run: string;
    ml_pct: number;
    buy_filled_at: string;
    sell_filled_at: string;
    actual_qty: number;
    trade_dollar_amount: number;
    actual_fill: number;
    actual_exit_fill: number;
    actual_pnl_dollar: number;
    actual_pnl_percent: number;
    mode: 'live' | 'paper';
}

interface BucketBreakdown {
    bucket_start: number;
    bucket_label: string;
    trade_count: number;
    winning_trades: number;
    losing_trades: number;
    win_rate: number;
    total_pnl: number;
    avg_pnl: number;
    trades: TradeRow[];
}

interface PipelineBreakdown {
    pipeline_run: string;
    trade_count: number;
    winning_trades: number;
    win_rate: number;
    net_pnl: number;
    avg_pnl: number;
    buckets: BucketBreakdown[];
}

interface Summary {
    days: number;
    start_date: string;
    end_date: string;
    total_trades: number;
    winning_trades: number;
    losing_trades: number;
    win_rate: number;
    net_pnl: number;
    avg_pnl: number;
}

interface Filters {
    days: number;
    group_by: 'combined' | 'pipeline';
    mode: 'live' | 'paper' | 'all';
    min_percent: number;
    start_date: string;
    end_date: string;
    active_time_slots_only?: boolean;
}

interface MlThresholdProfitLossProps {
    summary: Summary;
    combinedBreakdown: BucketBreakdown[];
    pipelineBreakdowns: PipelineBreakdown[];
    filters: Filters;
    pipelineMlThresholds?: Record<string, number>;
}

const dayOptionsExpanded = Array.from({ length: 180 }, (_, index) => index + 1);
const minPercentOptions = Array.from({ length: 21 }, (_, index) => index * 5);
const groupOptions: Array<{ value: 'combined' | 'pipeline'; label: string }> = [
    { value: 'combined', label: 'Combined' },
    { value: 'pipeline', label: 'By Pipeline' },
];

export default function MlThresholdProfitLoss({ summary, combinedBreakdown, pipelineBreakdowns, filters, pipelineMlThresholds = {} }: MlThresholdProfitLossProps) {
    const [days, setDays] = useState(filters.days.toString());
    const [groupBy, setGroupBy] = useState<'combined' | 'pipeline'>(filters.group_by);
    const [mode, setMode] = useState<'live' | 'paper' | 'all'>(filters.mode);
    const [minPercent, setMinPercent] = useState(
        filters.min_percent === -1 ? '-1' : filters.min_percent.toString(),
    );
    const [activeTimeSlotsOnly, setActiveTimeSlotsOnly] = useState(filters.active_time_slots_only ?? false);
    const [expandedRows, setExpandedRows] = useState<Set<string>>(new Set());

    const toggleRow = (key: string) => {
        setExpandedRows((current) => {
            const next = new Set(current);

            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }

            return next;
        });
    };

    const applyFilters = () => {
        router.get(
            '/analysis/ml-threshold-profit-loss',
            {
                days: Number(days),
                group_by: groupBy,
                mode,
                min_percent: Number(minPercent),
                active_time_slots_only: activeTimeSlotsOnly ? 1 : 0,
            },
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    };

    const formatCurrency = (value: number) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(value);
    };

    const formatDateTime = (value: string) => {
        const date = new Date(value.replace(' ', 'T'));

        return new Intl.DateTimeFormat('en-US', {
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }).format(date);
    };

    const visibleCombinedBreakdown = combinedBreakdown.filter((bucket) => bucket.trade_count > 0);
    const visiblePipelineBreakdowns = pipelineBreakdowns
        .map((pipeline) => ({
            ...pipeline,
            buckets: pipeline.buckets.filter((bucket) => bucket.trade_count > 0),
        }))
        .filter((pipeline) => pipeline.buckets.length > 0);

    const renderTradeDetails = (trades: TradeRow[]) => (
        <div className="border-t bg-muted/20 px-4 py-4">
            <div className="overflow-x-auto">
                <Table className="text-sm">
                    <TableHeader>
                        <TableRow>
                            <TableHead className="text-sm">Symbol</TableHead>
                            <TableHead className="text-sm">Pipeline</TableHead>
                            <TableHead className="text-sm">Mode</TableHead>
                            <TableHead className="text-right text-sm">ML %</TableHead>
                            <TableHead className="text-right text-sm">Qty</TableHead>
                            <TableHead className="text-right text-sm">Trade $</TableHead>
                            <TableHead className="text-right text-sm">Buy</TableHead>
                            <TableHead className="text-right text-sm">Sell</TableHead>
                            <TableHead className="text-right text-sm">P/L</TableHead>
                            <TableHead className="text-right text-sm">P/L %</TableHead>
                            <TableHead className="text-sm">Buy Time</TableHead>
                            <TableHead className="text-sm">Sell Time</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {trades.map((trade) => (
                            <TableRow key={trade.trade_id}>
                                <TableCell className="text-sm font-medium">{trade.symbol}</TableCell>
                                <TableCell className="text-sm font-mono">{trade.pipeline_run}</TableCell>
                                <TableCell>
                                    <Badge
                                        variant="outline"
                                        className={`capitalize ${
                                            trade.mode === 'live'
                                                ? 'border-green-200 bg-green-100 text-green-700 dark:border-green-900 dark:bg-green-950/30 dark:text-green-300'
                                                : 'border-orange-200 bg-orange-100 text-orange-700 dark:border-orange-900 dark:bg-orange-950/30 dark:text-orange-300'
                                        }`}
                                    >
                                        {trade.mode}
                                    </Badge>
                                </TableCell>
                                <TableCell className="text-right text-sm font-mono">{trade.ml_pct.toFixed(1)}%</TableCell>
                                <TableCell className="text-right text-sm font-mono">{trade.actual_qty.toFixed(2)}</TableCell>
                                <TableCell className="text-right text-sm font-mono">{formatCurrency(trade.trade_dollar_amount)}</TableCell>
                                <TableCell className="text-right text-sm font-mono">{formatCurrency(trade.actual_fill)}</TableCell>
                                <TableCell className="text-right text-sm font-mono">{formatCurrency(trade.actual_exit_fill)}</TableCell>
                                <TableCell className={`text-right text-sm font-mono ${trade.actual_pnl_dollar >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                    {formatCurrency(trade.actual_pnl_dollar)}
                                </TableCell>
                                <TableCell className={`text-right text-sm font-mono ${trade.actual_pnl_percent >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                    {trade.actual_pnl_percent >= 0 ? '+' : ''}{trade.actual_pnl_percent.toFixed(2)}%
                                </TableCell>
                                <TableCell className="text-sm font-mono text-muted-foreground">{formatDateTime(trade.buy_filled_at)}</TableCell>
                                <TableCell className="text-sm font-mono text-muted-foreground">{formatDateTime(trade.sell_filled_at)}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </div>
    );

    const renderBucketRow = (bucket: BucketBreakdown, rowKey: string, columnCount: number) => {
        const isExpanded = expandedRows.has(rowKey);

        return (
            <Fragment key={rowKey}>
                <TableRow
                    onClick={() => toggleRow(rowKey)}
                    className="cursor-pointer hover:bg-muted/50"
                >
                    <TableCell className="font-medium">
                        <div className="flex items-center gap-2">
                            {isExpanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                            {bucket.bucket_label}
                        </div>
                    </TableCell>
                    <TableCell className="text-right font-mono">{bucket.trade_count.toLocaleString()}</TableCell>
                    <TableCell className="text-right font-mono text-green-600">{bucket.winning_trades.toLocaleString()}</TableCell>
                    <TableCell className="text-right font-mono text-red-600">{bucket.losing_trades.toLocaleString()}</TableCell>
                    <TableCell className="text-right font-mono">{bucket.win_rate.toFixed(1)}%</TableCell>
                    <TableCell className={`text-right font-mono ${bucket.total_pnl >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                        {formatCurrency(bucket.total_pnl)}
                    </TableCell>
                    <TableCell className={`text-right font-mono ${bucket.avg_pnl >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                        {formatCurrency(bucket.avg_pnl)}
                    </TableCell>
                </TableRow>
                {isExpanded && (
                    <TableRow key={`${rowKey}-details`}>
                        <TableCell colSpan={columnCount} className="p-0">
                            {renderTradeDetails(bucket.trades)}
                        </TableCell>
                    </TableRow>
                )}
            </Fragment>
        );
    };

    return (
        <>
            <Head title="ML Threshold Profit/Loss - Analysis" />
            <AppLayout>
                <div className="flex flex-col gap-6 p-6">
                    <Heading
                        title="ML Threshold Profit/Loss"
                        description={`Closed Alpaca trades grouped into 5% ML bins over the last ${summary.days} days. Profit/loss is based on actual buy-to-sell fills matched to the originating trade alert.`}
                    />

                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <Filter className="h-5 w-5" />
                                <CardTitle>Filters</CardTitle>
                            </div>
                            <CardDescription>
                                Change the lookback window, switch between live/paper/all data, or view a combined or pipeline-specific breakdown.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-5">
                                <div className="space-y-2">
                                    <label className="text-sm font-medium">Days Back</label>
                                    <Select value={days} onValueChange={setDays}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {dayOptionsExpanded.map((value) => (
                                                <SelectItem key={value} value={value.toString()}>
                                                    {value} Days
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-2">
                                    <label className="text-sm font-medium">Group By</label>
                                    <Select value={groupBy} onValueChange={(value) => setGroupBy(value as 'combined' | 'pipeline')}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {groupOptions.map((option) => (
                                                <SelectItem key={option.value} value={option.value}>
                                                    {option.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div>
                                    <label className="block text-sm font-medium mb-1">Mode</label>
                                    <div className="flex items-center rounded-md border overflow-hidden">
                                        {(['live', 'paper', 'all'] as const).map((value) => (
                                            <button
                                                key={value}
                                                type="button"
                                                onClick={() => setMode(value)}
                                                className={`px-3 py-2 text-sm capitalize transition-colors ${
                                                    mode === value
                                                        ? 'bg-primary text-primary-foreground'
                                                        : 'bg-background hover:bg-muted'
                                                }`}
                                            >
                                                {value}
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <label className="text-sm font-medium">Min ML %</label>
                                    <Select value={minPercent} onValueChange={(val) => {
                                        setMinPercent(val);
                                        if (val === '-1') {
                                            router.get('/analysis/ml-threshold-profit-loss', {
                                                days: Number(days),
                                                group_by: groupBy,
                                                mode,
                                                min_percent: -1,
                                                active_time_slots_only: activeTimeSlotsOnly ? 1 : 0,
                                            }, { preserveScroll: true, preserveState: true });
                                        }
                                    }}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="-1">.env (per pipeline threshold)</SelectItem>
                                            {minPercentOptions.map((value) => (
                                                <SelectItem key={value} value={value.toString()}>
                                                    {value}%+
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="flex items-end">
                                    <Button onClick={applyFilters} className="w-full gap-2">
                                        <BarChart3 className="h-4 w-4" />
                                        Apply Filters
                                    </Button>
                                </div>
                            </div>

                            <div className="flex items-center gap-3 pt-3 border-t mt-3">
                                <label className="text-sm font-medium cursor-pointer select-none" htmlFor="active-time-slots-toggle">
                                    Only Active Time Slots
                                </label>
                                <button
                                    id="active-time-slots-toggle"
                                    type="button"
                                    role="switch"
                                    aria-checked={activeTimeSlotsOnly}
                                    onClick={() => setActiveTimeSlotsOnly(!activeTimeSlotsOnly)}
                                    className={`relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors ${
                                        activeTimeSlotsOnly ? 'bg-primary' : 'bg-muted'
                                    }`}
                                >
                                    <span
                                        className={`pointer-events-none block h-4 w-4 rounded-full bg-white shadow-sm transition-transform ${
                                            activeTimeSlotsOnly ? 'translate-x-4' : 'translate-x-0'
                                        }`}
                                    />
                                </button>
                                <p className="text-xs text-muted-foreground">
                                    Ignore buys outside of your configured active time slots
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Net P/L</CardTitle>
                                <TrendingUp className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className={`text-2xl font-bold ${summary.net_pnl >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                    {formatCurrency(summary.net_pnl)}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {summary.start_date} to {summary.end_date}
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Trades</CardTitle>
                                <Layers3 className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{summary.total_trades.toLocaleString()}</div>
                                <p className="text-xs text-muted-foreground">
                                    {summary.winning_trades} winners, {summary.losing_trades} losers
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Win Rate</CardTitle>
                                <TrendingDown className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{summary.win_rate.toFixed(1)}%</div>
                                <p className="text-xs text-muted-foreground">Across matched closed trades</p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Avg / Trade</CardTitle>
                                <CalendarDays className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className={`text-2xl font-bold ${summary.avg_pnl >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                    {formatCurrency(summary.avg_pnl)}
                                </div>
                                <p className="text-xs text-muted-foreground">Average realized P/L per trade</p>
                            </CardContent>
                        </Card>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {groupBy === 'combined' ? 'Combined ML Buckets' : 'Pipeline ML Buckets'}
                            </CardTitle>
                            <CardDescription>
                                5% ML win probability buckets. Positive rows are shown in green and negative rows in red.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {groupBy === 'combined' ? (
                                visibleCombinedBreakdown.length === 0 ? (
                                    <div className="py-10 text-center text-sm text-muted-foreground">No matched trades found for this window.</div>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>ML Bucket</TableHead>
                                                    <TableHead className="text-right">Trades</TableHead>
                                                    <TableHead className="text-right">Wins</TableHead>
                                                    <TableHead className="text-right">Losses</TableHead>
                                                    <TableHead className="text-right">Win Rate</TableHead>
                                                    <TableHead className="text-right">Total P/L</TableHead>
                                                    <TableHead className="text-right">Avg P/L</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {visibleCombinedBreakdown.map((bucket) => renderBucketRow(bucket, `combined-${bucket.bucket_start}`, 7))}
                                            </TableBody>
                                        </Table>
                                    </div>
                                )
                            ) : visiblePipelineBreakdowns.length === 0 ? (
                                <div className="py-10 text-center text-sm text-muted-foreground">No matched trades found for this window.</div>
                            ) : (
                                <div className="space-y-6">
                                    {visiblePipelineBreakdowns.map((pipeline) => (
                                        <div key={pipeline.pipeline_run} className="rounded-lg border p-4">
                                            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                                                <div>
                                                    <div className="flex items-center gap-2">
                                                        <h3 className="text-lg font-semibold">Pipeline {pipeline.pipeline_run}</h3>
                                                        <Badge variant="secondary">{pipeline.trade_count} trades</Badge>
                                                    </div>
                                                    <p className="text-sm text-muted-foreground">
                                                        {pipeline.winning_trades} wins, {pipeline.win_rate.toFixed(1)}% win rate, average {formatCurrency(pipeline.avg_pnl)} per trade.
                                                    </p>
                                                </div>
                                                <div className={`text-2xl font-bold ${pipeline.net_pnl >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                                    {formatCurrency(pipeline.net_pnl)}
                                                </div>
                                            </div>

                                            <div className="overflow-x-auto">
                                                <Table>
                                                    <TableHeader>
                                                        <TableRow>
                                                            <TableHead>ML Bucket</TableHead>
                                                            <TableHead className="text-right">Trades</TableHead>
                                                            <TableHead className="text-right">Wins</TableHead>
                                                            <TableHead className="text-right">Losses</TableHead>
                                                            <TableHead className="text-right">Win Rate</TableHead>
                                                            <TableHead className="text-right">Total P/L</TableHead>
                                                            <TableHead className="text-right">Avg P/L</TableHead>
                                                        </TableRow>
                                                    </TableHeader>
                                                    <TableBody>
                                                        {pipeline.buckets.map((bucket) => renderBucketRow(bucket, `pipeline-${pipeline.pipeline_run}-${bucket.bucket_start}`, 7))}
                                                    </TableBody>
                                                </Table>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </AppLayout>
        </>
    );
}