import { SharedData } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

interface SlippageOrder {
    id: number;
    symbol: string;
    qty: string;
    filled_qty: string | null;
    filled_avg_price: string | null;
    total_amount: number | null;
    order_type: string | null;
    stop_price: string | null;
    market_price_1m: string | null;
    market_price_timestamp: string | null;
    slippage_dollars: string | null;
    slippage_pct: string | null;
    submitted_at: string | null;
    filled_at: string | null;
    created_at: string;
}

interface PaginatedOrders {
    data: SlippageOrder[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Statistics {
    total_orders: number;
    avg_slippage_pct: string | null;
    min_slippage_pct: string | null;
    max_slippage_pct: string | null;
    total_slippage_dollars: string | null;
}

interface PageProps extends SharedData {
    orders: PaginatedOrders;
    statistics: Statistics;
    filters: {
        start_date: string;
        end_date: string;
    };
}

export default function AlpacaSellSlippage({
    orders,
    statistics,
    filters,
}: PageProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Alpaca', href: '/alpaca-orders' },
        { title: 'Sell Slippage', href: '/alpaca-sell-slippage' },
    ];

    const [startDate, setStartDate] = useState(filters.start_date || '');
    const [endDate, setEndDate] = useState(filters.end_date || '');

    const handleFilter = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            '/alpaca-sell-slippage',
            {
                start_date: startDate || undefined,
                end_date: endDate || undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
            },
        );
    };

    const handleClearFilters = () => {
        setStartDate('');
        setEndDate('');
        router.get(
            '/alpaca-sell-slippage',
            {},
            {
                preserveState: true,
                preserveScroll: true,
            },
        );
    };

    const formatCurrency = (value: string | null) => {
        if (!value) return '-';
        const num = parseFloat(value);
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
            minimumFractionDigits: 2,
            maximumFractionDigits: 4,
        }).format(num);
    };

    const formatPercent = (value: string | null) => {
        if (!value) return '-';
        const num = parseFloat(value);
        return `${num >= 0 ? '+' : ''}${num.toFixed(4)}%`;
    };

    // For sells: positive slippage = received less than market (bad = red)
    //            negative slippage = received more than market (good = green)
    const getSlippageColor = (value: string | null) => {
        if (!value) return '';
        const num = parseFloat(value);
        if (num > 0) return 'text-red-600 dark:text-red-400';
        if (num < 0) return 'text-green-600 dark:text-green-400';
        return '';
    };

    const formatDateTime = (dateString: string | null) => {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleString('en-US', {
            timeZone: 'America/New_York',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Alpaca Sell Slippage" />
            <div className="p-6">
                <div className="space-y-6">
                    {/* Header */}
                    <div>
                        <h1 className="text-2xl font-bold">Sell Slippage Analysis</h1>
                        <p className="text-muted-foreground mt-1">
                            Compare filled sell prices against 1-minute market prices at submission time
                        </p>
                    </div>

                    {/* Statistics */}
                    <div className="rounded-lg border bg-card p-4">
                        <h2 className="text-sm font-semibold mb-3 text-muted-foreground uppercase tracking-wide">
                            Summary ({statistics.total_orders} orders with market data)
                        </h2>
                        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div>
                                <div className="text-sm text-muted-foreground">Avg Slippage</div>
                                <div className={`text-xl font-bold ${getSlippageColor(statistics.avg_slippage_pct)}`}>
                                    {formatPercent(statistics.avg_slippage_pct)}
                                </div>
                            </div>
                            <div>
                                <div className="text-sm text-muted-foreground">Min Slippage</div>
                                <div className={`text-xl font-bold ${getSlippageColor(statistics.min_slippage_pct)}`}>
                                    {formatPercent(statistics.min_slippage_pct)}
                                </div>
                            </div>
                            <div>
                                <div className="text-sm text-muted-foreground">Max Slippage</div>
                                <div className={`text-xl font-bold ${getSlippageColor(statistics.max_slippage_pct)}`}>
                                    {formatPercent(statistics.max_slippage_pct)}
                                </div>
                            </div>
                            <div>
                                <div className="text-sm text-muted-foreground">Total Cost</div>
                                <div className={`text-xl font-bold ${getSlippageColor(statistics.total_slippage_dollars)}`}>
                                    {formatCurrency(statistics.total_slippage_dollars)}
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Date Filter */}
                    <div className="rounded-lg border bg-card p-4">
                        <form onSubmit={handleFilter} className="flex flex-wrap items-end gap-4">
                            <div className="flex-1 min-w-[200px]">
                                <label htmlFor="start_date" className="block text-sm font-medium mb-1">
                                    Start Date
                                </label>
                                <input
                                    type="date"
                                    id="start_date"
                                    value={startDate}
                                    onChange={(e) => setStartDate(e.target.value)}
                                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                />
                            </div>
                            <div className="flex-1 min-w-[200px]">
                                <label htmlFor="end_date" className="block text-sm font-medium mb-1">
                                    End Date
                                </label>
                                <input
                                    type="date"
                                    id="end_date"
                                    value={endDate}
                                    onChange={(e) => setEndDate(e.target.value)}
                                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                />
                            </div>
                            <div className="flex gap-2">
                                <button
                                    type="submit"
                                    className="px-4 py-2 rounded-md bg-primary text-primary-foreground hover:bg-primary/90 transition-colors"
                                >
                                    Apply Filters
                                </button>
                                <button
                                    type="button"
                                    onClick={handleClearFilters}
                                    className="px-4 py-2 rounded-md border bg-background hover:bg-muted transition-colors"
                                >
                                    Clear
                                </button>
                            </div>
                        </form>
                    </div>

                    {/* Orders Table */}
                    <div className="rounded-lg border bg-card overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead className="bg-muted/50 border-b">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-sm font-medium">Symbol</th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">Qty</th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">Fill Price</th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">Total Amount</th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">Market Price (1m)</th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">Slippage $</th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">Slippage %</th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">Total Impact</th>
                                        <th className="px-4 py-3 text-left text-sm font-medium">Submitted At</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {orders.data.map((order: SlippageOrder) => {
                                        const totalImpact =
                                            order.slippage_dollars && order.filled_qty
                                                ? (
                                                      parseFloat(order.slippage_dollars) *
                                                      parseFloat(order.filled_qty)
                                                  ).toFixed(2)
                                                : null;

                                        return (
                                            <tr
                                                key={order.id}
                                                className="hover:bg-muted/50 transition-colors"
                                            >
                                                <td className="px-4 py-3 font-medium">{order.symbol}</td>
                                                <td className="px-4 py-3 text-right">
                                                    {order.filled_qty
                                                        ? parseFloat(order.filled_qty).toFixed(2)
                                                        : '-'}
                                                </td>
                                                <td className="px-4 py-3 text-right font-mono">
                                                    {formatCurrency(order.filled_avg_price)}
                                                </td>
                                                <td className="px-4 py-3 text-right font-mono font-semibold">
                                                    {order.total_amount
                                                        ? formatCurrency(
                                                              parseFloat(order.total_amount.toString()).toFixed(2),
                                                          )
                                                        : '-'}
                                                </td>
                                                <td className="px-4 py-3 text-right font-mono">
                                                    {order.market_price_1m ? (
                                                        <span title={
                                                            order.order_type === 'stop'
                                                                ? 'Stop trigger price'
                                                                : `Market price at ${order.market_price_timestamp || 'submission'}`
                                                        }>
                                                            {formatCurrency(order.market_price_1m)}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">No data</span>
                                                    )}
                                                </td>
                                                <td
                                                    className={`px-4 py-3 text-right font-mono ${getSlippageColor(order.slippage_dollars)}`}
                                                >
                                                    {order.slippage_dollars
                                                        ? formatCurrency(order.slippage_dollars)
                                                        : '-'}
                                                </td>
                                                <td
                                                    className={`px-4 py-3 text-right font-mono ${getSlippageColor(order.slippage_pct)}`}
                                                >
                                                    {formatPercent(order.slippage_pct)}
                                                </td>
                                                <td
                                                    className={`px-4 py-3 text-right font-mono font-semibold ${getSlippageColor(totalImpact)}`}
                                                >
                                                    {totalImpact ? formatCurrency(totalImpact) : '-'}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-muted-foreground">
                                                    {formatDateTime(order.submitted_at)}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* Pagination */}
                    {orders.last_page > 1 && (
                        <div className="flex items-center justify-between">
                            <div className="text-sm text-muted-foreground">
                                Showing {(orders.current_page - 1) * orders.per_page + 1} to{' '}
                                {Math.min(orders.current_page * orders.per_page, orders.total)} of{' '}
                                {orders.total} orders
                            </div>
                            <div className="flex gap-2">
                                {orders.current_page > 1 && (
                                    <a
                                        href={`/alpaca-sell-slippage?page=${orders.current_page - 1}${startDate ? `&start_date=${startDate}` : ''}${endDate ? `&end_date=${endDate}` : ''}`}
                                        className="px-4 py-2 rounded-md border bg-background hover:bg-muted transition-colors"
                                    >
                                        Previous
                                    </a>
                                )}
                                {orders.current_page < orders.last_page && (
                                    <a
                                        href={`/alpaca-sell-slippage?page=${orders.current_page + 1}${startDate ? `&start_date=${startDate}` : ''}${endDate ? `&end_date=${endDate}` : ''}`}
                                        className="px-4 py-2 rounded-md border bg-background hover:bg-muted transition-colors"
                                    >
                                        Next
                                    </a>
                                )}
                            </div>
                        </div>
                    )}

                    {/* Legend */}
                    <div className="rounded-lg border bg-card p-4">
                        <h3 className="text-sm font-semibold mb-2">Understanding Sell Slippage</h3>
                        <ul className="text-sm text-muted-foreground space-y-1">
                            <li>
                                <span className="text-red-600 dark:text-red-400">Positive slippage</span>{' '}
                                (red) = You received less than the 1-minute market price
                            </li>
                            <li>
                                <span className="text-green-600 dark:text-green-400">Negative slippage</span>{' '}
                                (green) = You received more than the 1-minute market price
                            </li>
                            <li>
                                <strong>Total Impact</strong> = Slippage per share × Quantity filled
                            </li>
                            <li>
                                <strong>Market Price (1m)</strong> = The closing price of the 1-minute candle
                                at the time your order was submitted
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
