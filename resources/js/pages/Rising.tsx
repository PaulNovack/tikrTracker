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
import { useState } from 'react';

interface RisingStock {
    symbol: string;
    asset_type: 'stock';
    current_price: number;
    changes: Record<number, { 
        percent: number; 
        price: number; 
        open: number | null; 
        close: number | null; 
    } | null>;
    asset_info_id: number | null;
}

interface RisingProps {
    stocks: RisingStock[];
    timeRanges: Record<number, string>;
    selectedTimestamp: string;
    selectedTimestampEst: string;
    assetTypeFilter: 'stock';
    filters: {
        date: string | null;
    };
}

export default function Rising({
    stocks,
    timeRanges,
    selectedTimestamp,
    selectedTimestampEst,
    assetTypeFilter,
    filters,
}: RisingProps) {
    const [selectedDate, setSelectedDate] = useState(filters?.date || '');

    const handleDateChange = (date: string) => {
        setSelectedDate(date);
        router.visit(`/rising?filter=${assetTypeFilter}${date ? `&date=${date}` : ''}`, {
            preserveScroll: true,
        });
    };

    const handleFilterChange = (newFilter: 'stock') => {
        router.visit(`/rising?filter=${newFilter}${selectedDate ? `&date=${selectedDate}` : ''}`, {
            preserveScroll: true,
        });
    };

    const getMomentumIndicator = (stock: RisingStock) => {
        const change1D = stock.changes[1];
        const change2D = stock.changes[2];
        
        if (!change1D || !change2D || change1D.percent === null || change2D.percent === null) {
            return { color: 'text-gray-400', symbol: '–', title: 'No data' };
        }

        // Simple comparison: if 1D% > 2D%, momentum is increasing
        if (change1D.percent > change2D.percent) {
            return { 
                color: 'text-green-600 dark:text-green-400', 
                symbol: '▲', 
                title: `Accelerating: ${change1D.percent.toFixed(1)}% > ${change2D.percent.toFixed(1)}%` 
            };
        } else {
            return { 
                color: 'text-orange-600 dark:text-orange-400', 
                symbol: '▼', 
                title: `Decelerating: ${change1D.percent.toFixed(1)}% < ${change2D.percent.toFixed(1)}%` 
            };
        }
    };

    const getChangeColor = (percent: number | null | undefined) => {
        if (percent === null || percent === undefined) return '';
        
        // Handle negative percentages (red)
        if (percent < 0) {
            if (percent <= -5) {
                return 'text-red-700 dark:text-red-300 font-semibold'; // Strong red for -5%+
            }
            if (percent <= -2) {
                return 'text-red-600 dark:text-red-400 font-semibold'; // Bright red for -2%+
            }
            if (percent <= -1) {
                return 'text-red-500 dark:text-red-300 font-semibold'; // Regular red for -1%+
            }
            return 'text-red-400 dark:text-red-500'; // Light red for smaller losses
        }
        
        // Handle positive percentages (green)
        if (percent >= 5) {
            return 'text-green-700 dark:text-green-300 font-semibold'; // Strong green for 5%+
        }
        if (percent >= 2) {
            return 'text-green-600 dark:text-green-400 font-semibold'; // Bright green for 2%+
        }
        if (percent >= 1) {
            return 'text-green-500 dark:text-green-300 font-semibold'; // Regular green for 1%+
        }
        return 'text-green-400 dark:text-green-500'; // Light green for smaller gains
    };

    const formatPercent = (value: number | null | undefined) => {
        if (value === null || value === undefined) return '—';
        if (value < 0) {
            return `${value.toFixed(2)}%`; // Negative values already have the minus sign
        }
        return `+${value.toFixed(2)}%`; // Positive values get a plus sign
    };

    const formatPrice = (value: number | null | undefined) => {
        if (value === null || value === undefined) return '—';
        return `$${value.toFixed(2)}`;
    };

    return (
        <>
            <Head title="Daily Rising 100" />
            <AppLayout breadcrumbs={[{ title: 'Daily Rising 100', href: '/rising' }]}>
                <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between">
                        <Heading
                            title="Daily Rising 100"
                            description="Shows percentage changes from open (baseline) to close (current) prices over 1, 2, 3, 5, or 7 trading days back"
                        />
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Daily Rising 100 ({stocks.length})
                            </CardTitle>
                            <CardDescription>
                                Performance since specific trading days: each column shows percentage 
                                change from that many trading days ago to current price
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {/* Filters */}
                            <div className="mb-4 flex flex-wrap items-end gap-4">
                                <div className="flex gap-2">
                                    <Button
                                        variant={
                                            assetTypeFilter === 'stock'
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() => handleFilterChange('stock')}
                                    >
                                        Stocks
                                    </Button>
                                </div>
                                <div>
                                    <label className="block text-sm font-medium mb-1">Date</label>
                                    <input
                                        type="date"
                                        value={selectedDate}
                                        onChange={(e) => handleDateChange(e.target.value)}
                                        className="rounded-md border px-3 py-2 text-sm bg-background"
                                    />
                                </div>
                            </div>

                            {stocks.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No stocks with data at this time
                                </p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead className="border-b">
                                            <tr className="text-muted-foreground">
                                                <th className="px-4 py-2 text-left font-semibold">
                                                    Symbol
                                                </th>
                                                <th className="px-4 py-2 text-left font-semibold">
                                                    Type
                                                </th>
                                                {Object.entries(timeRanges)
                                                    .reverse()
                                                    .map(([minutes, label]) => (
                                                        <th
                                                            key={minutes}
                                                            className="px-4 py-2 text-right font-semibold"
                                                        >
                                                            {label}
                                                        </th>
                                                    ))}
                                                <th className="px-4 py-2 text-center font-semibold">
                                                    Trend
                                                </th>
                                                <th className="px-4 py-2 text-right font-semibold">
                                                    Price
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {stocks &&
                                                stocks.map((stock) => {
                                                    if (!stock || !stock.symbol)
                                                        return null;
                                                    return (
                                                        <tr
                                                            key={`${stock.symbol}-${stock.asset_type}`}
                                                            className="border-b hover:bg-muted/50"
                                                        >
                                                            <td className="px-4 py-3 font-medium">
                                                                <Link
                                                                    href={`/market-data/assets/${stock.asset_info_id}`}
                                                                    className="text-blue-600 hover:text-blue-800 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
                                                                >
                                                                    {
                                                                        stock.symbol
                                                                    }
                                                                </Link>
                                                            </td>
                                                            <td className="px-4 py-3">
                                                                <Badge
                                                                    variant="outline"
                                                                    className="capitalize"
                                                                >
                                                                    {
                                                                        stock.asset_type
                                                                    }
                                                                </Badge>
                                                            </td>
                                                            {Object.entries(
                                                                timeRanges,
                                                            )
                                                                .reverse()
                                                                .map(
                                                                    ([
                                                                        minutes,
                                                                    ]) => {
                                                                        const change =
                                                                            stock.changes
                                                                                ? stock
                                                                                      .changes[
                                                                                      Number(
                                                                                          minutes,
                                                                                      )
                                                                                  ]
                                                                                : null;
                                                                        return (
                                                                            <td
                                                                                key={
                                                                                    minutes
                                                                                }
                                                                                className={`px-4 py-3 text-right font-mono ${getChangeColor(change?.percent ?? null)}`}
                                                                            >
                                                                                <div className="flex flex-col items-end">
                                                                                    <span>
                                                                                        {formatPercent(
                                                                                            change?.percent ??
                                                                                                null,
                                                                                        )}
                                                                                    </span>
                                                                                    {change?.open && change?.close && (
                                                                                        <div className="text-xs text-muted-foreground mt-1">
                                                                                            <div>O: {formatPrice(change.open)}</div>
                                                                                            <div>C: {formatPrice(change.close)}</div>
                                                                                        </div>
                                                                                    )}
                                                                                </div>
                                                                            </td>
                                                                        );
                                                                    },
                                                                )}
                                                            <td className="px-4 py-3 text-center">
                                                                {(() => {
                                                                    const momentum = getMomentumIndicator(stock);
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
                                                            <td className="px-4 py-3 text-right font-mono">
                                                                $
                                                                {stock.current_price
                                                                    ? stock.current_price.toFixed(
                                                                          2,
                                                                      )
                                                                    : '—'}
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </AppLayout>
        </>
    );
}
