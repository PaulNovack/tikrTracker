import AppLayout from '@/layouts/app-layout';
import Heading from '@/components/heading';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { BarChart3, Filter, Hash, Info, Layers3, Lightbulb, TrendingDown, TrendingUp } from 'lucide-react';
import { useState } from 'react';

interface CalibrationBucket {
    id: number;
    pipeline: string;
    version: string;
    bucket_min: number | null;
    bucket_max: number | null;
    bucket_label: string;
    rows: number;
    win_rate: number;
    avg_pnl: number;
    median_pnl: number;
}

interface PipelineOption {
    value: string;
    label: string;
}

interface MlCalibrationProps {
    pipelineOptions: PipelineOption[];
    selectedPipeline: string;
    buckets: CalibrationBucket[];
    recommendation: {
        threshold: number | null;
        bucket_label: string | null;
        win_rate: number | null;
        avg_pnl: number;
        rationale: string;
        should_disable: boolean;
    };
}

function StatCard({ title, value, icon: Icon, subtitle }: { title: string; value: string; icon: React.ComponentType<{ className?: string }>; subtitle?: string }) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium">{title}</CardTitle>
                <Icon className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
                <div className="text-2xl font-bold">{value}</div>
                {subtitle && <p className="text-xs text-muted-foreground">{subtitle}</p>}
            </CardContent>
        </Card>
    );
}

const formatPercent = (value: number) => `${(Number(value) * 100).toFixed(1)}%`;
const formatPnL = (value: number | string) => {
    const n = Number(value);
    return n >= 0 ? `+${n.toFixed(2)}%` : `${n.toFixed(2)}%`;
};
function bucketDisplayLabel(bucket: CalibrationBucket): string {
    if (bucket.bucket_min !== null && bucket.bucket_max !== null) {
        return `${Math.round(Number(bucket.bucket_min) * 100)}% to ${Math.round(Number(bucket.bucket_max) * 100)}%`;
    }
    return bucket.bucket_label;
}

export default function MlCalibration({ pipelineOptions, selectedPipeline, buckets, recommendation }: MlCalibrationProps) {
    const [pipeline, setPipeline] = useState(selectedPipeline || '');

    const handlePipelineChange = (value: string) => {
        setPipeline(value);
        router.get('/analysis/ml-calibration', { pipeline: value || undefined }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const selected = pipelineOptions.find((o) => o.value === pipeline);
    const totalRows = buckets.reduce((sum, b) => sum + b.rows, 0);
    const weightedWinRate = totalRows > 0
        ? buckets.reduce((sum, b) => sum + b.rows * b.win_rate, 0) / totalRows
        : 0;
    const bucketCount = buckets.length;

    return (
        <>
            <Head title="ML Calibration - Analysis" />
            <AppLayout>
                <div className="flex flex-col gap-6 p-6">
                    <Heading
                        title="ML Probability Calibration"
                        description="Compare predicted win probabilities against actual win rates and P&L per probability bucket."
                    />

                    {/* Pipeline Filter */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Filter className="h-4 w-4" />
                                Pipeline Filter
                            </CardTitle>
                            <CardDescription>
                                Select a pipeline to view its probability calibration data.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Select value={pipeline} onValueChange={handlePipelineChange}>
                                <SelectTrigger className="w-[300px]">
                                    <SelectValue placeholder="Select a pipeline..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {pipelineOptions.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </CardContent>
                    </Card>

                    {selected && recommendation && !recommendation.should_disable && (
                        <Card className="border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-950">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Lightbulb className="h-5 w-5 text-green-600 dark:text-green-400" />
                                    ML Threshold Recommendation
                                </CardTitle>
                                <CardDescription>
                                    Use this calibration data to set the ML threshold in Trade Settings for pipeline <strong>{selected.pipeline}</strong>.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <span className="text-sm text-muted-foreground">Recommended Threshold</span>
                                        <div className="text-2xl font-bold text-green-600 dark:text-green-400">{recommendation.threshold !== null ? `${Math.round(recommendation.threshold * 100)}%` : 'N/A'}</div>
                                    </div>
                                    <div>
                                        <span className="text-sm text-muted-foreground">At This Bucket</span>
                                        <div className="text-2xl font-bold">{recommendation.win_rate !== null ? `${recommendation.win_rate}%` : 'N/A'}</div>
                                        <span className="text-xs text-muted-foreground">win rate</span>
                                    </div>
                                    <div>
                                        <span className="text-sm text-muted-foreground">Expected Avg P&amp;L</span>
                                        <div className={`text-2xl font-bold ${recommendation.avg_pnl >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}>
                                            +{recommendation.avg_pnl}%
                                        </div>
                                    </div>
                                </div>
                                <div className="flex items-start gap-2 text-sm text-muted-foreground">
                                    <Info className="h-4 w-4 mt-0.5 shrink-0" />
                                    <span>{recommendation.rationale}</span>
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    Go to <strong>Trade Settings → Pipelines</strong> tab and set the <strong>ML Threshold</strong> field
                                    for pipeline <strong>{selected.pipeline}</strong> to <strong>{recommendation.threshold !== null ? `${Math.round(recommendation.threshold * 100)}%` : 'N/A'}</strong>.
                                    Orders with a predicted win probability below this value will be skipped.
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {selected && recommendation && recommendation.should_disable && (
                        <Card className="border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <TrendingDown className="h-5 w-5 text-red-600 dark:text-red-400" />
                                    Pipeline Should Be Disabled
                                </CardTitle>
                                <CardDescription>
                                    The calibration data for pipeline <strong>{selected.pipeline}</strong> does not support active trading.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {recommendation.bucket_label && (
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <span className="text-sm text-muted-foreground">Best Bucket</span>
                                            <div className="text-xl font-bold">{recommendation.bucket_label}</div>
                                        </div>
                                        <div>
                                            <span className="text-sm text-muted-foreground">Best Avg P&amp;L</span>
                                            <div className={`text-xl font-bold ${recommendation.avg_pnl >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}>
                                                +{recommendation.avg_pnl}%
                                            </div>
                                        </div>
                                    </div>
                                )}
                                <div className="flex items-start gap-2 text-sm text-muted-foreground">
                                    <Info className="h-4 w-4 mt-0.5 shrink-0" />
                                    <span dangerouslySetInnerHTML={{ __html: recommendation.rationale }} />
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    Go to <strong>Trade Settings → Pipelines</strong> tab and set the <strong>ML Threshold</strong> field
                                    for pipeline <strong>{selected.pipeline}</strong> to <strong>above 100%</strong> (e.g., 110%).
                                    This effectively disables the pipeline while still allowing data collection for future retraining.
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {selected && buckets.length > 0 && (
                        <>
                            {/* Summary Stats */}
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <StatCard
                                    title="Total Rows"
                                    value={totalRows.toLocaleString()}
                                    icon={Hash}
                                    subtitle="Test-set alerts evaluated"
                                />
                                <StatCard
                                    title="Weighted Win Rate"
                                    value={formatPercent(weightedWinRate)}
                                    icon={TrendingUp}
                                    subtitle={`Across ${bucketCount} probability buckets`}
                                />
                                <StatCard
                                    title="Bucket Count"
                                    value={bucketCount.toString()}
                                    icon={Layers3}
                                    subtitle={`Model: ${selected.value} ${selected.label.includes('(') ? selected.label.slice(selected.label.indexOf('(')) : ''}`}
                                />
                            </div>

                            {/* Calibration Table */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <BarChart3 className="h-4 w-4" />
                                        Probability Buckets
                                    </CardTitle>
                                    <CardDescription>
                                        Each row shows how the model's predicted probability range maps to actual outcomes.
                                        A well-calibrated model should have win rates that increase with probability.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Bucket</TableHead>
                                                <TableHead className="text-right">Rows</TableHead>
                                                <TableHead className="text-right">Win Rate</TableHead>
                                                <TableHead className="text-right">Avg PnL</TableHead>
                                                <TableHead className="text-right">Median PnL</TableHead>
                                                <TableHead className="text-right">Calibration</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {buckets.map((bucket) => {
                                                const bucketMid = bucket.bucket_min !== null && bucket.bucket_max !== null
                                                    ? (bucket.bucket_min + bucket.bucket_max) / 2
                                                    : null;
                                                const calibrated = bucketMid !== null
                                                    ? (bucket.win_rate - bucketMid)
                                                    : 0;
                                                const isOverconfident = calibrated < -0.05;
                                                const isUnderconfident = calibrated > 0.05;

                                                return (
                                                    <TableRow key={bucket.id}>
                                                        <TableCell className="font-mono whitespace-nowrap">
                                                            {bucketDisplayLabel(bucket)}
                                                        </TableCell>
                                                        <TableCell className="text-right">
                                                            {bucket.rows.toLocaleString()}
                                                        </TableCell>
                                                        <TableCell className="text-right">
                                                            {formatPercent(bucket.win_rate)}
                                                        </TableCell>
                                                        <TableCell className={`text-right font-mono ${bucket.avg_pnl >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}>
                                                            {formatPnL(bucket.avg_pnl)}
                                                        </TableCell>
                                                        <TableCell className={`text-right font-mono ${bucket.median_pnl >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}>
                                                            {formatPnL(bucket.median_pnl)}
                                                        </TableCell>
                                                        <TableCell className="text-right">
                                                            {isOverconfident && <Badge variant="destructive">Overconfident</Badge>}
                                                            {isUnderconfident && <Badge variant="outline" className="text-blue-600 border-blue-600">Underconfident</Badge>}
                                                            {!isOverconfident && !isUnderconfident && <Badge variant="secondary">Good</Badge>}
                                                        </TableCell>
                                                    </TableRow>
                                                );
                                            })}
                                        </TableBody>
                                    </Table>
                                </CardContent>
                            </Card>
                        </>
                    )}

                    {pipeline && buckets.length === 0 && (
                        <Card>
                            <CardContent className="py-12 text-center text-muted-foreground">
                                No calibration data found for this pipeline. Run the training script first.
                            </CardContent>
                        </Card>
                    )}

                    {!pipeline && (
                        <Card>
                            <CardContent className="py-12 text-center text-muted-foreground">
                                Select a pipeline above to view its probability calibration data.
                            </CardContent>
                        </Card>
                    )}
                </div>
            </AppLayout>
        </>
    );
}
