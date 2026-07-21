import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import axios from 'axios';

interface DailyCapital {
    trading_day: string;
    peak_capital_needed: number;
    max_concurrent_positions: number;
    trades_opened: number;
}

interface Statistics {
    max_peak_capital: number;
    avg_peak_capital: number;
    max_concurrent_positions: number;
    total_trading_days: number;
}

interface Trade {
    id: number;
    symbol: string;
    entry_time: string;
    exit_time: string | null;
    entry_price: number;
    exit_price: number | null;
    position_size: number;
    ml_win_prob: number;
    signal_type: string;
    entry_type: string;
    pnl_percent: number | null;
    pnl_dollar: number | null;
    exit_reason: string | null;
    version: string;
    runningCapital?: number;
}

interface TimelineEvent {
    time: string;
    event_type: 'buy' | 'sell';
    symbol: string;
    qty: number;
    price: number | null;
    capital: number;
    total_invested: number;
    running_positions: number;
    ml_win_prob: number | null;
    signal_type: string | null;
    entry_type: string | null;
    version: string | null;
    exit_reason: string | null;
    pnl_dollar?: number | null;
    pnl_percent?: number | null;
}

export default function AlpacaCapitalInvested({
    dailyCapital,
    statistics,
    filters,
}: PageProps<{
    dailyCapital: DailyCapital[];
    statistics: Statistics;
    filters: {
        start_date: string;
        end_date: string;
    };
}>) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Alpaca', href: '/alpaca-orders' },
        { title: 'Capital Invested', href: '/alpaca-capital-invested' },
    ];

    const [startDate, setStartDate] = useState(filters.start_date);
    const [endDate, setEndDate] = useState(filters.end_date);
    const [selectedDay, setSelectedDay] = useState<string | null>(null);
    const [dayEvents, setDayEvents] = useState<TimelineEvent[]>([]);
    const [loadingTrades, setLoadingTrades] = useState(false);

    const handleFilter = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            '/alpaca-capital-invested',
            {
                start_date: startDate || undefined,
                end_date: endDate || undefined,
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
        router.get('/alpaca-capital-invested', {});
    };

    const formatCurrency = (value: number | null) => {
        if (value === null || value === undefined) return '$0.00';
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(value);
    };

    const formatDate = (dateString: string) => {
        return new Date(dateString + 'T00:00:00').toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
    };

    const handleRowClick = async (tradingDay: string) => {
        setSelectedDay(tradingDay);
        setLoadingTrades(true);
        try {
            const response = await axios.get(`/alpaca-capital-invested/trades/${tradingDay}`);
            
            const result = response.data;
            setDayEvents(result.events || []);
            // total PL from events not tracked per row
            // can be displayed from result.total_pl if needed
        } catch (error) {
            console.error('Error loading events:', error);
        } finally {
            setLoadingTrades(false);
        }
    };

    const closeModal = () => {
        setSelectedDay(null);
        setDayEvents([]);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Capital Invested Analysis" />

            <div className="mx-auto max-w-[100rem] px-4 py-6 sm:px-6 lg:px-8">
                <div className="space-y-6">
                    {/* Header */}
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900 dark:text-white">
                                Capital Invested Analysis
                            </h1>
                            <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                Peak capital requirements for concurrent positions
                            </p>
                        </div>
                    </div>

                    {/* Statistics Cards */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div className="bg-card rounded-lg border p-4">
                            <div className="text-sm text-muted-foreground">Max Peak Capital</div>
                            <div className="text-2xl font-bold mt-1">
                                {formatCurrency(statistics.max_peak_capital)}
                            </div>
                        </div>
                        <div className="bg-card rounded-lg border p-4">
                            <div className="text-sm text-muted-foreground">Avg Peak Capital</div>
                            <div className="text-2xl font-bold mt-1">
                                {formatCurrency(statistics.avg_peak_capital)}
                            </div>
                        </div>
                        <div className="bg-card rounded-lg border p-4">
                            <div className="text-sm text-muted-foreground">
                                Max Concurrent Positions
                            </div>
                            <div className="text-2xl font-bold mt-1">
                                {statistics.max_concurrent_positions || 0}
                            </div>
                        </div>
                        <div className="bg-card rounded-lg border p-4">
                            <div className="text-sm text-muted-foreground">Trading Days</div>
                            <div className="text-2xl font-bold mt-1">
                                {statistics.total_trading_days || 0}
                            </div>
                        </div>
                    </div>

                    {/* Filters */}
                    <div className="bg-card rounded-lg border p-4">
                        <form onSubmit={handleFilter} className="flex flex-wrap gap-4 items-end">
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

                    {/* Data Table */}
                    <div className="bg-card rounded-lg border overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead className="bg-muted/50 border-b">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-sm font-medium">
                                            Trading Day
                                        </th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">
                                            Peak Capital
                                        </th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">
                                            Max Positions
                                        </th>
                                        <th className="px-4 py-3 text-right text-sm font-medium">
                                            Trades Opened
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {dailyCapital.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={4}
                                                className="px-4 py-8 text-center text-muted-foreground"
                                            >
                                                No data available for the selected filters
                                            </td>
                                        </tr>
                                    ) : (
                                        dailyCapital.map((day) => (
                                            <tr
                                                key={day.trading_day}
                                                onClick={() => handleRowClick(day.trading_day)}
                                                className="hover:bg-muted/50 transition-colors cursor-pointer"
                                            >
                                                <td className="px-4 py-3 text-sm">
                                                    {formatDate(day.trading_day)}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-right font-medium">
                                                    {formatCurrency(day.peak_capital_needed)}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-right">
                                                    {day.max_concurrent_positions || 0}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-right">
                                                    {day.trades_opened || 0}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>

                    {/* Modal for Day Trades */}
                    {selectedDay && (
                        <div
                            className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-6"
                            onClick={closeModal}
                        >
                            <div
                                className="bg-card rounded-lg border w-full max-w-[95vw] max-h-[90vh] overflow-hidden flex flex-col"
                                onClick={(e) => e.stopPropagation()}
                            >
                                {/* Modal Header */}
                                <div className="px-6 py-4 border-b flex items-center justify-between">
                                    <div>
                                        <h2 className="text-xl font-bold">
                                            Trades for {formatDate(selectedDay)}
                                        </h2>
                                        <div className="flex items-center gap-4 mt-1">
                                            <p className="text-sm text-muted-foreground">
                                                {dayEvents.length} events on {formatDate(selectedDay)}
                                            </p>
                                            {dayEvents.length > 0 && (() => {
                                                const totalProfit = dayEvents.reduce(
                                                    (sum, trade) => sum + (trade.pnl_dollar || 0),
                                                    0
                                                );
                                                const profitColor = totalProfit > 0
                                                    ? 'text-green-600 dark:text-green-400'
                                                    : totalProfit < 0
                                                    ? 'text-red-600 dark:text-red-400'
                                                    : 'text-gray-600 dark:text-gray-400';
                                                return (
                                                    <p className={`text-sm font-semibold ${profitColor}`}>
                                                        Total P&L: {formatCurrency(totalProfit)}
                                                    </p>
                                                );
                                            })()}
                                        </div>
                                    </div>
                                    <button
                                        onClick={closeModal}
                                        className="text-muted-foreground hover:text-foreground"
                                    >
                                        <svg
                                            className="w-6 h-6"
                                            fill="none"
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth="2"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                        >
                                            <path d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                                </div>

                                {/* Modal Body */}
                                <div className="overflow-auto flex-1">
                                    {loadingTrades ? (
                                        <div className="flex items-center justify-center py-12">
                                            <div className="text-muted-foreground">Loading trades...</div>
                                        </div>
                                    ) : (
                                        <table className="w-full">
                                            <thead className="bg-muted/50 border-b sticky top-0">
                                                <tr>
                                                    <th className="px-4 py-3 text-left text-xs font-medium">Time</th>
                                                    <th className="px-4 py-3 text-left text-xs font-medium">Action</th>
                                                    <th className="px-4 py-3 text-left text-xs font-medium">Symbol</th>
                                                    <th className="px-4 py-3 text-right text-xs font-medium">Qty</th>
                                                    <th className="px-4 py-3 text-right text-xs font-medium">Price</th>
                                                    <th className="px-4 py-3 text-right text-xs font-medium">Capital</th>
                                                    <th className="px-4 py-3 text-right text-xs font-medium">Total Invested</th>
                                                    <th className="px-4 py-3 text-right text-xs font-medium">Positions</th>
                                                    <th className="px-4 py-3 text-right text-xs font-medium">ML%</th>
                                                    <th className="px-4 py-3 text-left text-xs font-medium">Signal</th>
                                                    <th className="px-4 py-3 text-left text-xs font-medium">Exit Reason</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {dayEvents.map((event, idx) => (
                                                    <tr key={idx} className="hover:bg-muted/50">
                                                        <td className="px-4 py-2 text-sm whitespace-nowrap">
                                                            {event.time}
                                                        </td>
                                                        <td className={`px-4 py-2 text-sm font-medium ${
                                                            event.event_type === 'buy'
                                                                ? 'text-green-600 dark:text-green-400'
                                                                : 'text-red-600 dark:text-red-400'
                                                        }`}>
                                                            {event.event_type === 'buy' ? 'BUY' : 'SELL'}
                                                        </td>
                                                        <td className="px-4 py-2 text-sm font-medium">
                                                            {event.symbol}
                                                        </td>
                                                        <td className="px-4 py-2 text-sm text-right">
                                                            {event.qty.toFixed(0)}
                                                        </td>
                                                        <td className="px-4 py-2 text-sm text-right">
                                                            ${event.price?.toFixed(2) ?? '-'}
                                                        </td>
                                                        <td className={`px-4 py-2 text-sm text-right font-medium ${
                                                            event.event_type === 'buy'
                                                                ? 'text-red-600 dark:text-red-400'
                                                                : 'text-green-600 dark:text-green-400'
                                                        }`}>
                                                            {event.event_type === 'buy' ? '-' : '+'}{formatCurrency(event.capital)}
                                                        </td>
                                                        <td className="px-4 py-2 text-sm text-right font-bold">
                                                            {formatCurrency(event.total_invested)}
                                                        </td>
                                                        <td className="px-4 py-2 text-sm text-right">
                                                            {event.running_positions}
                                                        </td>
                                                        <td className="px-4 py-2 text-sm text-right">
                                                            {event.ml_win_prob ? `${(event.ml_win_prob * 100).toFixed(1)}%` : '-'}
                                                        </td>
                                                        <td className="px-4 py-2 text-xs">
                                                            {event.signal_type && (
                                                                <>
                                                                    <div>{event.signal_type}</div>
                                                                    <div className="text-muted-foreground">{event.entry_type}</div>
                                                                </>
                                                            )}
                                                            {event.version && !event.signal_type && (
                                                                <div className="text-muted-foreground">{event.version}</div>
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-2 text-xs whitespace-normal break-words">
                                                            {event.event_type === 'sell' ? (event.exit_reason || '-') : '-'}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}
                            </table>
                        </div>
                    </div>

                    {/* Help Text */}
                    <div className="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-900 rounded-lg p-4">
                        <h3 className="text-sm font-medium text-blue-900 dark:text-blue-100 mb-2">
                            Understanding Capital Requirements
                        </h3>
                        <ul className="text-sm text-blue-800 dark:text-blue-200 space-y-1 list-disc list-inside">
                            <li>
                                <strong>Peak Capital:</strong> Maximum capital needed at any moment during
                                the day when multiple positions are open simultaneously
                            </li>
                            <li>
                                <strong>Max Positions:</strong> Highest number of concurrent open positions
                                during the day
                            </li>
                            <li>
                                <strong>ML Threshold:</strong> Only includes trades with ML win probability
                                at or above this threshold (default: 60%)
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
