import { Head, router, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import Heading from '@/components/heading';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { TrendingUp, Activity, CheckCircle, AlertCircle, BarChart3, Filter, Target } from 'lucide-react';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { show as showAsset } from '@/routes/asset-info';
import { useState, useEffect } from 'react';

interface BuyZoneCandidate {
    symbol: string;
    asset_id: number | null;
    high_7d: number;
    low_7d: number;
    entry_price: number;
    dist_from_7d_high: number;
    dist_from_7d_high_pct: number;
    retracement_pct: number;
    vwap_now: number | null;
    vwap_reclaimed: boolean;
    ema_state: string;
    ema_fast: number | null;
    ema_slow: number | null;
    rvol: number | null;
    pullback_low: number | null;
    stop_price: number;
    risk_per_share: number;
    risk_pct: number;
    risk_pct_pct: number;
    stop_viable_1pct: boolean;
    account_size: number;
    risk_per_trade_pct: number;
    risk_dollars: number;
    recommended_shares: number;
    position_notional: number;
    today_1m_bars: number;
    today_vol_sum: number;
    avg_daily_vol: number | null;
    was_below_vwap_in_lookback: boolean;
    is_above_vwap_now: boolean;
}

interface Filters {
    assetType: string;
    days: number;
    topPerformersLimit: number;
}

interface BuyZoneTopPerformersProps {
    candidates: BuyZoneCandidate[];
    totalTopPerformers: number;
    filters: Filters;
}

export default function BuyZoneTopPerformers({ candidates, totalTopPerformers, filters }: BuyZoneTopPerformersProps) {
    const [assetType, setAssetType] = useState(filters.assetType);
    const [days, setDays] = useState(filters.days.toString());
    const [testDateTime, setTestDateTime] = useState('');

    // Auto-refresh every 30 seconds
    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({
                preserveState: true,
                preserveScroll: true,
            });
        }, 30000); // 30 seconds

        return () => clearInterval(interval);
    }, []);

    const applyFilters = () => {
        const params: Record<string, any> = {
            assetType,
            days: parseInt(days),
            topPerformersLimit: filters.topPerformersLimit,
        };
        
        // Only include testDateTime if user selected a past time
        if (testDateTime) {
            params.testDateTime = testDateTime;
        }
        
        router.get('/analysis/buy-zone-top-performers', params, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const formatPrice = (price: number) => {
        return price.toFixed(2);
    };

    const formatVolume = (volume: number) => {
        if (volume >= 1_000_000_000) {
            return `${(volume / 1_000_000_000).toFixed(2)}B`;
        }
        if (volume >= 1_000_000) {
            return `${(volume / 1_000_000).toFixed(2)}M`;
        }
        if (volume >= 1_000) {
            return `${(volume / 1_000).toFixed(2)}K`;
        }
        return volume.toString();
    };

    const avgRvol = candidates.length > 0 
        ? candidates.reduce((sum, c) => sum + (c.rvol || 0), 0) / candidates.length 
        : 0;

    const avgRetrace = candidates.length > 0 
        ? candidates.reduce((sum, c) => sum + c.retracement_pct, 0) / candidates.length 
        : 0;

    const viableStopCount = candidates.filter(c => c.stop_viable_1pct).length;
    const totalPositionSize = candidates.reduce((sum, c) => sum + c.position_notional, 0);
    const avgRiskPct = candidates.length > 0
        ? candidates.reduce((sum, c) => sum + c.risk_pct_pct, 0) / candidates.length
        : 0;

    return (
        <>
            <Head title="Buy Zone Top Performers - Analysis" />
            <AppLayout>
                <div className="flex flex-col gap-6 p-6">
                    <Heading 
                        title="Buy Zone Top Performers"
                        description={`Filtered ${candidates.length} buy zone candidates from ${totalTopPerformers} top performers. Shows stocks with optimal pullback, VWAP reclaim, EMA alignment, and relative volume.`}
                    />

                    {/* Filters Card */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <Filter className="h-5 w-5" />
                                <CardTitle>Filters</CardTitle>
                            </div>
                            <CardDescription>
                                Configure analysis parameters
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                                <div className="space-y-2">
                                    <label className="text-sm font-medium">Asset Type</label>
                                    <Select value={assetType} onValueChange={setAssetType}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="stock">Stocks</SelectItem>
</SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-2">
                                    <label className="text-sm font-medium">Days Back</label>
                                    <Select value={days} onValueChange={setDays}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="3">3 Days</SelectItem>
                                            <SelectItem value="5">5 Days</SelectItem>
                                            <SelectItem value="7">7 Days</SelectItem>
                                            <SelectItem value="14">14 Days</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-2">
                                    <label className="text-sm font-medium">Test Date/Time (optional)</label>
                                    <Input
                                        type="datetime-local"
                                        value={testDateTime}
                                        onChange={(e) => setTestDateTime(e.target.value)}
                                        placeholder="Leave empty for current time"
                                        className="w-full"
                                    />
                                    {testDateTime && (
                                        <button
                                            onClick={() => setTestDateTime('')}
                                            className="text-xs text-blue-600 hover:text-blue-800 underline"
                                        >
                                            Reset to now
                                        </button>
                                    )}
                                </div>
                                <div className="flex items-end">
                                    <Button onClick={applyFilters} className="w-full">
                                        Apply Filters
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Summary Stats */}
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">
                                    Buy Zone Candidates
                                </CardTitle>
                                <Target className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold text-green-600">
                                    {candidates.length}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    From {totalTopPerformers} top performers
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">
                                    Avg RVOL
                                </CardTitle>
                                <Activity className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">
                                    {avgRvol.toFixed(2)}x
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Relative volume
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">
                                    Avg Retracement
                                </CardTitle>
                                <TrendingUp className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">
                                    {avgRetrace.toFixed(1)}%
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    From 7-day high
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">
                                    Viable Stops
                                </CardTitle>
                                <CheckCircle className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold text-green-600">
                                    {viableStopCount}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Risk ≤ 1% ({candidates.length > 0 ? ((viableStopCount / candidates.length) * 100).toFixed(0) : 0}%)
                                </p>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Results Table */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <BarChart3 className="h-5 w-5" />
                                <CardTitle>Buy Zone Candidates</CardTitle>
                            </div>
                            <CardDescription>
                                Stocks meeting all buy zone criteria: pullback range (3-8% below high), retracement (20-50%), VWAP reclaim, EMA9 &gt; EMA21, and RVOL ≥ 0.8x
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {candidates.length === 0 ? (
                                <div className="text-center py-8">
                                    <AlertCircle className="mx-auto h-12 w-12 text-muted-foreground mb-4" />
                                    <p className="text-lg font-medium text-muted-foreground">No buy zone candidates found</p>
                                    <p className="text-sm text-muted-foreground mt-2">
                                        Try adjusting the filters or check back during market hours
                                    </p>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="w-12">#</TableHead>
                                                <TableHead>Symbol</TableHead>
                                                <TableHead className="text-right">Entry</TableHead>
                                                <TableHead className="text-right">Stop</TableHead>
                                                <TableHead className="text-right">Risk %</TableHead>
                                                <TableHead className="text-right">Shares</TableHead>
                                                <TableHead className="text-right">Position $</TableHead>
                                                <TableHead className="text-right">RVOL</TableHead>
                                                <TableHead className="text-right">% Off High</TableHead>
                                                <TableHead>EMA</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {candidates.map((candidate, index) => (
                                                <TableRow key={candidate.symbol}>
                                                    <TableCell className="font-medium">
                                                        {index + 1}
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex items-center gap-2">
                                                            {candidate.asset_id ? (
                                                                <Link
                                                                    href={showAsset.url(candidate.asset_id)}
                                                                    className="font-bold text-blue-600 hover:text-blue-800 hover:underline"
                                                                >
                                                                    {candidate.symbol}
                                                                </Link>
                                                            ) : (
                                                                <span className="font-bold">
                                                                    {candidate.symbol}
                                                                </span>
                                                            )}
                                                            {index < 3 && (
                                                                <Badge variant="secondary" className="text-xs">
                                                                    Top {index + 1}
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex flex-col gap-1">
                                                            <span className="font-mono font-semibold">
                                                                ${formatPrice(candidate.entry_price)}
                                                            </span>
                                                            <span className="text-xs text-muted-foreground">
                                                                {candidate.dist_from_7d_high_pct.toFixed(1)}% off high
                                                            </span>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex flex-col gap-1">
                                                            <span className="font-mono">
                                                                ${formatPrice(candidate.stop_price)}
                                                            </span>
                                                            <Badge 
                                                                variant={candidate.stop_viable_1pct ? 'default' : 'destructive'}
                                                                className={candidate.stop_viable_1pct ? 'bg-green-500 text-xs' : 'text-xs'}
                                                            >
                                                                {candidate.stop_viable_1pct ? '✓ Viable' : '✗ Wide'}
                                                            </Badge>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <Badge 
                                                            variant="outline"
                                                            className={candidate.stop_viable_1pct ? 'bg-green-50 dark:bg-green-950' : 'bg-red-50 dark:bg-red-950'}
                                                        >
                                                            {candidate.risk_pct_pct.toFixed(2)}%
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell className="text-right font-mono font-semibold">
                                                        {candidate.recommended_shares > 0 ? candidate.recommended_shares.toLocaleString() : '—'}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex flex-col gap-1">
                                                            <span className="font-mono font-semibold">
                                                                ${candidate.position_notional > 0 ? candidate.position_notional.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, ',') : '—'}
                                                            </span>
                                                            {candidate.position_notional > 0 && (
                                                                <span className="text-xs text-muted-foreground">
                                                                    ${candidate.risk_dollars.toFixed(0)} risk
                                                                </span>
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <Badge 
                                                            variant={candidate.rvol && candidate.rvol >= 1.5 ? 'default' : 'outline'}
                                                            className={candidate.rvol && candidate.rvol >= 1.5 ? 'bg-green-500' : ''}
                                                        >
                                                            {candidate.rvol ? `${candidate.rvol.toFixed(2)}x` : 'N/A'}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <Badge variant="outline" className="bg-yellow-50 dark:bg-yellow-950">
                                                            {candidate.dist_from_7d_high_pct.toFixed(1)}%
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge 
                                                            variant={candidate.ema_state === 'EMA_FAST_ABOVE_SLOW' ? 'default' : 'outline'}
                                                            className={candidate.ema_state === 'EMA_FAST_ABOVE_SLOW' ? 'bg-green-500' : 'bg-red-500 text-white'}
                                                        >
                                                            {candidate.ema_state === 'EMA_FAST_ABOVE_SLOW' ? '9>21' : '9<21'}
                                                        </Badge>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Buy Zone Criteria */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Buy Zone Criteria & Risk Management</CardTitle>
                            <CardDescription>
                                Requirements for a stock to qualify as a buy zone candidate with position sizing
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <h4 className="font-semibold mb-2 flex items-center gap-2">
                                        <CheckCircle className="h-4 w-4 text-green-600" />
                                        Not Extended (3-8% off high)
                                    </h4>
                                    <p className="text-sm text-muted-foreground">
                                        Current price is 3-8% below the 7-day high, indicating a healthy pullback without being overextended
                                    </p>
                                </div>
                                <div>
                                    <h4 className="font-semibold mb-2 flex items-center gap-2">
                                        <CheckCircle className="h-4 w-4 text-green-600" />
                                        Pullback Range (20-50% retracement)
                                    </h4>
                                    <p className="text-sm text-muted-foreground">
                                        Price has retraced 20-50% of the 7-day high-to-low move, showing a manageable consolidation
                                    </p>
                                </div>
                                <div>
                                    <h4 className="font-semibold mb-2 flex items-center gap-2">
                                        <CheckCircle className="h-4 w-4 text-green-600" />
                                        VWAP Reclaimed
                                    </h4>
                                    <p className="text-sm text-muted-foreground">
                                        Stock was below VWAP in last 60 minutes and is now above, indicating renewed buying pressure
                                    </p>
                                </div>
                                <div>
                                    <h4 className="font-semibold mb-2 flex items-center gap-2">
                                        <CheckCircle className="h-4 w-4 text-green-600" />
                                        EMA Alignment (9 &gt; 21)
                                    </h4>
                                    <p className="text-sm text-muted-foreground">
                                        5-minute EMA9 is above EMA21, confirming short-term bullish momentum
                                    </p>
                                </div>
                                <div>
                                    <h4 className="font-semibold mb-2 flex items-center gap-2">
                                        <CheckCircle className="h-4 w-4 text-green-600" />
                                        Relative Volume (RVOL ≥ 0.8x)
                                    </h4>
                                    <p className="text-sm text-muted-foreground">
                                        Today's volume is at least 80% of the 20-day average, ensuring sufficient liquidity and interest
                                    </p>
                                </div>
                                <div>
                                    <h4 className="font-semibold mb-2 flex items-center gap-2">
                                        <CheckCircle className="h-4 w-4 text-green-600" />
                                        Data Quality Gates
                                    </h4>
                                    <p className="text-sm text-muted-foreground">
                                        Minimum 200 five-minute bars in 7 days and 120 one-minute bars today to ensure reliable signals
                                    </p>
                                </div>
                            </div>
                            <div className="mt-4 pt-4 border-t">
                                <h3 className="font-semibold text-lg mb-3">Risk Management & Position Sizing</h3>
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <h4 className="font-semibold mb-2 flex items-center gap-2">
                                            <Target className="h-4 w-4 text-blue-600" />
                                            Stop Loss Calculation
                                        </h4>
                                        <p className="text-sm text-muted-foreground">
                                            Stop is placed at the minimum of: pullback low (-0.1%), EMA21 (-0.1%), or VWAP (-0.2%) to protect capital while allowing normal price action
                                        </p>
                                    </div>
                                    <div>
                                        <h4 className="font-semibold mb-2 flex items-center gap-2">
                                            <Activity className="h-4 w-4 text-blue-600" />
                                            Position Sizing Formula
                                        </h4>
                                        <p className="text-sm text-muted-foreground">
                                            Shares = (Account × Risk%) ÷ (Entry - Stop). Default: $18,000 account with 0.5% risk per trade = $90 max loss per position
                                        </p>
                                    </div>
                                    <div>
                                        <h4 className="font-semibold mb-2 flex items-center gap-2">
                                            <CheckCircle className="h-4 w-4 text-blue-600" />
                                            Stop Viability (≤1% Risk)
                                        </h4>
                                        <p className="text-sm text-muted-foreground">
                                            Positions with stop distance ≤1% are flagged as "viable" - tight stops allow larger position sizes while maintaining fixed dollar risk
                                        </p>
                                    </div>
                                    <div>
                                        <h4 className="font-semibold mb-2 flex items-center gap-2">
                                            <BarChart3 className="h-4 w-4 text-blue-600" />
                                            Sorting Priority
                                        </h4>
                                        <p className="text-sm text-muted-foreground">
                                            Results are sorted by: 1) Stop viable (≤1%), 2) Higher RVOL (volume confirmation), 3) Lower risk % (tighter stop)
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </AppLayout>
        </>
    );
}
