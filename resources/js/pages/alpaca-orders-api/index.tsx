import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

interface AlpacaApiOrder {
    id: string;
    client_order_id: string;
    created_at: string;
    updated_at: string;
    submitted_at: string;
    filled_at: string | null;
    expired_at: string | null;
    canceled_at: string | null;
    failed_at: string | null;
    replaced_at: string | null;
    replaced_by: string | null;
    replaces: string | null;
    asset_id: string;
    symbol: string;
    asset_class: string;
    notional: string | null;
    qty: string;
    filled_qty: string;
    filled_avg_price: string | null;
    order_class: string;
    order_type: string;
    type: string;
    side: string;
    time_in_force: string;
    limit_price: string | null;
    stop_price: string | null;
    status: string;
    extended_hours: boolean;
    legs: any[] | null;
    trail_percent: string | null;
    trail_price: string | null;
    hwm: string | null;
    parent_order_id?: string | null;
}

interface CurrentPrice {
    price: string;
    timestamp: string;
}

export default function AlpacaOrdersApi({
    orders,
    error,
    status,
    filters,
    currentPrices,
    ownedQuantities,
    realizedSellPrices,
}: PageProps<{
    orders: AlpacaApiOrder[];
    error: string | null;
    status: string;
    filters: {
        start_date: string | null;
        end_date: string | null;
        only_owned: boolean;
    };
    currentPrices: Record<string, CurrentPrice>;
    ownedQuantities: Record<string, number>;
    realizedSellPrices: Record<string, { price: number; qty: number }>;
}>) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Alpaca', href: '/alpaca-orders' },
        { title: 'Orders From API', href: '/alpaca-orders-api' },
    ];
    const [startDate, setStartDate] = useState(filters.start_date || '');
    const [endDate, setEndDate] = useState(filters.end_date || '');
    const [limit, setLimit] = useState(500);

    const calculatePL = (order: AlpacaApiOrder, actualQty?: number) => {
        if (!order.filled_avg_price || !currentPrices[order.symbol]) {
            return { plPct: 0, plDollar: 0 };
        }

        const avgPrice = parseFloat(order.filled_avg_price);
        const currentPrice = parseFloat(currentPrices[order.symbol].price);
        const qty = actualQty || parseFloat(order.filled_qty);

        const plPct = ((currentPrice - avgPrice) / avgPrice) * 100;
        const plDollar = (currentPrice - avgPrice) * qty;

        return { plPct, plDollar };
    };

    const handleFilter = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            '/alpaca-orders-api',
            {
                start_date: startDate || undefined,
                end_date: endDate || undefined,
                limit,
                status,
            },
            {
                preserveState: true,
                preserveScroll: true,
            }
        );
    };

    const handleClear = () => {
        setStartDate('');
        setEndDate('');
        setLimit(500);
        router.get('/alpaca-orders-api', { status, limit: 500 });
    };
    const getStatusColor = (status: string) => {
        switch (status) {
            case 'filled':
                return 'text-green-600 dark:text-green-400';
            case 'partially_filled':
                return 'text-yellow-600 dark:text-yellow-400';
            case 'canceled':
            case 'cancelled':
            case 'expired':
            case 'rejected':
                return 'text-red-600 dark:text-red-400';
            default:
                return 'text-blue-600 dark:text-blue-400';
        }
    };

    const getSideColor = (side: string) => {
        return side === 'buy'
            ? 'text-green-600 dark:text-green-400 font-medium'
            : 'text-red-600 dark:text-red-400 font-medium';
    };

    const formatDateTime = (dateString: string | null) => {
        if (!dateString) return '-';
        try {
            return new Date(dateString).toLocaleString();
        } catch {
            return dateString;
        }
    };

    // P&L calculation — uses backend-built realizedSellPrices map keyed by
    // BUY alpaca_order_id. For buy orders, realizedSellPrices[buy.id] gives
    // the weighted sell price. For sell orders or unmatched buys, use current price.
    const calculatePLForSummary = (order: AlpacaApiOrder) => {
        if (!order.filled_avg_price || !order.filled_qty) {
            return null;
        }
        const qty = parseFloat(order.filled_qty);
        const avgPrice = parseFloat(order.filled_avg_price);

        if (order.side === 'buy') {
            const realized = realizedSellPrices[order.id];
            if (realized) {
                const sellPrice = realized.price;
                const matchedQty = Math.min(qty, realized.qty);
                const plDollar = (sellPrice - avgPrice) * matchedQty;
                return { plDollar, isRealized: true };
            }
            // Unmatched buy — use current price
            if (currentPrices[order.symbol]) {
                const current = parseFloat(currentPrices[order.symbol].price);
                return { plDollar: (current - avgPrice) * qty, isRealized: false };
            }
            return null;
        }

        // For sells, just show current vs avg (informational)
        if (currentPrices[order.symbol]) {
            const current = parseFloat(currentPrices[order.symbol].price);
            return { plDollar: (current - avgPrice) * qty, isRealized: false };
        }
        return null;
    };

    const filledOrders = orders.filter((o) => o.status === 'filled');
    const buyCount = filledOrders.filter((o) => o.side === 'buy').length;
    const sellCount = filledOrders.filter((o) => o.side === 'sell').length;

    let plDollar = 0;
    let totalBought = 0;
    for (const order of filledOrders) {
        if (order.side !== 'buy') continue;
        const pl = calculatePLForSummary(order);
        if (!pl) continue;
        plDollar += pl.plDollar;
        totalBought += parseFloat(order.filled_avg_price || '0') * parseFloat(order.filled_qty || '0');
    }
    const plPct = totalBought > 0 ? (plDollar / totalBought) * 100 : 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Alpaca Orders From API" />

            <div className="mx-auto max-w-[100rem] px-4 py-6 sm:px-6 lg:px-8">
                <div className="space-y-6">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900 dark:text-white">
                                Alpaca Orders From API
                            </h1>
                            <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                Live orders fetched directly from Alpaca API
                            </p>
                        </div>
                    </div>

                    <div className="rounded-lg border bg-card p-4">
                        <form onSubmit={handleFilter} className="flex flex-wrap items-end gap-4">
                            <div className="flex-1 min-w-[200px]">
                                <label className="block text-sm font-medium mb-2">
                                    Start Date
                                </label>
                                <input
                                    type="date"
                                    value={startDate}
                                    onChange={(e) => setStartDate(e.target.value)}
                                    className="w-full rounded-md border px-3 py-2 text-sm bg-background"
                                />
                            </div>
                            <div className="flex-1 min-w-[200px]">
                                <label className="block text-sm font-medium mb-2">
                                    End Date
                                </label>
                                <input
                                    type="date"
                                    value={endDate}
                                    onChange={(e) => setEndDate(e.target.value)}
                                    className="w-full rounded-md border px-3 py-2 text-sm bg-background"
                                />
                            </div>
<div className="flex-1 min-w-[120px]">
                                <label className="block text-sm font-medium mb-2">
                                    Limit
                                </label>
                                <select
                                    value={limit}
                                    onChange={(e) => setLimit(Number(e.target.value))}
                                    className="w-full rounded-md border px-3 py-2 text-sm bg-background"
                                >
                                    <option value={100}>100</option>
                                    <option value={250}>250</option>
                                    <option value={500}>500</option>
                                </select>
                            </div>
                            <div className="flex gap-2">
                                <button
                                    type="submit"
                                    className="px-4 py-2 bg-primary text-primary-foreground rounded-md text-sm font-medium hover:bg-primary/90"
                                >
                                    Filter
                                </button>
                                <button
                                    type="button"
                                    onClick={handleClear}
                                    className="px-4 py-2 bg-secondary text-secondary-foreground rounded-md text-sm font-medium hover:bg-secondary/90"
                                >
                                    Clear
                                </button>
                            </div>
                        </form>
                    </div>

                    {error && (
                        <div className="rounded-lg border border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950 p-4">
                            <p className="text-sm text-red-600 dark:text-red-400">
                                {error}
                            </p>
                        </div>
                    )}

                    {/* P/L Summary */}
                    <div className="rounded-lg border bg-card p-4">
                        <h2 className="text-sm font-semibold mb-3 text-muted-foreground uppercase tracking-wide">
                            Summary ({buyCount + sellCount} filled orders)
                        </h2>
                        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div>
                                <div className="text-sm text-muted-foreground">P/L $</div>
                                <div className={`text-xl font-bold ${plDollar >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}>
                                    {plDollar >= 0 ? '+' : ''}{plDollar.toFixed(2)}
                                </div>
                            </div>
                            <div>
                                <div className="text-sm text-muted-foreground">P/L %</div>
                                <div className={`text-xl font-bold ${plPct >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}>
                                    {plPct >= 0 ? '+' : ''}{plPct.toFixed(2)}%
                                </div>
                            </div>
                            <div>
                                <div className="text-sm text-muted-foreground">Total Bought</div>
                                <div className="text-xl font-bold">
                                    {totalBought > 0 ? `$${totalBought.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` : '-'}
                                </div>
                            </div>
                            <div>
                                <div className="text-sm text-muted-foreground">Buys vs Sells</div>
                                <div className="text-xl font-bold">
                                    {buyCount} / {sellCount}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="rounded-lg border bg-card">
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-3 py-1.5 text-left text-xs font-medium">
                                            Symbol
                                        </th>
                                        <th className="px-3 py-1.5 text-left text-xs font-medium">
                                            ID
                                        </th>
                                        <th className="px-3 py-1.5 text-left text-xs font-medium">
                                            Client ID
                                        </th>
                                        <th className="px-3 py-1.5 text-left text-xs font-medium">
                                            Side
                                        </th>
                                        <th className="px-3 py-1.5 text-left text-xs font-medium">
                                            Type
                                        </th>
                                        <th className="px-3 py-1.5 text-right text-xs font-medium">
                                            Qty
                                        </th>
                                        <th className="px-3 py-1.5 text-right text-xs font-medium">
                                            Filled
                                        </th>
                                        <th className="px-3 py-1.5 text-right text-xs font-medium">
                                            Avg Price
                                        </th>
                                        <th className="px-3 py-1.5 text-right text-xs font-medium">
                                            Current Price
                                        </th>
                                        <th className="px-3 py-1.5 text-right text-xs font-medium">
                                            P/L $
                                        </th>
                                        <th className="px-3 py-1.5 text-right text-xs font-medium">
                                            P/L %
                                        </th>
                                        <th className="px-3 py-1.5 text-right text-xs font-medium">
                                            Limit Price
                                        </th>
                                        <th className="px-3 py-1.5 text-right text-xs font-medium">
                                            Stop Price
                                        </th>
                                        <th className="px-3 py-1.5 text-left text-xs font-medium">
                                            Status
                                        </th>
                                        <th className="px-3 py-1.5 text-left text-xs font-medium">
                                            Submitted At
                                        </th>
                                        <th className="px-3 py-1.5 text-left text-xs font-medium">
                                            Filled At
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {orders.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={16}
                                                className="px-3 py-4 text-center text-xs text-muted-foreground"
                                            >
                                                No orders found
                                            </td>
                                        </tr>
                                    ) : (
                                        orders.map((order) => {
                                            const actualQty = ownedQuantities[order.symbol];
                                            const displayQty = actualQty !== undefined ? actualQty : parseFloat(order.filled_qty);
                                            const { plPct, plDollar } = calculatePL(order, actualQty);
                                            const currentPrice = currentPrices[order.symbol]?.price;
                                            
                                            return (
                                            <tr
                                                key={order.id}
                                                className="hover:bg-muted/50 transition-colors"
                                            >
                                                <td className="px-3 py-1.5 font-mono font-medium text-xs">
                                                    {order.symbol}
                                                </td>
                                                <td className="px-3 py-1.5 font-mono text-xs text-muted-foreground">
                                                    {order.id || '-'}
                                                </td>
                                                <td className="px-3 py-1.5 font-mono text-xs text-muted-foreground">
                                                    {order.client_order_id || '-'}
                                                </td>
                                                <td className="px-3 py-1.5 text-xs">
                                                    <span
                                                        className={getSideColor(
                                                            order.side
                                                        )}
                                                    >
                                                        {order.side.toUpperCase()}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-1.5 text-xs text-muted-foreground">
                                                    {order.order_type}
                                                </td>
                                                <td className="px-3 py-1.5 text-right font-mono text-xs">
                                                    {parseFloat(order.qty).toFixed(2)}
                                                </td>
                                                <td className="px-3 py-1.5 text-right font-mono text-xs">
                                                    {displayQty.toFixed(2)}
                                                </td>
                                                <td className="px-3 py-1.5 text-right font-mono text-xs">
                                                    {order.filled_avg_price
                                                        ? `$${parseFloat(order.filled_avg_price).toFixed(2)}`
                                                        : '-'}
                                                </td>
                                                <td className="px-3 py-1.5 text-right font-mono text-xs">
                                                    {currentPrice
                                                        ? `$${parseFloat(currentPrice).toFixed(2)}`
                                                        : '-'}
                                                </td>
                                                <td className="px-3 py-1.5 text-right font-mono text-xs">
                                                    {order.filled_avg_price && currentPrice ? (
                                                        <span className={plDollar >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}>
                                                            {plDollar >= 0 ? '+' : ''}{plDollar.toFixed(2)}
                                                        </span>
                                                    ) : '-'}
                                                </td>
                                                <td className="px-3 py-1.5 text-right font-mono text-xs">
                                                    {order.filled_avg_price && currentPrice ? (
                                                        <span className={plPct >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}>
                                                            {plPct >= 0 ? '+' : ''}{plPct.toFixed(2)}%
                                                        </span>
                                                    ) : '-'}
                                                </td>
                                                <td className="px-3 py-1.5 text-right font-mono text-xs">
                                                    {order.limit_price
                                                        ? `$${parseFloat(order.limit_price).toFixed(2)}`
                                                        : '-'}
                                                </td>
                                                <td className="px-3 py-1.5 text-right font-mono text-xs">
                                                    {order.stop_price
                                                        ? `$${parseFloat(order.stop_price).toFixed(2)}`
                                                        : '-'}
                                                </td>
                                                <td className="px-3 py-1.5 text-xs">
                                                    <span
                                                        className={getStatusColor(
                                                            order.status
                                                        )}
                                                    >
                                                        {order.status}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-1.5 text-xs text-muted-foreground">
                                                    {formatDateTime(
                                                        order.submitted_at
                                                    )}
                                                </td>
                                                <td className="px-3 py-1.5 text-xs text-muted-foreground">
                                                    {formatDateTime(
                                                        order.filled_at
                                                    )}
                                                </td>
                                            </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div className="text-sm text-muted-foreground">
                        Showing {orders.length} order{orders.length !== 1 ? 's' : ''}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
