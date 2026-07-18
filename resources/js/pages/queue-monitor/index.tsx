import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'System', href: '/mysql-health' },
    { title: 'Queue Monitor', href: '/queue-monitor' },
];

type QueueInfo = {
    name: string;
    key: string;
    count: number;
    workers: number;
};

type SupervisorGroup = {
    name: string;
    total: number;
    running: number;
    stopped: number;
    fatal: number;
};

type RedisInfo = {
    used_memory?: string;
    connected_clients?: number;
    uptime_days?: number;
    keyspace_hits?: number;
    keyspace_misses?: number;
    total_commands_processed?: string;
    instantaneous_ops_per_sec?: number;
};

type Props = {
    queues: QueueInfo[];
    supervisorProcesses: SupervisorGroup[];
    redisInfo: RedisInfo;
    lastUpdated: string;
};

export default function QueueMonitor({ queues, supervisorProcesses, redisInfo, lastUpdated }: Props) {
    const totalPending = queues.reduce((sum, q) => sum + q.count, 0);
    const totalRunning = supervisorProcesses.reduce((sum, p) => sum + p.running, 0);
    const totalFatal = supervisorProcesses.reduce((sum, p) => sum + p.fatal, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Queue Monitor" />
            <div className="flex flex-col gap-6 p-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Queue Monitor</h1>
                        <p className="text-sm text-muted-foreground">
                            Redis queue depths and supervisor worker status. Updated: {lastUpdated}
                        </p>
                    </div>
                    <a
                        href="/queue-monitor"
                        className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                    >
                        Refresh
                    </a>
                </div>

                {/* Summary Cards */}
                <div className="grid grid-cols-4 gap-4">
                    <div className="rounded-lg border bg-card p-4">
                        <p className="text-sm text-muted-foreground">Total Pending Jobs</p>
                        <p className="text-2xl font-bold">{totalPending.toLocaleString()}</p>
                    </div>
                    <div className="rounded-lg border bg-card p-4">
                        <p className="text-sm text-muted-foreground">Workers Running</p>
                        <p className="text-2xl font-bold text-green-600">{totalRunning}</p>
                    </div>
                    <div className="rounded-lg border bg-card p-4">
                        <p className="text-sm text-muted-foreground">FATAL Workers</p>
                        <p className={`text-2xl font-bold ${totalFatal > 0 ? 'text-red-600' : ''}`}>
                            {totalFatal}
                        </p>
                    </div>
                    <div className="rounded-lg border bg-card p-4">
                        <p className="text-sm text-muted-foreground">Redis Ops/sec</p>
                        <p className="text-2xl font-bold">
                            {(redisInfo.instantaneous_ops_per_sec ?? 0).toLocaleString()}
                        </p>
                    </div>
                </div>

                {/* Queue Depths */}
                <div className="rounded-lg border">
                    <div className="border-b px-6 py-3">
                        <h2 className="font-semibold">Queue Depths</h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-6 py-2 text-left font-medium">Queue</th>
                                    <th className="px-6 py-2 text-right font-medium">Pending Jobs</th>
                                    <th className="px-6 py-2 text-right font-medium">Workers</th>
                                    <th className="px-6 py-2 text-left font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {queues.map((q) => (
                                    <tr key={q.name} className="border-b">
                                        <td className="px-6 py-2 font-mono text-xs">{q.name}</td>
                                        <td className="px-6 py-2 text-right font-mono">
                                            {q.count.toLocaleString()}
                                        </td>
                                        <td className="px-6 py-2 text-right">{q.workers}</td>
                                        <td className="px-6 py-2">
                                            {q.count === 0 ? (
                                                <span className="text-green-600">● Idle</span>
                                            ) : q.workers === 0 ? (
                                                <span className="text-red-600">● No workers!</span>
                                            ) : (
                                                <span className="text-amber-600">● Processing</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Supervisor Processes */}
                <div className="rounded-lg border">
                    <div className="border-b px-6 py-3">
                        <h2 className="font-semibold">Supervisor Workers</h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-6 py-2 text-left font-medium">Program</th>
                                    <th className="px-6 py-2 text-right font-medium">Total</th>
                                    <th className="px-6 py-2 text-right font-medium">Running</th>
                                    <th className="px-6 py-2 text-right font-medium">Stopped</th>
                                    <th className="px-6 py-2 text-right font-medium">Fatal</th>
                                    <th className="px-6 py-2 text-left font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {supervisorProcesses.map((p) => (
                                    <tr key={p.name} className="border-b">
                                        <td className="px-6 py-2 font-mono text-xs">{p.name}</td>
                                        <td className="px-6 py-2 text-right">{p.total}</td>
                                        <td className="px-6 py-2 text-right text-green-600">{p.running}</td>
                                        <td className="px-6 py-2 text-right">{p.stopped}</td>
                                        <td className="px-6 py-2 text-right">
                                            <span className={p.fatal > 0 ? 'text-red-600 font-bold' : ''}>
                                                {p.fatal}
                                            </span>
                                        </td>
                                        <td className="px-6 py-2">
                                            {p.fatal > 0 ? (
                                                <span className="text-red-600">● FATAL</span>
                                            ) : p.running === p.total ? (
                                                <span className="text-green-600">● All Running</span>
                                            ) : p.running > 0 ? (
                                                <span className="text-amber-600">● Partial</span>
                                            ) : (
                                                <span className="text-muted-foreground">● Stopped</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Redis Info */}
                <div className="rounded-lg border">
                    <div className="border-b px-6 py-3">
                        <h2 className="font-semibold">Redis Info</h2>
                    </div>
                    <div className="grid grid-cols-3 gap-4 p-6">
                        <div>
                            <p className="text-xs text-muted-foreground">Memory Used</p>
                            <p className="font-mono text-sm">{redisInfo.used_memory ?? 'N/A'}</p>
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground">Connected Clients</p>
                            <p className="font-mono text-sm">{redisInfo.connected_clients ?? 'N/A'}</p>
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground">Uptime (days)</p>
                            <p className="font-mono text-sm">{redisInfo.uptime_days ?? 'N/A'}</p>
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground">Cache Hits</p>
                            <p className="font-mono text-sm">{((redisInfo.keyspace_hits ?? 0) === 0 && (redisInfo.keyspace_misses ?? 0) === 0) ? 'N/A' : (redisInfo.keyspace_hits ?? 0).toLocaleString()}</p>
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground">Cache Misses</p>
                            <p className="font-mono text-sm">{((redisInfo.keyspace_hits ?? 0) === 0 && (redisInfo.keyspace_misses ?? 0) === 0) ? 'N/A' : (redisInfo.keyspace_misses ?? 0).toLocaleString()}</p>
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground">Total Commands</p>
                            <p className="font-mono text-sm">{redisInfo.total_commands_processed ?? 'N/A'}</p>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
