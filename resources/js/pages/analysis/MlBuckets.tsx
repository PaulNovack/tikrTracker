import AppLayout from '@/layouts/app-layout';
import Heading from '@/components/heading';
import { Head, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { BarChart3, Calendar, Filter, Layers3, TrendingDown, TrendingUp } from 'lucide-react';
import { useState } from 'react';

interface BucketRow {
    bucket_start: number;
    bucket_label: string;
    trade_count: number;
    winning_trades: number;
    losing_trades: number;
    win_rate: number;
    total_pnl: number;
    avg_pnl: number;
}

interface PipelineBreakdown {
    pipeline_run: string;
    trade_count: number;
    winning_trades: number;
    win_rate: number;
    net_pnl: number;
    avg_pnl: number;
    buckets: BucketRow[];
}

interface Summary {
    start_date: string | null;
    first_date: string | null;
    last_date: string | null;
    total_alerts: number;
    total_pipelines: number;
    winning_trades: number;
    win_rate: number;
    net_pnl: number;
    avg_pnl: number;
}

interface Filters {
    start_date: string | null;
}

interface MlBucketsProps {
    summary: Summary;
    pipelineBreakdowns: PipelineBreakdown[];
    filters: Filters;
}

function formatPercent(value: number): string {
    const prefix = value >= 0 ? '+' : '';

    return `${prefix}${value.toFixed(1)}%`;
}

function getPercentColorClass(value: number): string {
    if (value < 0.5) {
        return 'text-red-600';
    }

    if (value > 1.5) {
        return 'text-green-600';
    }

    if (value > 1) {
        return 'text-green-400';
    }

    return 'text-orange-500';
}

function PercentValue({ value }: { value: number }) {
    return <span className={`${getPercentColorClass(value)} font-mono`}>{formatPercent(value)}</span>;
}

function shouldHighlightRow(bucket: BucketRow): boolean {
    return bucket.win_rate > 65 && bucket.avg_pnl > 1;
}

export default function MlBuckets({ summary, pipelineBreakdowns, filters }: MlBucketsProps) {
    const [startDate, setStartDate] = useState(filters.start_date || '');

    const applyFilter = () => {
        router.get('/analysis/ml-buckets', { start_date: startDate || undefined }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const clearFilter = () => {
        setStartDate('');
        router.get('/analysis/ml-buckets', {}, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    return (
        <>
            <Head title="ML Buckets - Analysis" />
            <AppLayout>
                <div className="flex flex-col gap-6 p-6">
                    <Heading
                        title="ML Buckets"
                        description="Trade alerts grouped by pipeline and 5% ML probability buckets. Use the start date filter to view alerts from a specific day onward, or leave it blank to show all data."
                    />

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Total Alerts</CardTitle>
                                <BarChart3 className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{summary.total_alerts.toLocaleString()}</div>
                                <p className="text-xs text-muted-foreground">Alerts with ML and P/L data</p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Pipelines</CardTitle>
                                <Layers3 className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{summary.total_pipelines.toLocaleString()}</div>
                                <p className="text-xs text-muted-foreground">Distinct pipeline runs</p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Win Rate</CardTitle>
                                <TrendingUp className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className={`text-2xl font-bold ${getPercentColorClass(summary.win_rate)}`}>{summary.win_rate.toFixed(1)}%</div>
                                <p className="text-xs text-muted-foreground">Winning alerts across all buckets</p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Avg P&amp;L</CardTitle>
                                <TrendingDown className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold"><PercentValue value={summary.avg_pnl} /></div>
                                <p className="text-xs text-muted-foreground">Average P/L per alert</p>
                            </CardContent>
                        </Card>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Filter className="h-4 w-4" />
                                Filter
                            </CardTitle>
                            <CardDescription>
                                Leave the start date blank to include all trade alerts.
                                {summary.first_date && summary.last_date && (
                                    <span className="block">
                                        Current range: <span className="font-medium">{summary.first_date}</span> to <span className="font-medium">{summary.last_date}</span>
                                    </span>
                                )}
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
                                <Button onClick={applyFilter}>Apply Filter</Button>
                                {filters.start_date && (
                                    <Button variant="outline" onClick={clearFilter}>
                                        Clear Filter
                                    </Button>
                                )}
                            </div>
                            {filters.start_date && (
                                <p className="mt-2 text-xs text-muted-foreground">
                                    Showing trade alerts from <span className="font-medium">{filters.start_date}</span> onward.
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    {pipelineBreakdowns.length === 0 ? (
                        <Card>
                            <CardContent className="py-12 text-center text-muted-foreground">
                                No trade alerts found for the selected date range.
                            </CardContent>
                        </Card>
                    ) : (
                        pipelineBreakdowns.map((pipeline) => (
                            <Card key={pipeline.pipeline_run}>
                                <CardHeader>
                                    <div className="flex flex-wrap items-start justify-between gap-4">
                                        <div>
                                            <CardTitle className="flex items-center gap-2">
                                                <Calendar className="h-4 w-4" />
                                                Pipeline {pipeline.pipeline_run}
                                            </CardTitle>
                                            <CardDescription>
                                                {pipeline.trade_count.toLocaleString()} alerts across all 5% ML buckets.
                                            </CardDescription>
                                        </div>
                                        <div className="grid grid-cols-2 gap-3 text-right sm:grid-cols-4">
                                            <div>
                                                <div className="text-xs text-muted-foreground">Alerts</div>
                                                <div className="font-medium">{pipeline.trade_count.toLocaleString()}</div>
                                            </div>
                                            <div>
                                                <div className="text-xs text-muted-foreground">Win Rate</div>
                                                <div className={`font-medium ${getPercentColorClass(pipeline.win_rate)}`}>{pipeline.win_rate.toFixed(1)}%</div>
                                            </div>
                                            <div>
                                                <div className="text-xs text-muted-foreground">Avg P&amp;L</div>
                                                <div className="font-medium"><PercentValue value={pipeline.avg_pnl} /></div>
                                            </div>
                                            <div>
                                                <div className="text-xs text-muted-foreground">Net P&amp;L</div>
                                                <div className="font-medium"><PercentValue value={pipeline.net_pnl} /></div>
                                            </div>
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <div className="overflow-x-auto">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>Bucket</TableHead>
                                                    <TableHead className="text-right">Trades</TableHead>
                                                    <TableHead className="text-right">Wins</TableHead>
                                                    <TableHead className="text-right">Losses</TableHead>
                                                    <TableHead className="text-right">Win Rate</TableHead>
                                                    <TableHead className="text-right">Avg P&amp;L</TableHead>
                                                    <TableHead className="text-right">Total P&amp;L</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {pipeline.buckets.map((bucket) => (
                                                    <TableRow
                                                        key={`${pipeline.pipeline_run}-${bucket.bucket_start}`}
                                                        className={shouldHighlightRow(bucket) ? 'bg-green-50/80 dark:bg-green-950/20' : undefined}
                                                    >
                                                        <TableCell className="font-mono font-medium">{bucket.bucket_label}</TableCell>
                                                        <TableCell className="text-right font-mono">{bucket.trade_count.toLocaleString()}</TableCell>
                                                        <TableCell className="text-right font-mono text-green-600">{bucket.winning_trades.toLocaleString()}</TableCell>
                                                        <TableCell className="text-right font-mono text-red-600">{bucket.losing_trades.toLocaleString()}</TableCell>
                                                        <TableCell className={`text-right font-mono ${getPercentColorClass(bucket.win_rate)}`}>{bucket.trade_count > 0 ? `${bucket.win_rate.toFixed(1)}%` : '—'}</TableCell>
                                                        <TableCell className="text-right font-mono">
                                                            {bucket.trade_count > 0 ? <PercentValue value={bucket.avg_pnl} /> : '—'}
                                                        </TableCell>
                                                        <TableCell className="text-right font-mono">
                                                            {bucket.trade_count > 0 ? <PercentValue value={bucket.total_pnl} /> : '—'}
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </div>
                                </CardContent>
                            </Card>
                        ))
                    )}
                </div>
            </AppLayout>
        </>
    );
}