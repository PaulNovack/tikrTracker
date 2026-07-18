import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { TrendingDown } from 'lucide-react';

interface StagnationData {
    id: number | null;
    symbol: string;
    asset_type: 'stock';
    current_price: number;
    short_change_pct: { percent: number | null; price: number | null } | null;
    long_change_pct: { percent: number | null; price: number | null } | null;
    is_stagnant: boolean;
    has_significant_gain: boolean;
    is_downtrend: boolean;
    day_range_pct: number | null;
    latest_ts: string;
    intraday_change_pct: { percent: number | null; price: number | null } | null;
    '1d_change_pct': { percent: number | null; price: number | null } | null;
    '3d_change_pct': { percent: number | null; price: number | null } | null;
    '5d_change_pct': { percent: number | null; price: number | null } | null;
    '15d_change_pct': { percent: number | null; price: number | null } | null;
    '30d_change_pct': { percent: number | null; price: number | null } | null;
}

interface StagnationProps {
    stagnationData: StagnationData[];
    shortDays: number;
    longDays: number;
    flatThresholdPct: number;
    goodPositivePct: number;
    greatPositivePct: number;
    negativeAlertPct: number;
    marketSchedule: Array<{
        date: string;
        status: 'open' | 'closed' | 'half_day' | 'holiday';
        reason: string | null;
    }>;
    tradingDates: Record<string, string>;
}

export default function Stagnation({
    stagnationData,
    shortDays,
    longDays,
    flatThresholdPct,
    goodPositivePct,
    greatPositivePct,
    negativeAlertPct,
    marketSchedule,
    tradingDates,
}: StagnationProps) {
    // Show all watched assets in a single table instead of categorizing them
    const allAssets = stagnationData;

    // Use backend-calculated trading dates instead of complex frontend logic
    const date30d = tradingDates['30d'] || 'N/A';
    const date15d = tradingDates['15d'] || 'N/A';
    const date5d = tradingDates['5d'] || 'N/A';
    const date3d = tradingDates['3d'] || 'N/A';
    const date1d = tradingDates['1d'] || 'N/A';

    const formatPercent = (change: { percent: number; price: number } | null) => {
        if (change === null) return 'N/A';
        return `${change.percent >= 0 ? '+' : ''}${change.percent.toFixed(2)}%`;
    };

    const formatPercentWithPrice = (change: { percent: number | null; price: number | null } | null) => {
        if (change === null || change.percent === null) return 'N/A';
        return (
            <div className="text-right">
                <div>{`${change.percent >= 0 ? '+' : ''}${change.percent.toFixed(2)}%`}</div>
                <div className="text-xs text-gray-500 dark:text-gray-400">
                    {change.price !== null ? `$${change.price.toFixed(2)}` : 'N/A'}
                </div>
            </div>
        );
    };

    // Determine cell color based on movement
    const getMovementColor = (change: { percent: number | null; price: number | null } | null) => {
        if (change === null || change.percent === null) return '';
        const value = change.percent;
        // Negative alert: bright yellow for drops below threshold
        if (value <= negativeAlertPct) {
            return 'text-yellow-500 dark:text-yellow-300 font-semibold'; // Bright yellow for negative moves
        }
        if (Math.abs(value) <= flatThresholdPct) {
            return 'text-yellow-600 dark:text-yellow-400 font-semibold'; // Stagnant: darker yellow
        }
        if (value >= greatPositivePct) {
            return 'text-green-600 dark:text-green-400 font-semibold'; // Great positive: bright green
        }
        if (value >= goodPositivePct) {
            return 'text-green-500 dark:text-green-300 font-semibold'; // Good positive: light green
        }
        return ''; // Default for small movements
    };

    // Momentum indicator comparing 3D vs 1D performance for trend detection
    const getMomentumIndicator = (asset: StagnationData) => {
        const day1Change = asset['1d_change_pct'];
        const day3Change = asset['3d_change_pct'];
        
        if (!day1Change || !day3Change || day1Change.percent === null || day3Change.percent === null) {
            return { color: 'text-gray-400', symbol: '–', title: 'Insufficient data' };
        }

        // Compare 1D vs 3D performance for momentum
        if (day1Change.percent > day3Change.percent) {
            return { 
                color: 'text-green-600 dark:text-green-400', 
                symbol: '▲', 
                title: `Accelerating: 1D ${day1Change.percent.toFixed(1)}% > 3D avg ${(day3Change.percent / 3).toFixed(1)}%` 
            };
        } else {
            return { 
                color: 'text-orange-600 dark:text-orange-400', 
                symbol: '▼', 
                title: `Decelerating: 1D ${day1Change.percent.toFixed(1)}% < 3D avg ${(day3Change.percent / 3).toFixed(1)}%` 
            };
        }
    };

    const StagnationTable = ({
        assets,
        title,
        isDowntrend = false,
    }: {
        assets: StagnationData[];
        title: string;
        isDowntrend?: boolean;
    }) => (
        <Card
            className={
                isDowntrend
                    ? 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950'
                    : ''
            }
        >
            <CardHeader>
                <CardTitle className="text-lg">{title}</CardTitle>
                <CardDescription>
                    {assets.length} asset{assets.length !== 1 ? 's' : ''}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {assets.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No assets to display
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="border-b">
                                <tr
                                    className={
                                        isDowntrend
                                            ? 'text-red-700 dark:text-red-300'
                                            : 'text-muted-foreground'
                                    }
                                >
                                    <th className="px-4 py-2 text-left font-semibold">
                                        Symbol
                                    </th>
                                    <th className="px-4 py-2 text-left font-semibold">
                                        Type
                                    </th>
                                    <th className="px-4 py-2 text-right font-semibold">
                                        Price
                                    </th>
                                    <th className="px-4 py-2 text-right font-semibold">
                                        30d ({date30d})
                                    </th>
                                    <th className="px-4 py-2 text-right font-semibold">
                                        15d ({date15d})
                                    </th>
                                    <th className="px-4 py-2 text-right font-semibold">
                                        5d ({date5d})
                                    </th>
                                    <th className="px-4 py-2 text-right font-semibold">
                                        3d ({date3d})
                                    </th>
                                    <th className="px-4 py-2 text-right font-semibold">
                                        1d ({date1d})
                                    </th>
                                    <th className="px-4 py-2 text-right font-semibold">
                                        <span title="Intraday performance from 9:30 AM EST to now (market days after 10 AM EST only)">
                                            Today
                                        </span>
                                    </th>
                                    <th className="px-4 py-2 text-center font-semibold">
                                        <span title="Momentum indicator comparing 1D vs 3D average: ▲ accelerating, ▼ decelerating">
                                            Trend
                                        </span>
                                    </th>
                                    <th className="px-4 py-2 text-right font-semibold">
                                        Status
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {assets.map((asset) => (
                                    <tr
                                        key={`${asset.symbol}-${asset.asset_type}`}
                                        className={`border-b ${isDowntrend ? 'bg-red-100/50 hover:bg-red-100/70 dark:bg-red-900/30 dark:hover:bg-red-900/50' : 'hover:bg-muted/50'}`}
                                    >
                                        <td className="px-4 py-3 font-medium">
                                            {asset.id ? (
                                                <Link
                                                    href={`/market-data/assets/${asset.id}`}
                                                    className="text-blue-600 hover:text-blue-800 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
                                                >
                                                    {asset.symbol}
                                                </Link>
                                            ) : (
                                                <span>{asset.symbol}</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                variant="outline"
                                                className="capitalize"
                                            >
                                                {asset.asset_type}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-right font-mono">
                                            ${asset.current_price.toFixed(2)}
                                        </td>
                                        <td
                                            className={`px-4 py-3 text-right font-mono ${getMovementColor(asset['30d_change_pct'])}`}
                                        >
                                            {formatPercentWithPrice(
                                                asset['30d_change_pct'],
                                            )}
                                        </td>
                                        <td
                                            className={`px-4 py-3 text-right font-mono ${getMovementColor(asset['15d_change_pct'])}`}
                                        >
                                            {formatPercentWithPrice(
                                                asset['15d_change_pct'],
                                            )}
                                        </td>
                                        <td
                                            className={`px-4 py-3 text-right font-mono ${getMovementColor(asset['5d_change_pct'])}`}
                                        >
                                            {formatPercentWithPrice(
                                                asset['5d_change_pct'],
                                            )}
                                        </td>
                                        <td
                                            className={`px-4 py-3 text-right font-mono ${getMovementColor(asset['3d_change_pct'])}`}
                                        >
                                            {formatPercentWithPrice(
                                                asset['3d_change_pct'],
                                            )}
                                        </td>
                                        <td
                                            className={`px-4 py-3 text-right font-mono ${getMovementColor(asset['1d_change_pct'])}`}
                                        >
                                            {formatPercentWithPrice(
                                                asset['1d_change_pct'],
                                            )}
                                        </td>
                                        <td
                                            className={`px-4 py-3 text-right font-mono ${getMovementColor(asset.intraday_change_pct)}`}
                                        >
                                            {asset.intraday_change_pct ? 
                                                formatPercentWithPrice(asset.intraday_change_pct) : 
                                                <span className="text-gray-400">—</span>
                                            }
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            {(() => {
                                                const momentum = getMomentumIndicator(asset);
                                                return (
                                                    <span
                                                        className={`text-lg font-semibold ${momentum.color}`}
                                                        title={momentum.title}
                                                    >
                                                        {momentum.symbol}
                                                    </span>
                                                );
                                            })()}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {asset.is_downtrend ? (
                                                <Badge className="bg-red-100 text-red-800 hover:bg-red-200 dark:bg-red-900 dark:text-red-100">
                                                    Downtrend
                                                </Badge>
                                            ) : asset.is_stagnant ? (
                                                <Badge className="bg-yellow-100 text-yellow-800 hover:bg-yellow-200 dark:bg-yellow-900 dark:text-yellow-100">
                                                    Stagnant
                                                </Badge>
                                            ) : (
                                                <Badge variant="outline">
                                                    Active
                                                </Badge>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </CardContent>
        </Card>
    );

    return (
        <>
            <Head title="Notable Assets" />
            <AppLayout
                breadcrumbs={[{ title: 'Notable Assets', href: '/notable-assets' }]}
            >
                <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between">
                        <Heading
                            title="Watched Analysis"
                            description="Monitor watched assets across multiple lookback periods (30d, 15d, 5d, 3d, 1d) plus intraday performance. Includes momentum indicators and performance categorization."
                        />
                    </div>

                    {/* Configuration Info */}
                    <Card className="bg-muted/50">
                        <CardHeader>
                            <CardTitle className="text-sm">
                                Configuration & Thresholds
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-8 gap-4 text-sm">
                                <div>
                                    <span className="text-muted-foreground">
                                        Active Assets:
                                    </span>
                                    <p className="font-semibold text-blue-600 dark:text-blue-400">
                                        Top 500
                                    </p>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">
                                        Short Window:
                                    </span>
                                    <p className="font-semibold">
                                        {shortDays} day
                                        {shortDays !== 1 ? 's' : ''}
                                    </p>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">
                                        Long Window:
                                    </span>
                                    <p className="font-semibold">
                                        {longDays} day
                                        {longDays !== 1 ? 's' : ''}
                                    </p>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">
                                        Flat Threshold:
                                    </span>
                                    <p className="font-semibold text-yellow-600 dark:text-yellow-400">
                                        ±{flatThresholdPct.toFixed(2)}%
                                    </p>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">
                                        Negative Alert:
                                    </span>
                                    <p className="font-semibold text-yellow-500 dark:text-yellow-300">
                                        {negativeAlertPct.toFixed(2)}%
                                    </p>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">
                                        Good Positive:
                                    </span>
                                    <p className="font-semibold text-green-500 dark:text-green-300">
                                        {goodPositivePct.toFixed(2)}%+
                                    </p>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">
                                        Great Positive:
                                    </span>
                                    <p className="font-semibold text-green-600 dark:text-green-400">
                                        {greatPositivePct.toFixed(2)}%+
                                    </p>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">
                                        Lookback:
                                    </span>
                                    <p className="text-xs font-semibold">
                                        30d, 15d, 5d, 3d, 1d
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* All Watched Assets Table */}
                    {allAssets.length === 0 ? (
                        <Card>
                            <CardContent className="p-6 text-center">
                                <p className="text-sm text-muted-foreground">
                                    No watched assets found. Add assets to your watch list to see analysis here.
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        <StagnationTable
                            assets={allAssets}
                            title={`All Watched Assets (${allAssets.length})`}
                        />
                    )}
                </div>
            </AppLayout>
        </>
    );
}
