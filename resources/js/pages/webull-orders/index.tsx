import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

interface Order {
    client_order_id: string;
    symbol: string;
    side: string;
    order_type: string;
    qty: string;
    filled_qty?: string;
    limit_price?: string;
    stop_price?: string;
    order_status?: string;
    place_time?: string;
    last_filled_time?: string;
    filled_price?: string;
    combo_type?: string;
}

export default function WebullOrders({
    orders_today,
}: PageProps<{ orders_today: Order[] }>) {
    // Calculate P/L for closed positions (matching buy/sell pairs)
    const calculatePL = (order: Order) => {
        // Only calculate P/L for filled sell orders
        if (order.side?.toLowerCase() !== 'sell' || order.order_status?.toLowerCase() !== 'filled') {
            return null;
        }

        const sellPrice = parseFloat(order.filled_price || '0');
        const sellQty = parseFloat(order.filled_qty || order.qty || '0');

        if (!sellPrice || !sellQty) {
            return null;
        }

        // Find matching buy order for the same symbol
        const buyOrder = orders_today.find(
            (o) =>
                o.symbol === order.symbol &&
                o.side?.toLowerCase() === 'buy' &&
                o.order_status?.toLowerCase() === 'filled'
        );

        if (!buyOrder) {
            return null;
        }

        const buyPrice = parseFloat(buyOrder.filled_price || '0');
        if (!buyPrice) {
            return null;
        }

        // Calculate P/L
        const plDollar = (sellPrice - buyPrice) * sellQty;
        const plPercent = ((sellPrice - buyPrice) / buyPrice) * 100;

        return {
            plDollar,
            plPercent,
        };
    };

    const formatPL = (pl: { plDollar: number; plPercent: number } | null) => {
        if (!pl) {
            return { dollar: '-', percent: '-', color: 'text-gray-600 dark:text-gray-400' };
        }

        const color = pl.plDollar >= 0 
            ? 'text-green-600 dark:text-green-400 font-medium' 
            : 'text-red-600 dark:text-red-400 font-medium';
        
        const dollar = `${pl.plDollar >= 0 ? '+' : ''}$${pl.plDollar.toFixed(2)}`;
        const percent = `${pl.plPercent >= 0 ? '+' : ''}${pl.plPercent.toFixed(2)}%`;

        return { dollar, percent, color };
    };

    const getStatusColor = (status: string | undefined) => {
        if (!status) return 'text-gray-600 dark:text-gray-400';
        switch (status.toLowerCase()) {
            case 'filled':
            case 'complete':
                return 'text-green-600 dark:text-green-400';
            case 'partially_filled':
            case 'partial':
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

    const getSideColor = (side: string | undefined) => {
        if (!side) return 'text-gray-600 dark:text-gray-400';
        return side.toLowerCase() === 'buy'
            ? 'text-green-600 dark:text-green-400 font-medium'
            : 'text-red-600 dark:text-red-400 font-medium';
    };

    const formatDateTime = (dateString: string | null | undefined) => {
        if (!dateString) return '-';
        try {
            return new Date(dateString).toLocaleString();
        } catch {
            return dateString;
        }
    };

    const formatPrice = (value: string | number | null | undefined) => {
        if (!value) return '-';
        const num = typeof value === 'string' ? parseFloat(value) : value;
        if (isNaN(num)) return '-';
        return `$${num.toFixed(2)}`;
    };

    const formatQuantity = (value: string | number | null | undefined) => {
        if (!value) return '-';
        const num = typeof value === 'string' ? parseFloat(value) : value;
        if (isNaN(num)) return '-';
        return num.toFixed(2);
    };

    return (
        <AppLayout>
            <Head title="Webull Orders" />

            <div className="mx-auto max-w-[100rem] px-4 py-6 sm:px-6 lg:px-8">
                <div className="space-y-6">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900 dark:text-white">
                                Webull Orders Today
                            </h1>
                            <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                Today's orders from Webull API
                            </p>
                        </div>
                    </div>

                    {/* Orders Table */}
                    <div className="rounded-lg border bg-card">
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-sm font-medium">
                                            Symbol
                                        </th>
                                        <th className="px-4 py-3 text-left text-sm font-medium">
                                            Side
                                        </th>
                                        <th className="px-4 py-3 text-left text-sm font-medium">
                                            Type
                                        </th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">
                                            Qty
                                        </th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">
                                            Filled Qty
                                        </th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">
                                            Limit Price
                                        </th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">
                                            Stop Price
                                        </th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">
                                            Avg Fill Price
                                        </th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">
                                            P/L $
                                        </th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">
                                            P/L %
                                        </th>
                                        <th className="px-4 py-3 text-left text-sm font-medium">
                                            Status
                                        </th>
                                        <th className="px-4 py-3 text-left text-sm font-medium">
                                            Time
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {orders_today.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={12}
                                                className="px-4 py-8 text-center text-sm text-muted-foreground"
                                            >
                                                No orders found for today
                                            </td>
                                        </tr>
                                    ) : (
                                        orders_today.map((order) => {
                                            const pl = calculatePL(order);
                                            const plFormatted = formatPL(pl);
                                            
                                            return (
                                            <tr
                                                key={order.client_order_id}
                                                className="hover:bg-muted/50 transition-colors"
                                            >
                                                <td className="px-4 py-3 font-mono font-medium">
                                                    {order.symbol || '-'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className={getSideColor(order.side)}>
                                                        {order.side ? order.side.toUpperCase() : '-'}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-sm text-muted-foreground">
                                                    {order.order_type || '-'}
                                                </td>
                                                <td className="px-4 py-3 text-right font-mono">
                                                    {formatQuantity(order.qty)}
                                                </td>
                                                <td className="px-4 py-3 text-right font-mono">
                                                    {formatQuantity(order.filled_qty)}
                                                </td>
                                                <td className="px-4 py-3 text-right font-mono">
                                                    {formatPrice(order.limit_price)}
                                                </td>
                                                <td className="px-4 py-3 text-right font-mono">
                                                    {formatPrice(order.stop_price)}
                                                </td>
                                                <td className="px-4 py-3 text-right font-mono">
                                                    {formatPrice(order.filled_price)}
                                                </td>
                                                <td className="px-4 py-3 text-right font-mono">
                                                    <span className={plFormatted.color}>
                                                        {plFormatted.dollar}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-right font-mono">
                                                    <span className={plFormatted.color}>
                                                        {plFormatted.percent}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className={getStatusColor(order.order_status)}>
                                                        {order.order_status || '-'}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-sm text-muted-foreground">
                                                    {formatDateTime(order.place_time || order.last_filled_time)}
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
                        Showing {orders_today.length} order{orders_today.length !== 1 ? 's' : ''}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
