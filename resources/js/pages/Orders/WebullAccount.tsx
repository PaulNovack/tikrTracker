import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { Loader2, Key, AlertCircle, CheckCircle } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Orders',
        href: '/orders/buy',
    },
    {
        title: 'Get Webull AcctId',
        href: '/orders/webull-account',
    },
];

interface AccountResponse {
    success: boolean;
    account_id?: string;
    total_accounts?: number;
    error?: string;
    raw_response?: any;
}

interface TokenResponse {
    success: boolean;
    token?: string;
    expires_in?: number;
    expires_at?: string;
    status?: 'PENDING' | 'NORMAL' | 'INVALID' | 'EXPIRED';
    environment?: string;
    error?: string;
    raw_response?: any;
}

export default function WebullAccount() {
    const [loading, setLoading] = useState(false);
    const [tokenLoading, setTokenLoading] = useState(false);
    const [accountData, setAccountData] = useState<AccountResponse | null>(null);
    const [tokenData, setTokenData] = useState<TokenResponse | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [tokenError, setTokenError] = useState<string | null>(null);

    const handleFetchAccount = async () => {
        setLoading(true);
        setError(null);
        setAccountData(null);

        try {
            const response = await fetch('/orders/webull-account', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute('content') || '',
                },
            });

            const data = await response.json();

            if (data.success) {
                setAccountData(data);
            } else {
                setError(data.error || 'Failed to fetch account information');
            }
        } catch (err) {
            setError(err instanceof Error ? err.message : 'An error occurred');
        } finally {
            setLoading(false);
        }
    };

    const handleCreateToken = async () => {
        setTokenLoading(true);
        setTokenError(null);
        setTokenData(null);

        try {
            const response = await fetch('/orders/webull-token', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute('content') || '',
                },
            });

            const data = await response.json();

            if (data.success) {
                setTokenData(data);
            } else {
                setTokenError(data.error || 'Failed to create token');
            }
        } catch (err) {
            setTokenError(err instanceof Error ? err.message : 'An error occurred');
        } finally {
            setTokenLoading(false);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Get Webull Account ID" />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight text-gray-900 dark:text-gray-100">
                        Get Webull Account ID
                    </h1>
                    <p className="mt-2 text-muted-foreground">
                        Fetch your Webull account information and create access tokens
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>1. Create Access Token</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div>
                            <Button
                                onClick={handleCreateToken}
                                disabled={tokenLoading}
                                size="lg"
                                className="w-full sm:w-auto"
                            >
                                {tokenLoading ? (
                                    <>
                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                        Creating Token...
                                    </>
                                ) : (
                                    <>
                                        <Key className="mr-2 h-4 w-4" />
                                        Create Token
                                    </>
                                )}
                            </Button>
                        </div>

                        {tokenError && (
                            <div className="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-950/50">
                                <AlertCircle className="mt-0.5 h-5 w-5 shrink-0 text-red-600 dark:text-red-400" />
                                <div className="flex-1">
                                    <p className="font-semibold text-red-800 dark:text-red-200">
                                        Error
                                    </p>
                                    <p className="mt-1 text-sm text-red-700 dark:text-red-300">
                                        {tokenError}
                                    </p>
                                </div>
                            </div>
                        )}

                        {tokenData?.success && (
                            <>
                                <div className="flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-950/50">
                                    <CheckCircle className="mt-0.5 h-5 w-5 shrink-0 text-green-600 dark:text-green-400" />
                                    <div className="flex-1 space-y-2">
                                        <p className="font-semibold text-green-800 dark:text-green-200">
                                            Token Created Successfully
                                        </p>
                                        <div className="space-y-1 text-sm text-green-700 dark:text-green-300">
                                            <p>
                                                <span className="font-medium">
                                                    Access Token:
                                                </span>{' '}
                                                <code className="rounded bg-green-100 px-2 py-1 text-xs dark:bg-green-900">
                                                    {tokenData.token}
                                                </code>
                                            </p>
                                            {tokenData.status && (
                                                <p>
                                                    <span className="font-medium">
                                                        Status:
                                                    </span>{' '}
                                                    <span className={
                                                        tokenData.status === 'PENDING'
                                                            ? 'text-orange-600 dark:text-orange-400'
                                                            : tokenData.status === 'NORMAL'
                                                              ? 'text-green-600 dark:text-green-400'
                                                              : 'text-red-600 dark:text-red-400'
                                                    }>
                                                        {tokenData.status}
                                                    </span>
                                                    {tokenData.status === 'PENDING' && (
                                                        <span className="ml-2 text-xs">
                                                            (Verify via Webull App SMS code)
                                                        </span>
                                                    )}
                                                </p>
                                            )}
                                            {tokenData.expires_in && (
                                                <p>
                                                    <span className="font-medium">
                                                        Expires:
                                                    </span>{' '}
                                                    {new Date(tokenData.expires_in).toLocaleString()}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <h3 className="font-semibold">
                                        Next Steps
                                    </h3>
                                    <div className="rounded-lg border border-orange-200 bg-orange-50 p-4 dark:border-orange-800 dark:bg-orange-950/50">
                                        <ol className="list-decimal space-y-2 pl-5 text-sm text-orange-800 dark:text-orange-200">
                                            {tokenData.status === 'PENDING' && (
                                                <li>Verify the token via Webull App SMS code to change status from PENDING to NORMAL</li>
                                            )}
                                            <li>Token has been saved to database for {tokenData.environment} environment</li>
                                            <li>Orders at /orders/buy will automatically use this token</li>
                                            <li>Token expires at: {tokenData.expires_at ? new Date(tokenData.expires_at).toLocaleString() : 'Unknown'}</li>
                                        </ol>
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <h3 className="font-semibold">
                                        Raw Token Response
                                    </h3>
                                    <div className="overflow-x-auto rounded-lg border border-border bg-muted">
                                        <pre className="p-4 text-sm">
                                            {JSON.stringify(
                                                tokenData.raw_response,
                                                null,
                                                2
                                            )}
                                        </pre>
                                    </div>
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>2. Fetch Account Information</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div>
                            <Button
                                onClick={handleFetchAccount}
                                disabled={loading}
                                size="lg"
                                className="w-full sm:w-auto"
                            >
                                {loading ? (
                                    <>
                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                        Fetching...
                                    </>
                                ) : (
                                    <>
                                        <Key className="mr-2 h-4 w-4" />
                                        Get Account ID
                                    </>
                                )}
                            </Button>
                        </div>

                        {error && (
                            <div className="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-950/50">
                                <AlertCircle className="mt-0.5 h-5 w-5 shrink-0 text-red-600 dark:text-red-400" />
                                <div className="flex-1">
                                    <p className="font-semibold text-red-800 dark:text-red-200">
                                        Error
                                    </p>
                                    <p className="mt-1 text-sm text-red-700 dark:text-red-300">
                                        {error}
                                    </p>
                                </div>
                            </div>
                        )}

                        {accountData?.success && (
                            <>
                                <div className="flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-950/50">
                                    <CheckCircle className="mt-0.5 h-5 w-5 shrink-0 text-green-600 dark:text-green-400" />
                                    <div className="flex-1 space-y-2">
                                        <p className="font-semibold text-green-800 dark:text-green-200">
                                            Account Information Retrieved
                                        </p>
                                        <div className="space-y-1 text-sm text-green-700 dark:text-green-300">
                                            <p>
                                                <span className="font-medium">
                                                    Account ID:
                                                </span>{' '}
                                                <code className="rounded bg-green-100 px-2 py-1 dark:bg-green-900">
                                                    {accountData.account_id}
                                                </code>
                                            </p>
                                            <p>
                                                <span className="font-medium">
                                                    Total Accounts:
                                                </span>{' '}
                                                {accountData.total_accounts}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <h3 className="font-semibold">
                                        Raw Response from Webull
                                    </h3>
                                    <div className="overflow-x-auto rounded-lg border border-border bg-muted">
                                        <pre className="p-4 text-sm">
                                            {JSON.stringify(
                                                accountData.raw_response,
                                                null,
                                                2
                                            )}
                                        </pre>
                                    </div>
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
