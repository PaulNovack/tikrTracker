import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Activity, AlertTriangle, Clock3, RefreshCw, Server } from 'lucide-react';
import { useEffect } from 'react';

type WorkerProcess = {
    name: string;
    group: string;
    status: string;
    pid: number | null;
    uptime: string | null;
    details: string;
};

type WorkerGroup = {
    name: string;
    total: number;
    running: number;
    stopped: number;
    fatal: number;
    processes: Array<{
        name: string;
        status: string;
        pid: number | null;
        uptime: string | null;
    }>;
};

type WorkerQueue = {
    name: string;
    key: string;
    pending: number;
    workers: number;
    status: string;
    error: string | null;
};

type WorkerHeartbeatSnapshot = {
    captured_at_utc: string | null;
    captured_at_est: string | null;
    overall_status: string;
    summary: {
        total_processes: number;
        running_processes: number;
        stopped_processes: number;
        fatal_processes: number;
        queue_pending: number;
    };
    queues: WorkerQueue[];
    supervisor_groups: WorkerGroup[];
    processes: WorkerProcess[];
    activity: {
        last_bar_ts_est: string | null;
        last_alert_ts_est: string | null;
    };
    notes: string[];
};

type Props = {
    snapshot: WorkerHeartbeatSnapshot;
    snapshotAgeSeconds: number | null;
    lastUpdated: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'System', href: '/mysql-health' },
    { title: 'Worker Heartbeat', href: '/worker-heartbeat' },
];

const formatAge = (seconds: number | null) => {
    if (seconds === null) {
        return 'Never';
    }

    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;

    if (minutes > 0) {
        return `${minutes}m ${remainingSeconds}s`;
    }

    return `${remainingSeconds}s`;
};

const formatTimestamp = (timestamp: string | null) => {
    if (!timestamp) {
        return 'N/A';
    }

    return timestamp;
};

const statusVariant = (status: string) => {
    switch (status) {
        case 'healthy':
        case 'RUNNING':
            return 'default';
        case 'warning':
        case 'processing':
        case 'idle':
            return 'secondary';
        case 'critical':
        case 'stalled':
        case 'FATAL':
        case 'error':
            return 'destructive';
        default:
            return 'outline';
    }
};

export default function WorkerHeartbeatIndex({ snapshot, snapshotAgeSeconds, lastUpdated }: Props) {
    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({ preserveUrl: true });
        }, 60 * 1000);

        return () => clearInterval(interval);
    }, []);

    const overallStatus =
        snapshotAgeSeconds !== null && snapshotAgeSeconds > 600 ? 'stale' : snapshot.overall_status;

    const totalGroups = snapshot.supervisor_groups.length;
    const totalWorkers = snapshot.summary.total_processes;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Worker Heartbeat" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Worker Heartbeat</h1>
                        <p className="text-muted-foreground">
                            Supervisor snapshot, queue depth, and live worker health from the 5-minute watchdog.
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <div className="text-right text-sm text-muted-foreground">
                            <div>Last heartbeat: {formatTimestamp(lastUpdated)}</div>
                            <div>Snapshot age: {formatAge(snapshotAgeSeconds)}</div>
                        </div>
                        <Button onClick={() => router.reload({ preserveUrl: true })} variant="outline" size="sm">
                            <RefreshCw className="mr-2 size-4" />
                            Refresh
                        </Button>
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-4">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Overall Status</p>
                                    <div className="mt-2">
                                        <Badge variant={statusVariant(overallStatus)}>{overallStatus}</Badge>
                                    </div>
                                </div>
                                <Activity className="size-6 text-muted-foreground" />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm text-muted-foreground">Workers Running</p>
                            <p className="mt-2 text-3xl font-bold text-green-600">
                                {snapshot.summary.running_processes}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm text-muted-foreground">Fatal / Stopped</p>
                            <p className="mt-2 text-3xl font-bold text-red-600">
                                {snapshot.summary.fatal_processes + snapshot.summary.stopped_processes}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm text-muted-foreground">Queue Pending</p>
                            <p className="mt-2 text-3xl font-bold">
                                {snapshot.summary.queue_pending.toLocaleString()}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {snapshot.notes.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AlertTriangle className="size-4 text-amber-600" />
                                Watchdog Notes
                            </CardTitle>
                            <CardDescription>Issues detected while capturing the heartbeat snapshot.</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm text-muted-foreground">
                            {snapshot.notes.map((note) => (
                                <div key={note} className="rounded-md border bg-muted/30 px-3 py-2">
                                    {note}
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Server className="size-5" />
                            Supervisor Worker Groups
                        </CardTitle>
                        <CardDescription>
                            {totalGroups} groups / {totalWorkers} processes captured in the latest heartbeat.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Group</TableHead>
                                        <TableHead className="text-right">Total</TableHead>
                                        <TableHead className="text-right">Running</TableHead>
                                        <TableHead className="text-right">Stopped</TableHead>
                                        <TableHead className="text-right">Fatal</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {snapshot.supervisor_groups.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={6} className="h-24 text-center text-muted-foreground">
                                                No supervisor processes found.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        snapshot.supervisor_groups.map((group) => (
                                            <TableRow key={group.name}>
                                                <TableCell className="font-mono text-xs">{group.name}</TableCell>
                                                <TableCell className="text-right">{group.total}</TableCell>
                                                <TableCell className="text-right text-green-600">{group.running}</TableCell>
                                                <TableCell className="text-right">{group.stopped}</TableCell>
                                                <TableCell className="text-right">
                                                    <span className={group.fatal > 0 ? 'font-bold text-red-600' : ''}>
                                                        {group.fatal}
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant={statusVariant(group.fatal > 0 ? 'critical' : group.running === group.total ? 'healthy' : group.running > 0 ? 'warning' : 'stale')}>
                                                        {group.fatal > 0
                                                            ? 'critical'
                                                            : group.running === group.total
                                                                ? 'healthy'
                                                                : group.running > 0
                                                                    ? 'warning'
                                                                    : 'stale'}
                                                    </Badge>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Clock3 className="size-5" />
                            Queue Health
                        </CardTitle>
                        <CardDescription>
                            Pending jobs and worker counts from the same heartbeat snapshot.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Queue</TableHead>
                                        <TableHead className="text-right">Pending</TableHead>
                                        <TableHead className="text-right">Workers</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Error</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {snapshot.queues.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={5} className="h-24 text-center text-muted-foreground">
                                                No queue data captured yet.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        snapshot.queues.map((queue) => (
                                            <TableRow key={queue.name}>
                                                <TableCell className="font-mono text-xs">{queue.name}</TableCell>
                                                <TableCell className="text-right font-mono">{queue.pending.toLocaleString()}</TableCell>
                                                <TableCell className="text-right">{queue.workers}</TableCell>
                                                <TableCell>
                                                    <Badge variant={statusVariant(queue.status)}>{queue.status}</Badge>
                                                </TableCell>
                                                <TableCell className="max-w-[24rem] text-xs text-muted-foreground">
                                                    {queue.error ?? '—'}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Individual Workers</CardTitle>
                        <CardDescription>Raw Supervisor entries captured by the watchdog.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Process</TableHead>
                                        <TableHead>Group</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">PID</TableHead>
                                        <TableHead>Uptime</TableHead>
                                        <TableHead>Details</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {snapshot.processes.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={6} className="h-24 text-center text-muted-foreground">
                                                No worker rows captured.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        snapshot.processes.map((process) => (
                                            <TableRow key={process.name}>
                                                <TableCell className="font-mono text-xs">{process.name}</TableCell>
                                                <TableCell className="font-mono text-xs">{process.group}</TableCell>
                                                <TableCell>
                                                    <Badge variant={statusVariant(process.status)}>{process.status}</Badge>
                                                </TableCell>
                                                <TableCell className="text-right font-mono text-xs">{process.pid ?? '—'}</TableCell>
                                                <TableCell className="text-xs text-muted-foreground">{process.uptime ?? '—'}</TableCell>
                                                <TableCell className="max-w-[28rem] text-xs text-muted-foreground">
                                                    {process.details}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Activity Timestamps</CardTitle>
                        <CardDescription>Latest bar and alert times observed when the watchdog ran.</CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4 md:grid-cols-2">
                        <div>
                            <p className="text-xs text-muted-foreground">Latest 1m bar</p>
                            <p className="font-mono text-sm">{formatTimestamp(snapshot.activity.last_bar_ts_est)}</p>
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground">Latest alert</p>
                            <p className="font-mono text-sm">{formatTimestamp(snapshot.activity.last_alert_ts_est)}</p>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}