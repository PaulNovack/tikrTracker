import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Database, Key, Search, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'System', href: '/mysql-health' },
    { title: 'Redis Keys', href: '/redis-keys' },
];

type KeyGroup = {
    prefix: string;
    total: number;
    types: Record<string, number>;
    sample_keys: string[];
};

type Summary = {
    total_keys: number;
    groups: KeyGroup[];
    error?: string;
};

type KeyInfo = {
    key: string;
    type: string;
    ttl: number;
    value: unknown;
    size: number;
};

interface Props {
    summary: Summary;
    lastUpdated: string;
}

export default function RedisKeys({ summary, lastUpdated }: Props) {
    const [searchPattern, setSearchPattern] = useState('');
    const [searchResults, setSearchResults] = useState<KeyInfo[] | null>(null);
    const [selectedKey, setSelectedKey] = useState<KeyInfo | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const fetchKeyValue = useCallback(async (key: string) => {
        setLoading(true);
        setError(null);
        setSelectedKey(null);

        try {
            const res = await fetch(`/redis-keys/show?key=${encodeURIComponent(key)}`);
            const data = await res.json();

            if (data.error) {
                setError(data.error);
            } else {
                setSelectedKey(data);
            }
        } catch {
            setError('Failed to fetch key value.');
        } finally {
            setLoading(false);
        }
    }, []);

    const handleSearch = useCallback(async () => {
        if (!searchPattern.trim()) return;

        setLoading(true);
        setError(null);
        setSearchResults(null);

        try {
            const res = await fetch(`/redis-keys/search?pattern=${encodeURIComponent(searchPattern.trim())}`);
            const data = await res.json();

            if (data.error) {
                setError(data.error);
            } else {
                setSearchResults(data.keys);
            }
        } catch {
            setError('Failed to search keys.');
        } finally {
            setLoading(false);
        }
    }, [searchPattern]);

    const handleDelete = useCallback(async (key: string) => {
        if (!confirm(`Delete key "${key}"? This cannot be undone.`)) return;

        setLoading(true);
        try {
            const res = await fetch('/redis-keys/destroy', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ key }),
            });
            const data = await res.json();

            if (data.deleted) {
                router.reload();
            } else if (data.error) {
                setError(data.error);
            }
        } catch {
            setError('Failed to delete key.');
        } finally {
            setLoading(false);
        }
    }, []);

    const typeColor = (type: string) => {
        switch (type) {
            case 'hash': return 'bg-blue-500';
            case 'string': return 'bg-green-500';
            case 'list': return 'bg-yellow-500';
            case 'set': return 'bg-purple-500';
            case 'zset': return 'bg-orange-500';
            default: return 'bg-gray-400';
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Redis Keys" />

            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <HeadingSmall
                        title="Redis Keys"
                        description={`${summary.total_keys.toLocaleString()} keys across ${summary.groups.length} prefix groups · Updated ${lastUpdated}`}
                    />
                    <div className="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                        <Database className="h-4 w-4" />
                        {summary.total_keys.toLocaleString()} total keys
                    </div>
                </div>

                {summary.error && (
                    <div className="rounded-md bg-red-50 p-4 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-400">
                        Error: {summary.error}
                    </div>
                )}

                {/* Search */}
                <div className="flex items-center gap-3">
                    <div className="relative flex-1 max-w-md">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                        <Input
                            className="pl-9"
                            placeholder="Search pattern (e.g. *market*, laravel_cache:*)"
                            value={searchPattern}
                            onChange={(e) => setSearchPattern(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                        />
                    </div>
                    <Button variant="outline" onClick={handleSearch} disabled={loading || !searchPattern.trim()}>
                        Search
                    </Button>
                </div>

                {/* Search Results */}
                {searchResults !== null && (
                    <div className="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                        <h3 className="mb-3 text-sm font-semibold text-gray-700 dark:text-gray-300">
                            Search Results ({searchResults.length} keys)
                        </h3>
                        {searchResults.length === 0 ? (
                            <p className="text-sm text-gray-400">No keys matched.</p>
                        ) : (
                            <div className="max-h-80 overflow-y-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-gray-100 dark:border-gray-800">
                                            <th className="pb-2 text-left font-medium text-gray-500">Key</th>
                                            <th className="pb-2 text-center font-medium text-gray-500">Type</th>
                                            <th className="pb-2 text-center font-medium text-gray-500">Size</th>
                                            <th className="pb-2 text-center font-medium text-gray-500">TTL</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-50 dark:divide-gray-800">
                                        {searchResults.map((k) => (
                                            <tr
                                                key={k.key}
                                                className="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800"
                                                onClick={() => fetchKeyValue(k.key)}
                                            >
                                                <td className="py-2 pr-4 font-mono text-xs text-gray-800 dark:text-gray-200 break-all">
                                                    {k.key}
                                                </td>
                                                <td className="py-2 text-center">
                                                    <Badge className={`text-xs text-white ${typeColor(k.type)}`}>
                                                        {k.type}
                                                    </Badge>
                                                </td>
                                                <td className="py-2 text-center text-gray-500">{k.size}</td>
                                                <td className="py-2 text-center text-gray-500">
                                                    {k.ttl === -1 ? '∞' : k.ttl === -2 ? '—' : `${k.ttl}s`}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}

                {/* Key Groups */}
                <div className="grid gap-4">
                    {summary.groups.map((group) => (
                        <div
                            key={group.prefix}
                            className="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
                        >
                            <div className="flex items-center justify-between mb-3">
                                <div className="flex items-center gap-3">
                                    <Key className="h-4 w-4 text-blue-500" />
                                    <span className="font-semibold text-gray-900 dark:text-gray-100">
                                        {group.prefix}
                                    </span>
                                    <Badge variant="secondary" className="text-xs">
                                        {group.total} keys
                                    </Badge>
                                </div>
                                <div className="flex items-center gap-2">
                                    {Object.entries(group.types).map(([type, count]) => (
                                        <Badge key={type} className={`text-xs text-white ${typeColor(type)}`}>
                                            {type}: {count}
                                        </Badge>
                                    ))}
                                </div>
                            </div>

                            <div className="space-y-1">
                                {group.sample_keys.map((key) => (
                                    <div key={key} className="flex items-center justify-between group">
                                        <button
                                            type="button"
                                            className="font-mono text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 text-left break-all"
                                            onClick={() => fetchKeyValue(key)}
                                        >
                                            {key}
                                        </button>
                                        <button
                                            type="button"
                                            className="ml-2 p-1 text-red-400 opacity-0 group-hover:opacity-100 hover:text-red-600 transition-opacity"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                handleDelete(key);
                                            }}
                                            title="Delete key"
                                        >
                                            <Trash2 className="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>

                {/* Key Value Viewer */}
                {loading && (
                    <div className="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                        <p className="text-sm text-gray-400">Loading...</p>
                    </div>
                )}

                {error && (
                    <div className="rounded-md bg-red-50 p-4 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-400">
                        {error}
                    </div>
                )}

                {selectedKey && (
                    <div className="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                        <div className="flex items-center justify-between mb-3">
                            <div className="flex items-center gap-3">
                                <h3 className="font-semibold text-gray-900 dark:text-gray-100">Key Viewer</h3>
                                <Badge className={`text-xs text-white ${typeColor(selectedKey.type)}`}>
                                    {selectedKey.type}
                                </Badge>
                                <span className="text-xs text-gray-400">
                                    Size: {selectedKey.size} · TTL: {selectedKey.ttl === -1 ? 'no expiry' : selectedKey.ttl === -2 ? 'expired' : `${selectedKey.ttl}s`}
                                </span>
                            </div>
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => handleDelete(selectedKey.key)}
                                    className="text-red-500 hover:text-red-700"
                                >
                                    <Trash2 className="h-3.5 w-3.5 mr-1" /> Delete
                                </Button>
                                <Button variant="ghost" size="sm" onClick={() => setSelectedKey(null)}>
                                    Close
                                </Button>
                            </div>
                        </div>

                        <Separator className="my-3" />

                        <p className="mb-2 font-mono text-xs text-gray-500 dark:text-gray-400 break-all">
                            {selectedKey.key}
                        </p>

                        <pre className="max-h-96 overflow-auto rounded-md bg-gray-50 p-4 text-xs text-gray-800 dark:bg-gray-800 dark:text-gray-200">
                            {JSON.stringify(selectedKey.value, null, 2)}
                        </pre>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
