import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { RefreshCw, Download, Search, X, Loader2 } from 'lucide-react';

interface SearchLine {
    text: string;
    is_match: boolean;
}

interface SearchBlock {
    lines: SearchLine[];
}

interface SearchResult {
    blocks: SearchBlock[];
    total_matches: number;
    total_lines: number;
    filename: string;
}

type LogType = 'app' | 'testing' | 'realtime';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Logs', href: '/logs/laravel' },
    { title: 'Laravel Log', href: '/logs/laravel' },
]

export default function LaravelLog() {
    const [activeTab, setActiveTab] = useState<LogType>('app');
    const [logContent, setLogContent] = useState('');
    const [filename, setFilename] = useState('');
    const [isLoading, setIsLoading] = useState(true);
    const [autoRefresh, setAutoRefresh] = useState(true);
    const [lines, setLines] = useState(300);
    const [searchQuery, setSearchQuery] = useState('');
    const [searchResult, setSearchResult] = useState<SearchResult | null>(null);
    const [isSearching, setIsSearching] = useState(false);
    const logEndRef = useRef<HTMLDivElement>(null);
    const intervalRef = useRef<NodeJS.Timeout | null>(null);
    const searchDebounceRef = useRef<NodeJS.Timeout | null>(null);

    const runSearch = useCallback(async (query: string) => {
        if (query.length < 2) {
            setSearchResult(null);
            return;
        }

        setIsSearching(true);

        try {
            const response = await fetch(`/api/logs/laravel/search?q=${encodeURIComponent(query)}&context=6&type=${activeTab}`);
            const data: SearchResult = await response.json();
            setSearchResult(data);
        } catch (error) {
            console.error('Error searching log:', error);
        } finally {
            setIsSearching(false);
        }
    }, [activeTab]);

    const handleSearchChange = (value: string) => {
        setSearchQuery(value);

        if (searchDebounceRef.current) {
            clearTimeout(searchDebounceRef.current);
        }

        if (value.length < 2) {
            setSearchResult(null);
            return;
        }

        searchDebounceRef.current = setTimeout(() => {
            runSearch(value);
        }, 400);
    };

    const fetchLog = async () => {
        try {
            const response = await fetch(`/api/logs/laravel?lines=${lines}&type=${activeTab}`);
            const data = await response.json();
            setLogContent(data.content);
            setFilename(data.filename);
            setIsLoading(false);
        } catch (error) {
            console.error('Error fetching log:', error);
            setIsLoading(false);
        }
    };

    const switchTab = (tab: LogType) => {
        if (tab === activeTab) {
            return;
        }

        setActiveTab(tab);
        setSearchQuery('');
        setSearchResult(null);
        setIsLoading(true);
    };

    const scrollToBottom = () => {
        logEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    };

    useEffect(() => {
        fetchLog();
        
        if (autoRefresh) {
            intervalRef.current = setInterval(fetchLog, 5000); // Refresh every 5 seconds
        }

        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, [autoRefresh, activeTab, lines]);

    useEffect(() => {
        if (autoRefresh) {
            scrollToBottom();
        }
    }, [logContent, autoRefresh]);

    const handleDownload = () => {
        const blob = new Blob([logContent], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename || (activeTab === 'app' ? 'laravel.log' : 'laravel-testing.log');
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Laravel Log" />
            <Card>
                <CardHeader>
                    <div className="mb-4 flex items-center gap-2">
                        <Button
                            type="button"
                            variant={activeTab === 'app' ? 'default' : 'outline'}
                            size="sm"
                            onClick={() => switchTab('app')}
                        >
                            Laravel Log
                        </Button>
                        <Button
                            type="button"
                            variant={activeTab === 'testing' ? 'default' : 'outline'}
                            size="sm"
                            onClick={() => switchTab('testing')}
                        >
                            Testing Log
                        </Button>
                        <Button
                            type="button"
                            variant={activeTab === 'realtime' ? 'default' : 'outline'}
                            size="sm"
                            onClick={() => switchTab('realtime')}
                        >
                            Realtime Log
                        </Button>
                    </div>
                    <div className="flex items-center justify-between">
                        <div>
                            <CardTitle>{activeTab === 'app' ? 'Laravel Log' : activeTab === 'testing' ? 'Laravel Testing Log' : 'Realtime Log'}</CardTitle>
                            <CardDescription>
                                {filename || 'Loading...'} — {autoRefresh && 'Auto-refreshing every 5s'}
                                {searchResult && (
                                    <span className="ml-2 text-blue-400">
                                        — {searchResult.total_matches} match{searchResult.total_matches !== 1 ? 'es' : ''} in {searchResult.total_lines?.toLocaleString()} lines for &quot;{searchQuery}&quot;
                                    </span>
                                )}
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
                                <RefreshCw className={`h-4 w-4 mr-2 ${autoRefresh ? 'animate-spin' : ''}`} />
                                {autoRefresh ? 'Auto-refresh ON' : 'Auto-refresh OFF'}
                            </Button>
                            <Button
                                onClick={fetchLog}
                                variant="outline"
                                size="sm"
                            >
                                <RefreshCw className="h-4 w-4 mr-2" />
                                Refresh Now
                            </Button>
                            <Button
                                onClick={handleDownload}
                                variant="outline"
                                size="sm"
                            >
                                <Download className="h-4 w-4 mr-2" />
                                Download
                            </Button>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    {isLoading ? (
                        <div className="text-center py-8 text-muted-foreground">
                            Loading log file...
                        </div>
                    ) : (
                        <div className="relative">
                            <div className="relative mb-3">
                                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                                <Input
                                    value={searchQuery}
                                    onChange={(e) => handleSearchChange(e.target.value)}
                                    placeholder="Search entire log file (2+ characters)..."
                                    className="pl-9 pr-9 font-mono text-sm"
                                />
                                {isSearching && (
                                    <Loader2 className="absolute right-3 top-1/2 -translate-y-1/2 h-4 w-4 animate-spin text-muted-foreground" />
                                )}
                                {!isSearching && searchQuery && (
                                    <button
                                        onClick={() => handleSearchChange('')}
                                        className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                    >
                                        <X className="h-4 w-4" />
                                    </button>
                                )}
                            </div>

                            {searchResult !== null ? (
                                searchResult.blocks.length === 0 ? (
                                    <div className="text-center py-8 text-muted-foreground">
                                        No matches found for &quot;{searchQuery}&quot; in {searchResult.total_lines?.toLocaleString()} lines
                                    </div>
                                ) : (
                                    <div className="bg-gray-900 rounded-lg overflow-auto h-[calc(100vh-320px)] p-4 space-y-2">
                                        {searchResult.blocks.map((block, blockIdx) => (
                                            <div key={blockIdx}>
                                                {blockIdx > 0 && (
                                                    <div className="text-gray-600 text-xs font-mono py-1 select-none">···</div>
                                                )}
                                                {block.lines.map((line, lineIdx) => (
                                                    <div
                                                        key={lineIdx}
                                                        className={`font-mono text-xs whitespace-pre-wrap break-all leading-5 ${
                                                            line.is_match
                                                                ? 'bg-yellow-500/20 text-yellow-200 -mx-4 px-4'
                                                                : 'text-green-400'
                                                        }`}
                                                    >
                                                        {line.text || '\u00A0'}
                                                    </div>
                                                ))}
                                            </div>
                                        ))}
                                    </div>
                                )
                            ) : (
                                <pre className="bg-gray-900 text-green-400 p-4 rounded-lg overflow-auto h-[calc(100vh-320px)] text-xs font-mono">
                                    {logContent || 'No log content available'}
                                    <div ref={logEndRef} />
                                </pre>
                            )}
                        </div>
                    )}
                </CardContent>
            </Card>
        </AppLayout>
    );
}
