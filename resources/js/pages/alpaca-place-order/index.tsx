import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { FormEvent, useEffect, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';

interface TodayAlert {
    id: number;
    symbol: string;
    entry_type: string;
    entry_ts_est: string;
    entry: number | null;
    stop: number | null;
    pipeline_run: string;
    ml_win_prob: number | null;
    passed_ml: number | null;
}

interface Props {
    todayAlerts: TodayAlert[];
}

export default function AlpacaPlaceOrderIndex({ todayAlerts }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Alpaca', href: '/alpaca-orders' },
        { title: 'Place Order', href: '/alpaca-place-order' },
    ];

    // Symbol typeahead state
    const [searchTerm, setSearchTerm] = useState('');
    const [suggestions, setSuggestions] = useState<AssetSearchResult[]>([]);
    const [showSuggestions, setShowSuggestions] = useState(false);
    const [isLoadingSuggestions, setIsLoadingSuggestions] = useState(false);
    const searchRef = useRef<HTMLDivElement>(null);

    // Form state
    const [shares, setShares] = useState('0');
    const [entryPrice, setEntryPrice] = useState('');
    const [stopPrice, setStopPrice] = useState('');
    const [notes, setNotes] = useState('');
    const [isPlacing, setIsPlacing] = useState(false);
    const [status, setStatus] = useState<{ type: 'success' | 'error'; message: string } | null>(null);
    const [boughtToday, setBoughtToday] = useState(false);

    // Derived total order value
    const sharesNum = parseInt(shares, 10);
    const priceNum = parseFloat(entryPrice);
    const totalOrder = sharesNum > 0 && priceNum > 0 ? sharesNum * priceNum : null;

    const formatTimeEST = (ts: string) => {
        const hh = parseInt(ts.slice(11, 13), 10);
        const mm = ts.slice(14, 16);
        const ampm = hh >= 12 ? 'PM' : 'AM';
        const h12 = hh === 0 ? 12 : hh > 12 ? hh - 12 : hh;
        return `${h12}:${mm} ${ampm}`;
    };

    // Fetch suggestions with debounce
    useEffect(() => {
        if (searchTerm.length < 1) {
            setSuggestions([]);
            setShowSuggestions(false);
            return;
        }

        const timer = setTimeout(async () => {
            setIsLoadingSuggestions(true);
            try {
                const res = await fetch(
                    `/market-data/assets/search?search=${encodeURIComponent(searchTerm)}&asset_type=all`,
                    { credentials: 'include' },
                );
                const data: AssetSearchResult[] = await res.json();
                setSuggestions(data);
                setShowSuggestions(true);
            } catch {
                setSuggestions([]);
            } finally {
                setIsLoadingSuggestions(false);
            }
        }, 300);

        return () => clearTimeout(timer);
    }, [searchTerm]);

    // Click outside to close suggestions
    useEffect(() => {
        const handler = (e: MouseEvent) => {
            if (searchRef.current && !searchRef.current.contains(e.target as Node)) {
                setShowSuggestions(false);
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

const csrfToken = (): string =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

    const handleSymbolSelect = (sym: string) => {
        setSearchTerm(sym);
        setShowSuggestions(false);
        setBoughtToday(false);
        // Fetch current price for selected symbol
        fetch('/alpaca-place-order/lookup', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ symbol: sym }),
        })
            .then((r) => r.json())
            .then((data) => {
                if (data.price) {
                    setEntryPrice(String(data.price));
                }
                if (data.bought_today) {
                    setBoughtToday(true);
                }
            })
            .catch(() => {});
    };

    const handleClearSymbol = () => {
        setSearchTerm('');
        setSuggestions([]);
        setEntryPrice('');
        setBoughtToday(false);
    };

    const handleSubmit = async (e: FormEvent) => {
        e.preventDefault();
        setIsPlacing(true);
        setStatus(null);

        try {
            const res = await fetch('/alpaca-place-order', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                body: JSON.stringify({
                    symbol: searchTerm.toUpperCase(),
                    shares: parseInt(shares, 10),
                    entry_price: parseFloat(entryPrice),
                    stop_price: stopPrice ? parseFloat(stopPrice) : null,
                    pipeline_run: 'MANUAL',
                    notes,
                }),
            });

            const data = await res.json();
            if (res.ok && data.success) {
                setStatus({ type: 'success', message: data.message });
                handleClearSymbol();
                setShares('0');
                setEntryPrice('');
                setStopPrice('');
                setNotes('');
            } else {
                setStatus({ type: 'error', message: data.error ?? 'Unknown error' });
            }
        } catch (err: any) {
            setStatus({ type: 'error', message: err.message ?? 'Network error' });
        } finally {
            setIsPlacing(false);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Place Alpaca Order" />

            <div className="mx-auto space-y-6 p-6">
                <h1 className="text-2xl font-bold tracking-tight">Place Alpaca Order</h1>

                {status && (
                    <div
                        className={`rounded-lg border px-4 py-3 text-sm ${
                            status.type === 'success'
                                ? 'border-green-200 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200'
                                : 'border-red-200 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200'
                        }`}
                    >
                        {status.message}
                    </div>
                )}

                {boughtToday && (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                        {searchTerm} was already bought today. Placing another order may trigger a wash trade or duplicate the position.
                    </div>
                )}

                <form onSubmit={handleSubmit} className="space-y-4 rounded-lg border p-6 dark:border-gray-700">
                    <div className="grid grid-cols-2 gap-4">
                        {/* Symbol with typeahead */}
                        <div className="relative" ref={searchRef}>
                            <label className="mb-1 block text-sm font-medium">Symbol</label>
                            <input
                                type="text"
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value.toUpperCase())}
                                className="w-full rounded-md border px-3 py-2 pr-8 text-sm dark:border-gray-600 dark:bg-gray-800"
                                placeholder="Search by symbol or name..."
                                required
                                autoComplete="off"
                            />
                            {searchTerm && (
                                <button
                                    type="button"
                                    onClick={handleClearSymbol}
                                    className="absolute right-2 top-1/2 -translate-y-1/3 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                >
                                    &times;
                                </button>
                            )}

                            {showSuggestions && (
                                <div className="absolute z-10 mt-1 w-full rounded-md border bg-white shadow-lg dark:border-gray-600 dark:bg-gray-800">
                                    {isLoadingSuggestions ? (
                                        <div className="px-4 py-3 text-sm text-gray-500">Loading...</div>
                                    ) : suggestions.length > 0 ? (
                                        <div className="max-h-60 overflow-y-auto">
                                            {suggestions.map((s) => (
                                                <button
                                                    key={s.id}
                                                    type="button"
                                                    onClick={() => handleSymbolSelect(s.symbol)}
                                                    className="flex w-full items-center gap-3 border-b border-gray-100 px-4 py-2 text-left last:border-0 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700"
                                                >
                                                    <span className="font-semibold text-gray-900 dark:text-gray-100">{s.symbol}</span>
                                                    <span className="flex-1 truncate text-sm text-gray-500 dark:text-gray-400">{s.common_name}</span>
                                                    <Badge variant="outline" className="text-xs">{s.asset_type}</Badge>
                                                </button>
                                            ))}
                                        </div>
                                    ) : (
                                        <div className="px-4 py-3 text-sm text-gray-500">No results found</div>
                                    )}
                                </div>
                            )}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium">Shares</label>
                            <input
                                type="number"
                                value={shares}
                                onChange={(e) => setShares(e.target.value)}
                                className="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800"
                                placeholder="0 = auto-calculate from volume"
                                min={0}
                            />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium">
                                Entry Price ($)
                                {entryPrice && <span className="ml-1 text-xs text-gray-400">(current market price)</span>}
                            </label>
                            <input
                                type="number"
                                step="0.01"
                                value={entryPrice}
                                readOnly
                                className="w-full cursor-default rounded-md border bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-400"
                                placeholder="Select a symbol first"
                            />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium">Total Order ($)</label>
                            <div className="flex h-10 items-center rounded-md border bg-gray-50 px-3 text-sm font-semibold dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                {totalOrder !== null ? `$${totalOrder.toFixed(2)}` : '—'}
                            </div>
                        </div>
                    </div>

                    <div className="flex gap-3 pt-2">
                        <button
                            type="submit"
                            disabled={isPlacing}
                            className="rounded-md bg-blue-600 px-6 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {isPlacing ? 'Placing Order...' : 'Place Buy Order'}
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                handleClearSymbol();
                                setShares('0');
                                setStatus(null);
                            }}
                            className="rounded-md border px-4 py-2 text-sm font-medium hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-800"
                        >
                            Clear
                        </button>
                    </div>
                </form>

                {todayAlerts.length > 0 && (
                    <div>
                        <h2 className="mb-3 text-lg font-semibold">Today's Alerts (reference)</h2>
                        <div className="overflow-x-auto rounded-lg border dark:border-gray-700">
                            <table className="w-full text-left text-sm">
                                <thead className="border-b bg-gray-50 dark:border-gray-700 dark:bg-gray-800">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">Symbol</th>
                                        <th className="px-3 py-2 font-medium">Type</th>
                                        <th className="px-3 py-2 font-medium">Entry Time</th>
                                        <th className="px-3 py-2 font-medium">Entry</th>
                                        <th className="px-3 py-2 font-medium">Stop</th>
                                        <th className="px-3 py-2 font-medium">Pipeline</th>
                                        <th className="px-3 py-2 font-medium">ML</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y dark:divide-gray-700">
                                    {todayAlerts.map((alert) => (
                                        <tr key={alert.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                            <td className="px-3 py-2 font-medium">{alert.symbol}</td>
                                            <td className="px-3 py-2">{alert.entry_type}</td>
                                            <td className="px-3 py-2 font-mono text-sm">{formatTimeEST(alert.entry_ts_est)}</td>
                                            <td className="px-3 py-2">{alert.entry !== null ? `$${alert.entry}` : '-'}</td>
                                            <td className="px-3 py-2">{alert.stop !== null ? `$${alert.stop}` : '-'}</td>
                                            <td className="px-3 py-2">{alert.pipeline_run}</td>
                                            <td className="px-3 py-2">
                                                {alert.ml_win_prob !== null
                                                    ? `${(alert.ml_win_prob * 100).toFixed(0)}%`
                                                    : alert.passed_ml !== null && alert.passed_ml === 0
                                                      ? '❌'
                                                      : '-'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
