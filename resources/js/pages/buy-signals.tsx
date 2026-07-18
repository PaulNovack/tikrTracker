import Heading from '@/components/heading';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Target, TrendingUp, AlertTriangle, Clock, RefreshCw } from 'lucide-react';
import { show as showAsset } from '@/routes/asset-info';
import { useState, useEffect } from 'react';

interface BuySignal {
    asset_id: number;
    symbol: string;
    entry_time_est: string;
    entry_price: number;
    stop_loss: number;
    risk_per_share: number;
    reason: string;
    ema9?: number;
    ema21?: number;
    ema50?: number;
    vwap?: number;
    score?: number;
    volume?: number;
    price_change_percent?: number;
}

interface SignalsData {
    current_time: string;
    is_historical: boolean;
    columns: string[];
    signals: BuySignal[];
    count: number;
}

interface BuySignalsProps {
    signals: SignalsData;
    time?: string; // Optional time parameter from URL
}

const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
};

const formatPercent = (value: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'percent',
        minimumFractionDigits: 2,
    }).format(value / 100);
};

const formatDateTime = (dateTimeString: string) => {
    if (!dateTimeString) return 'N/A';
    
    try {
        const date = new Date(dateTimeString);
        
        // Format as Eastern timezone specifically
        return new Intl.DateTimeFormat('en-US', {
            month: 'numeric',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
            timeZone: 'America/New_York'
        }).format(date);
    } catch (error) {
        return dateTimeString; // Return original if parsing fails
    }
};

const getScoreColor = (score: number) => {
    if (score >= 7) return 'text-green-600 font-semibold';
    if (score >= 5) return 'text-orange-600 font-medium';
    return 'text-red-600';
};

const getScoreBadge = (score: number) => {
    if (score >= 7) return 'bg-green-100 text-green-800';
    if (score >= 5) return 'bg-orange-100 text-orange-800';
    return 'bg-red-100 text-red-800';
};

export default function BuySignals({ signals, time }: BuySignalsProps) {
    const hasSignals = signals && signals.signals && signals.signals.length > 0;
    
    // Format current EST time as datetime-local value
    const getCurrentESTDateTime = () => {
        const now = new Date();
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'America/New_York',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }).formatToParts(now);
        const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '';
        const hour = get('hour') === '24' ? '00' : get('hour');
        return `${get('year')}-${get('month')}-${get('day')}T${hour}:${get('minute')}`;
    };
    
    // Initialize with time from URL or current time
    const getInitialDateTime = () => {
        if (time) {
            // Convert "YYYY-MM-DD HH:MM:SS" to "YYYY-MM-DDTHH:MM"
            return time.replace(' ', 'T').slice(0, 16);
        }
        return getCurrentESTDateTime();
    };
    
    const [selectedDateTime, setSelectedDateTime] = useState(getInitialDateTime());
    const [isLoading, setIsLoading] = useState(false);
    
    // Update datetime picker when time prop changes
    useEffect(() => {
        setSelectedDateTime(getInitialDateTime());
    }, [time]);
    
    // Auto-refresh every 30 seconds (only for live/current data, not historical)
    useEffect(() => {
        if (signals?.is_historical) {
            return;
        }

        const interval = setInterval(() => {
            if (!isLoading) {
                router.reload({ only: ['signals'] });
            }
        }, 30000); // 30 seconds

        return () => clearInterval(interval);
    }, [isLoading, signals?.is_historical]);
    
    const handleDateTimeChange = (event: React.ChangeEvent<HTMLInputElement>) => {
        setSelectedDateTime(event.target.value);
    };
    
    const handleAnalyze = () => {
        setIsLoading(true);
        // Convert datetime-local value back to YYYY-MM-DD HH:MM:SS format
        const formattedDateTime = selectedDateTime.replace('T', ' ') + ':00';
        
        router.get('/buy-signals', { 
            time: formattedDateTime 
        }, {
            preserveState: false,
            onFinish: () => setIsLoading(false)
        });
    };
    
    const handleResetToNow = () => {
        setSelectedDateTime(getCurrentESTDateTime());
        setIsLoading(true);
        router.get('/buy-signals', {}, {
            preserveState: false,
            onFinish: () => setIsLoading(false)
        });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Buy Signals', href: '/buy-signals' }]}>
            <Head title="Buy Signals" />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex items-center justify-between">
                    <Heading
                        title="Buy Signals"
                        description="Advanced trading signals and buy recommendations based on technical analysis and market conditions"
                    />
                </div>

                {/* Date/Time Analysis Controls */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Clock className="h-5 w-5 text-blue-600" />
                            Analysis Time Selection
                        </CardTitle>
                        <CardDescription>
                            Select a specific date and time to analyze historical buy signals, or use current time for real-time analysis
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-end">
                            <div className="grid flex-1 gap-2">
                                <Label htmlFor="analysis-time">
                                    Analysis Date & Time (EST)
                                </Label>
                                <Input
                                    id="analysis-time"
                                    type="datetime-local"
                                    value={selectedDateTime}
                                    onChange={handleDateTimeChange}
                                    className="w-full"
                                />
                            </div>
                            <div className="flex gap-2">
                                <Button 
                                    onClick={handleAnalyze}
                                    disabled={isLoading}
                                    className="flex items-center gap-2"
                                >
                                    {isLoading ? (
                                        <>
                                            <RefreshCw className="h-4 w-4 animate-spin" />
                                            Analyzing...
                                        </>
                                    ) : (
                                        <>
                                            <Target className="h-4 w-4" />
                                            Analyze
                                        </>
                                    )}
                                </Button>
                                <Button 
                                    onClick={handleResetToNow}
                                    variant="outline"
                                    disabled={isLoading}
                                    className="flex items-center gap-2"
                                >
                                    <Clock className="h-4 w-4" />
                                    Now
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Summary Stats */}
                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardContent className="p-6">
                            <div className="flex items-center">
                                <Target className="h-8 w-8 text-blue-600" />
                                <div className="ml-4">
                                    <p className="text-sm font-medium text-muted-foreground">
                                        Total Signals
                                    </p>
                                    <p className="text-2xl font-bold">
                                        {signals?.count || 0}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="p-6">
                            <div className="flex items-center">
                                <TrendingUp className="h-8 w-8 text-green-600" />
                                <div className="ml-4">
                                    <p className="text-sm font-medium text-muted-foreground">
                                        {signals?.is_historical ? 'Analysis Time (EST)' : 'Current Time (EST)'}
                                    </p>
                                    <p className="text-sm font-bold">
                                        {formatDateTime(signals?.current_time || '')}
                                    </p>
                                    {signals?.is_historical && (
                                        <p className="text-xs text-orange-600 font-medium">
                                            Historical Analysis
                                        </p>
                                    )}
                                </div>
                            </div>
                            {hasSignals && (
                                <div className="mt-4 pt-4 border-t">
                                    <div className="flex items-center">
                                        <Clock className="h-6 w-6 text-blue-600" />
                                        <div className="ml-3">
                                            <p className="text-sm font-medium text-muted-foreground">
                                                Entry Time for All Signals
                                            </p>
                                            <p className="text-sm font-bold text-blue-600">
                                                {formatDateTime(signals.signals[0]?.entry_time_est || '')}
                                            </p>
                                            {(() => {
                                                // Check if entry time is significantly different from current time (indicating market is closed)
                                                const entryTime = new Date(signals.signals[0]?.entry_time_est || '');
                                                const currentTime = new Date(signals?.current_time?.replace(' EST', '') || '');
                                                const timeDiffMinutes = Math.abs((entryTime.getTime() - currentTime.getTime()) / (1000 * 60));
                                                
                                                // If the difference is more than 30 minutes, assume market is closed and entry is scheduled for next open
                                                return timeDiffMinutes > 30 ? (
                                                    <p className="text-xs text-muted-foreground">
                                                        Market closed - entry at next open
                                                    </p>
                                                ) : null;
                                            })()}
                                        </div>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="p-6">
                            <div className="flex items-center">
                                <AlertTriangle className="h-8 w-8 text-orange-600" />
                                <div className="ml-4">
                                    <p className="text-sm font-medium text-muted-foreground">
                                        Risk Management
                                    </p>
                                    <p className="text-sm font-bold">
                                        Stop Loss Enabled
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Signals Table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Target className="h-5 w-5 text-blue-600" />
                            Buy Signals
                        </CardTitle>
                        <CardDescription>
                            Technical analysis based buy recommendations with entry points, stop losses, and risk metrics
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {hasSignals ? (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="border-b">
                                        <tr className="text-muted-foreground">
                                            <th className="px-4 py-2 text-left font-semibold">
                                                Symbol
                                            </th>
                                            <th className="px-4 py-2 text-center font-semibold">
                                                Score
                                            </th>
                                            <th className="px-4 py-2 text-right font-semibold">
                                                Entry Price
                                            </th>
                                            <th className="px-4 py-2 text-right font-semibold">
                                                Stop Loss
                                            </th>
                                            <th className="px-4 py-2 text-right font-semibold">
                                                Risk/Share
                                            </th>
                                            <th className="px-4 py-2 text-right font-semibold">
                                                EMA 9
                                            </th>
                                            <th className="px-4 py-2 text-right font-semibold">
                                                VWAP
                                            </th>
                                            <th className="px-4 py-2 text-left font-semibold">
                                                Reason
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {signals.signals.map((signal, index) => (
                                            <tr
                                                key={index}
                                                className="border-b hover:bg-muted/50"
                                            >
                                                <td className="px-4 py-3 font-medium">
                                                    <a
                                                        href={showAsset.url(signal.asset_id)}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="text-blue-600 hover:text-blue-700 hover:underline font-bold dark:text-blue-400 dark:hover:text-blue-300"
                                                    >
                                                        {signal.symbol}
                                                    </a>
                                                </td>
                                                <td className="px-4 py-3 text-center">
                                                    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${getScoreBadge(signal.score || 0)}`}>
                                                        {signal.score || 0}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-right font-medium">
                                                    {formatCurrency(signal.entry_price)}
                                                </td>
                                                <td className="px-4 py-3 text-right text-red-600">
                                                    {formatCurrency(signal.stop_loss)}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    {formatCurrency(signal.risk_per_share)}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    {signal.ema9 ? formatCurrency(signal.ema9) : 'N/A'}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    {signal.vwap ? formatCurrency(signal.vwap) : 'N/A'}
                                                </td>
                                                <td className="px-4 py-3 text-xs">
                                                    {signal.reason}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <div className="text-center py-12">
                                <Target className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                                <h3 className="text-lg font-medium text-muted-foreground mb-2">
                                    No Buy Signals
                                </h3>
                                <p className="text-sm text-muted-foreground max-w-md mx-auto">
                                    No buy signals are currently available for the given time period. 
                                    Check back later or try a different time parameter.
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Technical Analysis Info */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Technical Indicators</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm text-muted-foreground">
                                EMA (9, 21, 50), VWAP analysis, and volume confirmation signals
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Risk Management</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm text-muted-foreground">
                                Automated stop loss calculation and risk per share metrics
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Entry Timing</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm text-muted-foreground">
                                5-minute precision timing for optimal entry points
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}