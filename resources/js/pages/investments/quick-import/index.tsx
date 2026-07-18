import { store } from '@/actions/App/Http/Controllers/StockTransactionController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ArrowRight, Copy } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Investments',
        href: '#',
    },
    {
        title: 'Quick Import',
        href: '#',
    },
];

export default function QuickImport() {
    const [text, setText] = useState('');
    const [parsedData, setParsedData] = useState<any>(null);
    const [isLoading, setIsLoading] = useState(false);

    const handleParse = async () => {
        setIsLoading(true);
        try {
            const response = await fetch('/quick-import/parse', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content') || '',
                },
                body: JSON.stringify({ text }),
            });
            const data = await response.json();
            setParsedData(data);
        } catch (error) {
            console.error('Error parsing text:', error);
        } finally {
            setIsLoading(false);
        }
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        const formData = new FormData(e.target as HTMLFormElement);

        router.post(
            store().url,
            {
                type: 'buy',
                symbol: formData.get('symbol'),
                quantity: formData.get('quantity'),
                price_per_share: formData.get('price_per_share'),
                fee: formData.get('fee') || '0',
                transaction_date: formData.get('transaction_date'),
                notes: formData.get('notes'),
            },
            {
                onSuccess: () => {
                    setText('');
                    setParsedData(null);
                },
            },
        );
    };

    const exampleText = `Your position was opened
We filled your buy order for a new AXS position on 11/18/2025. Here are the details:
AXS Units:	227.876946
AXS Unit Price:	$1.3165
Invested Amount:	$300.00`;

    const handleCopyExample = () => {
        setText(exampleText);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Quick Import Trade" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">
                        Quick Import Trade
                    </h1>
                    <p className="text-muted-foreground">
                        Paste your trade notification to automatically import
                        the transaction
                    </p>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Input Section */}
                    <div className="rounded-lg border bg-card p-6">
                        <div className="mb-4">
                            <h2 className="text-lg font-semibold">
                                Paste Trade Details
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Copy and paste your trade notification from your
                                broker
                            </p>
                        </div>

                        <div className="mb-4 rounded-lg border bg-muted/50 p-3">
                            <div className="mb-2 flex items-center justify-between">
                                <span className="text-xs font-medium text-muted-foreground">
                                    Example format:
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={handleCopyExample}
                                >
                                    <Copy className="mr-1 size-3" />
                                    Use Example
                                </Button>
                            </div>
                            <pre className="text-xs text-muted-foreground">
                                {exampleText}
                            </pre>
                        </div>

                        <div className="space-y-4">
                            <div>
                                <Label htmlFor="text">
                                    Trade Notification Text
                                </Label>
                                <textarea
                                    id="text"
                                    value={text}
                                    onChange={(e) => setText(e.target.value)}
                                    rows={10}
                                    className="flex min-h-[200px] w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                                    placeholder="Paste your trade notification here..."
                                />
                            </div>

                            <Button
                                onClick={handleParse}
                                disabled={!text || isLoading}
                                className="w-full"
                            >
                                {isLoading ? 'Parsing...' : 'Parse Trade Data'}
                                <ArrowRight className="ml-2 size-4" />
                            </Button>
                        </div>
                    </div>

                    {/* Form Section */}
                    <div className="rounded-lg border bg-card p-6">
                        <div className="mb-4">
                            <h2 className="text-lg font-semibold">
                                Review & Submit
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Verify the extracted data and fill in any
                                missing fields
                            </p>
                        </div>

                        {!parsedData ? (
                            <div className="flex min-h-[400px] items-center justify-center rounded-lg border-2 border-dashed">
                                <p className="text-sm text-muted-foreground">
                                    Parsed data will appear here
                                </p>
                            </div>
                        ) : (
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div>
                                    <Label htmlFor="symbol">Symbol *</Label>
                                    <Input
                                        id="symbol"
                                        name="symbol"
                                        defaultValue={parsedData.symbol || ''}
                                        required
                                        placeholder="e.g., AAPL"
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="quantity">Quantity *</Label>
                                    <Input
                                        id="quantity"
                                        name="quantity"
                                        type="number"
                                        step="0.00000001"
                                        defaultValue={parsedData.quantity || ''}
                                        required
                                        placeholder="0.00"
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="price_per_share">
                                        Price Per Share *
                                    </Label>
                                    <Input
                                        id="price_per_share"
                                        name="price_per_share"
                                        type="number"
                                        step="0.01"
                                        defaultValue={
                                            parsedData.price_per_share || ''
                                        }
                                        required
                                        placeholder="0.00"
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="fee">Fee</Label>
                                    <Input
                                        id="fee"
                                        name="fee"
                                        type="number"
                                        step="0.01"
                                        defaultValue="0"
                                        placeholder="0.00"
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="transaction_date">
                                        Transaction Date *
                                    </Label>
                                    <Input
                                        id="transaction_date"
                                        name="transaction_date"
                                        type="datetime-local"
                                        defaultValue={
                                            parsedData.transaction_date ||
                                            new Date()
                                                .toISOString()
                                                .slice(0, 16)
                                        }
                                        required
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="notes">Notes</Label>
                                    <textarea
                                        id="notes"
                                        name="notes"
                                        rows={3}
                                        className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                                        placeholder="Original trade notification text..."
                                        defaultValue={text}
                                    />
                                </div>

                                <Button type="submit" className="w-full">
                                    Create Transaction
                                </Button>
                            </form>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
