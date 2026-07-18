import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Target, TrendingUp, Star, Award, Clock, RefreshCw, Filter, Activity } from 'lucide-react';
import { useState, useEffect } from 'react';
import { show as showAsset } from '@/routes/asset-info';

interface TightStopPick {
    symbol: string;
    asset_type: string;
    asset_id?: number | null;
    last_price: number;
    trend_pct: number;
    max_drawdown_pct: number;
    bars: number;
    up_bars: number;
    down_bars: number;
    risk_score: number;
    daily_high_price?: number;
    daily_high_pct?: number;
}

interface AnalysisSummary {
    end_est: string;
    lookback_minutes: number;
    max_drawdown_pct: number;
    min_trend_pct: number;
    asset_type: string;
    only_over_1mil: boolean;
    max_drawdown_display: string;
    min_trend_display: string;
    market_cap_filter: string;
}

interface CurrentParams {
    lookback: number;
    max_drawdown: number;
    min_trend: number;
    over_1mil: boolean;
}

interface PaulsPicksPageProps {
    title: string;
    description: string;
    time?: string;
    picks: TightStopPick[];
    analysisSummary: AnalysisSummary;
    totalPicks: number;
    assetTypeFilter: 'stock';
    currentParams: CurrentParams;
}

export default function PaulsPicksIndex({
    title,
    description,
    time,
    picks,
    analysisSummary,
    totalPicks,
    assetTypeFilter,
    currentParams,
}: PaulsPicksPageProps) {
    const [isLoading, setIsLoading] = useState(false);
    const [currentFilter, setCurrentFilter] = useState(assetTypeFilter);

    // Analysis parameter states
    const [lookbackMinutes, setLookbackMinutes] = useState(currentParams.lookback);
    const [maxDrawdownPct, setMaxDrawdownPct] = useState(currentParams.max_drawdown);
    const [minTrendPct, setMinTrendPct] = useState(currentParams.min_trend);
    const [onlyOver1Mil, setOnlyOver1Mil] = useState(currentParams.over_1mil);

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

    const handleDateTimeChange = (event: React.ChangeEvent<HTMLInputElement>) => {
        setSelectedDateTime(event.target.value);
    };

    const handleAnalyze = () => {
        setIsLoading(true);
        // Convert datetime-local value back to YYYY-MM-DD HH:MM:SS format
        const formattedDateTime = selectedDateTime.replace('T', ' ') + ':00';
        
        const params: Record<string, string | number | boolean> = {
            time: formattedDateTime,
            filter: currentFilter,
            lookback: lookbackMinutes,
            max_drawdown: maxDrawdownPct,
            min_trend: minTrendPct,
            over_1mil: onlyOver1Mil,
        };
        
        router.get('/clean-2h', params, {
            preserveState: false,
            onFinish: () => setIsLoading(false)
        });
    };

    const handleResetToNow = () => {
        setSelectedDateTime(getCurrentESTDateTime());
        setIsLoading(true);
        
        const params: Record<string, string | number | boolean> = {
            filter: currentFilter,
            lookback: lookbackMinutes,
            max_drawdown: maxDrawdownPct,
            min_trend: minTrendPct,
            over_1mil: onlyOver1Mil,
        };
        
        router.get('/clean-2h', params, {
            preserveState: false,
            onFinish: () => setIsLoading(false)
        });
    };

    const handleFilterChange = (filter: 'stock') => {
        setCurrentFilter(filter);
        const params: Record<string, string | number | boolean> = { 
            filter,
            lookback: lookbackMinutes,
            max_drawdown: maxDrawdownPct,
            min_trend: minTrendPct,
            over_1mil: onlyOver1Mil,
        };
        if (time) {
            params.time = time;
        }
        router.get('/clean-2h', params, {
            preserveState: true,
            replace: true,
        });
    };
    return (
        <>
            <Head title={title} />
            <AppLayout breadcrumbs={[{ title: title, href: '/clean-2h' }]}>
                <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between">
                        <Heading title={title} description={description} />
                        <div className="flex items-center gap-4">
                            <div className="flex gap-2">
                                <Button
                                    variant={currentFilter === 'stock' ? 'default' : 'outline'}
                                    onClick={() => handleFilterChange('stock')}
                                    size="sm"
                                >
                                    Stocks
                                </Button>
                                <Button
                                    variant={currentFilter === 'crypto' ? 'default' : 'outline'}
                                    onClick={() => handleFilterChange('stock')}
                                    size="sm"
                                >
                                    Crypto
                                </Button>
                            </div>
                        </div>
                    </div>

                    {/* Stats Cards */}
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">
                                    Total Picks
                                </CardTitle>
                                <Star className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{totalPicks}</div>
                                <p className="text-xs text-muted-foreground">
                                    Tight stops candidates
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">
                                    Lookback Period
                                </CardTitle>
                                <Clock className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{analysisSummary.lookback_minutes}m</div>
                                <p className="text-xs text-muted-foreground">
                                    Analysis window
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">
                                    Max Drawdown
                                </CardTitle>
                                <TrendingUp className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{analysisSummary.max_drawdown_display}</div>
                                <p className="text-xs text-muted-foreground">
                                    Threshold limit
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">
                                    Min Trend
                                </CardTitle>
                                <Award className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{analysisSummary.min_trend_display}</div>
                                <p className="text-xs text-muted-foreground">
                                    Required minimum
                                </p>
                            </CardContent>
                        </Card>
                    </div>                {/* Date/Time Analysis Controls */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Clock className="h-5 w-5 text-blue-600" />
                            Analysis Time Selection
                        </CardTitle>
                        <CardDescription>
                            Select a specific date and time to analyze historical patterns, or use current time for real-time analysis
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

                {/* Analysis Parameters Controls */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Target className="h-5 w-5 text-purple-600" />
                            Analysis Parameters
                        </CardTitle>
                        <CardDescription>
                            Customize the tight stops analysis criteria to refine your picks
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div className="space-y-2">
                                <Label htmlFor="lookback-minutes">
                                    Lookback Period (minutes)
                                </Label>
                                <Input
                                    id="lookback-minutes"
                                    type="number"
                                    min="30"
                                    max="480"
                                    step="30"
                                    value={lookbackMinutes}
                                    onChange={(e) => setLookbackMinutes(parseInt(e.target.value))}
                                    className="w-full"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Time window for analysis (30-480 min)
                                </p>
                            </div>
                            
                            <div className="space-y-2">
                                <Label htmlFor="max-drawdown">
                                    Max Drawdown (%)
                                </Label>
                                <Input
                                    id="max-drawdown"
                                    type="number"
                                    min="0.1"
                                    max="5.0"
                                    step="0.1"
                                    value={maxDrawdownPct * 100}
                                    onChange={(e) => setMaxDrawdownPct(parseFloat(e.target.value) / 100)}
                                    className="w-full"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Maximum allowed peak-to-trough drop
                                </p>
                            </div>
                            
                            <div className="space-y-2">
                                <Label htmlFor="min-trend">
                                    Min Trend (%)
                                </Label>
                                <Input
                                    id="min-trend"
                                    type="number"
                                    min="0.1"
                                    max="2.0"
                                    step="0.1"
                                    value={minTrendPct * 100}
                                    onChange={(e) => setMinTrendPct(parseFloat(e.target.value) / 100)}
                                    className="w-full"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Minimum required upward trend
                                </p>
                            </div>
                        </div>
                        
                        <div className="mt-6 flex gap-3">
                            <Button 
                                onClick={handleAnalyze}
                                disabled={isLoading}
                                className="flex items-center gap-2"
                            >
                                {isLoading ? (
                                    <>
                                        <RefreshCw className="h-4 w-4 animate-spin" />
                                        Updating Analysis...
                                    </>
                                ) : (
                                    <>
                                        <TrendingUp className="h-4 w-4" />
                                        Update Analysis
                                    </>
                                )}
                            </Button>
                            
                            <Button 
                                onClick={() => {
                                    setLookbackMinutes(120);
                                    setMaxDrawdownPct(0.01);
                                    setMinTrendPct(0.005);
                                }}
                                variant="outline"
                                disabled={isLoading}
                                className="flex items-center gap-2"
                            >
                                <RefreshCw className="h-4 w-4" />
                                Reset Defaults
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Tight Stops Picks Table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Star className="h-5 w-5 text-yellow-500" />
                            Tight Stops Strategy Picks
                        </CardTitle>
                        <CardDescription>
                            Assets suitable for tight 0.5-1% stop-loss strategies, ranked by risk-adjusted trend score
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {picks.length > 0 ? (
                            <div className="rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Symbol</TableHead>
                                            <TableHead>Price</TableHead>
                                            <TableHead>Trend</TableHead>
                                            <TableHead>Max DD</TableHead>
                                            <TableHead>Bars</TableHead>
                                            <TableHead>Up/Down</TableHead>
                                            <TableHead>Risk Score</TableHead>
                                            {time && (
                                                <TableHead>Days High</TableHead>
                                            )}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {picks.map((pick, index) => (
                                            <TableRow key={`${pick.symbol}-${index}`}>
                                                <TableCell className="font-medium">
                                                    <div className="flex items-center gap-2">
                                                        {pick.asset_id ? (
                                                            <a
                                                                href={showAsset.url(pick.asset_id)}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                className="text-blue-600 hover:text-blue-700 hover:underline font-bold dark:text-blue-400 dark:hover:text-blue-300"
                                                            >
                                                                {pick.symbol}
                                                            </a>
                                                        ) : (
                                                            pick.symbol
                                                        )}
                                                        <Badge 
                                                            variant="outline" 
                                                            className="text-xs"
                                                        >
                                                            {pick.asset_type}
                                                        </Badge>
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    ${pick.last_price.toFixed(2)}
                                                </TableCell>
                                                <TableCell>
                                                    <span className={pick.trend_pct >= 0 ? "text-green-600" : "text-red-600"}>
                                                        {(pick.trend_pct * 100).toFixed(2)}%
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    <span className="text-red-600">
                                                        {(pick.max_drawdown_pct * 100).toFixed(2)}%
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    {pick.bars}
                                                </TableCell>
                                                <TableCell>
                                                    <span className="text-green-600">{pick.up_bars}</span>
                                                    <span className="text-muted-foreground">/</span>
                                                    <span className="text-red-600">{pick.down_bars}</span>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="secondary">
                                                        {pick.risk_score.toFixed(2)}
                                                    </Badge>
                                                </TableCell>
                                                {time && (
                                                    <TableCell className={`${
                                                        pick.daily_high_pct !== undefined 
                                                            ? 'text-green-600'
                                                            : 'text-gray-400'
                                                    }`}>
                                                        {pick.daily_high_pct !== undefined 
                                                            ? `+${pick.daily_high_pct.toFixed(2)}%`
                                                            : 'N/A'
                                                        }
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        ) : (
                            <div className="text-center py-12 text-muted-foreground">
                                <Activity className="h-12 w-12 mx-auto mb-4 text-muted-foreground/50" />
                                <h3 className="text-lg font-semibold mb-2">No picks found</h3>
                                <p className="max-w-md mx-auto">
                                    No assets met the tight stops criteria for the selected time period. 
                                    Try adjusting the analysis time or parameters.
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Analysis Information */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Target className="h-5 w-5 text-blue-600" />
                            Strategy Details
                        </CardTitle>
                        <CardDescription>
                            How the tight stops analysis works
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div>
                                <h4 className="font-medium mb-2">Selection Criteria:</h4>
                                <ul className="space-y-1 text-muted-foreground">
                                    <li>• Minimum trend: {analysisSummary.min_trend_display}</li>
                                    <li>• Maximum drawdown: {analysisSummary.max_drawdown_display}</li>
                                    <li>• Analysis period: {analysisSummary.lookback_minutes} minutes</li>
                                    <li>• Minimum 3 bars required</li>
                                </ul>
                            </div>
                            <div>
                                <h4 className="font-medium mb-2">Risk Score Calculation:</h4>
                                <ul className="space-y-1 text-muted-foreground">
                                    <li>• Score = Trend % ÷ |Max Drawdown %|</li>
                                    <li>• Higher scores = better risk-adjusted returns</li>
                                    <li>• Sorted by score (best first)</li>
                                    <li>• Suitable for 0.5-1% stop losses</li>
                                </ul>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
            </AppLayout>
        </>
    );
}