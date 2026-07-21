import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

interface Position {
    symbol: string;
    instrument_id: string;
    qty?: string;
    unit_cost?: string;
    last_price?: string;
    market_value?: string;
    unrealized_profit_loss?: string;
    unrealized_profit_loss_rate?: string;
    // Fallback fields
    quantity?: string;
    avg_cost?: string;
    avg_price?: string;
    unrealized_pl?: string;
    unrealized_pnl?: string;
    unrealized_pl_pct?: string;
    current_price?: string;
    [key: string]: any;
}

export default function WebullPositions({
    positions,
}: PageProps<{ positions: Position[] }>) {
    const formatCurrency = (value: string | number | null | undefined) => {
        if (value === null || value === undefined) return '-';
        const num = typeof value === 'string' ? parseFloat(value) : value;
        if (isNaN(num)) return '-';
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(num);
    };

    const formatPercent = (value: string | number | null | undefined) => {
        if (value === null || value === undefined) return '-';
        const num = typeof value === 'string' ? parseFloat(value) : value;
        if (isNaN(num)) return '-';
        return `${num >= 0 ? '+' : ''}${num.toFixed(2)}%`;
    };

    const getPLColor = (value: string | number | null | undefined) => {
        if (value === null || value === undefined) return '';
        const num = typeof value === 'string' ? parseFloat(value) : value;
        if (isNaN(num)) return '';
        return num >= 0
            ? 'text-green-600 dark:text-green-400 font-medium'
            : 'text-red-600 dark:text-red-400 font-medium';
    };

    const formatQuantity = (value: string | number | null | undefined) => {
        if (value === null || value === undefined) return '-';
        const num = typeof value === 'string' ? parseFloat(value) : value;
        if (isNaN(num)) return '-';
        return num.toFixed(2);
    };

    const getQuantity = (position: Position) => {
        return position.qty || position.quantity || position.position_qty || '0';
    };

    const getAvgCost = (position: Position) => {
        return position.unit_cost || position.avg_cost || position.avg_price || '0';
    };

    const getMarketValue = (position: Position) => {
        return position.market_value || '0';
    };

    const getLastPrice = (position: Position) => {
        return position.last_price || position.current_price || '0';
    };

    const getUnrealizedPL = (position: Position) => {
        return position.unrealized_profit_loss || position.unrealized_pl || position.unrealized_pnl || '0';
    };

    const getUnrealizedPLPct = (position: Position) => {
        // Webull returns as decimal (0.01 = 1%), convert to percentage
        const rate = position.unrealized_profit_loss_rate || position.unrealized_pl_pct || position.unrealized_pnl_pct || '0';
        const num = typeof rate === 'string' ? parseFloat(rate) : rate;
        return (num * 100).toString();
    };

    const totalMarketValue = positions.reduce(
        (sum, pos) => sum + parseFloat(getMarketValue(pos)),
        0
    );
    const totalUnrealizedPL = positions.reduce(
        (sum, pos) => sum + parseFloat(getUnrealizedPL(pos)),
        0
    );

    return (
        <AppLayout>
            <Head title="Webull Positions" />

            <div className="mx-auto max-w-[100rem] px-4 py-6 sm:px-6 lg:px-8">
                <div className="space-y-6">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900 dark:text-white">
                                Webull Positions
                            </h1>
                            <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                Current holdings from Webull API
                            </p>
                        </div>
                    </div>

                    {/* Summary Cards */}
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div className="rounded-lg border bg-card p-4">
                            <div className="text-sm font-medium text-muted-foreground">
                                Total Positions
                            </div>
                            <div className="mt-1 text-2xl font-bold">
                                {positions.length}
                            </div>
                        </div>
                        <div className="rounded-lg border bg-card p-4">
                            <div className="text-sm font-medium text-muted-foreground">
                                Total Market Value
                            </div>
                            <div className="mt-1 text-2xl font-bold">
                                {formatCurrency(totalMarketValue)}
                            </div>
                        </div>
                        <div className="rounded-lg border bg-card p-4">
                            <div className="text-sm font-medium text-muted-foreground">
                                Total Unrealized P&L
                            </div>
                            <div className={`mt-1 text-2xl font-bold ${getPLColor(totalUnrealizedPL)}`}>
                                {formatCurrency(totalUnrealizedPL)}
                            </div>
                        </div>
                    </div>

                    {/* Positions Table */}
                    <div className="rounded-lg border bg-card">
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-sm font-medium">
                                            Symbol
                                        </th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">
                                            Quantity
                                        </th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">
                                            Avg Cost
                                        </th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">
                                            Last Price
                                        </th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">
                                            Market Value
                                        </th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">
                                            Unrealized P&L
                                        </th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">
                                            P&L %
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {positions.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={7}
                                                className="px-4 py-8 text-center text-sm text-muted-foreground"
                                            >
                                                No positions found
                                            </td>
                                        </tr>
                                    ) : (
                                        positions.map((position) => (
                                            <tr
                                                key={position.instrument_id}
                                                className="hover:bg-muted/50 transition-colors"
                                            >
                                                <td className="px-4 py-3 font-mono font-medium">
                                                    {position.symbol}
                                                </td>
                                                <td className="px-4 py-3 text-right font-mono">
                                                    {formatQuantity(getQuantity(position))}
                                                </td>
                                                <td className="px-4 py-3 text-right font-mono">
                                                    {formatCurrency(getAvgCost(position))}
                                                </td>
                                                <td className="px-4 py-3 text-right font-mono">
                                                    {formatCurrency(getLastPrice(position))}
                                                </td>
                                                <td className="px-4 py-3 text-right font-mono">
                                                    {formatCurrency(getMarketValue(position))}
                                                </td>
                                                <td className={`px-4 py-3 text-right font-mono ${getPLColor(getUnrealizedPL(position))}`}>
                                                    {formatCurrency(getUnrealizedPL(position))}
                                                </td>
                                                <td className={`px-4 py-3 text-right font-mono ${getPLColor(getUnrealizedPLPct(position))}`}>
                                                    {formatPercent(getUnrealizedPLPct(position))}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div className="text-sm text-muted-foreground">
                        Showing {positions.length} position{positions.length !== 1 ? 's' : ''}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
