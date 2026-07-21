import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { type BreadcrumbItem } from '@/types';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { RefreshCw, Terminal, Globe, Database, Cpu, HardDrive, Layers, Cog, Workflow, Server } from 'lucide-react';

interface CpuUsage {
    id: string;
    used: number;
    idle: number;
    user: number;
    system: number;
    iowait: number;
}

interface MemoryProcess {
    user: string;
    pid: string;
    cpu: number;
    mem: number;
    vsz: string;
    rss: string;
    command: string;
}

function CpuBar({ cpu }: { cpu: CpuUsage }) {
    const getColor = (usage: number) => {
        if (usage < 30) return 'bg-green-500';
        if (usage < 60) return 'bg-yellow-500';
        if (usage < 80) return 'bg-orange-500';
        return 'bg-red-500';
    };

    return (
        <div className="space-y-1">
            <div className="flex justify-between items-center text-sm">
                <span className="font-mono font-medium">{cpu.id}</span>
                <span className="font-mono text-xs text-muted-foreground">
                    {cpu.used.toFixed(1)}% used
                </span>
            </div>
            <div className="w-full bg-gray-200 dark:bg-gray-800 rounded-full h-4 overflow-hidden">
                <div
                    className={`h-full transition-all duration-300 ${getColor(cpu.used)} flex items-center justify-end pr-2`}
                    style={{ width: `${cpu.used}%` }}
                >
                    {cpu.used > 10 && (
                        <span className="text-xs font-bold text-white">
                            {cpu.used.toFixed(0)}%
                        </span>
                    )}
                </div>
            </div>
            <div className="flex justify-between text-xs text-muted-foreground font-mono">
                <span>usr: {cpu.user.toFixed(1)}%</span>
                <span>sys: {cpu.system.toFixed(1)}%</span>
                <span>io: {cpu.iowait.toFixed(1)}%</span>
            </div>
        </div>
    );
}

function getProcessIcon(command: string) {
    const cmd = command.toLowerCase();
    if (cmd.includes('php') || cmd.includes('artisan')) return <Cog className="h-3.5 w-3.5 text-purple-500" />;
    if (cmd.includes('mysql') || cmd.includes('mariadb')) return <Database className="h-3.5 w-3.5 text-blue-500" />;
    if (cmd.includes('nginx') || cmd.includes('apache') || cmd.includes('httpd')) return <Globe className="h-3.5 w-3.5 text-green-500" />;
    if (cmd.includes('node') || cmd.includes('npm') || cmd.includes('vite')) return <Server className="h-3.5 w-3.5 text-emerald-500" />;
    if (cmd.includes('python') || cmd.includes('pip')) return <Cpu className="h-3.5 w-3.5 text-yellow-500" />;
    if (cmd.includes('redis')) return <Layers className="h-3.5 w-3.5 text-red-500" />;
    if (cmd.includes('docker') || cmd.includes('containerd')) return <HardDrive className="h-3.5 w-3.5 text-cyan-500" />;
    if (cmd.includes('supervisor') || cmd.includes('cron')) return <Workflow className="h-3.5 w-3.5 text-orange-500" />;
    return <Terminal className="h-3.5 w-3.5 text-muted-foreground" />;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'System', href: '/mysql-health' },
    { title: 'HTOP', href: '/logs/htop' },
]

export default function Htop() {
    const [content, setContent] = useState('');
    const [cpuUsage, setCpuUsage] = useState<CpuUsage[]>([]);
    const [memoryUsage, setMemoryUsage] = useState<MemoryProcess[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [autoRefresh, setAutoRefresh] = useState(true);
    const intervalRef = useRef<NodeJS.Timeout | null>(null);

    const fetchHtop = async () => {
        try {
            const response = await fetch('/api/logs/htop');
            const data = await response.json();
            setContent(data.content);
            setCpuUsage(data.cpuUsage || []);
            setMemoryUsage(data.memoryUsage || []);
            setIsLoading(false);
        } catch (error) {
            console.error('Error fetching htop:', error);
            setIsLoading(false);
        }
    };

    useEffect(() => {
        fetchHtop();
        
        if (autoRefresh) {
            intervalRef.current = setInterval(fetchHtop, 1000); // Refresh every 1 second
        }

        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, [autoRefresh]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="System Monitor" />
            <div className="space-y-4">
                {/* CPU Usage Card */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle>CPU Usage</CardTitle>
                                <CardDescription>
                                    Per-core CPU utilization
                                    {autoRefresh && ' (Auto-refreshing every 1 second)'}
                                </CardDescription>
                            </div>
                            <div className="flex gap-2">
                                <Button
                                    onClick={() => setAutoRefresh(!autoRefresh)}
                                    variant={autoRefresh ? 'default' : 'outline'}
                                    size="sm"
                                >
                                    <RefreshCw className={`h-4 w-4 mr-2 ${autoRefresh ? 'animate-spin' : ''}`} />
                                    {autoRefresh ? 'Auto-refresh ON' : 'Auto-refresh OFF'}
                                </Button>
                                <Button
                                    onClick={fetchHtop}
                                    variant="outline"
                                    size="sm"
                                >
                                    <RefreshCw className="h-4 w-4 mr-2" />
                                    Refresh Now
                                </Button>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {isLoading ? (
                            <div className="text-center py-8 text-muted-foreground">
                                Loading CPU data...
                            </div>
                        ) : cpuUsage.length > 0 ? (
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                                {cpuUsage.map((cpu, index) => (
                                    <CpuBar key={index} cpu={cpu} />
                                ))}
                            </div>
                        ) : (
                            <div className="text-center py-8 text-muted-foreground">
                                No CPU data available
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Memory Usage by Application Card */}
                <Card>
                    <CardHeader>
                        <CardTitle>Memory Usage by Application</CardTitle>
                        <CardDescription>Top 20 processes by RSS memory</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {memoryUsage.length > 0 ? (
                            <div className="space-y-2">
                                <div className="grid grid-cols-12 gap-2 text-xs font-medium text-muted-foreground pb-2 border-b">
                                    <span className="col-span-1 flex justify-center"><Terminal className="h-3.5 w-3.5" /></span>
                                    <span className="col-span-3">Command</span>
                                    <span className="col-span-1 text-right">%CPU</span>
                                    <span className="col-span-2 text-right">%MEM</span>
                                    <span className="col-span-3 text-right">RSS</span>
                                    <span className="col-span-2 text-right">PID</span>
                                </div>
                                {memoryUsage.map((proc, index) => {
                                    const memColor = proc.mem < 10 ? 'bg-green-500' : proc.mem < 25 ? 'bg-yellow-500' : proc.mem < 50 ? 'bg-orange-500' : 'bg-red-500';
                                    const cpuColor = proc.cpu < 10 ? 'bg-green-500' : proc.cpu < 25 ? 'bg-yellow-500' : proc.cpu < 50 ? 'bg-orange-500' : 'bg-red-500';
                                    return (
                                        <div key={index} className="grid grid-cols-12 gap-2 text-xs items-center py-1 hover:bg-muted/50 rounded px-1">
                                            <span className="col-span-1 flex justify-center">{getProcessIcon(proc.command)}</span>
                                            <span className="col-span-3 font-mono truncate" title={proc.command}>{proc.command}</span>
                                            <span className="col-span-1 text-right">
                                                <span className="inline-flex items-center gap-1">
                                                    <div className="w-8 h-1.5 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                                        <div className={`h-full ${cpuColor} transition-all`} style={{ width: `${Math.min(proc.cpu, 100)}%` }} />
                                                    </div>
                                                    <span className="font-mono">{proc.cpu.toFixed(1)}%</span>
                                                </span>
                                            </span>
                                            <span className="col-span-2 text-right">
                                                <span className="inline-flex items-center gap-1.5">
                                                    <div className="w-10 h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                                        <div className={`h-full ${memColor} transition-all`} style={{ width: `${Math.min(proc.mem, 100)}%` }} />
                                                    </div>
                                                    <span className="font-mono">{proc.mem.toFixed(1)}%</span>
                                                </span>
                                            </span>
                                            <span className="col-span-3 text-right font-mono">{proc.rss}</span>
                                            <span className="col-span-2 text-right font-mono text-muted-foreground">{proc.pid}</span>
                                        </div>
                                    );
                                })}
                            </div>
                        ) : (
                            <div className="text-center py-8 text-muted-foreground">
                                No memory data available
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Process List Card */}
                <Card>
                    <CardHeader>
                        <CardTitle>Process List</CardTitle>
                        <CardDescription>Top running processes</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="relative">
                            <pre className="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-auto max-h-[500px] text-xs font-mono whitespace-pre">
                                {content || 'No data available'}
                            </pre>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
