import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { type BreadcrumbItem } from '@/types';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { RefreshCw, Download } from 'lucide-react';

// ─── Line parsers ────────────────────────────────────────────────────────────

interface ParsedToken {
    text: string;
    className: string;
}

/** bar-stream.log: "2026-05-13 08:13:00 [stream_bars] INFO Flushed 22 bars (total flushed: 437, total errors: 0)" */
function parseBarStreamLine(line: string): ParsedToken[] {
    const m = line.match(
        /^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+(\[\w+\])\s+(INFO|WARN|WARNING|ERROR|DEBUG)\s+(.*)/,
    );
    if (!m) return [{ text: line, className: 'text-gray-400' }];
    const [, ts, tag, level, rest] = m;
    const levelClass =
        level === 'ERROR' ? 'text-red-400 font-bold' :
        level === 'WARN' || level === 'WARNING' ? 'text-yellow-400' :
        'text-green-300';

    // Highlight numbers in the rest
    const restTokens: ParsedToken[] = [];
    const parts = rest.split(/(\b\d+\b)/);
    parts.forEach((p, i) =>
        restTokens.push({ text: p, className: i % 2 === 1 ? 'text-cyan-300 font-semibold' : 'text-gray-200' }),
    );

    return [
        { text: ts + ' ', className: 'text-gray-500' },
        { text: tag + ' ', className: 'text-blue-400' },
        { text: level + ' ', className: levelClass },
        ...restTokens,
    ];
}

/** pipeline-watcher.log: "New bar detected @ 2026-05-13 13:13:00 — firing pipelines…" */
function parsePipelineWatcherLine(line: string): ParsedToken[] {
    const m = line.match(/^(New bar detected @)\s+(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+(.*)/);
    if (m) {
        const [, prefix, ts, suffix] = m;
        return [
            { text: prefix + ' ', className: 'text-green-400 font-semibold' },
            { text: ts + ' ', className: 'text-cyan-300' },
            { text: suffix, className: 'text-gray-300' },
        ];
    }
    // ERROR lines
    if (/error/i.test(line)) return [{ text: line, className: 'text-red-400' }];
    return [{ text: line, className: 'text-gray-400' }];
}

function ParsedLine({ line, parser }: { line: string; parser: (l: string) => ParsedToken[] }) {
    const tokens = parser(line);
    return (
        <div className="leading-5">
            {tokens.map((t, i) => (
                <span key={i} className={t.className}>{t.text}</span>
            ))}
        </div>
    );
}

interface StreamLog {
    content: string | null;
    exists: boolean;
    filename: string;
    size: number;
}

interface ApiResponse {
    bar_stream: StreamLog;
    pipeline_watcher: StreamLog;
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Logs', href: '/logs/laravel' },
    { title: 'Streaming Daemons', href: '/logs/streaming' },
]

export default function StreamingLog() {
    const [data, setData] = useState<ApiResponse | null>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [autoRefresh, setAutoRefresh] = useState(true);
    const [lines, setLines] = useState(300);
    const barStreamEndRef = useRef<HTMLDivElement>(null);
    const pipelineWatcherEndRef = useRef<HTMLDivElement>(null);
    const intervalRef = useRef<NodeJS.Timeout | null>(null);

    const fetchLogs = async () => {
        try {
            const response = await fetch(`/api/logs/streaming?lines=${lines}`);
            const json: ApiResponse = await response.json();
            setData(json);
            setIsLoading(false);
        } catch (error) {
            console.error('Error fetching streaming logs:', error);
            setIsLoading(false);
        }
    };

    useEffect(() => {
        fetchLogs();

        if (autoRefresh) {
            intervalRef.current = setInterval(fetchLogs, 5000);
        }

        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, [autoRefresh, lines]);

    useEffect(() => {
        if (autoRefresh) {
            barStreamEndRef.current?.scrollIntoView({ behavior: 'smooth' });
            pipelineWatcherEndRef.current?.scrollIntoView({ behavior: 'smooth' });
        }
    }, [data, autoRefresh]);

    const handleDownload = (key: 'bar_stream' | 'pipeline_watcher') => {
        const log = data?.[key];
        if (!log?.content) return;
        const blob = new Blob([log.content], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = log.filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    };

    const panes: {
        key: 'bar_stream' | 'pipeline_watcher';
        label: string;
        headerClass: string;
        parser: (l: string) => ParsedToken[];
        endRef: React.RefObject<HTMLDivElement | null>;
    }[] = [
        { key: 'bar_stream', label: 'Bar Stream', headerClass: 'text-blue-400', parser: parseBarStreamLine, endRef: barStreamEndRef },
        { key: 'pipeline_watcher', label: 'Pipeline Watcher', headerClass: 'text-green-400', parser: parsePipelineWatcherLine, endRef: pipelineWatcherEndRef },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Streaming Daemons" />
            <div className="space-y-4">
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle>Streaming Daemons</CardTitle>
                                <CardDescription>
                                    bar-stream.log &amp; pipeline-watcher.log — {autoRefresh ? 'Auto-refreshing every 5s' : 'Auto-refresh OFF'}
                                </CardDescription>
                            </div>
                            <div className="flex items-center gap-2">
                                <select
                                    value={lines}
                                    onChange={(e) => setLines(Number(e.target.value))}
                                    className="rounded border border-input bg-background px-2 py-1 text-sm"
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
                                    {autoRefresh ? 'Auto-refresh ON' : 'Auto-refresh OFF'}
                                </Button>
                                <Button onClick={fetchLogs} variant="outline" size="sm">
                                    <RefreshCw className="mr-2 h-4 w-4" />
                                    Refresh Now
                                </Button>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {isLoading ? (
                            <div className="py-8 text-center text-muted-foreground">Loading log files...</div>
                        ) : (
                            <div className="grid grid-cols-2 gap-4">
                                {panes.map(({ key, label, headerClass, parser, endRef }) => {
                                    const log = data?.[key];
                                    const logLines = log?.content ? log.content.split('\n') : [];
                                    return (
                                        <div key={key} className="flex flex-col gap-2">
                                            <div className="flex flex-shrink-0 items-center justify-between">
                                                <div>
                                                    <span className={`font-mono text-sm font-semibold ${headerClass}`}>{label}</span>
                                                    {log?.exists && (
                                                        <span className="ml-2 text-xs text-muted-foreground">
                                                            {log.filename} · {formatBytes(log.size)}
                                                        </span>
                                                    )}
                                                </div>
                                                <Button
                                                    onClick={() => handleDownload(key)}
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={!log?.exists}
                                                >
                                                    <Download className="h-3 w-3" />
                                                </Button>
                                            </div>
                                            <div className="h-[calc(100vh-320px)] overflow-auto rounded-lg bg-gray-900 p-4 font-mono text-xs">
                                                {log?.exists ? (
                                                    logLines.length > 0 ? (
                                                        logLines.map((line, i) => (
                                                            <ParsedLine key={i} line={line} parser={parser} />
                                                        ))
                                                    ) : (
                                                        <span className="text-gray-500">Empty log file</span>
                                                    )
                                                ) : (
                                                    <span className="text-red-400">Log file not found: {log?.filename}</span>
                                                )}
                                                <div ref={endRef} />
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
