import {
    create,
    destroy,
    edit,
    index,
} from '@/actions/App/Http/Controllers/StockTransactionController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2, TrendingDown, TrendingUp } from 'lucide-react';

interface StockTransaction {
    id: number;
    type: 'buy' | 'sell';
    symbol: string;
    quantity: string;
    price_per_share: string;
    current_price_per_share: string | null;
    fee: string;
    total_amount: string;
    transaction_date: string;
    notes: string | null;
    stock_buy_id: number | null;
    stock_buy?: {
        id: number;
        symbol: string;
        price_per_share: string;
        transaction_date: string;
    };
    remaining_quantity?: string;
    profit_loss?: string;
}

interface Props {
    transactions: StockTransaction[];
    availableBuys?: any[];
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Investments',
        href: '#',
    },
    {
        title: 'Stock Transactions',
        href: index().url,
    },
];

export default function StockTransactionsIndex({ transactions }: Props) {
    const handleDelete = (transactionId: number) => {
        if (confirm('Are you sure you want to delete this transaction?')) {
            router.delete(destroy(transactionId).url);
        }
    };

    const formatCurrency = (amount: string) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(parseFloat(amount));
    };

    const formatDate = (dateString: string) => {
        return new Date(dateString).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    const totalBuyAmount = transactions
        .filter((t) => t.type === 'buy')
        .reduce((sum, t) => sum + parseFloat(t.total_amount), 0);

    const totalSellAmount = transactions
        .filter((t) => t.type === 'sell')
        .reduce((sum, t) => sum + parseFloat(t.total_amount), 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock Transactions" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">
                            Stock Transactions
                        </h1>
                        <p className="text-muted-foreground">
                            Track all stock purchases and sales
                        </p>
                    </div>
                    <Link href={create().url}>
                        <Button>
                            <Plus className="mr-2 size-4" />
                            Add Transaction
                        </Button>
                    </Link>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <div className="rounded-lg border bg-card p-6">
                        <div className="mb-2 flex items-center gap-2 text-sm text-muted-foreground">
                            <TrendingUp className="size-4 text-green-500" />
                            Total Purchased
                        </div>
                        <div className="text-3xl font-bold">
                            {formatCurrency(totalBuyAmount.toString())}
                        </div>
                    </div>
                    <div className="rounded-lg border bg-card p-6">
                        <div className="mb-2 flex items-center gap-2 text-sm text-muted-foreground">
                            <TrendingDown className="size-4 text-red-500" />
                            Total Sold
                        </div>
                        <div className="text-3xl font-bold">
                            {formatCurrency(totalSellAmount.toString())}
                        </div>
                    </div>
                </div>

                <div className="rounded-lg border bg-card">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Date</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Symbol</TableHead>
                                <TableHead className="text-right">
                                    Quantity
                                </TableHead>
                                <TableHead className="text-right">
                                    Purchase Price
                                </TableHead>
                                <TableHead className="text-right">
                                    Current/Sell Price
                                </TableHead>
                                <TableHead className="text-right">
                                    Fee
                                </TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                                <TableHead className="text-right">
                                    Profit/Loss
                                </TableHead>
                                <TableHead className="text-right">
                                    Status
                                </TableHead>
                                <TableHead className="text-right">
                                    Actions
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {transactions.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={11}
                                        className="h-24 text-center text-muted-foreground"
                                    >
                                        No transactions found. Add your first
                                        transaction!
                                    </TableCell>
                                </TableRow>
                            ) : (
                                transactions.map((transaction) => (
                                    <TableRow key={transaction.id}>
                                        <TableCell>
                                            {formatDate(
                                                transaction.transaction_date,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    transaction.type === 'buy'
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {transaction.type.toUpperCase()}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="font-semibold">
                                            {transaction.symbol}
                                            {transaction.stock_buy && (
                                                <div className="text-xs text-muted-foreground">
                                                    From buy on{' '}
                                                    {formatDate(
                                                        transaction.stock_buy
                                                            .transaction_date,
                                                    )}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {parseFloat(
                                                transaction.quantity,
                                            ).toFixed(4)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {formatCurrency(
                                                transaction.price_per_share,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {transaction.current_price_per_share
                                                ? formatCurrency(
                                                      transaction.current_price_per_share,
                                                  )
                                                : '—'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {formatCurrency(transaction.fee)}
                                        </TableCell>
                                        <TableCell className="text-right font-semibold">
                                            {formatCurrency(
                                                transaction.total_amount,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {transaction.profit_loss ? (
                                                <span
                                                    className={
                                                        parseFloat(
                                                            transaction.profit_loss,
                                                        ) >= 0
                                                            ? 'text-green-600 dark:text-green-400'
                                                            : 'text-red-600 dark:text-red-400'
                                                    }
                                                >
                                                    {parseFloat(
                                                        transaction.profit_loss,
                                                    ) >= 0
                                                        ? '+'
                                                        : ''}
                                                    {formatCurrency(
                                                        transaction.profit_loss,
                                                    )}
                                                </span>
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {transaction.type === 'buy' &&
                                            transaction.remaining_quantity ? (
                                                parseFloat(
                                                    transaction.remaining_quantity,
                                                ) > 0 ? (
                                                    <Badge variant="outline">
                                                        {parseFloat(
                                                            transaction.remaining_quantity,
                                                        ).toFixed(4)}{' '}
                                                        remaining
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="secondary">
                                                        Sold
                                                    </Badge>
                                                )
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex justify-end gap-2">
                                                <Link
                                                    href={
                                                        edit(transaction.id).url
                                                    }
                                                >
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                    >
                                                        <Pencil className="size-4" />
                                                    </Button>
                                                </Link>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        handleDelete(
                                                            transaction.id,
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
