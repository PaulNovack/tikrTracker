import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowDown, ArrowUp, TrendingUp, Gauge, AlertTriangle, TrendingUp as RisingIcon } from 'lucide-react';
import { Badge } from '@/components/ui/badge';

interface PressureStock {
    symbol: string;
    asset_info_id: number | null;
    ts_est: string;
    close: number;
    volume: number;
    vwap: number;
    body_pct: number;
    close_location: number;
    volume_ratio: number;
    vwap_dist_pct: number;
    momentum_5m_pct: number;
    upward_pressure_score: number;
    stretch_penalty: number;
    adjusted_score: number;
    prev_score_1: number | null;
    prev_score_2: number | null;
    is_rising: boolean;
}

interface Props {
    stocks: PressureStock[];
    totalSymbols: number;
    qualifiedCount: number;
    activeFilter: string;
}

export default function UpwardPressureIndex({ stocks, totalSymbols, qualifiedCount, activeFilter }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Analysis', href: '/analysis/vwap-status' },
        { title: 'Upward Pressure', href: '/analysis/upward-pressure' },
    ];

    const strongPressure = stocks.filter((s) => s.upward_pressure_score >= 70);
    const moderatePressure = stocks.filter((s) => s.upward_pressure_score >= 50 && s.upward_pressure_score < 70);

    const switchFilter = (filter: string) => {
        router.get('/analysis/upward-pressure', { filter }, { preserveState: true, replace: true });
    };

    // Score trend display helper
    const scoreTrend = (s: PressureStock) => {
        if (s.prev_score_1 === null && s.prev_score_2 === null) return null;
        const parts: string[] = [];
        if (s.prev_score_2 !== null) parts.push(s.prev_score_2.toFixed(1));
        if (s.prev_score_1 !== null) parts.push(s.prev_score_1.toFixed(1));
        parts.push(s.upward_pressure_score.toFixed(1));
        return parts.join(' → ');
    };

    // Determine if the score trajectory is rising (each step > prior)
    const isRisingTrajectory = (s: PressureStock) => {
        if (s.prev_score_1 === null || s.prev_score_2 === null) return false;
        return s.upward_pressure_score > s.prev_score_1 && s.prev_score_1 > s.prev_score_2;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Upward Pressure" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                            Upward Pressure
                        </h1>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Real-time 1-minute bar analysis — scores 0-100 combining body strength, close location, volume ratio, VWAP distance, and momentum
                        </p>
                    </div>
                </div>

                {/* Summary Cards */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Symbols Analyzed</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{totalSymbols}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                <span className="flex items-center gap-1.5">
                                    <Gauge className="h-4 w-4 text-green-500" /> Qualified
                                </span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-green-600">{qualifiedCount}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                <span className="flex items-center gap-1.5">
                                    <Gauge className="h-4 w-4 text-green-500" /> Strong (70+)
                                </span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-green-600">{strongPressure.length}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                <span className="flex items-center gap-1.5">
                                    <Gauge className="h-4 w-4 text-yellow-500" /> Moderate (50-69)
                                </span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-yellow-600">{moderatePressure.length}</div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filter Toggle */}
                <div className="flex items-center gap-2">
                    <span className="text-sm text-muted-foreground">Filter:</span>
                    <button
                        onClick={() => switchFilter('qualified')}
                        className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                            activeFilter === 'qualified'
                                ? 'bg-blue-600 text-white'
                                : 'border bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300'
                        }`}
                    >
                        Qualified Only
                    </button>
                    <button
                        onClick={() => switchFilter('all')}
                        className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                            activeFilter === 'all'
                                ? 'bg-blue-600 text-white'
                                : 'border bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300'
                        }`}
                    >
                        All Scored
                    </button>
                    <span className="ml-2 text-xs text-muted-foreground">
                        {activeFilter === 'qualified'
                            ? 'Score ≥ 65 · Volume ≥ 1.5x · Close > VWAP · Close Loc ≥ 0.65 · VWAP Dist 0.1%–1.2%'
                            : 'Showing all scored symbols'}
                    </span>
                </div>

                {/* Results Table */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <TrendingUp className="h-5 w-5" />
                            <CardTitle>Upward Pressure Scores</CardTitle>
                        </div>
                        <CardDescription>
                            {activeFilter === 'qualified'
                                ? 'Qualified entries — sorted by adjusted score (raw score minus stretch penalty)'
                                : 'All scored symbols — sorted by raw upward pressure score'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-border">
                                        <th className="pb-2 pr-3 text-left font-medium text-muted-foreground">#</th>
                                        <th className="pb-2 pr-3 text-left font-medium text-muted-foreground">Symbol</th>
                                        <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Score</th>
                                        <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Adj</th>
                                        <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Penalty</th>
                                        <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Trend</th>
                                        <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Rising</th>
                                        <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Close</th>
                                        <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Body %</th>
                                        <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Loc</th>
                                        <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Vol</th>
                                        <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">VWAP</th>
                                        <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">5m Mom</th>
                                        <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Volume</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border">
                                    {stocks.length === 0 && (
                                        <tr>
                                            <td colSpan={14} className="py-12 text-center text-muted-foreground">
                                                No data available. Market may be closed or no recent 1-minute bars.
                                            </td>
                                        </tr>
                                    )}
                                    {stocks.map((stock, i) => (
                                        <tr
                                            key={`${stock.symbol}-${i}`}
                                            className={`hover:bg-muted/50 ${stock.is_rising ? 'bg-green-50/40 dark:bg-green-950/20' : ''}`}
                                        >
                                            <td className="py-1.5 pr-3 text-muted-foreground">{i + 1}</td>
                                            <td className="py-1.5 pr-3 font-medium">
                                                <span className="flex items-center gap-1">
                                                    {stock.asset_info_id ? (
                                                        <a
                                                            href={`/market-data/assets/${stock.asset_info_id}`}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 hover:underline"
                                                        >
                                                            {stock.symbol}
                                                        </a>
                                                    ) : (
                                                        stock.symbol
                                                    )}
                                                    {stock.is_rising && (
                                                        <RisingIcon className="h-3 w-3 text-green-500" />
                                                    )}
                                                </span>
                                            </td>
                                            <td className="py-1.5 pr-3 text-right">
                                                <Badge
                                                    variant={stock.upward_pressure_score >= 70 ? 'default' : stock.upward_pressure_score >= 50 ? 'secondary' : 'outline'}
                                                    className="font-mono"
                                                >
                                                    {stock.upward_pressure_score.toFixed(1)}
                                                </Badge>
                                            </td>
                                            <td className="py-1.5 pr-3 text-right font-mono">
                                                {stock.adjusted_score.toFixed(1)}
                                            </td>
                                            <td className="py-1.5 pr-3 text-right font-mono">
                                                {stock.stretch_penalty > 0 ? (
                                                    <span className="text-orange-500">-{stock.stretch_penalty}</span>
                                                ) : (
                                                    <span className="text-muted-foreground">0</span>
                                                )}
                                            </td>
                                            <td className="py-1.5 pr-3 text-right font-mono text-xs text-muted-foreground">
                                                {scoreTrend(stock) ?? '—'}
                                            </td>
                                            <td className="py-1.5 pr-3 text-center">
                                                {stock.prev_score_1 !== null && stock.prev_score_2 !== null ? (
                                                    isRisingTrajectory(stock) ? (
                                                        <span className="inline-flex items-center gap-0.5 text-xs text-green-600">
                                                            <ArrowUp className="h-3 w-3" /> Rising
                                                        </span>
                                                    ) : stock.upward_pressure_score > stock.prev_score_1 ? (
                                                        <span className="inline-flex items-center gap-0.5 text-xs text-blue-500">
                                                            <ArrowUp className="h-3 w-3" /> Up
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center gap-0.5 text-xs text-red-500">
                                                            <ArrowDown className="h-3 w-3" /> Down
                                                        </span>
                                                    )
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">—</span>
                                                )}
                                            </td>
                                            <td className="py-1.5 pr-3 text-right font-mono">${stock.close.toFixed(2)}</td>
                                            <td className="py-1.5 pr-3 text-right font-mono">
                                                <span className={stock.body_pct >= 0 ? 'text-green-600' : 'text-red-600'}>
                                                    {stock.body_pct >= 0 ? '+' : ''}{stock.body_pct.toFixed(2)}%
                                                </span>
                                            </td>
                                            <td className="py-1.5 pr-3 text-right font-mono">{stock.close_location.toFixed(3)}</td>
                                            <td className="py-1.5 pr-3 text-right font-mono">{stock.volume_ratio.toFixed(2)}x</td>
                                            <td className="py-1.5 pr-3 text-right font-mono">
                                                <span className={stock.vwap_dist_pct >= 0 ? 'text-green-600' : 'text-red-600'}>
                                                    {stock.vwap_dist_pct >= 0 ? '+' : ''}{stock.vwap_dist_pct.toFixed(2)}%
                                                </span>
                                            </td>
                                            <td className="py-1.5 pr-3 text-right font-mono">
                                                <span className={stock.momentum_5m_pct >= 0 ? 'text-green-600' : 'text-red-600'}>
                                                    {stock.momentum_5m_pct >= 0 ? '+' : ''}{stock.momentum_5m_pct.toFixed(2)}%
                                                </span>
                                            </td>
                                            <td className="py-1.5 text-right font-mono text-muted-foreground">
                                                {stock.volume.toLocaleString()}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
