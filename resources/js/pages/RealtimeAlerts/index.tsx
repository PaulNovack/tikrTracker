import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Activity, Pause, Play, RefreshCw, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface Candidate {
    id: number;
    symbol: string;
    asset_type: string | null;
    detected_ts_est: string | null;
    detected_price: number | null;
    bid: number | null;
    ask: number | null;
    bid_qty: number | null;
    ask_qty: number | null;
    spread_pct: number | null;
    partial_open: number | null;
    partial_high: number | null;
    partial_low: number | null;
    partial_close: number | null;
    partial_volume: number | null;
    vwap: number | null;
    vwap_dist_pct: number | null;
    return_1m_pct: number | null;
    return_3m_pct: number | null;
    volume_ratio: number | null;
    dollar_volume_1m: number | null;
    bid_ask_imbalance: number | null;
    early_score: number | null;
    status: string | null;
    stale_seconds: number | null;
    rejection_reason: string | null;
    last_gate_fail_reason: string | null;
    trade_alert_id: number | null;
}

interface Props {
    candidates: Candidate[];
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Admin',
        href: '#',
    },
    {
        title: 'Realtime Alerts',
        href: '/logs/realtime-alerts',
    },
];

const formatDate = (dateString: string | null) => {
    if (!dateString) return '—';
    try {
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return 'Invalid date';
        return (
            date.toLocaleString('en-US', {
                timeZone: 'America/New_York',
                year: 'numeric',
                month: 'short',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: true,
            }) + ' EST'
        );
    } catch {
        return 'Invalid date';
    }
};

const formatPrice = (value: number | null) => {
    if (value === null || value === undefined) return '—';
    return '$' + value.toFixed(2);
};

const formatPct = (value: number | null) => {
    if (value === null || value === undefined) return '—';
    const num = value;
    const color = num > 0 ? 'text-green-600 dark:text-green-400' : num < 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-600 dark:text-slate-400';
    return <span className={`font-semibold ${color}`}>{num > 0 ? '+' : ''}{num.toFixed(2)}%</span>;
};

const statusBadge = (status: string | null) => {
    if (!status) return <span className="text-slate-400">—</span>;
    const base = 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium';
    const colors: Record<string, string> = {
        candidate: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
        accepted: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
        rejected: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
        triggered: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
        expired: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
        traded: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    };
    return <span className={`${base} ${colors[status.toLowerCase()] || 'bg-slate-100 text-slate-700 dark:bg-slate-900/30 dark:text-slate-400'}`}>{status}</span>;
};

export default function RealtimeAlertsIndex({ candidates }: Props) {
    const { url } = usePage();
    const [autoRefresh, setAutoRefresh] = useState(true);
    const [symbolFilter, setSymbolFilter] = useState<string>(
        new URLSearchParams(url.split('?')[1]).get('symbol') || ''
    );
    const [statusFilter, setStatusFilter] = useState<string>(
        new URLSearchParams(url.split('?')[1]).get('status') || 'all'
    );
    const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Auto-refresh every 15 seconds
    useEffect(() => {
        if (autoRefresh) {
            intervalRef.current = setInterval(() => {
                router.reload({ preserveUrl: true });
            }, 15000);
        }
        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, [autoRefresh]);

    const handleSymbolFilterChange = (value: string) => {
        setSymbolFilter(value);
        
        // Clear previous debounce timer
        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }
        
        // Set new debounce timer - wait 1 second after user stops typing
        debounceRef.current = setTimeout(() => {
            const params: Record<string, string> = {};
            if (value.trim()) params.symbol = value;
            if (statusFilter !== 'all') params.status = statusFilter;
            
            if (Object.keys(params).length > 0) {
                router.get('/logs/realtime-alerts', params);
            } else {
                router.get('/logs/realtime-alerts');
            }
        }, 1000);
    };

    const handleStatusFilterChange = (value: string) => {
        setStatusFilter(value);
        
        const params: Record<string, string> = {};
        if (symbolFilter.trim()) params.symbol = symbolFilter;
        if (value !== 'all') params.status = value;
        
        if (Object.keys(params).length > 0) {
            router.get('/logs/realtime-alerts', params);
        } else {
            router.get('/logs/realtime-alerts');
        }
    };

    const clearSymbolFilter = () => {
        setSymbolFilter('');
        // Clear any pending debounce
        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }
        
        const params: Record<string, string> = {};
        if (statusFilter !== 'all') params.status = statusFilter;
        
        if (Object.keys(params).length > 0) {
            router.get('/logs/realtime-alerts', params);
        } else {
            router.get('/logs/realtime-alerts');
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Realtime Alerts" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <div className="flex items-start justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Realtime Alerts</h1>
                        <p className="text-muted-foreground">Top 500 recent trade candidates detected by the realtime watch process</p>
                        <div className="mt-4 flex items-center gap-2">
                            <Input
                                type="text"
                                placeholder="Filter by symbol (e.g., AAPL)"
                                value={symbolFilter}
                                onChange={(e) => handleSymbolFilterChange(e.target.value)}
                                className="w-48"
                            />
                            {symbolFilter && (
                                <Button
                                    onClick={clearSymbolFilter}
                                    variant="ghost"
                                    size="sm"
                                >
                                    <X className="h-4 w-4" />
                                </Button>
                            )}
                            <Select value={statusFilter} onValueChange={handleStatusFilterChange}>
                                <SelectTrigger className="w-40">
                                    <SelectValue placeholder="Filter by status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Statuses</SelectItem>
                                    <SelectItem value="watching">Watching</SelectItem>
                                    <SelectItem value="triggered">Triggered</SelectItem>
                                    <SelectItem value="expired">Expired</SelectItem>
                                    <SelectItem value="rejected">Rejected</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button
                            onClick={() => setAutoRefresh(!autoRefresh)}
                            variant={autoRefresh ? 'default' : 'outline'}
                            size="sm"
                        >
                            {autoRefresh ? (
                                <><Pause className="h-4 w-4 mr-2" /> Auto-refresh ON</>
                            ) : (
                                <><Play className="h-4 w-4 mr-2" /> Auto-refresh OFF</>
                            )}
                        </Button>
                        <Button
                            onClick={() => router.reload({ preserveUrl: true })}
                            variant="outline"
                            size="sm"
                        >
                            <RefreshCw className="h-4 w-4 mr-2" />
                            Refresh Now
                        </Button>
                    </div>
                </div>

                <div className="rounded-lg border bg-card">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-16">ID</TableHead>
                                    <TableHead>Symbol</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Stale (s)</TableHead>
                                    <TableHead>Detected (EST)</TableHead>
                                    <TableHead className="text-right">Price</TableHead>
                                    <TableHead className="text-right">Spread</TableHead>
                                    <TableHead className="text-right">VWAP Dist</TableHead>
                                    <TableHead className="text-right">Return 1m</TableHead>
                                    <TableHead className="text-right">Return 3m</TableHead>
                                    <TableHead className="text-right">Vol Ratio</TableHead>
                                    <TableHead className="text-right">Early Score</TableHead>
                                    <TableHead>Last Gate Fail</TableHead>
                                    <TableHead>Rejection</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {candidates.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={14} className="h-24 text-center text-muted-foreground">
                                            No realtime trade candidates yet. They will appear here as the realtime watch process runs.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    candidates.map((c) => (
                                        <TableRow key={c.id}>
                                            <TableCell className="text-muted-foreground">{c.id}</TableCell>
                                            <TableCell className="font-semibold">{c.symbol}</TableCell>
                                            <TableCell>{c.asset_type || '—'}</TableCell>
                                            <TableCell>{statusBadge(c.status)}</TableCell>
                                            <TableCell className="text-right">{c.stale_seconds !== null && c.stale_seconds > 0 ? c.stale_seconds + 's' : '—'}</TableCell>
                                            <TableCell>{formatDate(c.detected_ts_est)}</TableCell>
                                            <TableCell className="text-right font-mono">{formatPrice(c.detected_price)}</TableCell>
                                            <TableCell className="text-right font-mono">{c.spread_pct !== null ? c.spread_pct.toFixed(2) + '%' : '—'}</TableCell>
                                            <TableCell className="text-right font-mono">{formatPct(c.vwap_dist_pct)}</TableCell>
                                            <TableCell className="text-right font-mono">{formatPct(c.return_1m_pct)}</TableCell>
                                            <TableCell className="text-right font-mono">{formatPct(c.return_3m_pct)}</TableCell>
                                            <TableCell className="text-right font-mono">{c.volume_ratio !== null ? c.volume_ratio.toFixed(2) : '—'}</TableCell>
                                            <TableCell className="text-right font-mono">{c.early_score !== null ? c.early_score.toFixed(2) : '—'}</TableCell>
                                            <TableCell className="max-w-48 truncate text-orange-600 dark:text-orange-400">{c.last_gate_fail_reason || '—'}</TableCell>
                                            <TableCell className="max-w-48 truncate text-red-600 dark:text-red-400">{c.rejection_reason || '—'}</TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
