import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { Head, router, Link } from '@inertiajs/react';
import { TrendingUp, Clock, RefreshCw, Activity, Target, Volume2, Timer, BarChart3 } from 'lucide-react';
import { show as showAsset } from '@/routes/asset-info';
import { useState, useEffect } from 'react';

interface MomentumCandidate {
    symbol: string;
    asset_id: number;
    last_price: number;
    move_pct: number;
    noise_pct: number;
    bars: number;
    volume_sum: number;
    distance_from_high: number;
    age_minutes: number;
    last_ts_est: string;
    window_high: number;
    window_low: number;
    first_price: number;
}

interface MomentumMetadata {
    reference_time: string | null;
    window_start: string | null;
    window_end: string | null;
    lookback_minutes: number;
    min_move_pct: number;
    asset_type: string;
    error?: string;
    filters: {
        noise_multiplier: number;
        min_bars: number;
        min_price: number;
        min_volume_sum: number;
        max_distance_from_high_pct: number;
    };
}

interface MomentumData {
    candidates: MomentumCandidate[];
    metadata: MomentumMetadata;
}

interface BreakoutPageProps {
    title: string;
    time?: string;
    asset_type?: string;
    lookback_minutes?: number;
    min_move_pct?: number;
    noise_multiplier?: number;
    min_bars?: number;
    min_price?: number;
    min_volume_sum?: number;
    max_distance_from_high_pct?: number;
    momentum_data?: MomentumData;
}

export default function Breakout({
    title,
    time,
    asset_type = 'stock',
    lookback_minutes = 5,            // PHP script default: $defaultLookbackMinutes = 5
    min_move_pct = 0.75,             // PHP script default: $defaultMinMovePct = 0.75
    noise_multiplier = 1.5,          // PHP script default: $noiseMultiplier = 1.5
    min_bars = 5,                    // PHP script default: $minBars = 5
    min_price = 1.0,                 // PHP script default: $minPrice = 1.00
    min_volume_sum = 10000,          // PHP script default: $minVolumeSum = 10000
    max_distance_from_high_pct = 0.25, // PHP script default: $maxDistanceFromHighPct = 0.25
    momentum_data,
}: BreakoutPageProps) {
    const [isLoading, setIsLoading] = useState(false);
    const [currentAssetType, setCurrentAssetType] = useState(asset_type);
    const [currentLookback, setCurrentLookback] = useState(lookback_minutes);
    const [currentMinMove, setCurrentMinMove] = useState(min_move_pct);
    const [showAdvanced, setShowAdvanced] = useState(false);
    const [parameters, setParameters] = useState({
        lookback: lookback_minutes,
        minMove: min_move_pct,
        noiseMultiplier: noise_multiplier,
        minBars: min_bars,
        minPrice: min_price,
        minVolumeSum: min_volume_sum,
        maxDistanceFromHigh: max_distance_from_high_pct
    });

    // Format current EST time as datetime-local value
    const getCurrentESTDateTime = () => {
        const now = new Date();
        // Convert to EST (UTC-5)
        const est = new Date(now.getTime() - (5 * 60 * 60 * 1000));
        return est.toISOString().slice(0, 16);
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

    // Update datetime picker when time prop changes
    useEffect(() => {
        setSelectedDateTime(getInitialDateTime());
    }, [time]);

    // Auto-refresh every 30 seconds
    useEffect(() => {
        const interval = setInterval(() => {
            // Auto-refresh if we're not currently loading data
            if (!isLoading) {
                // Get current EST time for auto-refresh
                const currentESTTime = getCurrentESTDateTime();
                const formattedDateTime = currentESTTime.replace('T', ' ') + ':00';
                
                const params: Record<string, string | number> = {
                    time: formattedDateTime,
                    asset_type: currentAssetType,
                    lookback: parameters.lookback,
                    min_move: parameters.minMove,
                    noise_multiplier: parameters.noiseMultiplier,
                    min_bars: parameters.minBars,
                    min_price: parameters.minPrice,
                    min_volume_sum: parameters.minVolumeSum,
                    max_distance_from_high: parameters.maxDistanceFromHigh,
                };
                
                // Update the time picker to current time
                setSelectedDateTime(currentESTTime);
                
                // Use router.get to refresh with updated time parameter
                router.get('/analysis/breakout', params, {
                    preserveState: false,
                    only: ['momentum_data']
                });
            }
        }, 30000); // 30 seconds

        return () => clearInterval(interval);
    }, [isLoading, currentAssetType, parameters]);

    const handleDateTimeChange = (event: React.ChangeEvent<HTMLInputElement>) => {
        setSelectedDateTime(event.target.value);
    };

    const handleResetToDefaults = () => {
        setParameters({
            lookback: 5,                     // PHP script defaults
            minMove: 0.75,
            noiseMultiplier: 1.5,
            minBars: 5,
            minPrice: 1.0,
            minVolumeSum: 10000,
            maxDistanceFromHigh: 0.25
        });
    };

    const handleAnalyze = () => {
        setIsLoading(true);
        // Convert datetime-local value back to YYYY-MM-DD HH:MM:SS format
        const formattedDateTime = selectedDateTime.replace('T', ' ') + ':00';
        
        const params: Record<string, string | number> = {
            time: formattedDateTime,
            asset_type: currentAssetType,
            lookback: parameters.lookback,
            min_move: parameters.minMove,
            noise_multiplier: parameters.noiseMultiplier,
            min_bars: parameters.minBars,
            min_price: parameters.minPrice,
            min_volume_sum: parameters.minVolumeSum,
            max_distance_from_high: parameters.maxDistanceFromHigh,
        };
        
        router.get('/analysis/breakout', params, {
            preserveState: false,
            onFinish: () => setIsLoading(false)
        });
    };

    const handleResetToNow = () => {
        setSelectedDateTime(getCurrentESTDateTime());
        setIsLoading(true);
        
        const params: Record<string, string | number> = {
            asset_type: currentAssetType,
            lookback: parameters.lookback,
            min_move: parameters.minMove,
            noise_multiplier: parameters.noiseMultiplier,
            min_bars: parameters.minBars,
            min_price: parameters.minPrice,
            min_volume_sum: parameters.minVolumeSum,
            max_distance_from_high: parameters.maxDistanceFromHigh,
        };
        
        router.get('/analysis/breakout', params, {
            preserveState: false,
            onFinish: () => setIsLoading(false)
        });
    };

    const handleAssetTypeChange = (assetType: string) => {
        setCurrentAssetType(assetType);
    };

    const formatNumber = (value: number): string => {
        if (value >= 1000000) {
            return (value / 1000000).toFixed(1) + 'M';
        } else if (value >= 1000) {
            return (value / 1000).toFixed(1) + 'K';
        }
        return value.toString();
    };

    const getMoveBadgeVariant = (movePct: number) => {
        if (movePct >= 3) return 'default'; // green
        if (movePct >= 1.5) return 'secondary'; // blue
        return 'outline'; // gray
    };

    const formatLastUpdate = (timestampStr: string): string => {
        try {
            // Parse the timestamp string (format: "YYYY-MM-DD HH:MM:SS")
            const date = new Date(timestampStr + ' EST');
            return date.toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
                hour12: true,
                timeZone: 'America/New_York'
            });
        } catch (error) {
            return timestampStr; // fallback to original if parsing fails
        }
    };

    return (
        <>
            <Head title={title} />
            <AppLayout>
                <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                    <Heading title={title} description="Identify stocks and crypto breaking out of key resistance levels" />

                    {/* Analysis Time Selection */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Clock className="h-5 w-5 text-blue-600" />
                                Analysis Time Selection
                            </CardTitle>
                            <CardDescription>
                                Select a specific date and time to analyze historical breakout patterns, or use current time for real-time analysis
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
                                <Target className="h-5 w-5 text-purple-600" />
                                Analysis Parameters
                            </CardTitle>
                            <CardDescription>
                                Configure the upward momentum detection criteria
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-6">
                                {/* Basic Parameters */}
                                <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                                    <div className="grid gap-2">
                                        <Label>Asset Type</Label>
                                        <div className="flex gap-2">
                                            <Button
                                                variant={currentAssetType === 'stock' ? 'default' : 'outline'}
                                                onClick={() => handleAssetTypeChange('stock')}
                                                size="sm"
                                            >
                                                Stocks
                                            </Button>
                                            <Button
                                                variant={currentAssetType === 'crypto' ? 'default' : 'outline'}
                                                onClick={() => handleAssetTypeChange('stock')}
                                                size="sm"
                                            >
                                                Crypto
                                            </Button>
                                        </div>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="lookback-minutes">Lookback (Minutes)</Label>
                                        <Input
                                            id="lookback-minutes"
                                            type="number"
                                            value={parameters.lookback}
                                            onChange={(e) => setParameters(prev => ({ ...prev, lookback: parseInt(e.target.value) || 5 }))}
                                            min="1"
                                            max="120"
                                        />
                                        <p className="text-xs text-muted-foreground">Default: 5 minutes</p>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="min-move">Min Move (%)</Label>
                                        <Input
                                            id="min-move"
                                            type="number"
                                            step="0.1"
                                            value={parameters.minMove}
                                            onChange={(e) => setParameters(prev => ({ ...prev, minMove: parseFloat(e.target.value) || 0.75 }))}
                                            min="0.1"
                                            max="10"
                                        />
                                        <p className="text-xs text-muted-foreground">Default: 0.75%</p>
                                    </div>
                                    <div className="grid gap-2">
                                        <Button 
                                            onClick={handleResetToDefaults}
                                            variant="outline" 
                                            size="sm"
                                            className="self-end"
                                        >
                                            Reset to PHP Defaults
                                        </Button>
                                    </div>
                                </div>

                                {/* Advanced Parameters Toggle */}
                                <div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setShowAdvanced(!showAdvanced)}
                                        className="flex items-center gap-2 p-0 h-auto text-sm text-muted-foreground hover:text-foreground"
                                    >
                                        {showAdvanced ? 'Hide' : 'Show'} Advanced Parameters
                                    </Button>
                                </div>

                                {/* Advanced Parameters */}
                                {showAdvanced && (
                                    <div className="space-y-4 border-t pt-4">
                                        <h3 className="font-medium">Advanced Parameters (match PHP script exactly)</h3>
                                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                            <div className="grid gap-2">
                                                <Label htmlFor="noise-multiplier">Noise Multiplier</Label>
                                                <Input
                                                    id="noise-multiplier"
                                                    type="number"
                                                    step="0.1"
                                                    value={parameters.noiseMultiplier}
                                                    onChange={(e) => setParameters(prev => ({ ...prev, noiseMultiplier: parseFloat(e.target.value) || 1.5 }))}
                                                    min="0.5"
                                                    max="5.0"
                                                />
                                                <p className="text-xs text-muted-foreground">Default: 1.5</p>
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="min-bars">Min 1m Bars</Label>
                                                <Input
                                                    id="min-bars"
                                                    type="number"
                                                    value={parameters.minBars}
                                                    onChange={(e) => setParameters(prev => ({ ...prev, minBars: parseInt(e.target.value) || 5 }))}
                                                    min="1"
                                                    max="20"
                                                />
                                                <p className="text-xs text-muted-foreground">Default: 5</p>
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="min-price">Min Price ($)</Label>
                                                <Input
                                                    id="min-price"
                                                    type="number"
                                                    step="0.1"
                                                    value={parameters.minPrice}
                                                    onChange={(e) => setParameters(prev => ({ ...prev, minPrice: parseFloat(e.target.value) || 1.0 }))}
                                                    min="0.1"
                                                    max="50"
                                                />
                                                <p className="text-xs text-muted-foreground">Default: $1.00</p>
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="min-volume-sum">Min Volume Sum</Label>
                                                <Input
                                                    id="min-volume-sum"
                                                    type="number"
                                                    value={parameters.minVolumeSum}
                                                    onChange={(e) => setParameters(prev => ({ ...prev, minVolumeSum: parseInt(e.target.value) || 10000 }))}
                                                    min="1000"
                                                    max="1000000"
                                                />
                                                <p className="text-xs text-muted-foreground">Default: 10,000</p>
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="max-distance-from-high">Max Distance from High (%)</Label>
                                                <Input
                                                    id="max-distance-from-high"
                                                    type="number"
                                                    step="0.01"
                                                    value={parameters.maxDistanceFromHigh}
                                                    onChange={(e) => setParameters(prev => ({ ...prev, maxDistanceFromHigh: parseFloat(e.target.value) || 0.25 }))}
                                                    min="0.01"
                                                    max="2.0"
                                                />
                                                <p className="text-xs text-muted-foreground">Default: 0.25%</p>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Results */}
                    {momentum_data && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Activity className="h-5 w-5 text-green-600" />
                                    Upward Momentum Results
                                </CardTitle>
                                <CardDescription>
                                    {momentum_data.metadata.error ? (
                                        <span className="text-red-600">{momentum_data.metadata.error}</span>
                                    ) : (
                                        <>
                                            Found {momentum_data.candidates.length} candidates showing upward momentum
                                            {momentum_data.metadata.reference_time && (
                                                <> at {momentum_data.metadata.reference_time} EST</>
                                            )}
                                        </>
                                    )}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {momentum_data.metadata.error ? (
                                    <div className="text-center py-8 text-muted-foreground">
                                        <Activity className="h-12 w-12 mx-auto mb-4 opacity-50" />
                                        <h3 className="text-lg font-medium mb-2">No Data Available</h3>
                                        <p>{momentum_data.metadata.error}</p>
                                    </div>
                                ) : momentum_data.candidates.length === 0 ? (
                                    <div className="text-center py-8 text-muted-foreground">
                                        <Activity className="h-12 w-12 mx-auto mb-4 opacity-50" />
                                        <h3 className="text-lg font-medium mb-2">No Candidates Found</h3>
                                        <p>Try adjusting the parameters or time range</p>
                                    </div>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>Symbol</TableHead>
                                                    <TableHead>Last Price</TableHead>
                                                    <TableHead>Move %</TableHead>
                                                    <TableHead>Noise %</TableHead>
                                                    <TableHead>Bars</TableHead>
                                                    <TableHead>Volume</TableHead>
                                                    <TableHead>Dist from High</TableHead>
                                                    <TableHead>Age (min)</TableHead>
                                                    <TableHead>Last Update</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {momentum_data.candidates.map((candidate, index) => (
                                                <TableRow key={`${candidate.symbol}-${index}`}>
                                                    <TableCell className="font-medium">
                                                        {candidate.asset_id ? (
                                                            <Link
                                                                href={showAsset.url(candidate.asset_id)}
                                                                className="text-blue-600 hover:text-blue-700 hover:underline font-bold dark:text-blue-400 dark:hover:text-blue-300"
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                            >
                                                                {candidate.symbol}
                                                            </Link>
                                                        ) : (
                                                            candidate.symbol
                                                        )}
                                                    </TableCell>
                                                        <TableCell>
                                                            ${candidate.last_price.toFixed(2)}
                                                        </TableCell>
                                                        <TableCell>
                                                            <Badge variant={getMoveBadgeVariant(candidate.move_pct)}>
                                                                +{candidate.move_pct.toFixed(2)}%
                                                            </Badge>
                                                        </TableCell>
                                                        <TableCell>{candidate.noise_pct.toFixed(2)}%</TableCell>
                                                        <TableCell>{candidate.bars}</TableCell>
                                                        <TableCell>{formatNumber(candidate.volume_sum)}</TableCell>
                                                        <TableCell>{candidate.distance_from_high.toFixed(3)}%</TableCell>
                                                        <TableCell>{candidate.age_minutes.toFixed(1)}</TableCell>
                                                        <TableCell className="text-xs">
                                                            {formatLastUpdate(candidate.last_ts_est)}
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
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