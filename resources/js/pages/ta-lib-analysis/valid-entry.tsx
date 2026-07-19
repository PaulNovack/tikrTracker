import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { show as showAsset } from '@/routes/asset-info';
import { BarChart3, Loader2, TrendingUp, Zap } from 'lucide-react';
import { useState } from 'react';
import {
    ComposedChart,
    Bar,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ResponsiveContainer,
    Cell,
} from 'recharts';

interface OHLCBar {
    date: string;
    open: number;
    high: number;
    low: number;
    close: number;
    volume: number;
}

interface ValidEntryResult {
    symbol: string;
    asset_id: number;
    signal: string;
    signal_value: number;
    last_date: string;
    engulfing_high: number;
    engulfing_low: number;
    engulfing_close: number;
    volume_ratio: number;
    entry_price: number;
    ohlc: OHLCBar[];
}

interface ValidEntryResponse {
    total_scanned: number;
    hits: number;
    results: ValidEntryResult[];
}

interface Props {
    results: ValidEntryResponse | null;
    limit: number;
    error: string | null;
}

function MiniCandlestickChart({ data }: { data: OHLCBar[] }) {
    const chartData = data.map((bar) => ({
        ...bar,
        fill: bar.close >= bar.open ? '#22c55e' : '#ef4444',
    }));

    const prices = data.flatMap((d) => [d.high, d.low]);
    const minPrice = Math.min(...prices);
    const maxPrice = Math.max(...prices);
    const padding = (maxPrice - minPrice) * 0.05 || 1;

    return (
        <div className="h-[200px] w-full">
            <ResponsiveContainer width="100%" height="100%">
                <ComposedChart data={chartData} margin={{ top: 4, right: 4, bottom: 4, left: 4 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#374151" />
                    <XAxis dataKey="date" hide />
                    <YAxis domain={[minPrice - padding, maxPrice + padding]} hide />
                    <Tooltip
                        contentStyle={{ backgroundColor: '#1f2937', border: '1px solid #374151', borderRadius: '8px' }}
                        labelStyle={{ color: '#9ca3af' }}
                        formatter={(value: number, name: string) => [value.toFixed(2), name]}
                    />
                    <Bar dataKey="close" fill="#22c55e" barSize={4}>
                        {chartData.map((entry, idx) => (
                            <Cell key={`cell-${idx}`} fill={entry.fill} />
                        ))}
                    </Bar>
                    <Line dataKey="high" stroke="#9ca3af" dot={false} strokeWidth={1} />
                    <Bar dataKey="volume" fill="#4b5563" opacity={0.3} barSize={2} yAxisId="volume" />
                    <YAxis yAxisId="volume" hide domain={[0, (max: number) => max * 4]} />
                </ComposedChart>
            </ResponsiveContainer>
        </div>
    );
}

export default function ValidEntry({ results, limit, error }: Props) {
    const [isLoading, setIsLoading] = useState(false);

    const handleScan = () => {
        setIsLoading(true);
        router.get('/ta-lib-analysis/valid-entry', { limit }, {
            preserveState: true,
            onFinish: () => setIsLoading(false),
        });
    };

    return (
        <AppLayout>
            <Head title="Valid Entry" />

            <div className="space-y-6 p-6">
                <div className="flex items-center gap-3">
                    <Zap className="h-8 w-8 text-primary" />
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Valid Entry</h1>
                        <p className="text-muted-foreground">
                            5-minute bullish engulfing confirmed by 1-minute breakout, VWAP, EMA crossover, and volume
                        </p>
                    </div>
                </div>

                {error && (
                    <Card className="border-destructive/50 bg-destructive/5">
                        <CardContent className="pt-4">
                            <p className="text-destructive font-medium">{error}</p>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Valid Entry Scanner</CardTitle>
                        <CardDescription>
                            Scans for 5-minute bullish engulfing with 1-minute confirmation (breakout, VWAP, EMA9&gt;EMA21, volume≥1.5x)
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="flex items-center gap-4">
                            <button
                                onClick={handleScan}
                                disabled={isLoading}
                                className="inline-flex items-center gap-2 rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                            >
                                {isLoading ? (
                                    <>
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                        Scanning...
                                    </>
                                ) : (
                                    <>
                                        <Zap className="h-4 w-4" />
                                        Scan Universe
                                    </>
                                )}
                            </button>
                        </div>

                        {results && !isLoading && (
                            <p className="mt-3 text-sm text-muted-foreground">
                                Scanned {results.total_scanned} symbols —{' '}
                                <span className="font-semibold text-green-400">{results.hits} valid entries</span>
                            </p>
                        )}
                    </CardContent>
                </Card>

                {results && results.results.length > 0 && (
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {results.results.map((r) => (
                            <Card key={r.symbol} className="border-l-4 border-l-green-500">
                                <CardHeader className="pb-2">
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="text-lg">
                                            <a
                                                href={showAsset.url(r.asset_id)}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-blue-600 hover:text-blue-700 hover:underline font-bold dark:text-blue-400 dark:hover:text-blue-300"
                                            >
                                                {r.symbol}
                                            </a>
                                        </CardTitle>
                                        <Badge className="bg-green-600">
                                            <TrendingUp className="mr-1 h-3 w-3" />
                                            Entry ${r.entry_price.toFixed(2)}
                                        </Badge>
                                    </div>
                                    <CardDescription className="flex flex-col gap-1 text-xs">
                                        <span>5m engulfing close: ${r.engulfing_close.toFixed(2)}</span>
                                        <span>Breakout above: ${r.engulfing_high.toFixed(2)}</span>
                                        <span>Vol ratio: {r.volume_ratio}x</span>
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <MiniCandlestickChart data={r.ohlc} />
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                {results && results.results.length === 0 && !isLoading && (
                    <Card>
                        <CardContent className="py-8 text-center">
                            <BarChart3 className="mx-auto h-12 w-12 text-muted-foreground/50" />
                            <p className="mt-4 text-lg font-medium">No valid entries found</p>
                            <p className="text-sm text-muted-foreground">
                                No 5-minute bullish engulfing patterns with 1-minute confirmation found across {results.total_scanned} symbols
                            </p>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
