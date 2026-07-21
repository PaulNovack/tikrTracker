import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { TrendingUp, Clock, RefreshCw, Target, BarChart3, Volume2, Timer } from 'lucide-react';
import { useState, useEffect } from 'react';

interface ConfirmedMomentumResult {
    symbol: string;
    last_price: number;
    move_pct: number;
    noise_pct: number;
    bars_1m: number;
    volume_sum_1m: number;
    distance_from_high: number;
    age_minutes: number;
    last_ts_est_1m: string;
    recent5_high: number;
    last5_open: number;
    last5_close: number;
    bars_5m: number;
    body_pct_5m: number;
    last_ts_est_5m: string;
}

interface ConfirmedMomentumMetadata {
    reference_time_est: string;
    window_1m_start: string;
    window_1m_end: string;
    window_5m_start?: string;
    lookback_minutes: number;
    five_min_range_minutes?: number;
    asset_type: string;
    message?: string;
    filters?: {
        min_move_pct: number;
        noise_multiplier: number;
        max_distance_from_high_pct: number;
        strong_body_min_pct: number;
        min_bars_5m: number;
    };
}

interface BreakoutConfirmedPageProps {
    title: string;
    time?: string;
    assetType?: string;
    lookback?: number;
    minMove?: number;
    noiseMultiplier?: number;
    maxDistanceFromHigh?: number;
    minPrice?: number;
    minVolumeSum?: number;
    minBars1m?: number;
    strongBodyMinPct?: number;
    fiveMinBarsCount?: number;
    fiveMinRangeFactor?: number;
    minBars5m?: number;
    results?: ConfirmedMomentumResult[];
    metadata?: ConfirmedMomentumMetadata;
}

export default function BreakoutConfirmed({ 
    title, 
    time, 
    assetType = 'stocks',
    lookback = 15,           // PHP script default: $defaultLookbackMinutes = 15
    minMove = 0.75,          // PHP script default: $defaultMinMovePct = 0.75
    noiseMultiplier = 1.5,   // PHP script default: $noiseMultiplier = 1.5
    maxDistanceFromHigh = 0.25,  // PHP script default: $maxDistanceFromHighPct = 0.25
    minPrice = 1.0,          // PHP script default: $minPrice = 1.00
    minVolumeSum = 10000,    // PHP script default: $minVolumeSum1m = 10000
    minBars1m = 5,           // PHP script default: $minBars1m = 5
    strongBodyMinPct = 0.3,  // PHP script default: $strongBodyMinPct = 0.3
    fiveMinBarsCount = 5,    // PHP script default: $fiveMinBarsCount = 5
    fiveMinRangeFactor = 5.0, // PHP script default: $fiveMinRangeFactor = 5
    minBars5m = 3,           // PHP script default: $minBars5m = 3
    results = [], 
    metadata 
}: BreakoutConfirmedPageProps) {
    const [isLoading, setIsLoading] = useState(false);

    // Format current EST time as datetime-local value
    const getCurrentESTDateTime = () => {
        const now = new Date();
        // Convert to EST timezone using toLocaleString
        const estString = now.toLocaleString('en-CA', {
            timeZone: 'America/New_York',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false
        });
        
        // Format: "2025-12-08, 14:18"
        const [datePart, timePart] = estString.split(', ');
        
        return `${datePart}T${timePart}`;
    };

    // Initialize with time from URL or current time
    const getInitialDateTime = () => {
        if (time) {
            // Convert from "YYYY-MM-DD HH:MM:SS" to "YYYY-MM-DDTHH:MM"
            return time.replace(' ', 'T').slice(0, 16);
        }
        return getCurrentESTDateTime();
    };

    const [selectedDateTime, setSelectedDateTime] = useState(getInitialDateTime());
    const [showAdvanced, setShowAdvanced] = useState(false);
    const [parameters, setParameters] = useState({
        lookback: lookback,
        minMove: minMove,
        noiseMultiplier: noiseMultiplier,
        maxDistanceFromHigh: maxDistanceFromHigh,
        minPrice: minPrice,
        minVolumeSum: minVolumeSum,
        minBars1m: minBars1m,
        strongBodyMinPct: strongBodyMinPct,
        fiveMinBarsCount: fiveMinBarsCount,
        fiveMinRangeFactor: fiveMinRangeFactor,
        minBars5m: minBars5m
    });

    // Update datetime picker when time prop changes
    useEffect(() => {
        setSelectedDateTime(getInitialDateTime());
    }, [time]);

    // Auto-refresh every 30 seconds
    useEffect(() => {
        const interval = setInterval(() => {
            // Auto-refresh if we're not currently loading data
            if (!isLoading) {
                // Use Inertia router to reload with preserved URL params
                router.reload({ only: ['confirmed_results'] });
            }
        }, 30000); // 30 seconds

        return () => clearInterval(interval);
    }, [isLoading]);

    const handleDateTimeChange = (event: React.ChangeEvent<HTMLInputElement>) => {
        setSelectedDateTime(event.target.value);
    };

    const handleAnalyze = () => {
        setIsLoading(true);
        // Convert datetime-local value back to YYYY-MM-DD HH:MM:SS format
        const formattedDateTime = selectedDateTime.replace('T', ' ') + ':00';
        
        const params: Record<string, string> = {
            time: formattedDateTime,
            asset_type: assetType,
            lookback: parameters.lookback.toString(),
            min_move: parameters.minMove.toString(),
            noise_multiplier: parameters.noiseMultiplier.toString(),
            max_distance_from_high: parameters.maxDistanceFromHigh.toString(),
            min_price: parameters.minPrice.toString(),
            min_volume_sum: parameters.minVolumeSum.toString(),
            min_bars_1m: parameters.minBars1m.toString(),
            strong_body_min_pct: parameters.strongBodyMinPct.toString(),
            five_min_bars_count: parameters.fiveMinBarsCount.toString(),
            five_min_range_factor: parameters.fiveMinRangeFactor.toString(),
            min_bars_5m: parameters.minBars5m.toString(),
        };
        
        router.get('/analysis/breakout-confirmed', params, {
            preserveState: false,
            onFinish: () => setIsLoading(false)
        });
    };

    const handleResetToNow = () => {
        const currentTime = getCurrentESTDateTime();
        setSelectedDateTime(currentTime);
        setIsLoading(true);
        
        // Convert datetime-local value back to YYYY-MM-DD HH:MM:SS format for URL
        const formattedDateTime = currentTime.replace('T', ' ') + ':00';
        
        router.get('/analysis/breakout-confirmed', {
            time: formattedDateTime,
            asset_type: assetType,
            lookback: parameters.lookback.toString(),
            min_move: parameters.minMove.toString(),
        }, {
            preserveState: false,
            onFinish: () => setIsLoading(false)
        });
    };

    const handleResetToDefaults = () => {
        setParameters({
            lookback: 15,           // PHP script defaults
            minMove: 0.75,
            noiseMultiplier: 1.5,
            maxDistanceFromHigh: 0.25,
            minPrice: 1.0,
            minVolumeSum: 10000,
            minBars1m: 5,
            strongBodyMinPct: 0.3,
            fiveMinBarsCount: 5,
            fiveMinRangeFactor: 5.0,
            minBars5m: 3
        });
    };

    // Format last update time to AM/PM format
    const formatLastUpdate = (timeString: string): string => {
        try {
            const date = new Date(timeString + ' EST');
            return date.toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
                hour12: true,
                timeZone: 'America/New_York'
            });
        } catch (error) {
            return timeString;
        }
    };

    return (
        <>
            <Head title={title} />
            <AppLayout>
                <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                    <Heading 
                        title={title} 
                        description="Analyze confirmed breakout patterns with dual-timeframe momentum validation. Uses same parameters as scan_confirmed_momentum.php script by default." 
                    />

                    {/* Analysis Time Selection */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Clock className="h-5 w-5 text-blue-600" />
                                Analysis Time Selection
                            </CardTitle>
                            <CardDescription>
                                Select a specific date and time to analyze confirmed breakout patterns with 1-minute momentum and 5-minute structural validation
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-end">
                                <div className="grid flex-1 gap-2">
                                    <Label htmlFor="analysis-time">
                                        Analysis Date & Time (EST)
                                    </Label>
                                    <Input
                                        id="analysis-time"
                                        type="datetime-local"
                                        value={selectedDateTime}
                                        onChange={handleDateTimeChange}
                                        className="w-full"
                                    />
                                </div>
                                <div className="flex gap-2">
                                    <Button 
                                        onClick={handleAnalyze}
                                        disabled={isLoading}
                                        className="flex items-center gap-2"
                                    >
                                        {isLoading ? (
                                            <>
                                                <RefreshCw className="h-4 w-4 animate-spin" />
                                                Analyzing...
                                            </>
                                        ) : (
                                            <>
                                                <TrendingUp className="h-4 w-4" />
                                                Analyze
                                            </>
                                        )}
                                    </Button>
                                    <Button 
                                        onClick={handleResetToNow}
                                        variant="outline"
                                        disabled={isLoading}
                                        className="flex items-center gap-2"
                                    >
                                        <Clock className="h-4 w-4" />
                                        Now
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Analysis Parameters */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <BarChart3 className="h-5 w-5 text-purple-600" />
                                Analysis Parameters
                            </CardTitle>
                            <CardDescription>
                                Configure the momentum and breakout detection parameters. Defaults match the scan_confirmed_momentum.php script.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-6">
                                {/* Basic Parameters */}
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="lookback">Lookback Minutes</Label>
                                        <Input
                                            id="lookback"
                                            type="number"
                                            value={parameters.lookback}
                                            onChange={(e) => setParameters(prev => ({ ...prev, lookback: parseInt(e.target.value) || 15 }))}
                                            min="1"
                                            max="120"
                                            placeholder="15 (PHP script default)"
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="minMove">Min Move %</Label>
                                        <Input
                                            id="minMove"
                                            type="number"
                                            step="0.1"
                                            value={parameters.minMove}
                                            onChange={(e) => setParameters(prev => ({ ...prev, minMove: parseFloat(e.target.value) || 0.75 }))}
                                            min="0.1"
                                            max="10"
                                            placeholder="0.75 (PHP script default)"
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="strongBodyMinPct">5m Body Min %</Label>
                                        <Input
                                            id="strongBodyMinPct"
                                            type="number"
                                            step="0.1"
                                            value={parameters.strongBodyMinPct}
                                            onChange={(e) => setParameters(prev => ({ ...prev, strongBodyMinPct: parseFloat(e.target.value) || 0.3 }))}
                                            min="0.1"
                                            max="5"
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="minVolumeSum">Min Volume</Label>
                                        <Input
                                            id="minVolumeSum"
                                            type="number"
                                            value={parameters.minVolumeSum}
                                            onChange={(e) => setParameters(prev => ({ ...prev, minVolumeSum: parseInt(e.target.value) || 10000 }))}
                                            min="0"
                                            max="1000000"
                                        />
                                    </div>
                                </div>

                                <Separator />

                                {/* Advanced Parameters Toggle */}
                                <div className="flex items-center justify-between">
                                    <div>
                                        <h4 className="text-sm font-medium">Advanced Parameters</h4>
                                        <p className="text-xs text-muted-foreground">Fine-tune noise filtering and breakout detection</p>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={handleResetToDefaults}
                                        >
                                            Reset to PHP Defaults
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setShowAdvanced(!showAdvanced)}
                                        >
                                            {showAdvanced ? 'Hide' : 'Show'} Advanced
                                        </Button>
                                    </div>
                                </div>

                                {/* Advanced Parameters */}
                                {showAdvanced && (
                                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                        <div className="grid gap-2">
                                            <Label htmlFor="noiseMultiplier">Noise Multiplier</Label>
                                            <Input
                                                id="noiseMultiplier"
                                                type="number"
                                                step="0.1"
                                                value={parameters.noiseMultiplier}
                                                onChange={(e) => setParameters(prev => ({ ...prev, noiseMultiplier: parseFloat(e.target.value) || 1.5 }))}
                                                min="0.1"
                                                max="10"
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="maxDistanceFromHigh">Max Distance from High %</Label>
                                            <Input
                                                id="maxDistanceFromHigh"
                                                type="number"
                                                step="0.1"
                                                value={parameters.maxDistanceFromHigh}
                                                onChange={(e) => setParameters(prev => ({ ...prev, maxDistanceFromHigh: parseFloat(e.target.value) || 0.25 }))}
                                                min="0.1"
                                                max="10"
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="minPrice">Min Price $</Label>
                                            <Input
                                                id="minPrice"
                                                type="number"
                                                step="0.1"
                                                value={parameters.minPrice}
                                                onChange={(e) => setParameters(prev => ({ ...prev, minPrice: parseFloat(e.target.value) || 1.0 }))}
                                                min="0.1"
                                                max="100"
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="minBars1m">Min 1m Bars</Label>
                                            <Input
                                                id="minBars1m"
                                                type="number"
                                                value={parameters.minBars1m}
                                                onChange={(e) => setParameters(prev => ({ ...prev, minBars1m: parseInt(e.target.value) || 5 }))}
                                                min="1"
                                                max="50"
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="fiveMinBarsCount">5m Bars Count</Label>
                                            <Input
                                                id="fiveMinBarsCount"
                                                type="number"
                                                value={parameters.fiveMinBarsCount}
                                                onChange={(e) => setParameters(prev => ({ ...prev, fiveMinBarsCount: parseInt(e.target.value) || 5 }))}
                                                min="1"
                                                max="20"
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="fiveMinRangeFactor">5m Range Factor</Label>
                                            <Input
                                                id="fiveMinRangeFactor"
                                                type="number"
                                                step="0.1"
                                                value={parameters.fiveMinRangeFactor}
                                                onChange={(e) => setParameters(prev => ({ ...prev, fiveMinRangeFactor: parseFloat(e.target.value) || 5.0 }))}
                                                min="1"
                                                max="20"
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="minBars5m">Min 5m Bars</Label>
                                            <Input
                                                id="minBars5m"
                                                type="number"
                                                value={parameters.minBars5m}
                                                onChange={(e) => setParameters(prev => ({ ...prev, minBars5m: parseInt(e.target.value) || 3 }))}
                                                min="1"
                                                max="20"
                                            />
                                        </div>
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Results Section */}
                    {metadata && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Target className="h-5 w-5 text-green-600" />
                                    Confirmed Momentum Results
                                </CardTitle>
                                <CardDescription>
                                    Stocks showing strong 1-minute momentum confirmed by bullish 5-minute breakouts above recent highs
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {/* Metadata Summary */}
                                <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    <div className="rounded-lg border bg-muted/50 p-4">
                                        <div className="flex items-center gap-2 text-sm font-medium">
                                            <Clock className="h-4 w-4 text-blue-600" />
                                            Reference Time
                                        </div>
                                        <div className="mt-2 text-lg font-semibold">
                                            {formatLastUpdate(metadata.reference_time_est)}
                                        </div>
                                        <div className="text-xs text-muted-foreground">EST</div>
                                    </div>
                                    <div className="rounded-lg border bg-muted/50 p-4">
                                        <div className="flex items-center gap-2 text-sm font-medium">
                                            <Timer className="h-4 w-4 text-orange-600" />
                                            Lookback Window
                                        </div>
                                        <div className="mt-2 text-lg font-semibold">
                                            {metadata.lookback_minutes} min
                                        </div>
                                        <div className="text-xs text-muted-foreground">1m momentum scan</div>
                                    </div>
                                    <div className="rounded-lg border bg-muted/50 p-4">
                                        <div className="flex items-center gap-2 text-sm font-medium">
                                            <BarChart3 className="h-4 w-4 text-purple-600" />
                                            5m Window
                                        </div>
                                        <div className="mt-2 text-lg font-semibold">
                                            {metadata.five_min_range_minutes || 25} min
                                        </div>
                                        <div className="text-xs text-muted-foreground">Structure validation</div>
                                    </div>
                                    <div className="rounded-lg border bg-muted/50 p-4">
                                        <div className="flex items-center gap-2 text-sm font-medium">
                                            <TrendingUp className="h-4 w-4 text-green-600" />
                                            Candidates Found
                                        </div>
                                        <div className="mt-2 text-lg font-semibold">
                                            {results.length}
                                        </div>
                                        <div className="text-xs text-muted-foreground">confirmed breakouts</div>
                                    </div>
                                </div>

                                <Separator className="mb-6" />

                                {/* Results Table */}
                                {results.length > 0 ? (
                                    <div className="overflow-x-auto">
                                        <table className="w-full table-auto text-sm">
                                            <thead>
                                                <tr className="border-b">
                                                    <th className="px-4 py-3 text-left font-medium">Symbol</th>
                                                    <th className="px-4 py-3 text-right font-medium">Last Price</th>
                                                    <th className="px-4 py-3 text-right font-medium">1m Move%</th>
                                                    <th className="px-4 py-3 text-right font-medium">1m Noise%</th>
                                                    <th className="px-4 py-3 text-right font-medium">5m Body%</th>
                                                    <th className="px-4 py-3 text-right font-medium">5m High</th>
                                                    <th className="px-4 py-3 text-right font-medium">Volume</th>
                                                    <th className="px-4 py-3 text-right font-medium">Age</th>
                                                    <th className="px-4 py-3 text-right font-medium">Last Update</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {results.map((result, index) => (
                                                    <tr key={index} className="border-b transition-colors hover:bg-muted/50">
                                                        <td className="px-4 py-3">
                                                            <Badge variant="secondary" className="font-mono">
                                                                {result.symbol}
                                                            </Badge>
                                                        </td>
                                                        <td className="px-4 py-3 text-right font-mono">
                                                            ${result.last_price.toFixed(4)}
                                                        </td>
                                                        <td className="px-4 py-3 text-right">
                                                            <Badge 
                                                                variant={result.move_pct >= 2.0 ? "default" : "secondary"}
                                                                className={result.move_pct >= 2.0 ? "bg-green-100 text-green-800" : ""}
                                                            >
                                                                +{result.move_pct.toFixed(2)}%
                                                            </Badge>
                                                        </td>
                                                        <td className="px-4 py-3 text-right font-mono text-muted-foreground">
                                                            {result.noise_pct.toFixed(2)}%
                                                        </td>
                                                        <td className="px-4 py-3 text-right">
                                                            <Badge 
                                                                variant={result.body_pct_5m >= 1.0 ? "default" : "secondary"}
                                                                className={result.body_pct_5m >= 1.0 ? "bg-blue-100 text-blue-800" : ""}
                                                            >
                                                                +{result.body_pct_5m.toFixed(2)}%
                                                            </Badge>
                                                        </td>
                                                        <td className="px-4 py-3 text-right font-mono text-muted-foreground">
                                                            ${result.recent5_high.toFixed(4)}
                                                        </td>
                                                        <td className="px-4 py-3 text-right font-mono text-muted-foreground">
                                                            {result.volume_sum_1m.toLocaleString()}
                                                        </td>
                                                        <td className="px-4 py-3 text-right font-mono text-muted-foreground">
                                                            {result.age_minutes.toFixed(1)}m
                                                        </td>
                                                        <td className="px-4 py-3 text-right font-mono text-muted-foreground">
                                                            {formatLastUpdate(result.last_ts_est_1m)}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                ) : (
                                    <div className="flex flex-col items-center justify-center py-12 text-center">
                                        <Target className="mx-auto mb-4 h-12 w-12 text-muted-foreground" />
                                        <h3 className="mb-2 text-lg font-semibold">No confirmed momentum candidates found</h3>
                                        <p className="text-muted-foreground">
                                            {metadata.message || "Try adjusting the time window or reducing the minimum move threshold."}
                                        </p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}
                </div>
            </AppLayout>
        </>
    );
}