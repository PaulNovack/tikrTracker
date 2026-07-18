import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Form } from '@inertiajs/react';
import { CheckCircle2, AlertTriangle, Upload } from 'lucide-react';

interface Props {
    maxWatches: number;
    currentWatchCount: number;
}

export default function CsvSetWatches({ maxWatches, currentWatchCount }: Props) {
    const canAddMore = currentWatchCount < maxWatches;
    
    return (
        <AppLayout breadcrumbs={[
            { title: 'Watches', href: '/watches' },
            { title: 'CSV Set Watches', href: '/watches/csv' },
        ]}>
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <Heading
                    title="CSV Set Watches"
                    description="Add multiple stocks to your watch list using comma-separated symbols"
                />

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Info Cards */}
                    <div className="lg:col-span-1 space-y-4">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2">
                                    <Upload className="h-5 w-5" />
                                    Watch Limits
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold text-blue-600">
                                    {currentWatchCount} / {maxWatches}
                                </div>
                                <p className="text-sm text-muted-foreground mt-1">
                                    watches used
                                </p>
                                {!canAddMore && (
                                    <div className="mt-2 flex items-center gap-2 text-orange-600">
                                        <AlertTriangle className="h-4 w-4" />
                                        <span className="text-sm">Watch limit reached</span>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-lg">Instructions</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="flex items-start gap-2">
                                    <CheckCircle2 className="h-4 w-4 text-green-600 mt-0.5 flex-shrink-0" />
                                    <p className="text-sm">Enter symbols separated by commas</p>
                                </div>
                                <div className="flex items-start gap-2">
                                    <CheckCircle2 className="h-4 w-4 text-green-600 mt-0.5 flex-shrink-0" />
                                    <p className="text-sm">Duplicates will be automatically skipped</p>
                                </div>
                                <div className="flex items-start gap-2">
                                    <CheckCircle2 className="h-4 w-4 text-green-600 mt-0.5 flex-shrink-0" />
                                    <p className="text-sm">Invalid symbols will be ignored</p>
                                </div>
                                <div className="bg-gray-50 dark:bg-gray-900 p-3 rounded-md mt-3">
                                    <p className="text-sm font-medium">Example:</p>
                                    <p className="text-sm text-muted-foreground mt-1">
                                        AAPL, MSFT, GOOGL, TSLA, AMZN
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Main Form */}
                    <div className="lg:col-span-2">
                        <Form action="/watches/csv" method="post">
                            {({
                                errors,
                                hasErrors,
                                processing,
                                wasSuccessful,
                                recentlySuccessful,
                            }) => (
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Add Symbols</CardTitle>
                                        <CardDescription>
                                            Enter stock symbols separated by commas to add them to your watch list
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        {recentlySuccessful && (
                                            <Card className="border-green-200 bg-green-50 dark:border-green-900 dark:bg-green-950/30">
                                                <CardContent className="pt-6 flex gap-2 items-center">
                                                    <CheckCircle2 className="h-5 w-5 text-green-600 flex-shrink-0" />
                                                    <p className="text-sm text-green-800 dark:text-green-200">
                                                        Symbols processed successfully!
                                                    </p>
                                                </CardContent>
                                            </Card>
                                        )}

                                        {hasErrors && (
                                            <Card className="border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/30">
                                                <CardContent className="pt-6">
                                                    <div className="flex gap-2 items-start">
                                                        <AlertTriangle className="h-5 w-5 text-red-600 flex-shrink-0 mt-0.5" />
                                                        <div className="space-y-2">
                                                            {Object.entries(errors).map(([field, message]) => (
                                                                <p key={field} className="text-sm text-red-800 dark:text-red-200">
                                                                    {message}
                                                                </p>
                                                            ))}
                                                        </div>
                                                    </div>
                                                </CardContent>
                                            </Card>
                                        )}

                                        <div className="space-y-2">
                                            <label htmlFor="symbols" className="text-sm font-medium">
                                                Stock Symbols
                                            </label>
                                            <Textarea
                                                id="symbols"
                                                name="symbols"
                                                placeholder="Enter symbols separated by commas (e.g., AAPL, MSFT, GOOGL, TSLA)"
                                                rows={6}
                                                className="resize-none"
                                                disabled={processing || !canAddMore}
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Separate symbols with commas. Whitespace will be automatically trimmed.
                                            </p>
                                        </div>

                                        <div className="flex justify-end">
                                            <Button
                                                type="submit"
                                                disabled={processing || !canAddMore}
                                                className="min-w-24"
                                            >
                                                {processing ? 'Processing...' : 'Add Symbols'}
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            )}
                        </Form>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}