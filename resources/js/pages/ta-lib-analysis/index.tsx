import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { show as showAsset } from '@/routes/asset-info';
import { BarChart3, CandlestickChart, Loader2, TrendingDown, TrendingUp } from 'lucide-react';
import { useState } from 'react';
import { PatternDescriptionCard } from './pattern-descriptions';
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

interface ScanResult {
    symbol: string;
    asset_id: number;
    signal: 'bullish' | 'bearish';
    signal_value: number;
    last_date: string;
    ohlc: OHLCBar[];
}

interface ScanResponse {
    pattern: string;
    pattern_name: string;
    total_scanned: number;
    hits: number;
    results: ScanResult[];
}

interface Props {
    patterns: Record<string, string>;
    selectedPattern: string;
    results: ScanResponse | null;
    limit: number;
    error: string | null;
}

function MiniCandlestickChart({ data, signal }: { data: OHLCBar[]; signal: 'bullish' | 'bearish' }) {
    const chartData = data.map((bar) => ({
        ...bar,
        // Color each candle: green if close > open, red if close < open
        fill: bar.close >= bar.open ? '#22c55e' : '#ef4444',
        // Volume bar color
        volFill: bar.close >= bar.open ? '#4ade80' : '#f87171',
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
                    {/* Candle body */}
                    <Bar dataKey="close" fill="#22c55e" barSize={4}>
                        {chartData.map((entry, idx) => (
                            <Cell key={`cell-${idx}`} fill={entry.fill} />
                        ))}
                    </Bar>
                    {/* Candle wick */}
                    <Line dataKey="high" stroke="#9ca3af" dot={false} strokeWidth={1} />
                    {/* Volume bars at bottom */}
                    <Bar dataKey="volume" fill="#4b5563" opacity={0.3} barSize={2} yAxisId="volume" />
                    <YAxis yAxisId="volume" hide domain={[0, (max: number) => max * 4]} />
                </ComposedChart>
            </ResponsiveContainer>
        </div>
    );
}

export default function TaLibAnalysis({ patterns, selectedPattern, results, limit, error }: Props) {
    const [isLoading, setIsLoading] = useState(false);
    const [showBullishOnly, setShowBullishOnly] = useState(true);

    const handlePatternSelect = (value: string) => {
        if (!value) return;
        setIsLoading(true);
        router.get('/ta-lib-analysis', { pattern: value, limit }, {
            preserveState: true,
            onFinish: () => setIsLoading(false),
        });
    };

    const filteredResults = results?.results.filter((r) => {
        if (!showBullishOnly) return true;
        return r.signal === 'bullish';
    }) ?? [];

    return (
        <AppLayout>
            <Head title="Daily" />

            <div className="space-y-6 p-6">
                <div className="flex items-center gap-3">
                    <CandlestickChart className="h-8 w-8 text-primary" />
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Daily</h1>
                        <p className="text-muted-foreground">
                            Scan for candlestick patterns using TA-Lib across your trading universe
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
                        <CardTitle>Pattern Scanner</CardTitle>
                        <CardDescription>Select a candlestick pattern to scan the universe</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="flex items-center gap-4">
                            <Select value={selectedPattern} onValueChange={handlePatternSelect}>
                                <SelectTrigger className="w-[320px]">
                                    <SelectValue placeholder="-- Select a Pattern --" />
                                </SelectTrigger>
                                <SelectContent>
                                    {Object.entries(patterns).map(([key, name]) => (
                                        <SelectItem key={key} value={key}>{name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            {isLoading && <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />}
                        </div>

                        {results && !isLoading && (
                            <div className="mt-3 flex items-center gap-4">
                                <p className="text-sm text-muted-foreground">
                                    Scanned {results.total_scanned} symbols —{' '}
                                    <span className="font-semibold text-foreground">{results.hits} hits</span>
                                    {' '}for <Badge variant="outline">{results.pattern_name}</Badge>
                                </p>
                                <label className="flex items-center gap-2 cursor-pointer text-sm">
                                    <input
                                        type="checkbox"
                                        checked={showBullishOnly}
                                        onChange={(e) => setShowBullishOnly(e.target.checked)}
                                        className="h-4 w-4 rounded border-gray-600 bg-gray-700 text-green-500 focus:ring-green-500"
                                    />
                                    <span className={showBullishOnly ? 'text-green-400' : 'text-muted-foreground'}>
                                        Show Bullish Only
                                    </span>
                                </label>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Pattern description */}
                {selectedPattern && <PatternDescriptionCard pattern={selectedPattern} />}

                {filteredResults.length > 0 && (
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {filteredResults.map((r) => (
                            <Card key={r.symbol} className={r.signal === 'bullish' ? 'border-l-4 border-l-green-500' : 'border-l-4 border-l-red-500'}>
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
                                        <Badge variant={r.signal === 'bullish' ? 'default' : 'destructive'}
                                               className={r.signal === 'bullish' ? 'bg-green-600' : ''}>
                                            {r.signal === 'bullish' ? (
                                                <TrendingUp className="mr-1 h-3 w-3" />
                                            ) : (
                                                <TrendingDown className="mr-1 h-3 w-3" />
                                            )}
                                            {r.signal}
                                        </Badge>
                                    </div>
                                    <CardDescription>Last date: {r.last_date}</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <MiniCandlestickChart data={r.ohlc} signal={r.signal} />
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                {results && !isLoading && filteredResults.length === 0 && results.results.length === 0 && (
                    <Card>
                        <CardContent className="py-8 text-center">
                            <BarChart3 className="mx-auto h-12 w-12 text-muted-foreground/50" />
                            <p className="mt-4 text-lg font-medium">No patterns detected</p>
                            <p className="text-sm text-muted-foreground">
                                No {results.pattern_name} signals found across {results.total_scanned} symbols
                            </p>
                        </CardContent>
                    </Card>
                )}

                {results && !isLoading && filteredResults.length === 0 && results.results.length > 0 && (
                    <Card>
                        <CardContent className="py-8 text-center">
                            <TrendingUp className="mx-auto h-12 w-12 text-muted-foreground/50" />
                            <p className="mt-4 text-lg font-medium">No bullish patterns</p>
                            <p className="text-sm text-muted-foreground">
                                {results.hits} total hits found, but none are bullish. Try toggling "Show Bullish Only" off.
                            </p>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
