import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface AssetInfo {
    id: number;
    symbol: string;
    asset_type: 'stock';
    common_name: string;
    description?: string;
    sector?: string;
}

interface AssetSearchResult {
    id: number;
    symbol: string;
    common_name: string;
    asset_type: 'stock';
}

interface Props {
    assets: AssetInfo[];
    filter: 'stock' | 'all';
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Market Data',
        href: '#',
    },
    {
        title: 'Assets',
        href: '#',
    },
];

export default function AssetInfoIndex({
    assets: initialAssets,
    filter: initialFilter,
}: Props) {
    const { auth } = usePage().props;
    const isAdmin = (auth as { user?: { role?: string } }).user?.role === 'admin';
    
    const [filter, setFilter] = useState<'stock' | 'all'>(
        initialFilter || 'stock',
    );
    const [searchTerm, setSearchTerm] = useState('');
    const [suggestions, setSuggestions] = useState<AssetSearchResult[]>([]);
    const [showSuggestions, setShowSuggestions] = useState(false);
    const [isLoading, setIsLoading] = useState(false);
    const [selectedSymbol, setSelectedSymbol] = useState<string | null>(null);
    const [assets] = useState<AssetInfo[]>(initialAssets); // Remove setAssets since we no longer update this
    // Remove loadingDescriptions state - no longer needed
    const searchRef = useRef<HTMLDivElement>(null);

    // Remove the description fetching useEffect - descriptions now come with initial load

    // Handle clicks outside of search dropdown
    useEffect(() => {
        function handleClickOutside(event: MouseEvent) {
            if (
                searchRef.current &&
                !searchRef.current.contains(event.target as Node)
            ) {
                setShowSuggestions(false);
            }
        }

        document.addEventListener('mousedown', handleClickOutside);
        return () =>
            document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    // Fetch suggestions with debouncing
    useEffect(() => {
        if (!searchTerm.trim()) {
            setSuggestions([]);
            setShowSuggestions(false);
            return;
        }

        const timer = setTimeout(async () => {
            setIsLoading(true);
            try {
                // Always search across all asset types for better UX
                // Users should be able to find any symbol regardless of current filter
                const response = await fetch(
                    `/market-data/assets/search?search=${encodeURIComponent(searchTerm)}&asset_type=all`,
                    { credentials: 'include' },
                );
                const data = await response.json();
                setSuggestions(data);
                setShowSuggestions(true);
            } catch (error) {
                console.error('Failed to fetch suggestions:', error);
            } finally {
                setIsLoading(false);
            }
        }, 300);

        return () => clearTimeout(timer);
    }, [searchTerm]); // Removed filter dependency since we always search 'all'

    const handleSymbolSelect = (symbol: string) => {
        setSelectedSymbol(symbol);
        setSearchTerm(symbol);
        setShowSuggestions(false);
        
        // Find the selected asset to determine its type
        const selectedAsset = suggestions.find((s) => s.symbol === symbol);
        if (selectedAsset) {
            // Automatically switch filter to match the selected asset's type
            // This ensures the selected asset will be visible in the results
            setFilter(selectedAsset.asset_type);
        }
    };

    const handleClearSymbol = () => {
        setSelectedSymbol(null);
        setSearchTerm('');
        setSuggestions([]);
    };

    const handleFilterChange = (newFilter: 'stock' | 'all') => {
        setFilter(newFilter);
        setSelectedSymbol(null);
        setSearchTerm('');
        setSuggestions([]);
        
        // Navigate to the new URL with the filter parameter
        // This will reload the page with the filtered assets from the backend
        router.visit(`/market-data/assets?filter=${newFilter}`, {
            preserveState: false, // Force a full reload to get new assets
        });
    };

    // Filter assets based on type and search
    let displayAssets = assets;

    if (filter !== 'all') {
        displayAssets = displayAssets.filter((a) => a.asset_type === filter);
    }

    if (selectedSymbol) {
        displayAssets = displayAssets.filter(
            (a) => a.symbol === selectedSymbol,
        );
    }

    const totalCount = initialAssets.length;
    const filteredCount = displayAssets.length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Market Data - Assets" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">
                            Market Data Assets
                        </h1>
                        <p className="text-muted-foreground">
                            Stocks and cryptocurrencies tracked in the system
                        </p>
                    </div>
                    {isAdmin && (
                        <Link href="/market-data/assets/add">
                            <Button>Add New Symbol</Button>
                        </Link>
                    )}
                </div>

                {/* Search Bar */}
                <div className="relative" ref={searchRef}>
                    <div className="relative">
                        <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            type="text"
                            placeholder="Search by symbol or name..."
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            className="pr-9 pl-9"
                        />
                        {searchTerm && (
                            <button
                                onClick={handleClearSymbol}
                                className="absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        )}
                    </div>

                    {/* Suggestions Dropdown */}
                    {showSuggestions && (
                        <div className="absolute z-10 mt-1 w-full rounded-md border bg-popover shadow-lg">
                            {isLoading ? (
                                <div className="p-4 text-center text-sm text-muted-foreground">
                                    Loading...
                                </div>
                            ) : suggestions.length > 0 ? (
                                <div className="max-h-60 overflow-y-auto">
                                    {suggestions.map((suggestion) => (
                                        <button
                                            key={suggestion.id}
                                            onClick={() =>
                                                handleSymbolSelect(
                                                    suggestion.symbol,
                                                )
                                            }
                                            className="flex w-full items-center gap-3 border-b px-4 py-2 text-left last:border-b-0 hover:bg-accent"
                                        >
                                            <span className="font-semibold">
                                                {suggestion.symbol}
                                            </span>
                                            <span className="flex-1 text-sm text-muted-foreground">
                                                {suggestion.common_name}
                                            </span>
                                            <Badge
                                                variant={
                                                    suggestion.asset_type ===
                                                    'stock'
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                className="text-xs"
                                            >
                                                {suggestion.asset_type}
                                            </Badge>
                                        </button>
                                    ))}
                                </div>
                            ) : (
                                <div className="p-4 text-center text-sm text-muted-foreground">
                                    No results found
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {/* Filter Buttons */}
                <div className="flex gap-2">
                    <Button
                        variant={filter === 'stock' ? 'default' : 'outline'}
                        onClick={() => handleFilterChange('stock')}
                    >
                        Stocks
                    </Button>
                    <Button
                        variant={filter === 'all' ? 'default' : 'outline'}
                        onClick={() => handleFilterChange('all')}
                    >
                        All ({totalCount})
                    </Button>
                </div>

                {/* Results Summary */}
                <div className="text-sm text-muted-foreground">
                    Showing {filteredCount} of {totalCount} assets
                </div>

                {/* Assets Grid */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    {displayAssets.map((asset: AssetInfo) => (
                        <Link
                            key={asset.id}
                            href={`/market-data/assets/${asset.id}`}
                            className="block"
                        >
                            <div className="rounded-lg border bg-card p-4 transition-colors hover:bg-accent">
                                <div className="mb-2 flex items-center justify-between">
                                    <span className="text-xl font-bold">
                                        {asset.symbol}
                                    </span>
                                    <Badge
                                        variant={
                                            asset.asset_type === 'stock'
                                                ? 'default'
                                                : 'outline'
                                        }
                                    >
                                        {asset.asset_type}
                                    </Badge>
                                </div>
                                <h3 className="mb-2 font-semibold text-card-foreground">
                                    {asset.common_name}
                                </h3>
                                {asset.sector && (
                                    <div className="mb-2">
                                        <Badge
                                            variant="secondary"
                                            className="text-xs"
                                        >
                                            {asset.sector}
                                        </Badge>
                                    </div>
                                )}

                                {/* Description - now loaded with initial data */}
                                <p className="line-clamp-3 text-sm text-muted-foreground">
                                    {asset.description || 'No description available'}
                                </p>
                            </div>
                        </Link>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
