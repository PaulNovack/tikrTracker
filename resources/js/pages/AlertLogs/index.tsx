import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { AlertCircle, CheckCircle, Clock } from 'lucide-react';
import { useEffect } from 'react';

interface AlertLog {
    id: number;
    symbol: string;
    asset_id: number | null;
    direction: 'up' | 'down';
    trigger_price: number;
    current_price: number;
    trigger_percentage: number;
    change_percentage: number;
    email_status: 'sent' | 'failed' | 'retry';
    email_error: string | null;
    sent_at: string;
}

interface Props {
    logs: {
        data: AlertLog[];
        current_page: number;
        total: number;
        per_page: number;
        last_page: number;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Admin',
        href: '#',
    },
    {
        title: 'Alert Logs',
        href: '/alert-logs',
    },
];

const formatDate = (dateString: string) => {
    try {
        // Parse ISO8601 string properly
        const date = new Date(dateString);

        // Verify it's a valid date
        if (isNaN(date.getTime())) {
            return 'Invalid date';
        }

        // Convert UTC to EST using toLocaleString
        return (
            date.toLocaleString('en-US', {
                timeZone: 'America/New_York',
                year: 'numeric',
                month: 'short',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false,
            }) + ' EST'
        );
    } catch (e) {
        return 'Invalid date';
    }
};

export default function AlertLogsIndex({ logs }: Props) {
    // Auto-refresh every 5 minutes
    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({ preserveUrl: true });
        }, 5 * 60 * 1000); // 5 minutes in milliseconds

        return () => clearInterval(interval);
    }, []);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Alert Logs" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">
                            Alert Logs
                        </h1>
                        <p className="text-muted-foreground">
                            View all triggered price alerts and their delivery
                            status
                        </p>
                    </div>
                    <Link href="/notifications-settings">
                        <Button>Manage Alerts</Button>
                    </Link>
                </div>

                {/* Table */}
                <div className="rounded-lg border bg-card">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Symbol</TableHead>
                                <TableHead>Direction</TableHead>
                                <TableHead>Trigger Price</TableHead>
                                <TableHead>Current Price</TableHead>
                                <TableHead>Change %</TableHead>
                                <TableHead>Email Status</TableHead>
                                <TableHead>Sent (EST)</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {logs.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="h-24 text-center text-muted-foreground"
                                    >
                                        No alert logs yet. Create price alerts
                                        to see them appear here.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                logs.data.map((log) => {
                                    // Use the calculated change_percentage which considers direction
                                    const changePercent = log.change_percentage
                                        ? parseFloat(
                                              log.change_percentage as any,
                                          )
                                        : 0;

                                    return (
                                        <TableRow key={log.id}>
                                            <TableCell className="font-semibold">
                                                {log.asset_id ? (
                                                    <Link
                                                        href={`/market-data/assets/${log.asset_id}`}
                                                        className="text-blue-600 hover:text-blue-800 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
                                                    >
                                                        {log.symbol}
                                                    </Link>
                                                ) : (
                                                    <span>{log.symbol}</span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <span
                                                    className={`inline-flex items-center rounded-full px-3 py-1 text-sm font-medium ${
                                                        log.direction === 'up'
                                                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                                            : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                                                    }`}
                                                >
                                                    {log.direction === 'up' ? (
                                                        <>📈 Up</>
                                                    ) : (
                                                        <>📉 Down</>
                                                    )}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                $
                                                {log.trigger_price
                                                    ? parseFloat(
                                                          log.trigger_price as any,
                                                      ).toFixed(2)
                                                    : '—'}
                                            </TableCell>
                                            <TableCell>
                                                $
                                                {log.current_price
                                                    ? parseFloat(
                                                          log.current_price as any,
                                                      ).toFixed(2)
                                                    : '—'}
                                            </TableCell>
                                            <TableCell>
                                                <span
                                                    className={`font-semibold ${
                                                        changePercent > 0
                                                            ? 'text-green-600 dark:text-green-400'
                                                            : changePercent < 0
                                                              ? 'text-red-600 dark:text-red-400'
                                                              : 'text-slate-600 dark:text-slate-400'
                                                    }`}
                                                >
                                                    {changePercent > 0
                                                        ? '+'
                                                        : ''}
                                                    {changePercent.toFixed(2)}%
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                {log.email_status === 'sent' ? (
                                                    <span className="inline-flex items-center gap-2 rounded-full bg-green-100 px-3 py-1 text-sm font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                                        <CheckCircle className="size-4" />
                                                        Sent
                                                    </span>
                                                ) : log.email_status ===
                                                  'failed' ? (
                                                    <div className="space-y-1">
                                                        <span className="inline-flex items-center gap-2 rounded-full bg-red-100 px-3 py-1 text-sm font-medium text-red-700 dark:bg-red-900/30 dark:text-red-400">
                                                            <AlertCircle className="size-4" />
                                                            Failed
                                                        </span>
                                                        {log.email_error && (
                                                            <p className="text-xs text-muted-foreground">
                                                                {
                                                                    log.email_error
                                                                }
                                                            </p>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <span className="inline-flex items-center gap-2 rounded-full bg-yellow-100 px-3 py-1 text-sm font-medium text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400">
                                                        <Clock className="size-4" />
                                                        Retry
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {formatDate(log.sent_at)}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })
                            )}
                        </TableBody>
                    </Table>
                </div>
                {/* Pagination */}
                {logs.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing{' '}
                            {logs.data.length === 0
                                ? 0
                                : (logs.current_page - 1) * logs.per_page +
                                  1}{' '}
                            to{' '}
                            {Math.min(
                                logs.current_page * logs.per_page,
                                logs.total,
                            )}{' '}
                            of {logs.total} logs
                        </p>
                        <div className="flex gap-2">
                            {logs.current_page > 1 && (
                                <Link
                                    href={`/alert-logs?page=${logs.current_page - 1}`}
                                >
                                    <Button variant="outline">Previous</Button>
                                </Link>
                            )}
                            {logs.current_page < logs.last_page && (
                                <Link
                                    href={`/alert-logs?page=${logs.current_page + 1}`}
                                >
                                    <Button variant="outline">Next</Button>
                                </Link>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
