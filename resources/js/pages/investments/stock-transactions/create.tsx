import {
    index,
    store,
} from '@/actions/App/Http/Controllers/StockTransactionController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';

interface AvailableBuy {
    id: number;
    symbol: string;
    quantity: string;
    remaining_quantity: string;
    price_per_share: string;
    transaction_date: string;
}

interface Props {
    availableBuys: AvailableBuy[];
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
    {
        title: 'New Transaction',
        href: '#',
    },
];

export default function StockTransactionsCreate({ availableBuys }: Props) {
    const [transactionType, setTransactionType] = useState<'buy' | 'sell'>(
        'buy',
    );
    const [selectedBuy, setSelectedBuy] = useState<AvailableBuy | null>(null);

    const handleBuySelection = (buyId: string) => {
        const buy = availableBuys.find((b) => b.id === parseInt(buyId));
        setSelectedBuy(buy || null);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add Stock Transaction" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Add Stock Transaction
                    </h1>
                    <p className="text-muted-foreground">
                        Record a new stock purchase or sale
                    </p>
                </div>

                <div className="max-w-2xl rounded-lg border bg-card p-6">
                    <Form
                        action={store().url}
                        method="post"
                        className="space-y-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="type">
                                        Transaction Type
                                    </Label>
                                    <select
                                        id="type"
                                        name="type"
                                        required
                                        value={transactionType}
                                        onChange={(e) =>
                                            setTransactionType(
                                                e.target.value as
                                                    | 'buy'
                                                    | 'sell',
                                            )
                                        }
                                        className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                                    >
                                        <option value="buy">Buy</option>
                                        <option value="sell">Sell</option>
                                    </select>
                                    <InputError message={errors.type} />
                                </div>

                                {transactionType === 'sell' && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="stock_buy_id">
                                            Select Purchase to Sell
                                        </Label>
                                        <select
                                            id="stock_buy_id"
                                            name="stock_buy_id"
                                            required
                                            onChange={(e) =>
                                                handleBuySelection(
                                                    e.target.value,
                                                )
                                            }
                                            className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                                        >
                                            <option value="">
                                                Select a purchase...
                                            </option>
                                            {availableBuys.map((buy) => (
                                                <option
                                                    key={buy.id}
                                                    value={buy.id}
                                                >
                                                    {buy.symbol} -{' '}
                                                    {buy.remaining_quantity}{' '}
                                                    shares @ $
                                                    {parseFloat(
                                                        buy.price_per_share,
                                                    ).toFixed(2)}{' '}
                                                    (
                                                    {new Date(
                                                        buy.transaction_date,
                                                    ).toLocaleDateString()}
                                                    )
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={errors.stock_buy_id}
                                        />
                                    </div>
                                )}

                                <div className="grid gap-2">
                                    <Label htmlFor="symbol">Stock Symbol</Label>
                                    <Input
                                        id="symbol"
                                        name="symbol"
                                        type="text"
                                        required
                                        value={selectedBuy?.symbol || ''}
                                        readOnly={
                                            transactionType === 'sell' &&
                                            !!selectedBuy
                                        }
                                        placeholder="AAPL"
                                        className="uppercase"
                                    />
                                    <InputError message={errors.symbol} />
                                </div>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="quantity">
                                            Quantity
                                        </Label>
                                        <Input
                                            id="quantity"
                                            name="quantity"
                                            type="number"
                                            step="0.00000001"
                                            min="0.00000001"
                                            max={
                                                selectedBuy?.remaining_quantity ||
                                                undefined
                                            }
                                            required
                                            defaultValue={
                                                selectedBuy?.remaining_quantity ||
                                                ''
                                            }
                                            placeholder="10.5"
                                        />
                                        <InputError message={errors.quantity} />
                                        {selectedBuy && (
                                            <p className="text-sm text-muted-foreground">
                                                Available:{' '}
                                                {parseFloat(
                                                    selectedBuy.remaining_quantity,
                                                ).toFixed(8)}
                                            </p>
                                        )}
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="price_per_share">
                                            {transactionType === 'buy'
                                                ? 'Purchase Price ($)'
                                                : 'Original Purchase Price ($)'}
                                        </Label>
                                        <Input
                                            id="price_per_share"
                                            name="price_per_share"
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            required
                                            value={
                                                selectedBuy?.price_per_share ||
                                                ''
                                            }
                                            readOnly={
                                                transactionType === 'sell' &&
                                                !!selectedBuy
                                            }
                                            placeholder="150.00"
                                        />
                                        <InputError
                                            message={errors.price_per_share}
                                        />
                                    </div>
                                </div>

                                {transactionType === 'sell' && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="current_price_per_share">
                                            Current Sell Price ($)
                                        </Label>
                                        <Input
                                            id="current_price_per_share"
                                            name="current_price_per_share"
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            required
                                            placeholder="175.00"
                                        />
                                        <InputError
                                            message={
                                                errors.current_price_per_share
                                            }
                                        />
                                    </div>
                                )}

                                {transactionType === 'buy' && (
                                    <div className="grid gap-4 md:grid-cols-3">
                                        <div className="grid gap-2">
                                            <Label htmlFor="stop_loss">
                                                Stop Loss ($)
                                            </Label>
                                            <Input
                                                id="stop_loss"
                                                name="stop_loss"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                placeholder="0.00"
                                            />
                                            <InputError
                                                message={errors.stop_loss}
                                            />
                                            <p className="text-sm text-muted-foreground">
                                                Dollar amount
                                            </p>
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="break_even">
                                                Break Even ($)
                                            </Label>
                                            <Input
                                                id="break_even"
                                                name="break_even"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                placeholder="0.00"
                                            />
                                            <InputError
                                                message={errors.break_even}
                                            />
                                            <p className="text-sm text-muted-foreground">
                                                Dollar amount
                                            </p>
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="trailing">
                                                Trailing ($)
                                            </Label>
                                            <Input
                                                id="trailing"
                                                name="trailing"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                placeholder="0.00"
                                            />
                                            <InputError
                                                message={errors.trailing}
                                            />
                                            <p className="text-sm text-muted-foreground">
                                                Dollar amount
                                            </p>
                                        </div>
                                    </div>
                                )}

                                <div className="grid gap-2">
                                    <Label htmlFor="fee">
                                        Transaction Fee ($)
                                    </Label>
                                    <Input
                                        id="fee"
                                        name="fee"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        defaultValue="0"
                                        placeholder="0.00"
                                    />
                                    <InputError message={errors.fee} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="transaction_date">
                                        Transaction Date
                                    </Label>
                                    <Input
                                        id="transaction_date"
                                        name="transaction_date"
                                        type="datetime-local"
                                        required
                                        defaultValue={new Date()
                                            .toISOString()
                                            .slice(0, 16)}
                                    />
                                    <InputError
                                        message={errors.transaction_date}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="notes">
                                        Notes (Optional)
                                    </Label>
                                    <textarea
                                        id="notes"
                                        name="notes"
                                        rows={3}
                                        className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                                        placeholder="Additional notes about this transaction..."
                                    />
                                    <InputError message={errors.notes} />
                                </div>

                                <div className="flex gap-3">
                                    <Button type="submit" disabled={processing}>
                                        {processing
                                            ? 'Adding...'
                                            : 'Add Transaction'}
                                    </Button>
                                    <Link href={index().url}>
                                        <Button type="button" variant="outline">
                                            Cancel
                                        </Button>
                                    </Link>
                                </div>
                            </>
                        )}
                    </Form>
                </div>
            </div>
        </AppLayout>
    );
}
