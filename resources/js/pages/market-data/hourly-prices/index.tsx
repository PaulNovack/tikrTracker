import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface AssetSymbol {
    symbol: string;
    name: string;
    asset_type: 'stock';
}

interface HourlyPrice {
    id: number;
    symbol: string;
    asset_type: 'stock';
    ts: string;
    price: string;
    volume: number;
}

interface PaginatedData {
    data: HourlyPrice[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Props {
    prices: PaginatedData;
    filters: {
        symbol?: string;
        asset_type?: string;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Market Data',
        href: '#',
    },
    {
        title: 'Hourly Prices',
        href: '#',
    },
];

export default function HourlyPricesIndex({ prices, filters }: Props) {
    const [searchTerm, setSearchTerm] = useState(filters.symbol || '');
    const [suggestions, setSuggestions] = useState<AssetSymbol[]>([]);
    const [showSuggestions, setShowSuggestions] = useState(false);
    const [isLoading, setIsLoading] = useState(false);
    const searchRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const handleClickOutside = (event: MouseEvent) => {
            if (
                searchRef.current &&
                !searchRef.current.contains(event.target as Node)
            ) {
                setShowSuggestions(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);
        return () =>
            document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    useEffect(() => {
        if (searchTerm.length < 1) {
            setSuggestions([]);
            return;
        }

        const debounce = setTimeout(() => {
            fetchSymbols(searchTerm);
        }, 300);

        return () => clearTimeout(debounce);
    }, [searchTerm, filters.asset_type]);

    const fetchSymbols = async (search: string) => {
        setIsLoading(true);
        try {
            const params = new URLSearchParams({
                search,
                ...(filters.asset_type && filters.asset_type !== 'all'
                    ? { asset_type: filters.asset_type }
                    : {}),
            });
            const response = await fetch(
                `/market-data/hourly-prices/symbols?${params}`,
            );
            const data = await response.json();
            setSuggestions(data);
            setShowSuggestions(true);
        } catch (error) {
            console.error('Failed to fetch symbols:', error);
        } finally {
            setIsLoading(false);
        }
    };

    const handleFilterChange = (assetType: string | null) => {
        router.get(
            '/market-data/hourly-prices',
            { asset_type: assetType },
            { preserveState: true, preserveScroll: true },
        );
        setSearchTerm('');
    };

    const handlePageChange = (page: number) => {
        router.get(
            '/market-data/hourly-prices',
            { asset_type: filters.asset_type, symbol: filters.symbol, page },
            { preserveState: true, preserveScroll: false },
        );
    };

    const handleSymbolSelect = (symbol: string) => {
        setSearchTerm(symbol);
        setShowSuggestions(false);
        router.get(
            '/market-data/hourly-prices',
            { asset_type: filters.asset_type, symbol },
            { preserveState: true, preserveScroll: true },
        );
    };

    const handleClearSymbol = () => {
        setSearchTerm('');
        router.get(
            '/market-data/hourly-prices',
            { asset_type: filters.asset_type },
            { preserveState: true, preserveScroll: true },
        );
    };

    const handleRowClick = (symbol: string) => {
        setSearchTerm(symbol);
        router.get(
            '/market-data/hourly-prices',
            { asset_type: filters.asset_type, symbol },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Hourly Prices" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Hourly Prices
                    </h1>
                    <p className="text-muted-foreground">
                        Intraday hourly price data for stocks and
                        cryptocurrencies
                    </p>
                </div>

                {/* Filter Buttons */}
                <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div className="flex gap-2">
                        <Button
                            variant={
                                filters.asset_type === 'stock'
                                    ? 'default'
                                    : 'outline'
                            }
                            onClick={() => handleFilterChange('stock')}
                        >
                            Stocks
                        </Button>
                        <Button
                            variant={
                                filters.asset_type === 'all'
                                    ? 'default'
                                    : 'outline'
                            }
                            onClick={() => handleFilterChange('all')}
                        >
                            All
                        </Button>
                    </div>

                    {/* Symbol Search */}
                    <div className="relative w-full md:w-80" ref={searchRef}>
                        <div className="relative">
                            <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                type="text"
                                placeholder="Search by symbol or name..."
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                onFocus={() =>
                                    searchTerm.length > 0 &&
                                    setShowSuggestions(true)
                                }
                                className="pr-10 pl-10"
                            />
                            {searchTerm && (
                                <button
                                    type="button"
                                    onClick={handleClearSymbol}
                                    className="absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                >
                                    <X className="h-4 w-4" />
                                </button>
                            )}
                        </div>

                        {/* Suggestions Dropdown */}
                        {showSuggestions && suggestions.length > 0 && (
                            <div className="absolute z-50 mt-1 max-h-80 w-full overflow-auto rounded-md border bg-popover shadow-md">
                                {suggestions.map((item) => (
                                    <button
                                        key={item.symbol}
                                        type="button"
                                        onClick={() =>
                                            handleSymbolSelect(item.symbol)
                                        }
                                        className="flex w-full items-center justify-between gap-2 border-b px-4 py-3 text-left last:border-0 hover:bg-accent"
                                    >
                                        <div className="flex-1">
                                            <div className="font-semibold">
                                                {item.symbol}
                                            </div>
                                            <div className="text-sm text-muted-foreground">
                                                {item.name}
                                            </div>
                                        </div>
                                        <Badge
                                            variant={
                                                item.asset_type === 'stock'
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                        >
                                            {item.asset_type}
                                        </Badge>
                                    </button>
                                ))}
                            </div>
                        )}

                        {showSuggestions && isLoading && (
                            <div className="absolute z-50 mt-1 w-full rounded-md border bg-popover p-4 text-center text-sm text-muted-foreground shadow-md">
                                Loading...
                            </div>
                        )}

                        {showSuggestions &&
                            !isLoading &&
                            searchTerm.length > 0 &&
                            suggestions.length === 0 && (
                                <div className="absolute z-50 mt-1 w-full rounded-md border bg-popover p-4 text-center text-sm text-muted-foreground shadow-md">
                                    No symbols found
                                </div>
                            )}
                    </div>
                </div>

                <div className="rounded-lg border bg-card">
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead>
                                <tr className="border-b bg-muted/50 text-left text-sm">
                                    <th className="p-3 font-medium">Symbol</th>
                                    <th className="p-3 font-medium">Type</th>
                                    <th className="p-3 font-medium">
                                        Timestamp
                                    </th>
                                    <th className="p-3 text-right font-medium">
                                        Price
                                    </th>
                                    <th className="p-3 text-right font-medium">
                                        Volume
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {prices.data.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="p-12 text-center text-muted-foreground"
                                        >
                                            No hourly price data available
                                        </td>
                                    </tr>
                                ) : (
                                    prices.data.map((price) => (
                                        <tr
                                            key={price.id}
                                            onClick={() =>
                                                handleRowClick(price.symbol)
                                            }
                                            className="cursor-pointer border-b transition-colors last:border-0 hover:bg-muted/50"
                                        >
                                            <td className="p-3 font-semibold">
                                                {price.symbol}
                                            </td>
                                            <td className="p-3">
                                                <Badge
                                                    variant={
                                                        price.asset_type ===
                                                        'stock'
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                >
                                                    {price.asset_type}
                                                </Badge>
                                            </td>
                                            <td className="p-3">
                                                {new Date(
                                                    price.ts,
                                                ).toLocaleString()}
                                            </td>
                                            <td className="p-3 text-right font-semibold">
                                                {price.price
                                                    ? `$${parseFloat(price.price).toFixed(2)}`
                                                    : '—'}
                                            </td>
                                            <td className="p-3 text-right">
                                                {price.volume?.toLocaleString()}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {prices.last_page > 1 && (
                    <div className="flex items-center justify-between gap-4">
                        <div className="text-sm text-muted-foreground">
                            Showing{' '}
                            {(prices.current_page - 1) * prices.per_page + 1} to{' '}
                            {Math.min(
                                prices.current_page * prices.per_page,
                                prices.total,
                            )}{' '}
                            of {prices.total} prices
                        </div>
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    handlePageChange(prices.current_page - 1)
                                }
                                disabled={prices.current_page === 1}
                            >
                                <ChevronLeft className="h-4 w-4" />
                                Previous
                            </Button>
                            <div className="text-sm text-muted-foreground">
                                Page {prices.current_page} of {prices.last_page}
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    handlePageChange(prices.current_page + 1)
                                }
                                disabled={
                                    prices.current_page === prices.last_page
                                }
                            >
                                Next
                                <ChevronRight className="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
