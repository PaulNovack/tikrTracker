import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Head, router } from '@inertiajs/react';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Download, TrendingUp } from 'lucide-react';

interface TopMover {
    symbol: string;
    gain_pct: number;
}

interface MarketMoversData {
    date: string;
    bars_4pct_plus: number;
    bars_5pct_plus: number;
    bars_10pct_plus: number;
    max_gain: number;
    strength: number;
    label: 'STRONG' | 'MODERATE' | 'WEAK';
    top_movers: TopMover[];
}

interface Props {
    data: MarketMoversData[];
    days: number;
    avgStrength: number;
    startDate: string;
    endDate: string;
    assetIds: Record<string, number>;
}

export default function MarketMovers({ data, days, avgStrength, startDate, endDate, assetIds }: Props) {
    const handleDaysChange = (newDays: number) => {
        router.get(
            '/market-movers', 
            { days: newDays }, 
            { 
                preserveScroll: true,
                replace: true
            }
        );
    };

    const handleExport = () => {
        window.location.href = `/market-movers/export?days=${days}`;
    };

    const getStrengthColor = (label: string) => {
        switch (label) {
            case 'STRONG':
                return 'bg-green-500';
            case 'MODERATE':
                return 'bg-orange-400';
            case 'WEAK':
                return 'bg-red-500';
            default:
                return 'bg-gray-500';
        }
    };

    const getBadgeVariant = (label: string): 'default' | 'secondary' | 'destructive' | 'outline' => {
        switch (label) {
            case 'STRONG':
                return 'default';
            case 'MODERATE':
                return 'secondary';
            case 'WEAK':
                return 'destructive';
            default:
                return 'outline';
        }
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Dashboard', href: '/' },
                { title: 'Market Regime', href: '/market-strength' },
                { title: 'Market Movers', href: '/market-movers' },
            ]}
        >
            <Head title="Market Movers" />
            <Card>
                <CardHeader className="pb-4">
                    <div className="flex items-center justify-between">
                        <div>
                            <CardTitle className="text-lg">Market Movers Analysis</CardTitle>
                            <CardDescription className="text-sm">
                                {data.length} days · {startDate} to {endDate}
                            </CardDescription>
                        </div>
                        <div className="flex gap-2">
                            <div className="flex gap-1.5">
                                <Button
                                    size="sm"
                                    variant={days === 7 ? 'default' : 'outline'}
                                    onClick={() => handleDaysChange(7)}
                                >
                                    7D
                                </Button>
                                <Button
                                    size="sm"
                                    variant={days === 14 ? 'default' : 'outline'}
                                    onClick={() => handleDaysChange(14)}
                                >
                                    14D
                                </Button>
                                <Button
                                    size="sm"
                                    variant={days === 30 ? 'default' : 'outline'}
                                    onClick={() => handleDaysChange(30)}
                                >
                                    30D
                                </Button>
                                <Button
                                    size="sm"
                                    variant={days === 60 ? 'default' : 'outline'}
                                    onClick={() => handleDaysChange(60)}
                                >
                                    60D
                                </Button>
                                <Button
                                    size="sm"
                                    variant={days === 90 ? 'default' : 'outline'}
                                    onClick={() => handleDaysChange(90)}
                                >
                                    90D
                                </Button>
                            </div>
                            <Button size="sm" variant="outline" onClick={handleExport}>
                                <Download className="mr-1.5 h-3.5 w-3.5" />
                                Export CSV
                            </Button>
                        </div>
                    </div>
                </CardHeader>

                <CardContent className="space-y-4">
                    {/* Explanation */}
                    <div className="rounded-md border bg-blue-50 p-3 dark:bg-blue-950/30">
                        <h3 className="mb-1.5 text-sm font-semibold">How Market Movers Work</h3>
                        <p className="mb-2 text-xs text-muted-foreground">
                            Market movers show explosive 5-minute price movements alongside the actual symbols that moved:
                        </p>
                        <ul className="ml-4 list-disc space-y-0.5 text-xs text-muted-foreground">
                            <li>Strength Score: 0-100 based on number of 4%+ moves (200 bars = 100%)</li>
                            <li>All Movers: Every symbol with 4%+ gains that day (sorted by highest gain)</li>
                            <li>Strong Days (70+): 140+ explosive moves across multiple symbols</li>
                            <li>Moderate Days (40-69): 80-139 moves, selective opportunities</li>
                            <li>Weak Days (0-39): Fewer than 80 moves, minimal setups</li>
                        </ul>
                    </div>

                    {/* Summary Stats */}
                    <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-4">
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-xs font-medium text-muted-foreground">Average Strength</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-xl font-bold">{avgStrength}</div>
                                <p className="text-xs text-muted-foreground">out of 100</p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-xs font-medium text-muted-foreground">Strong Days</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-xl font-bold">
                                    {data.filter((d) => d.label === 'STRONG').length}
                                </div>
                                <p className="text-xs text-muted-foreground">70+ strength</p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-xs font-medium text-muted-foreground">Moderate Days</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-xl font-bold">
                                    {data.filter((d) => d.label === 'MODERATE').length}
                                </div>
                                <p className="text-xs text-muted-foreground">40-69 strength</p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-xs font-medium text-muted-foreground">Weak Days</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-xl font-bold">
                                    {data.filter((d) => d.label === 'WEAK').length}
                                </div>
                                <p className="text-xs text-muted-foreground">0-39 strength</p>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Data Table */}
                    <div className="rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-28">Date</TableHead>
                                    <TableHead className="w-20 text-right">Strength</TableHead>
                                    <TableHead className="w-24">Status</TableHead>
                                    <TableHead className="w-20 text-right">4%+</TableHead>
                                    <TableHead className="w-20 text-right">5%+</TableHead>
                                    <TableHead className="w-20 text-right">10%+</TableHead>
                                    <TableHead className="w-24 text-right">Max Gain</TableHead>
                                    <TableHead className="w-32">Visual</TableHead>
                                    <TableHead>All Movers (Symbol: %)</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.map((row) => (
                                    <TableRow key={row.date}>
                                        <TableCell className="font-medium whitespace-nowrap">{row.date}</TableCell>
                                        <TableCell className="text-right font-semibold">{row.strength}</TableCell>
                                        <TableCell>
                                            <Badge 
                                                variant={getBadgeVariant(row.label)}
                                                className={
                                                    row.label === 'STRONG' ? 'bg-green-600 hover:bg-green-700 text-white' :
                                                    row.label === 'MODERATE' ? 'bg-orange-400 hover:bg-orange-500 text-white' : ''
                                                }
                                            >
                                                {row.label}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right text-sm">{row.bars_4pct_plus}</TableCell>
                                        <TableCell className="text-right text-sm">{row.bars_5pct_plus}</TableCell>
                                        <TableCell className="text-right text-sm">{row.bars_10pct_plus}</TableCell>
                                        <TableCell className="text-right text-sm">{row.max_gain}%</TableCell>
                                        <TableCell>
                                            <div className="h-3 w-full max-w-[8rem] bg-muted">
                                                <div
                                                    className={`h-full ${getStrengthColor(row.label)}`}
                                                    style={{ width: `${row.strength}%` }}
                                                />
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            {row.top_movers.length > 0 ? (
                                                <div className="flex flex-wrap gap-1.5">
                                                    {row.top_movers.map((mover, idx) => (
                                                        <Badge 
                                                            key={idx} 
                                                            variant="outline"
                                                            className="text-xs font-mono"
                                                        >
                                                            <TrendingUp className="mr-1 h-3 w-3 text-green-600" />
                                                            {assetIds[mover.symbol] ? (
                                                                <a
                                                                    href={`/market-data/assets/${assetIds[mover.symbol]}`}
                                                                    target="_blank"
                                                                    rel="noopener noreferrer"
                                                                    className="text-blue-600 hover:underline dark:text-blue-400"
                                                                >
                                                                    {mover.symbol}
                                                                </a>
                                                            ) : (
                                                                mover.symbol
                                                            )}: {mover.gain_pct}%
                                                        </Badge>
                                                    ))}
                                                </div>
                                            ) : (
                                                <span className="text-xs text-muted-foreground">No movers</span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>

                    {/* Reference Note */}
                    <div className="rounded-md border bg-muted/50 p-3 text-xs text-muted-foreground">
                        <p className="mb-1">
                            <strong>Example:</strong> On strong days, you'll see dozens or hundreds of symbols with significant gains. 
                            This helps identify which stocks drove the market activity and spot recurring patterns.
                        </p>
                        <p>
                            All symbols with 4%+ gains are shown, sorted by highest percentage gain.
                        </p>
                    </div>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
