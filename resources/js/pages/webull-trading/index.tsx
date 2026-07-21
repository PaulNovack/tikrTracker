import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Position {
    symbol: string;
    qty?: string;
    instrument_id?: string;
    last_price?: string;
    market_value?: string;
    unrealized_profit_loss?: string;
    [key: string]: any;
}

interface Props extends PageProps {
    positions: Position[];
    todayOrders: any[];
    openOrders: any[];
}

export default function WebullTrading({ positions, todayOrders, openOrders }: Props) {
    const { flash } = usePage().props as any;
    const [activeTab, setActiveTab] = useState<'buy' | 'sell' | 'stop'>('sell');
    const [isSubmitting, setIsSubmitting] = useState(false);

    // Form states
    const [buyForm, setBuyForm] = useState({ symbol: '', qty: '', stop_loss_price: '' });
    const [sellForm, setSellForm] = useState({ symbol: '', qty: '' });
    const [stopForm, setStopForm] = useState({ symbol: '', qty: '', stop_price: '' });

    const handleBuy = (e: React.FormEvent) => {
        e.preventDefault();
        setIsSubmitting(true);
        router.post('/webull-trading/buy-market', 
            { 
                symbol: buyForm.symbol, 
                qty: parseInt(buyForm.qty),
                stop_loss_price: buyForm.stop_loss_price ? parseFloat(buyForm.stop_loss_price) : null
            },
            { onFinish: () => setIsSubmitting(false) }
        );
    };

    const handleSell = (e: React.FormEvent) => {
        e.preventDefault();
        setIsSubmitting(true);
        router.post('/webull-trading/sell-all', 
            { symbol: sellForm.symbol, qty: parseInt(sellForm.qty) },
            { onFinish: () => setIsSubmitting(false) }
        );
    };

    const handleStopLoss = (e: React.FormEvent) => {
        e.preventDefault();
        setIsSubmitting(true);
        router.post('/webull-trading/set-stop-loss', 
            { 
                symbol: stopForm.symbol, 
                qty: parseInt(stopForm.qty),
                stop_price: parseFloat(stopForm.stop_price)
            },
            { onFinish: () => setIsSubmitting(false) }
        );
    };

    const fillForm = (pos: Position) => {
        const data = { symbol: pos.symbol, qty: pos.qty || '1' };
        if (activeTab === 'buy') setBuyForm({ ...buyForm, ...data });
        else if (activeTab === 'sell') setSellForm(data);
        else setStopForm({ ...stopForm, ...data });
    };

    return (
        <AppLayout>
            <Head title="Webull Trading" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="bg-white p-6 shadow sm:rounded-lg dark:bg-gray-800">
                        <h1 className="text-2xl font-bold text-gray-900 dark:text-white mb-6">
                            Webull Trading
                        </h1>

                        {/* Flash Messages */}
                        {flash?.success && (
                            <div className="mb-6 rounded-lg bg-green-50 border-l-4 border-green-500 p-4 dark:bg-green-900/20">
                                <p className="text-sm font-semibold text-green-800 dark:text-green-300">
                                    ✓ {flash.success}
                                </p>
                            </div>
                        )}
                        
                        {flash?.error && (
                            <div className="mb-6 rounded-lg bg-red-50 border-l-4 border-red-500 p-4 dark:bg-red-900/20">
                                <p className="text-sm font-semibold text-red-800 dark:text-red-300">
                                    ✗ {flash.error}
                                </p>
                            </div>
                        )}

                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            {/* Trading Forms */}
                            <div>
                                {/* Tabs */}
                                <div className="flex gap-2 border-b border-gray-200 dark:border-gray-700 mb-6">
                                    <button
                                        onClick={() => setActiveTab('buy')}
                                        className={`px-4 py-2 font-medium transition-colors ${
                                            activeTab === 'buy'
                                                ? 'border-b-2 border-green-600 text-green-600 dark:text-green-400'
                                                : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'
                                        }`}
                                    >
                                        Buy
                                    </button>
                                    <button
                                        onClick={() => setActiveTab('sell')}
                                        className={`px-4 py-2 font-medium transition-colors ${
                                            activeTab === 'sell'
                                                ? 'border-b-2 border-red-600 text-red-600 dark:text-red-400'
                                                : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'
                                        }`}
                                    >
                                        Sell
                                    </button>
                                    <button
                                        onClick={() => setActiveTab('stop')}
                                        className={`px-4 py-2 font-medium transition-colors ${
                                            activeTab === 'stop'
                                                ? 'border-b-2 border-orange-600 text-orange-600 dark:text-orange-400'
                                                : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'
                                        }`}
                                    >
                                        Stop Loss
                                    </button>
                                </div>

                                {/* Buy Form */}
                                {activeTab === 'buy' && (
                                    <form onSubmit={handleBuy} className="space-y-4">
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                Symbol *
                                            </label>
                                            <input
                                                type="text"
                                                value={buyForm.symbol}
                                                onChange={(e) => setBuyForm({ ...buyForm, symbol: e.target.value.toUpperCase() })}
                                                className="w-full rounded-md border border-gray-300 px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                                placeholder="AAPL"
                                                required
                                            />
                                        </div>
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                Quantity *
                                            </label>
                                            <input
                                                type="number"
                                                value={buyForm.qty}
                                                onChange={(e) => setBuyForm({ ...buyForm, qty: e.target.value })}
                                                className="w-full rounded-md border border-gray-300 px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                                placeholder="1"
                                                min="1"
                                                required
                                            />
                                        </div>
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                Stop Loss Price (optional)
                                            </label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                value={buyForm.stop_loss_price}
                                                onChange={(e) => setBuyForm({ ...buyForm, stop_loss_price: e.target.value })}
                                                className="w-full rounded-md border border-gray-300 px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                                placeholder="100.00"
                                            />
                                        </div>
                                        <button
                                            type="submit"
                                            disabled={isSubmitting}
                                            className="w-full rounded-md bg-green-600 px-4 py-2 font-semibold text-white hover:bg-green-700 disabled:opacity-50"
                                        >
                                            {isSubmitting ? 'Placing Order...' : 'Buy Market Order'}
                                        </button>
                                    </form>
                                )}

                                {/* Sell Form */}
                                {activeTab === 'sell' && (
                                    <form onSubmit={handleSell} className="space-y-4">
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                Symbol *
                                            </label>
                                            <input
                                                type="text"
                                                value={sellForm.symbol}
                                                onChange={(e) => setSellForm({ ...sellForm, symbol: e.target.value.toUpperCase() })}
                                                className="w-full rounded-md border border-gray-300 px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                                placeholder="QQQ"
                                                required
                                            />
                                        </div>
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                Quantity *
                                            </label>
                                            <input
                                                type="number"
                                                value={sellForm.qty}
                                                onChange={(e) => setSellForm({ ...sellForm, qty: e.target.value })}
                                                className="w-full rounded-md border border-gray-300 px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                                placeholder="1"
                                                min="1"
                                                required
                                            />
                                        </div>
                                        <button
                                            type="submit"
                                            disabled={isSubmitting}
                                            className="w-full rounded-md bg-red-600 px-4 py-2 font-semibold text-white hover:bg-red-700 disabled:opacity-50"
                                        >
                                            {isSubmitting ? 'Selling...' : 'Sell All Shares'}
                                        </button>
                                    </form>
                                )}

                                {/* Stop Loss Form */}
                                {activeTab === 'stop' && (
                                    <form onSubmit={handleStopLoss} className="space-y-4">
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                Symbol *
                                            </label>
                                            <input
                                                type="text"
                                                value={stopForm.symbol}
                                                onChange={(e) => setStopForm({ ...stopForm, symbol: e.target.value.toUpperCase() })}
                                                className="w-full rounded-md border border-gray-300 px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                                placeholder="TSLA"
                                                required
                                            />
                                        </div>
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                Quantity *
                                            </label>
                                            <input
                                                type="number"
                                                value={stopForm.qty}
                                                onChange={(e) => setStopForm({ ...stopForm, qty: e.target.value })}
                                                className="w-full rounded-md border border-gray-300 px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                                placeholder="1"
                                                min="1"
                                                required
                                            />
                                        </div>
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                Stop Price *
                                            </label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                value={stopForm.stop_price}
                                                onChange={(e) => setStopForm({ ...stopForm, stop_price: e.target.value })}
                                                className="w-full rounded-md border border-gray-300 px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                                placeholder="250.00"
                                                required
                                            />
                                        </div>
                                        <button
                                            type="submit"
                                            disabled={isSubmitting}
                                            className="w-full rounded-md bg-orange-600 px-4 py-2 font-semibold text-white hover:bg-orange-700 disabled:opacity-50"
                                        >
                                            {isSubmitting ? 'Setting Stop Loss...' : 'Set Stop Loss Order'}
                                        </button>
                                    </form>
                                )}
                            </div>

                            {/* Positions */}
                            <div>
                                <h2 className="text-lg font-semibold mb-4 text-gray-900 dark:text-white">
                                    Positions ({positions.length})
                                </h2>
                                <div className="max-h-96 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700">
                                    <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead className="bg-gray-50 dark:bg-gray-700 sticky top-0">
                                            <tr>
                                                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Symbol</th>
                                                <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Qty</th>
                                                <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Price</th>
                                                <th className="px-3 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                            {positions.map((pos, idx) => (
                                                <tr key={idx} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                                                    <td className="px-3 py-2 text-sm font-semibold text-gray-900 dark:text-white">
                                                        {pos.symbol}
                                                    </td>
                                                    <td className="px-3 py-2 text-sm text-right text-gray-600 dark:text-gray-300">
                                                        {pos.qty}
                                                    </td>
                                                    <td className="px-3 py-2 text-sm text-right text-gray-600 dark:text-gray-300">
                                                        ${pos.last_price}
                                                    </td>
                                                    <td className="px-3 py-2 text-sm text-center">
                                                        <button
                                                            onClick={() => fillForm(pos)}
                                                            className="text-blue-600 hover:text-blue-800 dark:text-blue-400 font-medium"
                                                        >
                                                            Fill
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
