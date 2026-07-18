import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import { Head, router, Link } from '@inertiajs/react';
import { TrendingDown, Clock, RefreshCw, Activity, Target, Volume2, Timer, BarChart3, AlertTriangle } from 'lucide-react';
import { show as showAsset } from '@/routes/asset-info';
import { useState, useEffect } from 'react';

interface BottomCandidate {
    symbol: string;
    asset_id: number;
    price: number;
    baseLow: number;
    gainFromBottomPct: number;
    rsi: number;
    bbLower: number;
    emaFast: number;
    score: number;
    flags: string[];
    oversoldTs: string;
    baseStartTs: string;
    barTs: string;
    asOf: string;
}

interface BottomDetectionMetadata {
    scan_date: string;
    lookback_days: number;
    min_rsi_oversold: number;
    max_rsi_oversold: number;
    min_base_days: number;
    min_reclaim_volume_ratio: number;
    min_dollar_volume: number;
    error?: string;
}

interface BottomDetectionData {
    candidates: BottomCandidate[];
    metadata: BottomDetectionMetadata;
}

interface BottomDetectPageProps {
    title: string;
    scan_date?: string;
    lookback_days?: number;
    min_rsi_oversold?: number;
    max_rsi_oversold?: number;
    min_base_days?: number;
    min_reclaim_volume_ratio?: number;
    min_dollar_volume?: number;
    max_gain_from_bottom_pct?: number;
    bottom_data?: BottomDetectionData;
}

export default function BottomDetect({
    title,
    scan_date,
    lookback_days = 30,
    min_rsi_oversold = 25,
    max_rsi_oversold = 35,
    min_base_days = 3,
    min_reclaim_volume_ratio = 1.5,
    min_dollar_volume = 1000000,
    max_gain_from_bottom_pct = 15.0,
    bottom_data,
}: BottomDetectPageProps) {
    const [isLoading, setIsLoading] = useState(false);
    const [showAdvanced, setShowAdvanced] = useState(false);
    const [parameters, setParameters] = useState({
        lookbackDays: lookback_days,
        minRsiOversold: min_rsi_oversold,
        maxRsiOversold: max_rsi_oversold,
        minBaseDays: min_base_days,
        minReclaimVolumeRatio: min_reclaim_volume_ratio,
        minDollarVolume: min_dollar_volume,
        maxGainFromBottomPct: max_gain_from_bottom_pct
    });

    // Format current date as YYYY-MM-DD
    const getCurrentDate = () => {
        const now = new Date();
        return now.toISOString().slice(0, 10);
    };

    const [selectedDate, setSelectedDate] = useState(scan_date || getCurrentDate());

    // Update date when scan_date prop changes
    useEffect(() => {
        setSelectedDate(scan_date || getCurrentDate());
    }, [scan_date]);

    const handleRefresh = () => {
        setIsLoading(true);
        
        const params: Record<string, string | number> = {
            scan_date: selectedDate,
            lookback_days: parameters.lookbackDays,
            min_rsi_oversold: parameters.minRsiOversold,
            max_rsi_oversold: parameters.maxRsiOversold,
            min_base_days: parameters.minBaseDays,
            min_reclaim_volume_ratio: parameters.minReclaimVolumeRatio,
            min_dollar_volume: parameters.minDollarVolume,
            maxGainFromBottomPct: parameters.maxGainFromBottomPct,
        };

        router.get('/analysis/bottom-detect', params, {
            preserveState: false,
            onFinish: () => setIsLoading(false),
        });
    };

    const handleParameterChange = (param: string, value: string) => {
        setParameters(prev => ({ ...prev, [param]: parseFloat(value) || 0 }));
    };

    const formatCurrency = (value: number): string => {
        return new Intl.NumberFormat('en-US', { 
            style: 'currency', 
            currency: 'USD' 
        }).format(value);
    };

    const formatNumber = (value: number): string => {
        return new Intl.NumberFormat('en-US').format(value);
    };

    const formatDate = (dateStr: string): string => {
        return new Date(dateStr).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        });
    };

    const getScoreBadgeVariant = (score: number) => {
        if (score >= 80) return 'default'; // Green for high scores
        if (score >= 60) return 'secondary'; // Gray for medium scores
        return 'destructive'; // Red for low scores
    };

    const getRsiBadgeVariant = (rsi: number) => {
        if (rsi <= 30) return 'default'; // Green for oversold
        if (rsi <= 50) return 'secondary'; // Gray for neutral
        return 'destructive'; // Red for overbought
    };

    return (
        <AppLayout>
            <Head title={title} />
            
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <Heading 
                        title="Bottom Detection Analysis" 
                        description="Technical analysis for identifying potential stock bottoms using RSI oversold events, base formation, and volume confirmation"
                    />
                    <div className="flex items-center gap-2">
                        <Button
                            onClick={() => setShowAdvanced(!showAdvanced)}
                            variant="outline"
                            size="sm"
                        >
                            {showAdvanced ? 'Hide' : 'Show'} Advanced
                        </Button>
                        <Button
                            onClick={handleRefresh}
                            disabled={isLoading}
                            size="sm"
                        >
                            {isLoading ? (
                                <>
                                    <RefreshCw className="mr-2 h-4 w-4 animate-spin" />
                                    Scanning...
                                </>
                            ) : (
                                <>
                                    <RefreshCw className="mr-2 h-4 w-4" />
                                    Refresh
                                </>
                            )}
                        </Button>
                    </div>
                </div>

                {/* Controls */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Target className="h-5 w-5" />
                            Analysis Parameters
                        </CardTitle>
                        <CardDescription>
                            Configure the parameters for bottom detection analysis
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="date">Scan Date</Label>
                                <Input
                                    id="date"
                                    type="date"
                                    value={selectedDate}
                                    onChange={(e) => setSelectedDate(e.target.value)}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="lookback">Lookback Days</Label>
                                <Input
                                    id="lookback"
                                    type="number"
                                    min="1"
                                    max="90"
                                    value={parameters.lookbackDays}
                                    onChange={(e) => handleParameterChange('lookbackDays', e.target.value)}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="minDollarVolume">Min Dollar Volume</Label>
                                <Input
                                    id="minDollarVolume"
                                    type="number"
                                    min="0"
                                    step="100000"
                                    value={parameters.minDollarVolume}
                                    onChange={(e) => handleParameterChange('minDollarVolume', e.target.value)}
                                />
                            </div>
                        </div>

                        {showAdvanced && (
                            <>
                                <Separator />
                                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="minRsi">Min RSI Oversold</Label>
                                        <Input
                                            id="minRsi"
                                            type="number"
                                            min="0"
                                            max="50"
                                            value={parameters.minRsiOversold}
                                            onChange={(e) => handleParameterChange('minRsiOversold', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="maxRsi">Max RSI Oversold</Label>
                                        <Input
                                            id="maxRsi"
                                            type="number"
                                            min="0"
                                            max="50"
                                            value={parameters.maxRsiOversold}
                                            onChange={(e) => handleParameterChange('maxRsiOversold', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="minBaseDays">Min Base Days</Label>
                                        <Input
                                            id="minBaseDays"
                                            type="number"
                                            min="1"
                                            max="20"
                                            value={parameters.minBaseDays}
                                            onChange={(e) => handleParameterChange('minBaseDays', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="minVolumeRatio">Min Volume Ratio</Label>
                                        <Input
                                            id="minVolumeRatio"
                                            type="number"
                                            min="1"
                                            max="5"
                                            step="0.1"
                                            value={parameters.minReclaimVolumeRatio}
                                            onChange={(e) => handleParameterChange('minReclaimVolumeRatio', e.target.value)}
                                        />
                                    </div>
                                </div>
                                
                                <Separator />
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="maxGainFromBottom">Max Gain from Bottom %</Label>
                                        <Input
                                            id="maxGainFromBottom"
                                            type="number"
                                            min="5"
                                            max="50"
                                            step="1"
                                            value={parameters.maxGainFromBottomPct}
                                            onChange={(e) => handleParameterChange('maxGainFromBottomPct', e.target.value)}
                                        />
                                    </div>
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>

                {/* Results */}
                {bottom_data && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <TrendingDown className="h-5 w-5" />
                                Bottom Detection Results
                                {bottom_data.candidates.length > 0 && (
                                    <Badge variant="secondary">
                                        {bottom_data.candidates.length} candidates found
                                    </Badge>
                                )}
                            </CardTitle>
                            <CardDescription>
                                Stocks showing potential bottom patterns as of {formatDate(bottom_data.metadata.scan_date)}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {bottom_data.metadata.error ? (
                                <div className="flex items-center gap-2 p-4 border border-destructive/20 bg-destructive/10 rounded-lg text-destructive">
                                    <AlertTriangle className="h-4 w-4" />
                                    <span>{bottom_data.metadata.error}</span>
                                </div>
                            ) : bottom_data.candidates.length === 0 ? (
                                <div className="text-center py-8 text-muted-foreground">
                                    No bottom candidates found for the current parameters.
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Symbol</TableHead>
                                                <TableHead>Price</TableHead>
                                                <TableHead>Score</TableHead>
                                                <TableHead>RSI</TableHead>
                                                <TableHead>BB Lower</TableHead>
                                                <TableHead>EMA Fast</TableHead>
                                                <TableHead>Flags</TableHead>
                                                <TableHead>Oversold Date</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {bottom_data.candidates.map((candidate) => (
                                                <TableRow key={candidate.symbol}>
                                                    <TableCell>
                                                        <a
                                                            href={showAsset.url(candidate.asset_id)}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="font-semibold text-blue-600 hover:text-blue-800 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
                                                        >
                                                            {candidate.symbol}
                                                        </a>
                                                    </TableCell>
                                                    <TableCell>{formatCurrency(candidate.price)}</TableCell>
                                                    <TableCell>
                                                        <Badge variant={getScoreBadgeVariant(candidate.score)}>
                                                            {candidate.score.toFixed(1)}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge variant={getRsiBadgeVariant(candidate.rsi)}>
                                                            {candidate.rsi.toFixed(1)}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell>{formatCurrency(candidate.bbLower)}</TableCell>
                                                    <TableCell>{formatCurrency(candidate.emaFast)}</TableCell>
                                                    <TableCell>
                                                        <div className="flex gap-1 flex-wrap">
                                                            {candidate.flags.map((flag, index) => (
                                                                <Badge key={index} variant="outline" className="text-xs">
                                                                    {flag}
                                                                </Badge>
                                                            ))}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>{formatDate(candidate.oversoldTs)}</TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {/* Analysis Info */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <BarChart3 className="h-5 w-5" />
                            Analysis Methodology
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                            <div>
                                <h4 className="font-semibold mb-2">Step 1: Oversold Detection</h4>
                                <p className="text-muted-foreground">
                                    Identifies stocks with RSI values between 25-35, indicating potential oversold conditions
                                </p>
                            </div>
                            <div>
                                <h4 className="font-semibold mb-2">Step 2: Base Formation</h4>
                                <p className="text-muted-foreground">
                                    Analyzes price consolidation patterns following oversold events for at least 3 days
                                </p>
                            </div>
                            <div>
                                <h4 className="font-semibold mb-2">Step 3: Volume Confirmation</h4>
                                <p className="text-muted-foreground">
                                    Confirms reclaim patterns with volume spikes of 1.5x or higher than recent averages
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}