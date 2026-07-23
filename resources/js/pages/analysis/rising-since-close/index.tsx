import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowDown, ArrowUp, TrendingUp } from 'lucide-react';

interface RisingStock {
    symbol: string;
    asset_info_id: number | null;
    close_price: number;
    open_price: number | null;
    current_price: number;
    pct_change: number;
    since_open_pct: number | null;
    price_timestamp: string;
}

interface Props {
    stocks: RisingStock[];
    lastCloseDate: string;
    totalSymbols: number;
    newsLink: string;
}

export default function RisingSinceCloseIndex({ stocks, lastCloseDate, totalSymbols, newsLink }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Analysis', href: '/analysis/vwap-status' },
        { title: 'Rising Since Close', href: '/analysis/rising-since-close' },
    ];

    const topGainers = stocks.filter((s) => s.pct_change > 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Rising Since Close — ${lastCloseDate}`} />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                            Rising Since Close
                        </h1>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Stocks sorted by percentage gain since last market close{' '}
                            <span className="font-medium">{lastCloseDate}</span>
                            {' — '}
                            <span className="font-medium">{topGainers.length}</span> of{' '}
                            {totalSymbols} symbols are rising
                        </p>
                    </div>
                </div>

                {/* Summary Cards */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Symbols Analyzed</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{totalSymbols}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                <span className="flex items-center gap-1.5">
                                    <ArrowUp className="h-4 w-4 text-green-500" /> Rising
                                </span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-green-600">{topGainers.length}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                <span className="flex items-center gap-1.5">
                                    <ArrowDown className="h-4 w-4 text-red-500" /> Declining
                                </span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-red-600">
                                {totalSymbols - topGainers.length}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Results Table */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <TrendingUp className="h-5 w-5" />
                            <CardTitle>Stocks Since Close — {lastCloseDate}</CardTitle>
                        </div>
                        <CardDescription>
                            Sorted by percentage change, highest gainers first
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-border">
                                        <th className="pb-2 pr-3 text-left font-medium text-muted-foreground">#</th>
                                        <th className="pb-2 pr-3 text-left font-medium text-muted-foreground">Symbol</th>
                                        <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Close</th>
                                        <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Open</th>
                                        <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Current</th>
                                        <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Since Close</th>
                                        <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Since Open</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border">
                                    {stocks.length === 0 && (
                                        <tr>
                                            <td colSpan={7} className="py-12 text-center text-muted-foreground">
                                                No data available for {lastCloseDate}. The market may have been closed or no prices are loaded.
                                            </td>
                                        </tr>
                                    )}
                                    {stocks.map((stock, i) => (
                                        <tr key={stock.symbol} className="hover:bg-muted/50">
                                            <td className="py-1.5 pr-3 text-muted-foreground">{i + 1}</td>
                                            <td className="py-1.5 pr-3 font-medium">
                                                <div className="flex items-center gap-1.5">
                                                    <a
                                                        href={`/market-data/assets/${stock.asset_info_id}`}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 hover:underline"
                                                    >
                                                        {stock.symbol}
                                                    </a>
                                                    {newsLink && (
                                                        <a
                                                            href={newsLink.replace(/<SYMBOL>/gi, stock.symbol)}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="inline-flex items-center gap-0.5 text-xs text-blue-500 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
                                                            title={`News for ${stock.symbol}`}
                                                        >
                                                            <svg xmlns="http://www.w3.org/2000/svg" className="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                                <path strokeLinecap="round" strokeLinejoin="round" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z" />
                                                            </svg>
                                                            News
                                                        </a>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="py-1.5 pr-3 text-right font-mono">
                                                ${stock.close_price.toFixed(2)}
                                            </td>
                                            <td className="py-1.5 pr-3 text-right font-mono">
                                                {stock.open_price != null ? `$${stock.open_price.toFixed(2)}` : '—'}
                                            </td>
                                            <td className="py-1.5 pr-3 text-right font-mono">
                                                ${stock.current_price.toFixed(2)}
                                            </td>
                                            <td className="py-1.5 pr-3 text-right font-mono">
                                                {stock.pct_change >= 0 ? (
                                                    <span className="inline-flex items-center gap-1 text-green-600 dark:text-green-400">
                                                        <ArrowUp className="h-3.5 w-3.5" />
                                                        +{stock.pct_change.toFixed(2)}%
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center gap-1 text-red-600 dark:text-red-400">
                                                        <ArrowDown className="h-3.5 w-3.5" />
                                                        {stock.pct_change.toFixed(2)}%
                                                    </span>
                                                )}
                                            </td>
                                            <td className="py-1.5 text-right font-mono">
                                                {stock.since_open_pct != null ? (
                                                    stock.since_open_pct >= 0 ? (
                                                        <span className="inline-flex items-center gap-1 text-green-600 dark:text-green-400">
                                                            <ArrowUp className="h-3.5 w-3.5" />
                                                            +{stock.since_open_pct.toFixed(2)}%
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center gap-1 text-red-600 dark:text-red-400">
                                                            <ArrowDown className="h-3.5 w-3.5" />
                                                            {stock.since_open_pct.toFixed(2)}%
                                                        </span>
                                                    )
                                                ) : (
                                                    <span className="text-muted-foreground">—</span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
