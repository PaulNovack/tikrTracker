import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Calculator, ShoppingCart, CheckCircle } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Orders',
        href: '/orders/buy',
    },
    {
        title: 'Buy Order',
        href: '/orders/buy',
    },
];

export default function BuyOrder() {
    const [formData, setFormData] = useState({
        symbol: '',
        shares: '',
        amount: '',
    });
    const [calculating, setCalculating] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');
    const [priceInfo, setPriceInfo] = useState<{ price: number; timestamp: string } | null>(null);

    const handleCalculateShares = async () => {
        if (!formData.symbol || !formData.amount) {
            setError('Please enter both symbol and amount');
            return;
        }

        setCalculating(true);
        setError('');
        setSuccess('');
        setPriceInfo(null);

        try {
            const response = await fetch('/orders/calculate-shares', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    symbol: formData.symbol,
                    amount: formData.amount,
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                setError(data.error || 'Failed to calculate shares');
                return;
            }

            setFormData(prev => ({ ...prev, shares: data.max_shares.toString() }));
            setPriceInfo({
                price: data.price,
                timestamp: data.timestamp,
            });
        } catch (err) {
            setError('Failed to calculate shares. Please try again.');
        } finally {
            setCalculating(false);
        }
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        
        if (!formData.symbol || !formData.shares) {
            setError('Please enter symbol and number of shares');
            return;
        }

        setSubmitting(true);
        setError('');
        setSuccess('');

        try {
            const response = await fetch('/orders/place', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    symbol: formData.symbol,
                    shares: formData.shares,
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                setError(data.error || 'Failed to place order');
                return;
            }

            setSuccess(data.message);
            
            // Reset form after successful order
            setTimeout(() => {
                setFormData({ symbol: '', shares: '', amount: '' });
                setPriceInfo(null);
                setSuccess('');
            }, 3000);

        } catch (err) {
            setError('Failed to place order. Please try again.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Buy Order" />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight text-gray-900 dark:text-gray-100">
                        Buy Order
                    </h1>
                    <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        Place a buy order for stocks using Webull API
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <ShoppingCart className="h-5 w-5" />
                            Order Details
                        </CardTitle>
                        <CardDescription>
                            Enter the stock symbol and quantity you want to purchase
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-6">
                            <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
                                <div>
                                    <Label htmlFor="symbol">
                                        Symbol
                                    </Label>
                                    <Input
                                        type="text"
                                        id="symbol"
                                        name="symbol"
                                        value={formData.symbol}
                                        onChange={(e) => setFormData(prev => ({ ...prev, symbol: e.target.value.toUpperCase() }))}
                                        placeholder="AAPL"
                                        className="mt-1"
                                        required
                                        disabled={submitting}
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="amount">
                                        $ Amount
                                    </Label>
                                    <Input
                                        type="number"
                                        id="amount"
                                        name="amount"
                                        min="0"
                                        step="0.01"
                                        value={formData.amount}
                                        onChange={(e) => setFormData(prev => ({ ...prev, amount: e.target.value }))}
                                        placeholder="100.00"
                                        className="mt-1"
                                        disabled={submitting}
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="shares">
                                        Number of Shares
                                    </Label>
                                    <div className="mt-1 flex gap-2">
                                        <Input
                                            type="number"
                                            id="shares"
                                            name="shares"
                                            min="1"
                                            value={formData.shares}
                                            onChange={(e) => setFormData(prev => ({ ...prev, shares: e.target.value }))}
                                            placeholder="10"
                                            required
                                            disabled={submitting}
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={handleCalculateShares}
                                            disabled={calculating || submitting}
                                        >
                                            <Calculator className="h-4 w-4" />
                                        </Button>
                                    </div>
                                </div>
                            </div>

                            {error && (
                                <div className="rounded-md bg-red-50 p-3 dark:bg-red-950/30">
                                    <p className="text-sm text-red-700 dark:text-red-400">{error}</p>
                                </div>
                            )}

                            {success && (
                                <div className="rounded-md bg-green-50 p-3 dark:bg-green-950/30">
                                    <div className="flex items-center gap-2">
                                        <CheckCircle className="h-4 w-4 text-green-600 dark:text-green-400" />
                                        <p className="text-sm text-green-700 dark:text-green-400">{success}</p>
                                    </div>
                                </div>
                            )}

                            {priceInfo && (
                                <div className="rounded-md bg-blue-50 p-3 dark:bg-blue-950/30">
                                    <p className="text-sm text-blue-700 dark:text-blue-400">
                                        Latest price: <strong>${priceInfo.price.toFixed(2)}</strong> (as of {new Date(priceInfo.timestamp).toLocaleString()})
                                    </p>
                                </div>
                            )}

                            <div className="flex justify-end">
                                <Button type="submit" size="lg" disabled={submitting}>
                                    <ShoppingCart className="mr-2 h-4 w-4" />
                                    {submitting ? 'Placing Order...' : 'Submit Order'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
