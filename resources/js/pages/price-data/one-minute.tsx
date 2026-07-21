import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

interface PriceData {
    symbol: string;
    price: string;
    open?: string;
    high?: string;
    low?: string;
    volume?: string;
    ts_est?: string;
    trading_date_est?: string;
}

interface PaginatedData {
    data: PriceData[];
    current_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    total?: number;
    last_page?: number;
    next_page_url: string | null;
    prev_page_url: string | null;
}

interface Props {
    data: PaginatedData;
}

export default function OneMinute({ data }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Price Data', href: '/price-data/one-minute' }, { title: 'One Minute', href: '/price-data/one-minute' }]}>
            <div className="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>One Minute Price Data</CardTitle>
                        <p className="text-sm text-muted-foreground">
                            Showing {data.from ?? 1}–{data.to ?? data.data.length} of latest 500 records
                        </p>
                    </CardHeader>
                    <CardContent>
                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Time (EST)</TableHead>
                                        <TableHead>Symbol</TableHead>
                                        <TableHead className="text-right">Price</TableHead>
                                        <TableHead className="text-right">Open</TableHead>
                                        <TableHead className="text-right">High</TableHead>
                                        <TableHead className="text-right">Low</TableHead>
                                        <TableHead className="text-right">Volume</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {data.data.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={7} className="text-center text-muted-foreground">
                                                No data available
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        data.data.map((row, index) => (
                                            <TableRow key={index}>
                                                <TableCell className="font-mono text-sm">
                                                    {row.ts_est || row.trading_date_est || 'N/A'}
                                                </TableCell>
                                                <TableCell className="font-semibold">{row.symbol}</TableCell>
                                                <TableCell className="text-right font-mono">
                                                    ${parseFloat(row.price).toFixed(2)}
                                                </TableCell>
                                                <TableCell className="text-right font-mono">
                                                    {row.open ? `$${parseFloat(row.open).toFixed(2)}` : '-'}
                                                </TableCell>
                                                <TableCell className="text-right font-mono">
                                                    {row.high ? `$${parseFloat(row.high).toFixed(2)}` : '-'}
                                                </TableCell>
                                                <TableCell className="text-right font-mono">
                                                    {row.low ? `$${parseFloat(row.low).toFixed(2)}` : '-'}
                                                </TableCell>
                                                <TableCell className="text-right font-mono">
                                                    {row.volume ? parseInt(row.volume).toLocaleString() : '-'}
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
                                Page {data.current_page}
                            </div>
                            <div className="flex gap-2">
                                {data.prev_page_url ? (
                                    <Link href={data.prev_page_url} preserveState>
                                        <Button variant="outline" size="sm">
                                            <ChevronLeft className="h-4 w-4" />
                                        </Button>
                                    </Link>
                                ) : (
                                    <Button variant="outline" size="sm" disabled>
                                        <ChevronLeft className="h-4 w-4" />
                                    </Button>
                                )}
                                {data.next_page_url ? (
                                    <Link href={data.next_page_url} preserveState>
                                        <Button variant="outline" size="sm">
                                            <ChevronRight className="h-4 w-4" />
                                        </Button>
                                    </Link>
                                ) : (
                                    <Button variant="outline" size="sm" disabled>
                                        <ChevronRight className="h-4 w-4" />
                                    </Button>
                                )}
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
