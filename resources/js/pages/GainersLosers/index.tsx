import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { TrendingUp, TrendingDown, Activity, Calendar, RefreshCw } from 'lucide-react';
import { useState, useEffect } from 'react';
import { show as showAsset } from '@/routes/asset-info';

interface GainerLoser {
    symbol: string;
    asset_type: string;
    asset_id?: number | null;
    open_price: number;
    close_price: number;
    change_abs: number;
    change_pct: number;
}

interface Summary {
    trading_date: string;
    asset_type: string;
    total_symbols_analyzed: number;
    analysis_description: string;
}

interface GainersLosersPageProps {
    title: string;
    description: string;
    gainers: GainerLoser[];
    losers: GainerLoser[];
    tradingDate: string;
    assetTypeFilter: 'stock';
    topCount: number;
    summary: Summary;
}

export default function GainersLosersIndex({
    title,
    description,
    gainers,
    losers,
    tradingDate,
    assetTypeFilter,
    topCount,
    summary,
}: GainersLosersPageProps) {
    const [isLoading, setIsLoading] = useState(false);
    const [currentFilter, setCurrentFilter] = useState(assetTypeFilter);
    const [selectedDate, setSelectedDate] = useState(tradingDate);
    const [selectedCount, setSelectedCount] = useState(topCount);

    // Auto-refresh every minute while preserving URL parameters
    useEffect(() => {
        const interval = setInterval(() => {
            // Auto-refresh if we're not currently loading data
            if (!isLoading) {
                router.visit('/analysis/gainers-losers', {
                    data: { 
                        filter: currentFilter,
                        date: selectedDate,
                        count: selectedCount,
                    },
                    preserveScroll: true,
                    preserveState: true,
                    only: ['gainers', 'losers', 'summary'],
                });
            }
        }, 60000); // 60 seconds = 1 minute

        return () => clearInterval(interval);
    }, [isLoading, currentFilter, selectedDate, selectedCount]);

    const handleFilterChange = (filter: 'stock') => {
        if (filter === currentFilter) return;
        
        setIsLoading(true);
        setCurrentFilter(filter);
        
        router.visit('/analysis/gainers-losers', {
            data: { 
                filter,
                date: selectedDate,
                count: selectedCount,
            },
            preserveScroll: true,
            onFinish: () => setIsLoading(false),
        });
    };

    const handleRefresh = () => {
        setIsLoading(true);
        router.reload({ 
            only: ['gainers', 'losers', 'summary'],
            onFinish: () => setIsLoading(false),
        });
    };

    const handleDateChange = () => {
        setIsLoading(true);
        router.visit('/analysis/gainers-losers', {
            data: { 
                filter: currentFilter,
                date: selectedDate,
                count: selectedCount,
            },
            preserveScroll: true,
            onFinish: () => setIsLoading(false),
        });
    };

    const formatPrice = (price: number) => `$${price.toFixed(2)}`;
    const formatChange = (changePct: number, changeAbs: number) => {
        const sign = changePct >= 0 ? '+' : '';
        return {
            pct: `${sign}${changePct.toFixed(2)}%`,
            abs: `${sign}${changeAbs.toFixed(2)}`,
            color: changePct >= 0 ? 'text-green-600' : 'text-red-600'
        };
    };

    const renderTable = (data: GainerLoser[], type: 'gainers' | 'losers') => (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    {type === 'gainers' ? (
                        <>
                            <TrendingUp className="h-5 w-5 text-green-600" />
                            Top {topCount} Gainers
                        </>
                    ) : (
                        <>
                            <TrendingDown className="h-5 w-5 text-red-600" />
                            Top {topCount} Losers
                        </>
                    )}
                </CardTitle>
                <CardDescription>
                    {type === 'gainers' 
                        ? `Best performing ${currentFilter}s from market open to close`
                        : `Worst performing ${currentFilter}s from market open to close`
                    }
                </CardDescription>
            </CardHeader>
            <CardContent>
                {data.length > 0 ? (
                    <div className="rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Symbol</TableHead>
                                    <TableHead className="text-right">Open</TableHead>
                                    <TableHead className="text-right">Close</TableHead>
                                    <TableHead className="text-right">Change %</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.map((item, index) => {
                                    const change = formatChange(item.change_pct, item.change_abs);
                                    return (
                                        <TableRow key={`${item.symbol}-${index}`}>
                                            <TableCell className="font-medium">
                                                <div className="flex items-center gap-2">
                                                    {item.asset_id ? (
                                                        <Link
                                                            href={showAsset.url(item.asset_id)}
                                                            className="text-blue-600 hover:text-blue-700 hover:underline font-bold dark:text-blue-400 dark:hover:text-blue-300"
                                                        >
                                                            {item.symbol}
                                                        </Link>
                                                    ) : (
                                                        item.symbol
                                                    )}
                                                    <Badge variant="outline" className="text-xs">
                                                        {item.asset_type}
                                                    </Badge>
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-right font-mono">
                                                {formatPrice(item.open_price)}
                                            </TableCell>
                                            <TableCell className="text-right font-mono">
                                                {formatPrice(item.close_price)}
                                            </TableCell>
                                            <TableCell className={`text-right font-mono font-semibold ${change.color}`}>
                                                {change.pct}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>
                ) : (
                    <div className="text-center py-12 text-muted-foreground">
                        <Activity className="h-12 w-12 mx-auto mb-4 text-muted-foreground/50" />
                        <h3 className="text-lg font-semibold mb-2">
                            No {type} found
                        </h3>
                        <p className="max-w-md mx-auto">
                            No {type} data available for the selected trading day and asset type.
                        </p>
                    </div>
                )}
            </CardContent>
        </Card>
    );

    return (
        <>
            <Head title={title} />
            <AppLayout>
                <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                    {/* Header */}
                    <div className="flex items-start justify-between">
                        <div>
                            <Heading 
                                title={title}
                                description=""
                            />
                            <p className="mt-2 text-lg text-muted-foreground">{description}</p>
                        </div>
                        <Button 
                            onClick={handleRefresh}
                            disabled={isLoading}
                            variant="outline"
                            className="flex items-center gap-2"
                        >
                            <RefreshCw className={`h-4 w-4 ${isLoading ? 'animate-spin' : ''}`} />
                            Refresh
                        </Button>
                    </div>

                    {/* Controls */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Calendar className="h-5 w-5" />
                                Analysis Settings
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                {/* Asset Type Filter */}
                                <div>
                                    <Label>Asset Type</Label>
                                    <div className="flex gap-2 mt-2">
                                        <Button
                                            variant={currentFilter === 'stock' ? 'default' : 'outline'}
                                            onClick={() => handleFilterChange('stock')}
                                            disabled={isLoading}
                                            size="sm"
                                        >
                                            Stocks
                                        </Button>
                                        <Button
                                            variant={currentFilter === 'crypto' ? 'default' : 'outline'}
                                            onClick={() => handleFilterChange('stock')}
                                            disabled={isLoading}
                                            size="sm"
                                        >
                                            Crypto
                                        </Button>
                                    </div>
                                </div>

                                {/* Trading Date */}
                                <div>
                                    <Label htmlFor="trading-date">Trading Date</Label>
                                    <div className="flex gap-2 mt-2">
                                        <Input
                                            id="trading-date"
                                            type="date"
                                            value={selectedDate}
                                            onChange={(e) => setSelectedDate(e.target.value)}
                                            className="flex-1"
                                        />
                                        <Button onClick={handleDateChange} disabled={isLoading} size="sm">
                                            Apply
                                        </Button>
                                    </div>
                                </div>

                                {/* Count */}
                                <div>
                                    <Label htmlFor="count">Number to Show</Label>
                                    <div className="flex gap-2 mt-2">
                                        <Input
                                            id="count"
                                            type="number"
                                            min="1"
                                            step="1"
                                            value={selectedCount}
                                            onChange={(e) => setSelectedCount(parseInt(e.target.value) || 50)}
                                            className="flex-1"
                                        />
                                        <Button onClick={handleDateChange} disabled={isLoading} size="sm">
                                            Apply
                                        </Button>
                                    </div>
                                </div>
                            </div>

                            {/* Summary */}
                            <div className="pt-4 border-t">
                                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                    <div>
                                        <div className="font-medium text-muted-foreground">Trading Date</div>
                                        <div className="font-semibold">{new Date(tradingDate + 'T12:00:00').toLocaleDateString()}</div>
                                    </div>
                                    <div>
                                        <div className="font-medium text-muted-foreground">Asset Type</div>
                                        <div className="font-semibold capitalize">{summary.asset_type}</div>
                                    </div>
                                    <div>
                                        <div className="font-medium text-muted-foreground">Symbols Analyzed</div>
                                        <div className="font-semibold">{summary.total_symbols_analyzed.toLocaleString()}</div>
                                    </div>
                                    <div>
                                        <div className="font-medium text-muted-foreground">Results Shown</div>
                                        <div className="font-semibold">{gainers.length + losers.length} of {topCount * 2}</div>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Tables */}
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        {renderTable(gainers, 'gainers')}
                        {renderTable(losers, 'losers')}
                    </div>

                    {/* Information */}
                    <Card>
                        <CardHeader>
                            <CardTitle>How It Works</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                                <div>
                                    <h4 className="font-medium mb-2">Calculation Method:</h4>
                                    <ul className="space-y-1 text-muted-foreground">
                                        <li>• Uses 5-minute price data for accuracy</li>
                                        <li>• Compares first price (market open) to last price (market close)</li>
                                        <li>• Calculates percentage change from open to close</li>
                                        <li>• Filters out symbols with insufficient data</li>
                                    </ul>
                                </div>
                                <div>
                                    <h4 className="font-medium mb-2">Data Source:</h4>
                                    <ul className="space-y-1 text-muted-foreground">
                                        <li>• Market open: ~9:30 AM EST</li>
                                        <li>• Market close: ~3:55 PM EST</li>
                                        <li>• Based on actual trading activity</li>
                                        <li>• Updated after each trading day</li>
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