import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Search, TrendingUp, TrendingDown, DollarSign, Target, ArrowUpCircle, ArrowDownCircle } from 'lucide-react';
import { useState, useCallback, useRef } from 'react';

interface StockTransaction {
    id: number;
    type: 'buy' | 'sell';
    symbol: string;
    company_name: string;
    quantity: number;
    price_per_share: number;
    avg_price: number;
    fee: number;
    total_amount: number;
    transaction_date: string;
    placed_time: string;
    filled_time: string;
    order_status: string;
    profit_loss: number | null;
    profit_loss_percent: number | null;
    notes: string | null;
    buy_transaction?: {
        id: number;
        price_per_share: number;
        transaction_date: string;
    };
}

interface TransactionStats {
    total_trades: number;
    profitable_trades: number;
    win_rate: number;
    total_profit: number;
    total_loss: number;
    net_profit_loss: number;
}

interface Props {
    transactions: {
        data: StockTransaction[];
        links: any[];
        meta: any;
        last_page: number;
        current_page: number;
        per_page: number;
        total: number;
    };
    stats: TransactionStats;
    filters: {
        symbol?: string;
        type?: string;
        start_date?: string;
        end_date?: string;
    };
}

export default function WebullTransactions({ transactions, stats, filters }: Props) {
    const [searchSymbol, setSearchSymbol] = useState(filters.symbol || '');
    const [selectedType, setSelectedType] = useState(filters.type || 'all');
    const [startDate, setStartDate] = useState(filters.start_date || '');
    const [endDate, setEndDate] = useState(filters.end_date || '');

    const handleSearch = () => {
        router.get('/webull-transactions', {
            symbol: searchSymbol || undefined,
            type: selectedType !== 'all' ? selectedType : undefined,
            start_date: startDate || undefined,
            end_date: endDate || undefined,
        });
    };

    const handleReset = () => {
        setSearchSymbol('');
        setSelectedType('all');
        setStartDate('');
        setEndDate('');
        router.get('/webull-transactions');
    };

    const formatCurrency = (amount: number) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(amount);
    };

    const formatPercent = (percent: number) => {
        return `${percent >= 0 ? '+' : ''}${percent.toFixed(2)}%`;
    };

    const formatDate = (dateString: string) => {
        const date = new Date(dateString);
        // Add 5 hours to adjust for Webull's time format
        date.setHours(date.getHours() + 5);
        
        return date.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            timeZone: 'America/New_York', // EST/EDT
        });
    };

    const getProfitLossColor = (amount: number | null) => {
        if (amount === null || amount === undefined) return 'text-gray-500 dark:text-gray-400';
        if (amount === 0) return 'text-gray-600 dark:text-gray-300';
        return amount > 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400';
    };

    const updateNotes = useCallback(async (transactionId: number, notes: string) => {
        try {
            router.patch(`/webull-transactions/${transactionId}/notes`, 
                { notes },
                {
                    preserveScroll: true,
                    preserveState: true,
                    only: [], // Don't reload any data
                }
            );
        } catch (error) {
            console.error('Failed to update notes:', error);
        }
    }, []);

    const NotesInput = ({ transaction }: { transaction: StockTransaction }) => {
        const [notes, setNotes] = useState(transaction.notes || '');
        const [isEditing, setIsEditing] = useState(false);
        const timeoutRef = useRef<NodeJS.Timeout | null>(null);

        const handleNotesChange = (value: string) => {
            setNotes(value);
            
            // Clear existing timeout
            if (timeoutRef.current) {
                clearTimeout(timeoutRef.current);
            }
            
            // Set new timeout for auto-save
            timeoutRef.current = setTimeout(() => {
                updateNotes(transaction.id, value);
            }, 500);
        };

        const handleBlur = () => {
            setIsEditing(false);
            // Save immediately on blur
            if (timeoutRef.current) {
                clearTimeout(timeoutRef.current);
            }
            updateNotes(transaction.id, notes);
        };

        const handleFocus = () => {
            setIsEditing(true);
        };

        return (
            <Input
                value={notes}
                onChange={(e) => handleNotesChange(e.target.value)}
                onBlur={handleBlur}
                onFocus={handleFocus}
                placeholder="Add notes..."
                className="w-full min-w-48"
            />
        );
    };

    return (
        <>
            <Head title="View Transactions - Webull" />
            <AppLayout
                breadcrumbs={[
                    { title: 'Webull', href: '/upload-webull-data' },
                    { title: 'View Transactions', href: '/webull-transactions' }
                ]}
            >
                <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                    <Heading
                        title="View Transactions"
                        description="Review your Webull trading history with profit/loss analysis and performance metrics."
                    />

                    {/* Stats Cards */}
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Total Trades</CardTitle>
                                <Target className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{stats.total_trades}</div>
                            </CardContent>
                        </Card>
                        
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Win Rate</CardTitle>
                                <TrendingUp className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{stats.win_rate}%</div>
                                <p className="text-xs text-muted-foreground">
                                    {stats.profitable_trades} of {stats.total_trades} trades
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Total Profit</CardTitle>
                                <ArrowUpCircle className="h-4 w-4 text-green-600" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold text-green-600">
                                    {formatCurrency(stats.total_profit)}
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Net P&L</CardTitle>
                                <DollarSign className={`h-4 w-4 ${stats.net_profit_loss >= 0 ? 'text-green-600' : 'text-red-600'}`} />
                            </CardHeader>
                            <CardContent>
                                <div className={`text-2xl font-bold ${stats.net_profit_loss >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                    {formatCurrency(stats.net_profit_loss)}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Total Losses: {formatCurrency(stats.total_loss)}
                                </p>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Filters */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Filters</CardTitle>
                            <CardDescription>
                                Filter transactions by symbol, type, or date range
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <Label htmlFor="symbol">Symbol</Label>
                                    <Input
                                        id="symbol"
                                        placeholder="Search by symbol (e.g., AAPL)"
                                        value={searchSymbol}
                                        onChange={(e) => setSearchSymbol(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="type">Type</Label>
                                    <Select value={selectedType} onValueChange={setSelectedType}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All Types</SelectItem>
                                            <SelectItem value="buy">Buy Only</SelectItem>
                                            <SelectItem value="sell">Sell Only</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label htmlFor="start_date">Start Date</Label>
                                    <Input
                                        id="start_date"
                                        type="date"
                                        value={startDate}
                                        onChange={(e) => setStartDate(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="end_date">End Date</Label>
                                    <Input
                                        id="end_date"
                                        type="date"
                                        value={endDate}
                                        onChange={(e) => setEndDate(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                    />
                                </div>
                            </div>
                            <div className="flex gap-2 mt-4">
                                <Button onClick={handleSearch} className="flex items-center gap-2">
                                    <Search className="h-4 w-4" />
                                    Search
                                </Button>
                                <Button onClick={handleReset} variant="outline">
                                    Reset
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Transactions Table */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Transaction History</CardTitle>
                            <CardDescription>
                                Showing {transactions?.data?.length || 0} of {transactions?.meta?.total || 0} transactions
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Type</TableHead>
                                            <TableHead>Symbol</TableHead>
                                            <TableHead>Company</TableHead>
                                            <TableHead className="text-right">Quantity</TableHead>
                                            <TableHead className="text-right">Price</TableHead>
                                            <TableHead className="text-right">Avg Price</TableHead>
                                            <TableHead className="text-right">Total</TableHead>
                                            <TableHead className="text-right">P&L</TableHead>
                                            <TableHead className="text-right">P&L %</TableHead>
                                            <TableHead>Date</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {transactions?.data?.map((transaction) => (
                                            <>
                                                <TableRow key={transaction.id}>
                                                    <TableCell>
                                                        <Badge
                                                            variant={transaction.type === 'buy' ? 'default' : 'secondary'}
                                                            className={transaction.type === 'buy' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300' : 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300'}
                                                        >
                                                            {transaction.type.toUpperCase()}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell className="font-medium">
                                                        {transaction.symbol}
                                                    </TableCell>
                                                    <TableCell className="max-w-xs truncate">
                                                        {transaction.company_name}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {Number(transaction.quantity).toLocaleString()}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {formatCurrency(transaction.price_per_share)}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {formatCurrency(transaction.avg_price)}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {formatCurrency(transaction.total_amount)}
                                                    </TableCell>
                                                    <TableCell className={`text-right font-medium ${getProfitLossColor(transaction.profit_loss)}`}>
                                                        {transaction.profit_loss !== null ? formatCurrency(transaction.profit_loss) : '-'}
                                                    </TableCell>
                                                    <TableCell className={`text-right font-medium ${getProfitLossColor(transaction.profit_loss)}`}>
                                                        {transaction.profit_loss_percent !== null ? formatPercent(transaction.profit_loss_percent) : '-'}
                                                    </TableCell>
                                                    <TableCell className="text-sm">
                                                        {formatDate(transaction.transaction_date)}
                                                    </TableCell>
                                                </TableRow>
                                                {transaction.type === 'sell' && (
                                                    <TableRow key={`notes-${transaction.id}`} className="border-b-0">
                                                        <TableCell colSpan={10} className="py-2 px-4 bg-gray-50 dark:bg-gray-800/50">
                                                            <div className="flex items-center gap-2">
                                                                <span className="text-sm text-muted-foreground font-medium">Notes:</span>
                                                                <NotesInput transaction={transaction} />
                                                            </div>
                                                        </TableCell>
                                                    </TableRow>
                                                )}
                                            </>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>

                            {/* Pagination */}
                            {transactions?.last_page > 1 && (
                                <div className="mt-4 flex items-center justify-center space-x-2">
                                    {transactions.links?.map((link, index) => {
                                        if (!link.url) {
                                            return (
                                                <span
                                                    key={index}
                                                    className="px-3 py-2 text-sm text-muted-foreground"
                                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                                />
                                            );
                                        }

                                        // Extract page number from URL and preserve filters
                                        const url = new URL(link.url, window.location.origin);
                                        const page = url.searchParams.get('page');
                                        
                                        return (
                                            <button
                                                key={index}
                                                onClick={() => {
                                                    router.get('/webull-transactions', {
                                                        symbol: searchSymbol || undefined,
                                                        type: selectedType !== 'all' ? selectedType : undefined,
                                                        start_date: startDate || undefined,
                                                        end_date: endDate || undefined,
                                                        page: page || undefined,
                                                    });
                                                }}
                                                className={`px-3 py-2 text-sm rounded-md ${
                                                    link.active 
                                                        ? 'bg-primary text-primary-foreground' 
                                                        : 'bg-secondary text-secondary-foreground hover:bg-secondary/80'
                                                }`}
                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                            />
                                        );
                                    })}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </AppLayout>
        </>
    );
}