import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Activity, Clock, Cpu, HardDrive, RefreshCw, X, AlertCircle, CheckCircle, Server } from 'lucide-react';
import { useEffect, useState } from 'react';
import { killProcess } from '@/actions/App/Http/Controllers/ProcessMonitorController';

interface ScheduledCommand {
    name: string;
    signature: string;
    status: 'running' | 'idle';
    locked_since: string | null;
    type: 'laravel_scheduled';
}

interface PythonProcess {
    pid: string;
    name: string;
    description?: string;
    command: string;
    cpu_usage: string;
    memory_usage: string;
    start_time: string;
    status: 'running';
    type: 'python_process' | 'artisan_command';
    runtime: string;
}

interface CacheLock {
    key: string;
    ttl?: number;
    expires_in?: string;
    status?: string;
    error?: string;
    type: 'cache_lock' | 'cache_info';
}

interface ProcessError {
    name: string;
    status: 'error';
    error: string;
    type: 'error';
}

interface SupervisorProcess {
    name: string;
    status: string;
    pid: string | null;
    uptime: string | null;
    details: string;
    description: string;
}

interface Props {
    processes: {
        scheduled_commands: ScheduledCommand[];
        python_processes: (PythonProcess | ProcessError)[];
        supervisor_processes: SupervisorProcess[];
        cache_locks: CacheLock[];
        last_updated: string;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'System',
        href: '/mysql-health',
    },
    {
        title: 'Processes Running',
        href: '/processes-running',
    },
];

const formatLastUpdated = (dateString: string) => {
    try {
        const date = new Date(dateString);
        return date.toLocaleString('en-US', {
            timeZone: 'America/New_York',
            year: 'numeric',
            month: 'short',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false,
        }) + ' EST';
    } catch (e) {
        return 'Invalid date';
    }
};

const formatRuntime = (lockedSince: string | null) => {
    if (!lockedSince) return 'N/A';
    
    try {
        const startTime = new Date(lockedSince);
        const now = new Date();
        const diffMs = now.getTime() - startTime.getTime();
        
        const hours = Math.floor(diffMs / (1000 * 60 * 60));
        const minutes = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((diffMs % (1000 * 60)) / 1000);
        
        if (hours > 0) {
            return `${hours}h ${minutes}m`;
        } else if (minutes > 0) {
            return `${minutes}m ${seconds}s`;
        } else {
            return `${seconds}s`;
        }
    } catch (e) {
        return 'Unknown';
    }
};

const getStatusBadgeVariant = (status: string) => {
    switch (status) {
        case 'running':
            return 'default';
        case 'idle':
            return 'secondary';
        case 'error':
            return 'destructive';
        default:
            return 'outline';
    }
};

const getStatusIcon = (status: string) => {
    switch (status) {
        case 'running':
            return <Activity className="size-4 text-green-500" />;
        case 'idle':
            return <Clock className="size-4 text-gray-400" />;
        case 'error':
            return <Activity className="size-4 text-red-500" />;
        default:
            return <Activity className="size-4" />;
    }
};

export default function ProcessesRunningIndex({ processes }: Props) {
    const [killingProcess, setKillingProcess] = useState<string | null>(null);
    const [confirmDialog, setConfirmDialog] = useState<{
        open: boolean;
        pid: string;
        processName: string;
    } | null>(null);
    const [messageDialog, setMessageDialog] = useState<{
        open: boolean;
        type: 'success' | 'error';
        title: string;
        message: string;
    } | null>(null);

    // Auto-refresh every 10 seconds
    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({ preserveUrl: true });
        }, 10 * 1000); // 10 seconds

        return () => clearInterval(interval);
    }, []);

    const handleRefresh = () => {
        router.reload({ preserveUrl: true });
    };

    const showKillConfirmation = (pid: string, processName: string) => {
        setConfirmDialog({
            open: true,
            pid,
            processName,
        });
    };

    const handleConfirmKill = async () => {
        if (!confirmDialog) return;
        
        const { pid, processName } = confirmDialog;
        setConfirmDialog(null);
        setKillingProcess(pid);
        
        try {
            const response = await fetch(killProcess().url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                },
                body: JSON.stringify({ pid: parseInt(pid) }),
            });

            const result = await response.json();

            if (result.success) {
                setMessageDialog({
                    open: true,
                    type: 'success',
                    title: 'Process Killed Successfully',
                    message: result.message,
                });
                // Refresh the page to show updated process list
                setTimeout(() => {
                    router.reload({ preserveUrl: true });
                }, 1500);
            } else {
                setMessageDialog({
                    open: true,
                    type: 'error',
                    title: 'Failed to Kill Process',
                    message: result.message,
                });
            }
        } catch (error) {
            console.error('Error killing process:', error);
            setMessageDialog({
                open: true,
                type: 'error',
                title: 'Error',
                message: 'An error occurred while trying to kill the process',
            });
        } finally {
            setKillingProcess(null);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Processes Running" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Processes Running</h1>
                        <p className="text-muted-foreground">
                            Monitor active Laravel scheduled commands and Python processes
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <p className="text-sm text-muted-foreground">
                            Last updated: {formatLastUpdated(processes.last_updated)}
                        </p>
                        <Button onClick={handleRefresh} variant="outline" size="sm">
                            <RefreshCw className="mr-2 size-4" />
                            Refresh
                        </Button>
                    </div>
                </div>

                {/* Python Processes */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Cpu className="size-5" />
                            Active Market Data Processes
                        </CardTitle>
                        <CardDescription>
                            Currently running Python scripts and Laravel Artisan commands related to market data
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Process</TableHead>
                                        <TableHead>Description</TableHead>
                                        <TableHead>PID</TableHead>
                                        <TableHead>CPU</TableHead>
                                        <TableHead>Memory</TableHead>
                                        <TableHead>Runtime</TableHead>
                                        <TableHead>Actions</TableHead>
                                        <TableHead>Command</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {processes.python_processes.length === 0 ? (
                                        <TableRow>
                                            <TableCell 
                                                colSpan={8}
                                                className="h-24 text-center text-muted-foreground"
                                            >
                                                No processes Running
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        processes.python_processes.map((process, index) => {
                                            if (process.type === 'error') {
                                                return (
                                                    <TableRow key={index}>
                                                        <TableCell colSpan={8} className="text-red-500">
                                                            Error: {process.error}
                                                        </TableCell>
                                                    </TableRow>
                                                );
                                            }

                                            const pythonProcess = process as PythonProcess;
                                            return (
                                                <TableRow key={index}>
                                                    <TableCell className="font-medium">
                                                        <div className="flex items-center gap-2">
                                                            <Activity className="size-4 text-green-500" />
                                                            {pythonProcess.name}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {pythonProcess.description || 'No description available'}
                                                    </TableCell>
                                                    <TableCell className="font-mono text-sm">
                                                        {pythonProcess.pid}
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex items-center gap-1">
                                                            <Cpu className="size-3" />
                                                            {pythonProcess.cpu_usage}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex items-center gap-1">
                                                            <HardDrive className="size-3" />
                                                            {pythonProcess.memory_usage}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>
                                                        {pythonProcess.runtime}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => showKillConfirmation(pythonProcess.pid, pythonProcess.name)}
                                                            disabled={killingProcess === pythonProcess.pid}
                                                            className="h-8 w-8 p-0 text-red-500 hover:text-red-700 hover:bg-red-50"
                                                        >
                                                            {killingProcess === pythonProcess.pid ? (
                                                                <RefreshCw className="size-4 animate-spin" />
                                                            ) : (
                                                                <X className="size-4" />
                                                            )}
                                                        </Button>
                                                    </TableCell>
                                                    <TableCell className="font-mono text-xs whitespace-normal break-all">
                                                        {pythonProcess.command}
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                {/* Supervisor Processes */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Server className="size-5" />
                            Supervisor Processes
                        </CardTitle>
                        <CardDescription>
                            Processes managed by supervisord — queue workers, Reverb WebSocket server, and continuous backtest loops
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Program</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>PID</TableHead>
                                        <TableHead>Uptime</TableHead>
                                        <TableHead>Description</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {processes.supervisor_processes.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={5} className="h-24 text-center text-muted-foreground">
                                                No supervisor processes found
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        processes.supervisor_processes.map((proc, index) => (
                                            <TableRow key={index}>
                                                <TableCell className="font-medium font-mono text-sm">
                                                    {proc.name}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant={
                                                            proc.status === 'running'
                                                                ? 'default'
                                                                : proc.status === 'stopped' || proc.status === 'fatal'
                                                                  ? 'destructive'
                                                                  : 'secondary'
                                                        }
                                                    >
                                                        {proc.status}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="font-mono text-sm">
                                                    {proc.pid ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-sm">
                                                    {proc.uptime ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground text-sm">
                                                    {proc.description}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                {/* Cache Locks */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Clock className="size-5" />
                            Active Process Locks
                        </CardTitle>
                        <CardDescription>
                            Current application and framework locks preventing overlapping execution
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Lock Key</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Duration</TableHead>
                                        <TableHead>TTL</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {processes.cache_locks.length === 0 ? (
                                        <TableRow>
                                            <TableCell 
                                                colSpan={4}
                                                className="h-24 text-center text-muted-foreground"
                                            >
                                                No active cache locks
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        processes.cache_locks.map((lock, index) => {
                                            if (lock.type === 'cache_info') {
                                                return (
                                                    <TableRow key={index}>
                                                        <TableCell colSpan={4} className="text-muted-foreground text-center">
                                                            {lock.error || lock.status}
                                                        </TableCell>
                                                    </TableRow>
                                                );
                                            }

                                            const getLockTypeLabel = (type: string) => {
                                                switch (type) {
                                                    case 'app_lock':
                                                        return 'Application Lock';
                                                    case 'framework_lock':
                                                        return 'Framework Lock';
                                                    case 'redis_lock':
                                                        return 'Redis Lock';
                                                    default:
                                                        return 'Cache Lock';
                                                }
                                            };

                                            const getLockTypeColor = (type: string) => {
                                                switch (type) {
                                                    case 'app_lock':
                                                        return 'text-blue-600 bg-blue-50';
                                                    case 'framework_lock':
                                                        return 'text-green-600 bg-green-50';
                                                    case 'redis_lock':
                                                        return 'text-red-600 bg-red-50';
                                                    default:
                                                        return 'text-gray-600 bg-gray-50';
                                                }
                                            };

                                            return (
                                                <TableRow key={index}>
                                                    <TableCell className="font-mono text-sm">
                                                        {lock.key}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge className={`text-xs ${getLockTypeColor(lock.type)}`}>
                                                            {getLockTypeLabel(lock.type)}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell>
                                                        {lock.expires_in || 'N/A'}
                                                    </TableCell>
                                                    <TableCell className="font-mono text-sm">
                                                        {lock.ttl !== undefined ? `${lock.ttl}s` : 'N/A'}
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                {/* Laravel Scheduled Commands */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Activity className="size-5" />
                            Laravel Scheduled Commands
                        </CardTitle>
                        <CardDescription>
                            Status of scheduled Laravel artisan commands with mutex locks
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Command</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Runtime</TableHead>
                                        <TableHead>Signature</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {processes.scheduled_commands.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={4}
                                                className="h-24 text-center text-muted-foreground"
                                            >
                                                No scheduled commands found
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        processes.scheduled_commands.map((command, index) => (
                                            <TableRow key={index}>
                                                <TableCell className="font-medium">
                                                    <div className="flex items-center gap-2">
                                                        {getStatusIcon(command.status)}
                                                        {command.name}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant={getStatusBadgeVariant(command.status)}>
                                                        {command.status}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    {command.status === 'running'
                                                        ? formatRuntime(command.locked_since)
                                                        : '—'
                                                    }
                                                </TableCell>
                                                <TableCell className="font-mono text-xs">
                                                    {command.signature}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Confirmation Dialog */}
            <Dialog open={confirmDialog?.open || false} onOpenChange={(open) => {
                if (!open) {
                    setConfirmDialog(null);
                }
            }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <AlertCircle className="size-5 text-red-500" />
                            Confirm Process Termination
                        </DialogTitle>
                        <DialogDescription>
                            Are you sure you want to kill "{confirmDialog?.processName}" (PID: {confirmDialog?.pid})? This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setConfirmDialog(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleConfirmKill}
                        >
                            Kill Process
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Message Dialog */}
            <Dialog open={messageDialog?.open || false} onOpenChange={(open) => {
                if (!open) {
                    setMessageDialog(null);
                }
            }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            {messageDialog?.type === 'success' ? (
                                <CheckCircle className="size-5 text-green-500" />
                            ) : (
                                <AlertCircle className="size-5 text-red-500" />
                            )}
                            {messageDialog?.title}
                        </DialogTitle>
                        <DialogDescription>
                            {messageDialog?.message}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button onClick={() => setMessageDialog(null)}>
                            OK
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}