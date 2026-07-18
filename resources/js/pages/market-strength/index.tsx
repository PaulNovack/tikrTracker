import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Head, router } from '@inertiajs/react';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Download } from 'lucide-react';

interface MarketStrengthData {
    date: string;
    bars_4pct_plus: number;
    bars_5pct_plus: number;
    bars_10pct_plus: number;
    max_gain: number;
    strength: number;
    label: 'STRONG' | 'MODERATE' | 'WEAK';
}

interface Props {
    data: MarketStrengthData[];
    days: number;
    avgStrength: number;
    startDate: string;
    endDate: string;
}

export default function MarketStrength({ data, days, avgStrength, startDate, endDate }: Props) {
    const handleDaysChange = (newDays: number) => {
        router.get('/market-strength', { days: newDays }, { preserveState: true });
    };

    const handleExport = () => {
        window.location.href = `/market-strength/export?days=${days}`;
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
                { label: 'Dashboard', href: '/' },
                { label: 'Market Strength' },
            ]}
        >
            <Head title="Market Strength" />
            <Card>
                <CardHeader className="pb-4">
                    <div className="flex items-center justify-between">
                        <div>
                            <CardTitle className="text-lg">Market Strength Analysis</CardTitle>
                            <CardDescription className="text-sm">
                                {data.length} days · {startDate} to {endDate}
                            </CardDescription>
                        </div>
                        <div className="flex gap-2">
                            <div className="flex gap-1.5">
                                <Button
                                    variant={days === 7 ? 'default' : 'outline'}
                                    size="sm"
                                    onClick={() => handleDaysChange(7)}
                                >
                                    7D
                                </Button>
                                <Button
                                    variant={days === 30 ? 'default' : 'outline'}
                                    size="sm"
                                    onClick={() => handleDaysChange(30)}
                                >
                                    30D
                                </Button>
                                <Button
                                    variant={days === 90 ? 'default' : 'outline'}
                                    size="sm"
                                    onClick={() => handleDaysChange(90)}
                                >
                                    90D
                                </Button>
                                <Button
                                    variant={days === 180 ? 'default' : 'outline'}
                                    size="sm"
                                    onClick={() => handleDaysChange(180)}
                                >
                                    180D
                                </Button>
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={handleExport}
                            >
                                <Download className="mr-1.5 h-4 w-4" />
                                Export CSV
                            </Button>
                        </div>
                    </div>
                </CardHeader>
                <CardContent className="space-y-4">
                    {/* Explanation */}
                    <div className="rounded-md border bg-muted/50 p-3 text-xs">
                        <h3 className="mb-1.5 text-sm font-semibold">How Market Strength is Calculated</h3>
                        <p className="mb-2 text-muted-foreground">
                            Market strength measures explosive 5-minute price movements throughout each trading day:
                        </p>
                        <ul className="space-y-0.5 text-muted-foreground">
                            <li><strong>4%+ Bars:</strong> Moderate momentum opportunities</li>
                            <li><strong>5%+ Bars:</strong> Strong intraday moves</li>
                            <li><strong>10%+ Bars:</strong> Explosive breakouts</li>
                        </ul>
                        <p className="mt-2 text-muted-foreground">
                            <strong>Score:</strong> Based on 4%+ bar count (100 = 200+ bars). Example: Dec 11, 2025 had 166 bars scoring 83/100.
                        </p>
                    </div>

                    {/* Summary Stats */}
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-xs font-medium text-muted-foreground">Average Strength</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-xl font-bold">{avgStrength}/100</div>
                                <p className="text-xs text-muted-foreground">
                                    {avgStrength >= 70 ? 'Strong Market' : avgStrength >= 40 ? 'Moderate Market' : 'Weak Market'}
                                </p>
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
                                    <TableHead>Visual</TableHead>
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
                                            <div className="h-3 w-full max-w-xs bg-muted">
                                                <div
                                                    className={`h-full ${getStrengthColor(row.label)}`}
                                                    style={{ width: `${row.strength}%` }}
                                                />
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>

                    {/* Reference Note */}
                    <div className="rounded-md border bg-muted/50 p-3 text-xs text-muted-foreground">
                        <p className="mb-1">
                            <strong>Example:</strong> Dec 11, 2025 had 166 bars with 4%+ gains (99 at 5%+, 23 at 10%+). This scored 83/100 with ~$2,000 profit potential.
                        </p>
                        <p>
                            Weak market days (0-39) have fewer than 80 explosive moves, limiting quality trading setups.
                        </p>
                    </div>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
