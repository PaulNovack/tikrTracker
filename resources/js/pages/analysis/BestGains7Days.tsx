import { Head, router, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import Heading from '@/components/heading';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { TrendingUp, Activity, Calendar, BarChart3, Filter } from 'lucide-react';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { show as showAsset } from '@/routes/asset-info';
import { useState } from 'react';

interface Performer {
    symbol: string;
    asset_id: number | null;
    bars: number;
    vol_sum: number;
    first_ts: string;
    last_ts: string;
    first_price: string;
    last_price: string;
    pct_return: number;
    pct_return_pct: number;
}

interface Filters {
    assetType: string;
    days: number;
    limit: number;
    minBars: number;
    rthOnly: boolean;
}

interface BestGains7DaysProps {
    performers: Performer[];
    filters: Filters;
}

export default function BestGains7Days({ performers, filters }: BestGains7DaysProps) {
    const [assetType, setAssetType] = useState(filters.assetType);
    const [days, setDays] = useState(filters.days.toString());
    const [rthOnly, setRthOnly] = useState(filters.rthOnly ? 'true' : 'false');

    const applyFilters = () => {
        router.get('/analysis/best-gains-7d', {
            assetType,
            days: parseInt(days),
            rthOnly: rthOnly === 'true',
            limit: filters.limit,
            minBars: filters.minBars,
        }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const formatPrice = (price: string) => {
        return parseFloat(price).toFixed(2);
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

    const formatDate = (dateStr: string) => {
        return new Date(dateStr).toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    const topPerformers = performers.slice(0, 3);
    const avgReturn = performers.length > 0 
        ? performers.reduce((sum, p) => sum + p.pct_return_pct, 0) / performers.length 
        : 0;

    return (
        <>
            <Head title={`Best Gains ${filters.days} Days - Analysis`} />
            <AppLayout>
                <div className="flex flex-col gap-6 p-6">
                    <Heading 
                        title={`Best Gains ${filters.days} Days`}
                        description={`Top performing ${filters.assetType}s based on 5-minute price data. Found ${performers.length} assets matching the criteria.`}
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
                                            <SelectItem value="1">1 Day</SelectItem>
                                            <SelectItem value="3">3 Days</SelectItem>
                                            <SelectItem value="5">5 Days</SelectItem>
                                            <SelectItem value="7">7 Days</SelectItem>
                                            <SelectItem value="14">14 Days</SelectItem>
                                            <SelectItem value="30">30 Days</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-2">
                                    <label className="text-sm font-medium">Market Hours</label>
                                    <Select value={rthOnly} onValueChange={setRthOnly}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="true">Regular Hours Only</SelectItem>
                                            <SelectItem value="false">All Hours</SelectItem>
                                        </SelectContent>
                                    </Select>
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
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">
                                    Top Performer
                                </CardTitle>
                                <TrendingUp className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                {topPerformers[0] ? (
                                    <>
                                        <div className="text-2xl font-bold text-green-600">
                                            {topPerformers[0].symbol}
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            +{topPerformers[0].pct_return_pct.toFixed(2)}% gain
                                        </p>
                                    </>
                                ) : (
                                    <div className="text-sm text-muted-foreground">No data</div>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">
                                    Average Return
                                </CardTitle>
                                <Activity className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold text-blue-600">
                                    {avgReturn.toFixed(2)}%
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Across {performers.length} assets
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">
                                    Analysis Period
                                </CardTitle>
                                <Calendar className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">
                                    {filters.days} Days
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Min {filters.minBars} bars required
                                </p>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Results Table */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <BarChart3 className="h-5 w-5" />
                                <CardTitle>Top Performers</CardTitle>
                            </div>
                            <CardDescription>
                                Ranked by percentage return over the {filters.days}-day period
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {performers.length === 0 ? (
                                <div className="text-center py-8 text-muted-foreground">
                                    No performers found matching the criteria
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="w-12">#</TableHead>
                                                <TableHead>Symbol</TableHead>
                                                <TableHead className="text-right">Return %</TableHead>
                                                <TableHead className="text-right">Start Price</TableHead>
                                                <TableHead className="text-right">End Price</TableHead>
                                                <TableHead className="text-right">Bars</TableHead>
                                                <TableHead className="text-right">Volume</TableHead>
                                                <TableHead>First TS</TableHead>
                                                <TableHead>Last TS</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {performers.map((performer, index) => (
                                                <TableRow key={performer.symbol}>
                                                    <TableCell className="font-medium">
                                                        {index + 1}
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex items-center gap-2">
                                                            {performer.asset_id ? (
                                                                <Link
                                                                    href={showAsset.url(performer.asset_id)}
                                                                    className="font-bold text-blue-600 hover:text-blue-800 hover:underline"
                                                                >
                                                                    {performer.symbol}
                                                                </Link>
                                                            ) : (
                                                                <span className="font-bold">
                                                                    {performer.symbol}
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
                                                        <Badge
                                                            variant={performer.pct_return >= 0 ? 'default' : 'destructive'}
                                                            className={
                                                                performer.pct_return >= 0
                                                                    ? 'bg-green-500 hover:bg-green-600'
                                                                    : ''
                                                            }
                                                        >
                                                            {performer.pct_return >= 0 ? '+' : ''}
                                                            {performer.pct_return_pct.toFixed(2)}%
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell className="text-right font-mono">
                                                        ${formatPrice(performer.first_price)}
                                                    </TableCell>
                                                    <TableCell className="text-right font-mono">
                                                        ${formatPrice(performer.last_price)}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {performer.bars}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {formatVolume(performer.vol_sum)}
                                                    </TableCell>
                                                    <TableCell className="text-sm text-muted-foreground">
                                                        {formatDate(performer.first_ts)}
                                                    </TableCell>
                                                    <TableCell className="text-sm text-muted-foreground">
                                                        {formatDate(performer.last_ts)}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Methodology */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Methodology</CardTitle>
                            <CardDescription>
                                How we calculate best performers
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <h4 className="font-semibold mb-2">Ranking Metric</h4>
                                <p className="text-sm text-muted-foreground">
                                    Percentage return = (last_price - first_price) / first_price × 100
                                </p>
                            </div>
                            <div>
                                <h4 className="font-semibold mb-2">Data Source</h4>
                                <p className="text-sm text-muted-foreground">
                                    5-minute price bars from the five_minute_prices table
                                </p>
                            </div>
                            <div>
                                <h4 className="font-semibold mb-2">Filters</h4>
                                <ul className="text-sm text-muted-foreground space-y-1">
                                    <li>• Minimum {filters.minBars} bars (data points) required</li>
                                    <li>• {filters.rthOnly ? 'Regular trading hours only (9:30 AM - 4:00 PM ET)' : 'All trading hours included'}</li>
                                    <li>• Analyzes {filters.assetType === 'stock' ? 'stocks' : 'cryptocurrencies'} only</li>
                                </ul>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </AppLayout>
        </>
    );
}
