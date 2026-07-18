import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useEffect, useState } from 'react';

interface QuoteRow {
    symbol: string;
    bid_price: string;
    ask_price: string;
    bid_size: string;
    ask_size: string;
    bid_exchange: string;
    ask_exchange: string;
    quote_ts_utc: string;
    received_at_utc: string;
    feed: string;
    updated_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatedData {
    data: QuoteRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: PaginationLink[];
    from: number;
    to: number;
}

interface Props {
    data: PaginatedData;
    filters: { symbol: string };
}

const EXCHANGE_MAP: Record<string, string> = {
    A: 'NYSE American (AMEX)',
    B: 'NASDAQ OMX BX',
    C: 'National Stock Exchange',
    D: 'FINRA ADF',
    E: 'Market Independent',
    H: 'MIAX',
    I: 'International Securities Exchange',
    J: 'Cboe EDGA',
    K: 'Cboe EDGX',
    L: 'Long Term Stock Exchange',
    M: 'Chicago Stock Exchange',
    N: 'New York Stock Exchange',
    P: 'NYSE Arca',
    Q: 'NASDAQ OMX',
    S: 'NASDAQ Small Cap',
    T: 'NASDAQ Int',
    U: 'Members Exchange (MEMX)',
    V: 'IEX',
    W: 'CBOE',
    X: 'NASDAQ OMX PSX',
    Y: 'Cboe BYX',
    Z: 'Cboe BZX',
};

function formatPrice(val: string | null | undefined): string {
    if (!val) return '—';
    const num = parseFloat(val);
    const decimals = num.toFixed(3);
    if (decimals.endsWith('0')) {
        return '$' + num.toFixed(2);
    }
    return '$' + decimals;
}

function formatSize(val: string | null | undefined): string {
    if (!val) return '—';
    return parseInt(val).toLocaleString();
}

function formatExchange(val: string | null | undefined): string {
    if (!val) return '—';
    return EXCHANGE_MAP[val] || val;
}

function formatUTC(val: string | null | undefined): string {
    if (!val) return '—';
    const d = new Date(val + 'Z');
    return d.toLocaleString('en-US', {
        timeZone: 'America/New_York',
        hour12: true,
    });
}

function formatTimeOnly(val: string | null | undefined): string {
    if (!val) return '—';
    const d = new Date(val + 'Z');
    return d.toLocaleString('en-US', {
        timeZone: 'America/New_York',
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
    });
}

function formatQuoteAge(val: string | null | undefined): string {
    if (!val) return '—';
    const d = new Date(val + 'Z');
    const now = new Date();
    const diffMs = now.getTime() - d.getTime();
    const totalSeconds = Math.max(0, Math.floor(diffMs / 1000));
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    return `${minutes}m ${seconds}s`;
}

function spread(bid: string | null, ask: string | null): string {
    if (!bid || !ask) return '—';
    const b = parseFloat(bid);
    const a = parseFloat(ask);
    const mid = (b + a) / 2;
    if (mid <= 0) return '—';
    return (((a - b) / mid) * 100).toFixed(4) + '%';
}

export default function LatestQuotes({ data, filters }: Props) {
    const [symbolFilter, setSymbolFilter] = useState(filters.symbol || '');

    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({
                only: ['data'],
                preserveScroll: true,
                preserveState: true,
            });
        }, 500);

        return () => clearInterval(interval);
    }, []);

    const handleSymbolFilter = (value: string) => {
        setSymbolFilter(value);
        const params = new URLSearchParams(window.location.search);
        if (value) {
            params.set('symbol', value);
        } else {
            params.delete('symbol');
        }
        params.delete('page');
        router.visit(`/price-data/latest-quotes?${params.toString()}`, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Quotes', href: '/price-data/latest-quotes' },
                { title: 'Quotes', href: '/price-data/latest-quotes' },
            ]}
        >
            <Head title="Quotes" />
            <div className="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Quotes</CardTitle>
                        <div className="mt-3">
                            <Input
                                type="text"
                                placeholder="Filter by symbol (e.g. AAPL)..."
                                value={symbolFilter}
                                onChange={(e) =>
                                    handleSymbolFilter(e.target.value)
                                }
                                className="max-w-xs font-mono"
                            />
                        </div>
                        <div className="space-y-3 border-l-2 border-blue-300 pl-4 text-sm text-muted-foreground dark:border-blue-700">
                            <p>
                                Real-time SIP (Algo Trader Plus) quotes streamed
                                from Alpaca by the{' '}
                                <code className="rounded bg-gray-100 px-1 dark:bg-gray-800">
                                    stream_bars.py
                                </code>{' '}
                                daemon. Each symbol appears once — only the most
                                recent quote is stored.
                            </p>
                            <div className="space-y-1">
                                <p className="font-medium text-foreground">
                                    Columns:
                                </p>
                                <ul className="ml-4 list-disc space-y-1">
                                    <li>
                                        <strong>Symbol</strong> — Ticker symbol
                                    </li>
                                    <li>
                                        <strong>Bid Price / Ask Price</strong> —
                                        Best bid and ask from the SIP feed
                                        (real-time)
                                    </li>
                                    <li>
                                        <strong>Bid Size / Ask Size</strong> —
                                        Number of shares at the best bid/ask (in
                                        round lots × 100)
                                    </li>
                                    <li>
                                        <strong>
                                            Bid Exchange / Ask Exchange
                                        </strong>{' '}
                                        — Exchange code where the quote
                                        originated
                                    </li>
                                    <li>
                                        <strong>Quote TS</strong> — Timestamp
                                        when Alpaca generated the quote,
                                        displayed in Eastern Time
                                    </li>
                                    <li>
                                        <strong>Feed</strong> — Quote feed
                                        source (<code>sip</code> = consolidated,{' '}
                                        <code>iex</code> = direct exchange)
                                    </li>
                                    <li>
                                        <strong>Updated At</strong> — Last time
                                        this row was refreshed
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p className="mb-4 text-sm text-muted-foreground">
                            Showing {data.from} to {data.to} of{' '}
                            {data.total.toLocaleString()} symbols
                        </p>

                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Symbol</TableHead>
                                        <TableHead className="text-right">
                                            Bid
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Ask
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Bid Size
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Ask Size
                                        </TableHead>
                                        <TableHead>Bid Exchange</TableHead>
                                        <TableHead>Ask Exchange</TableHead>
                                        <TableHead className="text-right">
                                            Spread
                                        </TableHead>
                                        <TableHead>Quote TS</TableHead>
                                        <TableHead>Quote Age</TableHead>
                                        <TableHead>Feed</TableHead>
                                        <TableHead>Updated</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {data.data.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={12}
                                                className="text-center text-muted-foreground"
                                            >
                                                No quotes received yet — is{' '}
                                                <code>stream_bars.py</code>{' '}
                                                running?
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        data.data.map((row) => (
                                            <TableRow
                                                key={row.symbol}
                                                className="h-8"
                                            >
                                                <TableCell className="py-0.5 font-mono font-semibold">
                                                    {row.symbol}
                                                </TableCell>
                                                <TableCell className="py-0.5 font-mono">
                                                    {formatPrice(row.bid_price)}
                                                </TableCell>
                                                <TableCell className="py-0.5 text-right font-mono text-red-600 dark:text-red-400">
                                                    {formatPrice(row.ask_price)}
                                                </TableCell>
                                                <TableCell className="py-0.5 text-right font-mono">
                                                    {formatSize(row.bid_size)}
                                                </TableCell>
                                                <TableCell className="py-0.5 text-right font-mono">
                                                    {formatSize(row.ask_size)}
                                                </TableCell>
                                                <TableCell className="py-0.5 font-mono">
                                                    {formatExchange(
                                                        row.bid_exchange,
                                                    )}
                                                </TableCell>
                                                <TableCell className="py-0.5 font-mono">
                                                    {formatExchange(
                                                        row.ask_exchange,
                                                    )}
                                                </TableCell>
                                                <TableCell className="py-0.5 text-right font-mono">
                                                    {spread(
                                                        row.bid_price,
                                                        row.ask_price,
                                                    )}
                                                </TableCell>
                                                <TableCell className="py-0.5 font-mono">
                                                    {formatTimeOnly(
                                                        row.quote_ts_utc,
                                                    )}
                                                </TableCell>
                                                <TableCell className="py-0.5 font-mono">
                                                    {formatQuoteAge(
                                                        row.quote_ts_utc,
                                                    )}
                                                </TableCell>
                                                <TableCell className="py-0.5 font-mono">
                                                    {row.feed || '—'}
                                                </TableCell>
                                                <TableCell className="py-0.5 font-mono">
                                                    {formatTimeOnly(
                                                        row.updated_at,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>

                        {/* Pagination */}
                        <div className="mt-4 flex items-center justify-between">
                            <div className="text-sm text-muted-foreground">
                                Page {data.current_page} of {data.last_page}
                            </div>
                            <div className="flex gap-2">
                                {data.links.map((link, index) => {
                                    if (!link.url) {
                                        return (
                                            <Button
                                                key={index}
                                                variant="outline"
                                                size="sm"
                                                disabled
                                            >
                                                {link.label ===
                                                    '&laquo; Previous' && (
                                                    <ChevronLeft className="h-4 w-4" />
                                                )}
                                                {link.label ===
                                                    'Next &raquo;' && (
                                                    <ChevronRight className="h-4 w-4" />
                                                )}
                                                {link.label !==
                                                    '&laquo; Previous' &&
                                                    link.label !==
                                                        'Next &raquo;' &&
                                                    link.label}
                                            </Button>
                                        );
                                    }

                                    return (
                                        <Link
                                            key={index}
                                            href={link.url}
                                            preserveState
                                        >
                                            <Button
                                                variant={
                                                    link.active
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                size="sm"
                                            >
                                                {link.label ===
                                                    '&laquo; Previous' && (
                                                    <ChevronLeft className="h-4 w-4" />
                                                )}
                                                {link.label ===
                                                    'Next &raquo;' && (
                                                    <ChevronRight className="h-4 w-4" />
                                                )}
                                                {link.label !==
                                                    '&laquo; Previous' &&
                                                    link.label !==
                                                        'Next &raquo;' &&
                                                    link.label}
                                            </Button>
                                        </Link>
                                    );
                                })}
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
