import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { RefreshCw, Download, Search } from 'lucide-react';

interface VersionInfo {
    letter: string;
    version: string;
}

interface LogData {
    content: string | null;
    exists: boolean;
    filename: string;
    size: number;
    date: string;
}

interface PageProps {
    versions: VersionInfo[];
    [key: string]: unknown;
}

const ALL_TAB = 'all' as const;
type TabId = typeof ALL_TAB | string;

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Logs', href: '/logs/laravel' },
    { title: 'Bar Events', href: '/logs/bar-events' },
];

export default function BarEvents() {
    const { versions } = usePage<PageProps>().props;

    const [data, setData] = useState<LogData | null>(null);
    const [activeTab, setActiveTab] = useState<TabId>(ALL_TAB);
    const [isLoading, setIsLoading] = useState(true);
    const [autoRefresh, setAutoRefresh] = useState(true);
    const [lines, setLines] = useState(500);
    const [searchQuery, setSearchQuery] = useState('');
    const logEndRef = useRef<HTMLDivElement>(null);
    const intervalRef = useRef<NodeJS.Timeout | null>(null);

    const fetchLogs = async () => {
        try {
            const response = await fetch(`/api/logs/bar-events?lines=${lines}`);
            const json: LogData = await response.json();
            setData(json);
            setIsLoading(false);
        } catch (error) {
            console.error('Error fetching bar events log:', error);
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
        if (!data?.content) return;
        const blob = new Blob([filteredContent], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `bar-events-${activeTab}.log`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    };

    const filteredContent = useMemo(() => {
        if (!data?.content) return '';

        let contentLines = data.content.split('\n');

        if (activeTab !== ALL_TAB) {
            const version = versions.find((v) => v.letter === activeTab)?.version ?? '';
            const escapedVersion = version.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

            contentLines = contentLines.filter((line) => {
                // Match [Scanner vX.Y] pattern for this version
                if (new RegExp(`\\[Scanner\\s+${escapedVersion}\\]`, 'i').test(line)) return true;
                // Match BarConsumer with version in JSON payload
                if (line.includes(`"version":"${version}"`)) return true;
                // Match pipeline letter
                if (line.includes(`pipelineLetter":"${activeTab.toUpperCase()}"`)) return true;
                return false;
            });
        }

        if (searchQuery.trim()) {
            const q = searchQuery.toLowerCase();
            contentLines = contentLines.filter((line) => line.toLowerCase().includes(q));
        }

        return contentLines.join('\n');
    }, [data, activeTab, searchQuery, versions]);

    const lineCount = useMemo(() => {
        return filteredContent ? filteredContent.split('\n').filter(Boolean).length : 0;
    }, [filteredContent]);

    const getTabLabel = (v: VersionInfo): string => {
        return `${v.letter} · ${v.version}`;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bar Events Logs" />
            <Card>
                <CardHeader>
                    <div className="flex flex-col gap-3">
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle>Bar Events Logs</CardTitle>
                                <CardDescription>
                                    {data
                                        ? `${data.date} — redis-scan channel`
                                        : 'Loading...'}
                                    {data?.exists && ` · ${formatBytes(data.size)}`}
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
                                <Button onClick={handleDownload} variant="outline" size="sm" disabled={!data?.exists}>
                                    <Download className="mr-2 h-4 w-4" />
                                    Download
                                </Button>
                            </div>
                        </div>

                        {/* Search */}
                        <div className="relative">
                            <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <input
                                type="text"
                                placeholder="Filter lines... (e.g. WOLF, stale, signal found)"
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                className="w-full rounded-md border border-input bg-background py-1.5 pl-9 pr-3 text-sm"
                            />
                            {searchQuery && (
                                <span className="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">
                                    {lineCount} matches
                                </span>
                            )}
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    {isLoading ? (
                        <div className="py-8 text-center text-muted-foreground">Loading logs…</div>
                    ) : (
                        <>
                            {/* Version tabs */}
                            <div className="mb-3 flex flex-wrap gap-2">
                                <button
                                    onClick={() => setActiveTab(ALL_TAB)}
                                    className={[
                                        'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                        activeTab === ALL_TAB
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-muted text-muted-foreground hover:bg-muted/80',
                                    ].join(' ')}
                                >
                                    All Versions
                                </button>
                                {versions.map((v) => (
                                    <button
                                        key={v.letter}
                                        onClick={() => setActiveTab(v.letter)}
                                        className={[
                                            'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                            activeTab === v.letter
                                                ? 'bg-primary text-primary-foreground'
                                                : 'bg-muted text-muted-foreground hover:bg-muted/80',
                                        ].join(' ')}
                                    >
                                        {getTabLabel(v)}
                                    </button>
                                ))}
                            </div>

                            {/* Log content */}
                            <div className="relative">
                                {data?.exists ? (
                                    <>
                                        <div className="mb-2 text-xs text-muted-foreground">
                                            Showing {lineCount} lines
                                            {activeTab !== ALL_TAB && ` for ${getTabLabel(versions.find((v) => v.letter === activeTab)!)}`}
                                            {searchQuery && ` matching "${searchQuery}"`}
                                        </div>
                                        <pre className="max-h-[700px] overflow-auto rounded-lg bg-gray-900 p-4 font-mono text-xs text-green-400">
                                            {filteredContent || 'No matching lines.'}
                                            <div ref={logEndRef} />
                                        </pre>
                                    </>
                                ) : (
                                    <div className="flex h-40 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                        No log file found for {data?.date}
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
