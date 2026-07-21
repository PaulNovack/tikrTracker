import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { TrendingUp, Gauge, Info, Target } from 'lucide-react';
import { useEffect, useState } from 'react';

interface GoodBuyStock {
    symbol: string;
    asset_info_id: number | null;
    action: string;
    buy_grade: string;
    confirmed_pattern: string;
    tuning_profile: string;
    signal_ts_est: string;
    signal_age_seconds: number;
    last_price: number;
    suggested_limit_buy_price: number;
    suggested_stop_price: number;
    initial_risk_pct: number;
    target_1_5r: number;
    target_2r: number;
    vwap: number;
    vwap_dist_pct: number;
    ema9: number;
    ema21: number;
    ema9_ema21_spread: number;
    ret_1m_pct: number;
    ret_3m_pct: number;
    ret_5m_pct: number;
    volume: number;
    avg_vol_20m: number;
    vol_ratio: number;
    dollar_vol_1m: number;
    dollar_vol_5m: number;
    atr: number;
    atr_pct: number;
    prev_high: number;
    prior_high_5m: number;
    prior_high_15m: number;
    low_15m: number;
    move_from_15m_low_pct: number;
    buy_score: number;
    reason: string;
}

interface Props {
    stocks: GoodBuyStock[];
    totalSymbols: number;
}

export default function GoodLongBuyIndex({ stocks, totalSymbols }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Analysis', href: '/analysis/vwap-status' },
        { title: 'Good Long Buy', href: '/analysis/good-long-buy' },
    ];

    // Auto-refresh every 1 minute
    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({ preserveState: true, preserveScroll: true });
        }, 60000);
        return () => clearInterval(interval);
    }, []);

    const [tab, setTab] = useState<'table' | 'risk'>('table');

    const aBuys = stocks.filter((s) => s.buy_grade === 'A_BUY_NOW_CONFIRMED');
    const bBuys = stocks.filter((s) => s.buy_grade === 'B_BUY_NOW_CONFIRMED');
    const cBuys = stocks.filter((s) => s.buy_grade === 'C_BUY_NOW_CONFIRMED');

    const gradeBadge = (grade: string) => {
        switch (grade) {
            case 'A_BUY_NOW_CONFIRMED':
                return <Badge className="bg-green-600">{grade.replace(/_/g, ' ')}</Badge>;
            case 'B_BUY_NOW_CONFIRMED':
                return <Badge variant="secondary">{grade.replace(/_/g, ' ')}</Badge>;
            case 'C_BUY_NOW_CONFIRMED':
                return <Badge variant="outline">{grade.replace(/_/g, ' ')}</Badge>;
            default:
                return <Badge variant="outline">{grade}</Badge>;
        }
    };

    const patternColor = (pattern: string) => {
        switch (pattern) {
            case 'FRESH_15M_BREAKOUT_CONFIRMED': return 'text-green-600';
            case 'VWAP_RECLAIM_CONFIRMED': return 'text-blue-600';
            case 'VWAP_PULLBACK_HOLD_CONFIRMED': return 'text-orange-600';
            case 'EMA9_BOUNCE_CONFIRMED': return 'text-purple-600';
            case 'MICRO_PULLBACK_BREAKOUT_CONFIRMED': return 'text-cyan-600';
            default: return 'text-gray-600';
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Good Long Buy" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                            Good Long Buy
                        </h1>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Confirmed intraday long setups — 1m bar confirmation with VWAP/EMA trend, volume expansion, and risk targets
                        </p>
                    </div>
                </div>

                {/* Summary Cards */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-5">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Candidates</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{totalSymbols}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">A Buy</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-green-600">{aBuys.length}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">B Buy</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-blue-600">{bBuys.length}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">C Buy</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-yellow-600">{cBuys.length}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Max Age (s)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {stocks.length > 0 ? Math.max(...stocks.map((s) => s.signal_age_seconds)) : 0}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Tab Switcher */}
                <div className="flex items-center gap-2 border-b pb-2">
                    <button onClick={() => setTab('table')} className={`px-3 py-1.5 text-sm font-medium ${tab === 'table' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-muted-foreground'}`}>Main View</button>
                    <button onClick={() => setTab('risk')} className={`px-3 py-1.5 text-sm font-medium ${tab === 'risk' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-muted-foreground'}`}>Risk & Entry</button>
                </div>

                {/* Main View Table */}
                {tab === 'table' && (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2"><TrendingUp className="h-5 w-5" /><CardTitle>Confirmed Long Setups</CardTitle></div>
                            <CardDescription>Sorted by grade → buy score → dollar volume. Auto-refreshes every 5 min.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-border">
                                            <th className="pb-2 pr-3 text-left font-medium text-muted-foreground">#</th>
                                            <th className="pb-2 pr-3 text-left font-medium text-muted-foreground">Symbol</th>
                                            <th className="pb-2 pr-3 text-left font-medium text-muted-foreground">Pattern</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Score</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Grade</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Price</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">VWAP Dist</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">1m Ret</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">3m Ret</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">5m Ret</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Vol Ratio</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">$Vol 5m</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">ATR %</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Age (s)</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {stocks.length === 0 && (
                                            <tr><td colSpan={14} className="py-12 text-center text-muted-foreground">No candidates found. Market may be closed.</td></tr>
                                        )}
                                        {stocks.map((stock, i) => (
                                            <tr key={`${stock.symbol}-${i}`} className="hover:bg-muted/50">
                                                <td className="py-1.5 pr-3 text-muted-foreground">{i + 1}</td>
                                                <td className="py-1.5 pr-3 font-medium">
                                                    {stock.asset_info_id ? (
                                                        <a href={`/market-data/assets/${stock.asset_info_id}`} target="_blank" rel="noopener noreferrer" className="text-blue-600 hover:underline">{stock.symbol}</a>
                                                    ) : stock.symbol}
                                                </td>
                                                <td className="py-1.5 pr-3"><span className={`text-xs font-medium ${patternColor(stock.confirmed_pattern)}`}>{(stock.confirmed_pattern ?? '').replace(/_/g, ' ') || '—'}</span></td>
                                                <td className="py-1.5 pr-3 text-right font-mono font-bold text-lg">{(stock.buy_score ?? 0).toFixed(0)}</td>
                                                <td className="py-1.5 pr-3 text-right">{gradeBadge(stock.buy_grade)}</td>
                                                <td className="py-1.5 pr-3 text-right font-mono">${stock.last_price.toFixed(2)}</td>
                                                <td className="py-1.5 pr-3 text-right font-mono"><span className={stock.vwap_dist_pct >= 0 ? 'text-green-600' : 'text-red-600'}>{stock.vwap_dist_pct >= 0 ? '+' : ''}{(stock.vwap_dist_pct ?? 0).toFixed(3)}%</span></td>
                                                <td className="py-1.5 pr-3 text-right font-mono"><span className={stock.ret_1m_pct >= 0 ? 'text-green-600' : 'text-red-600'}>{stock.ret_1m_pct >= 0 ? '+' : ''}{(stock.ret_1m_pct ?? 0).toFixed(2)}%</span></td>
                                                <td className="py-1.5 pr-3 text-right font-mono"><span className={stock.ret_3m_pct >= 0 ? 'text-green-600' : 'text-red-600'}>{stock.ret_3m_pct >= 0 ? '+' : ''}{(stock.ret_3m_pct ?? 0).toFixed(2)}%</span></td>
                                                <td className="py-1.5 pr-3 text-right font-mono"><span className={stock.ret_5m_pct >= 0 ? 'text-green-600' : 'text-red-600'}>{stock.ret_5m_pct >= 0 ? '+' : ''}{(stock.ret_5m_pct ?? 0).toFixed(2)}%</span></td>
                                                <td className="py-1.5 pr-3 text-right font-mono">{(stock.vol_ratio ?? 0).toFixed(2)}x</td>
                                                <td className="py-1.5 pr-3 text-right font-mono text-xs">${(stock.dollar_vol_5m / 1000000).toFixed(1)}M</td>
                                                <td className="py-1.5 pr-3 text-right font-mono">{(stock.atr_pct ?? 0).toFixed(2)}%</td>
                                                <td className="py-1.5 pr-3 text-right font-mono text-xs text-muted-foreground">{(stock.signal_age_seconds ?? 0)}s</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Risk & Entry Tab */}
                {tab === 'risk' && (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2"><Target className="h-5 w-5" /><CardTitle>Risk & Entry Details</CardTitle></div>
                            <CardDescription>Suggested limit price, stop loss, and 1.5R / 2R targets.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-border">
                                            <th className="pb-2 pr-3 text-left font-medium text-muted-foreground">Symbol</th>
                                            <th className="pb-2 pr-3 text-left font-medium text-muted-foreground">Pattern</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Price</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Limit Buy</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Stop Loss</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Risk %</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">1.5R</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">2R</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Prev High</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">15m Low</th>
                                            <th className="pb-2 pr-3 text-right font-medium text-muted-foreground">Move %</th>
                                            <th className="pb-2 pr-3 text-left font-medium text-muted-foreground">Reason</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {stocks.length === 0 && <tr><td colSpan={12} className="py-12 text-center text-muted-foreground">No candidates.</td></tr>}
                                        {stocks.map((stock, i) => (
                                            <tr key={`risk-${stock.symbol}-${i}`} className="hover:bg-muted/50">
                                                <td className="py-1.5 pr-3 font-medium">{stock.symbol}</td>
                                                <td className="py-1.5 pr-3"><span className={`text-xs font-medium ${patternColor(stock.confirmed_pattern)}`}>{(stock.confirmed_pattern ?? '').replace(/_/g, ' ') || '—'}</span></td>
                                                <td className="py-1.5 pr-3 text-right font-mono">${(stock.last_price ?? 0).toFixed(2)}</td>
                                                <td className="py-1.5 pr-3 text-right font-mono text-green-600">${(stock.suggested_limit_buy_price ?? 0).toFixed(2)}</td>
                                                <td className="py-1.5 pr-3 text-right font-mono text-red-600">${(stock.suggested_stop_price ?? 0).toFixed(2)}</td>
                                                <td className="py-1.5 pr-3 text-right font-mono">{(stock.initial_risk_pct ?? 0).toFixed(2)}%</td>
                                                <td className="py-1.5 pr-3 text-right font-mono text-blue-600">${(stock.target_1_5r ?? 0).toFixed(2)}</td>
                                                <td className="py-1.5 pr-3 text-right font-mono text-blue-600">${(stock.target_2r ?? 0).toFixed(2)}</td>
                                                <td className="py-1.5 pr-3 text-right font-mono text-xs">${(stock.prev_high ?? 0).toFixed(2)}</td>
                                                <td className="py-1.5 pr-3 text-right font-mono text-xs">${(stock.low_15m ?? 0).toFixed(2)}</td>
                                                <td className="py-1.5 pr-3 text-right font-mono text-xs">{(stock.move_from_15m_low_pct ?? 0).toFixed(2)}%</td>
                                                <td className="py-1.5 text-xs text-muted-foreground max-w-xs truncate" title={stock.reason}>{stock.reason}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
