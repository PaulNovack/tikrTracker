import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { RefreshCw, Download } from 'lucide-react';

const PIPELINES = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q'] as const;
type Pipeline = (typeof PIPELINES)[number];

interface PipelineLog {
    content: string | null;
    exists: boolean;
    filename: string;
    size: number;
}

interface ApiResponse {
    pipelines: Record<Pipeline, PipelineLog>;
    date: string;
}

const PIPELINE_LABELS: Record<Pipeline, string> = {
    a: 'A',
    b: 'B',
    c: 'C',
    d: 'D',
    e: 'E',
    f: 'F',
    g: 'G',
    h: 'H',
    i: 'I',
    j: 'J',
    k: 'K',
    l: 'L',
    m: 'M',
    n: 'N',
    o: 'O',
    p: 'P',
    q: 'Q',
};

const PIPELINE_DISPLAY_NAMES: Record<Pipeline, string> = {
    a: 'A — Momentum Continuation',
    b: 'B — Elite Multi-Day Momentum',
    c: 'C — Hybrid Big-Move Breakout',
    d: 'D — Hybrid Breakout',
    e: 'E — Multi-Day Pattern Continuation',
    f: 'F — Risk-Off Winners',
    g: 'G — Oversold Bounce',
    h: 'H — Quality-First',
    i: 'I — Base Pattern',
    j: 'J — Market Movers Universe',
    k: 'K — Scarcity Leader (RS vs SPY)',
    l: 'L — Early Momentum Pre-Breakout',
    m: 'M — Tight Stops Clean Trend',
    n: 'N — Two-Bar Momentum',
    o: 'O — Opening Range Breakout',
    p: 'P — Biased Forward-Looking 5-Hour',
    q: 'Q — Volume-First',
};

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Logs', href: '/logs/laravel' },
    { title: 'Continuous Backtest Logs', href: '/logs/continuous-bt' },
]

export default function ContinuousBT() {
    const [data, setData] = useState<ApiResponse | null>(null);
    const [activeTab, setActiveTab] = useState<Pipeline>('a');
    const [isLoading, setIsLoading] = useState(true);
    const [autoRefresh, setAutoRefresh] = useState(true);
    const [lines, setLines] = useState(300);
    const logEndRef = useRef<HTMLDivElement>(null);
    const intervalRef = useRef<NodeJS.Timeout | null>(null);

    const fetchLogs = async () => {
        try {
            const response = await fetch(`/api/logs/continuous-bt?lines=${lines}`);
            const json: ApiResponse = await response.json();
            setData(json);
            setIsLoading(false);
        } catch (error) {
            console.error('Error fetching backtest logs:', error);
            setIsLoading(false);
        }
    };

    useEffect(() => {
        fetchLogs();

        if (autoRefresh) {
            intervalRef.current = setInterval(fetchLogs, 5000);
        }

        return () => {
            if (intervalRef.current) clearInterval(intervalRef.current);
        };
    }, [autoRefresh, lines]);

    useEffect(() => {
        if (autoRefresh) {
            logEndRef.current?.scrollIntoView({ behavior: 'smooth' });
        }
    }, [data, activeTab, autoRefresh]);

    const handleDownload = () => {
        const pipelineLog = data?.pipelines[activeTab];
        if (!pipelineLog?.content) return;
        const blob = new Blob([pipelineLog.content], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = pipelineLog.filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    };

    const activePipelineLog = data?.pipelines[activeTab];
    const existingPipelines = data ? PIPELINES.filter((p) => data.pipelines[p].exists) : [];
    const missingPipelines = data ? PIPELINES.filter((p) => !data.pipelines[p].exists) : [];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Continuous BT Logs" />
            <Card>
                <CardHeader>
                    <div className="flex items-center justify-between">
                        <div>
                            <CardTitle>Continuous Backtest Logs</CardTitle>
                            <CardDescription>
                                {data ? `${data.date} — ` : ''}
                                {existingPipelines.length}/{PIPELINES.length} pipelines running
                                {autoRefresh && ' · Auto-refreshing every 5s'}
                            </CardDescription>
                        </div>
                        <div className="flex gap-2">
                            <select
                                className="rounded-md border border-input bg-background px-3 py-1.5 text-sm"
                                value={lines}
                                onChange={(e) => setLines(Number(e.target.value))}
                            >
                                <option value={100}>100 lines</option>
                                <option value={300}>300 lines</option>
                                <option value={500}>500 lines</option>
                                <option value={1000}>1000 lines</option>
                            </select>
                            <Button
                                onClick={() => setAutoRefresh(!autoRefresh)}
                                variant={autoRefresh ? 'default' : 'outline'}
                                size="sm"
                            >
                                <RefreshCw className={`mr-2 h-4 w-4 ${autoRefresh ? 'animate-spin' : ''}`} />
                                {autoRefresh ? 'Auto ON' : 'Auto OFF'}
                            </Button>
                            <Button onClick={fetchLogs} variant="outline" size="sm">
                                <RefreshCw className="mr-2 h-4 w-4" />
                                Refresh
                            </Button>
                            <Button onClick={handleDownload} variant="outline" size="sm" disabled={!activePipelineLog?.exists}>
                                <Download className="mr-2 h-4 w-4" />
                                Download
                            </Button>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    {isLoading ? (
                        <div className="py-8 text-center text-muted-foreground">Loading logs…</div>
                    ) : (
                        <>
                            {/* Pipeline tabs */}
                            <div className="mb-3 flex flex-wrap gap-2">
                                {PIPELINES.map((p) => {
                                    const log = data?.pipelines[p];
                                    const exists = log?.exists ?? false;
                                    return (
                                        <button
                                            key={p}
                                            onClick={() => setActiveTab(p)}
                                            className={[
                                                'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                                activeTab === p
                                                    ? 'bg-primary text-primary-foreground'
                                                    : exists
                                                      ? 'bg-muted text-muted-foreground hover:bg-muted/80'
                                                      : 'cursor-not-allowed bg-muted/40 text-muted-foreground/40',
                                            ].join(' ')}
                                        >
                                            {PIPELINE_DISPLAY_NAMES[p]}
                                            {exists && log?.size ? (
                                                <span className="ml-1.5 text-xs opacity-60">{formatBytes(log.size)}</span>
                                            ) : (
                                                <span className="ml-1.5 text-xs opacity-40">no log</span>
                                            )}
                                        </button>
                                    );
                                })}
                            </div>

                            {/* Missing pipelines notice */}
                            {missingPipelines.length > 0 && (
                                <p className="mb-3 text-xs text-muted-foreground">
                                    No log file for today: {missingPipelines.map((p) => PIPELINE_LABELS[p]).join(', ')}
                                </p>
                            )}

                            {/* Log content */}
                            <div className="relative">
                                {activePipelineLog?.exists ? (
                                    <pre className="max-h-[700px] overflow-auto rounded-lg bg-gray-900 p-4 font-mono text-xs text-green-400">
                                        {activePipelineLog.content || 'Log file is empty.'}
                                        <div ref={logEndRef} />
                                    </pre>
                                ) : (
                                    <div className="flex h-40 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                        No log file found for {PIPELINE_LABELS[activeTab]} on {data?.date}
                                    </div>
                                )}
                            </div>
                        </>
                    )}
                </CardContent>
            </Card>
        </AppLayout>
    );
}
