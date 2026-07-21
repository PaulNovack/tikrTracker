import { Head, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Activity, BarChart3, Calendar, Hash } from 'lucide-react';
import { useState } from 'react';

interface PipelineCount {
    pipeline_run: string;
    total_alerts: number;
    first_date: string;
    last_date: string;
    trading_days: number;
    unique_symbols: number;
}

interface PipelineCountsProps {
    counts: PipelineCount[];
    filters: {
        date: string | null;
    };
}

export default function PipelineCounts({ counts, filters }: PipelineCountsProps) {
    const [dateValue, setDateValue] = useState(filters.date || '');
    const totalAlerts = counts.reduce((sum, c) => sum + c.total_alerts, 0);
    const totalPipelines = counts.length;
    const totalUniqueDays = counts.reduce((max, c) => Math.max(max, c.trading_days), 0);

    const handleApplyFilter = () => {
        router.get('/analysis/pipeline-counts', { date: dateValue || undefined }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const handleClearFilter = () => {
        setDateValue('');
        router.get('/analysis/pipeline-counts', {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    return (
        <>
            <Head title="Pipeline Counts - Analysis" />
            <AppLayout>
                <div className="flex flex-col gap-6 p-6">
                    <Heading
                        title="Pipeline Counts"
                        description="Total alert counts grouped by pipeline run across all trading days."
                    />

                    {/* Summary Stats */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Total Alerts</CardTitle>
                                <Activity className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{totalAlerts.toLocaleString()}</div>
                                <p className="text-xs text-muted-foreground">Across all pipelines</p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Active Pipelines</CardTitle>
                                <Hash className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{totalPipelines}</div>
                                <p className="text-xs text-muted-foreground">Distinct pipeline runs</p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Max Trading Days</CardTitle>
                                <Calendar className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{totalUniqueDays.toLocaleString()}</div>
                                <p className="text-xs text-muted-foreground">Days with alerts (single pipeline)</p>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Date Filter */}
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-end gap-4">
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="from-date">From Date</Label>
                                    <Input
                                        id="from-date"
                                        type="date"
                                        value={dateValue}
                                        onChange={(e) => setDateValue(e.target.value)}
                                        className="w-48"
                                    />
                                </div>
                                <Button onClick={handleApplyFilter}>Apply Filter</Button>
                                {filters.date && (
                                    <Button variant="outline" onClick={handleClearFilter}>
                                        Clear Filter (Show All)
                                    </Button>
                                )}
                            </div>
                            {filters.date && (
                                <p className="text-xs text-muted-foreground mt-2">
                                    Showing alerts from <span className="font-medium">{filters.date}</span> onward
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Counts Table */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <BarChart3 className="h-5 w-5" />
                                <CardTitle>Counts by Pipeline</CardTitle>
                            </div>
                            <CardDescription>
                                Alert counts, date range, and unique symbols per pipeline run
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {counts.length === 0 ? (
                                <div className="text-center py-8 text-muted-foreground">No data found</div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Pipeline</TableHead>
                                                <TableHead className="text-right">Total Alerts</TableHead>
                                                <TableHead className="text-right">Unique Symbols</TableHead>
                                                <TableHead className="text-right">Trading Days</TableHead>
                                                <TableHead>First Date</TableHead>
                                                <TableHead>Last Date</TableHead>
                                                <TableHead className="text-right">Avg / Day</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {counts.map((row) => (
                                                <TableRow key={row.pipeline_run}>
                                                    <TableCell className="font-bold font-mono">{row.pipeline_run}</TableCell>
                                                    <TableCell className="text-right font-mono">{row.total_alerts.toLocaleString()}</TableCell>
                                                    <TableCell className="text-right font-mono">{row.unique_symbols.toLocaleString()}</TableCell>
                                                    <TableCell className="text-right font-mono">{row.trading_days.toLocaleString()}</TableCell>
                                                    <TableCell className="text-sm text-muted-foreground">{row.first_date}</TableCell>
                                                    <TableCell className="text-sm text-muted-foreground">{row.last_date}</TableCell>
                                                    <TableCell className="text-right font-mono">
                                                        {row.trading_days > 0
                                                            ? Math.round(row.total_alerts / row.trading_days).toLocaleString()
                                                            : '—'}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
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
