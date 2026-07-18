import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Bell, Clock, Target, TrendingUp } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast, Toaster } from 'sonner';

// Calculate relative time (e.g., "5 minutes ago")
function getRelativeTime(timestamp: string): string {
    const now = new Date();
    // Parse as UTC by appending 'Z' if not present
    const utcTimestamp = timestamp.includes('Z') ? timestamp : timestamp + 'Z';
    const then = new Date(utcTimestamp);
    const diffMs = now.getTime() - then.getTime();
    const diffSeconds = Math.floor(diffMs / 1000);
    const diffMinutes = Math.floor(diffSeconds / 60);
    const diffHours = Math.floor(diffMinutes / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffSeconds < 60) return `${diffSeconds} second${diffSeconds === 1 ? '' : 's'} ago`;
    if (diffMinutes < 60) return `${diffMinutes} minute${diffMinutes === 1 ? '' : 's'} ago`;
    if (diffHours < 24) return `${diffHours} hour${diffHours === 1 ? '' : 's'} ago`;
    return `${diffDays} day${diffDays === 1 ? '' : 's'} ago`;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Trade Alerts',
        href: '/trade-alerts',
    },
];

interface TradeAlert {
    id: number;
    symbol: string;
    asset_id: number | null;
    signal_type: string;
    entry_type: string;
    risk_level: string;
    version: string;
    pipeline_run: string;
    is_realtime?: boolean;
    created_at: string;
    formatted_time: string;
    time_ago: string;
    signal_time_est: string;
    signal_time_ago: string;
    alert_created_time_est: string;
    alert_created_time_ago: string;
    staleness_seconds: number;
    staleness_formatted: string;
    entry_time_est: string;
    entry_time_ago: string;
    target_hit?: string | null;
    targets?: {
        '1R'?: number;
        '2R'?: number;
        '3R'?: number;
    };
    atr?: number;
    atrPct?: number;
    suggestedTrailingStop?: number;
    suggestedTrailingStopPct?: number;
    ml_win_prob?: number | null;
    mlWinProb?: number | null;
    ml_scored_at?: string | null;
    mlScoredAt?: string | null;
    ml_model_version?: string | null;
    mlModelVersion?: string | null;
    ml_live_win_prob?: number | null;
    mlLiveWinProb?: number | null;
    ml_live_scored_at?: string | null;
    mlLiveScoredAt?: string | null;
    meta?: {
        price?: number;
        volume?: number;
        change?: number;
        change_percent?: number;
        risk_score?: number;
        confidence?: number;
        position_size_usd?: number;
        stop_loss?: number;
        take_profit?: number;
        risk_percent?: number;
        score?: number;
        vol_ratio?: number;
    };
}

interface PaginationData {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

interface DataHealth {
    daily_prices_healthy: boolean;
    last_trading_day: string;
    actual_last_daily_price_date: string | null;
    message: string | null;
}

interface TradeAlertsPageProps {
    alerts: TradeAlert[];
    pagination: PaginationData;
    isUnfiltered?: boolean;
    mlMinThreshold?: number;
    dateFilter?: string;
    symbolFilter?: string;
    pipelineFilter?: string;
    pipelineMlThresholds?: Record<string, number>;
    includeBacktest?: boolean;
    dataHealth?: DataHealth;
}

const getRiskColor = (riskLevel: string) => {
    switch (riskLevel?.toLowerCase()) {
        case 'high':
            return 'text-red-600 bg-red-50 border-red-200';
        case 'medium':
            return 'text-yellow-600 bg-yellow-50 border-yellow-200';
        case 'low':
            return 'text-green-600 bg-green-50 border-green-200';
        default:
            return 'text-gray-600 bg-gray-50 border-gray-200';
    }
};

const getSignalIcon = (signalType: string) => {
    switch (signalType?.toLowerCase()) {
        case 'momentum_buy':
        case 'breakout':
            return <TrendingUp className="h-4 w-4" />;
        case 'universe_1m':
            return <Target className="h-4 w-4" />;
        default:
            return <AlertTriangle className="h-4 w-4" />;
    }
};

const formatCurrency = (value: number | undefined) => {
    if (!value) return 'N/A';
    const decimals = value < 10 ? 4 : 2;
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(value);
};

const formatPercent = (value: any): string => {
    if (
        value === null ||
        value === undefined ||
        typeof value !== 'number' ||
        isNaN(value)
    ) {
        return 'N/A';
    }
    return `${value > 0 ? '+' : ''}${value.toFixed(2)}%`;
};

const formatNumber = (value: any, decimals: number = 2): string => {
    if (
        value === null ||
        value === undefined ||
        typeof value !== 'number' ||
        isNaN(value)
    ) {
        return 'N/A';
    }
    return value.toFixed(decimals);
};

const formatRatio = (value: any): string => {
    if (
        value === null ||
        value === undefined ||
        typeof value !== 'number' ||
        isNaN(value)
    ) {
        return 'N/A';
    }
    return `${value.toFixed(1)}x`;
};

const formatConfidence = (value: any): string => {
    if (
        value === null ||
        value === undefined ||
        typeof value !== 'number' ||
        isNaN(value)
    ) {
        return 'N/A';
    }
    return `${(value * 100).toFixed(0)}%`;
};

const getPipelineLabel = (pipelineRun: string): string => {
    if (pipelineRun?.toUpperCase() === 'J') {
        return 'Pipeline J — Recent 4 Percent Plus Movers';
    }
    if (pipelineRun?.toUpperCase() === 'P') {
        return 'Pipeline P — Institutional Follow-Through';
    }

    return `Pipeline ${pipelineRun || 'A'}`;
};

// Play notification bell sound using Web Audio API
const playNotificationSound = () => {
    try {
        const audioContext = new (window.AudioContext ||
            (window as any).webkitAudioContext)();
        const oscillator = audioContext.createOscillator();
        const gainNode = audioContext.createGain();

        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);

        // Bell-like sound with two tones
        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(800, audioContext.currentTime); // First tone
        oscillator.frequency.setValueAtTime(
            600,
            audioContext.currentTime + 0.1,
        ); // Second tone

        gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
        gainNode.gain.exponentialRampToValueAtTime(
            0.01,
            audioContext.currentTime + 0.5,
        );

        oscillator.start(audioContext.currentTime);
        oscillator.stop(audioContext.currentTime + 0.5);
    } catch (error) {
        console.warn('Could not play notification sound:', error);
    }
};

export default function TradeAlertsPage({
    alerts,
    pagination,
    isUnfiltered = false,
    mlMinThreshold: initialMlMinThreshold = 0,
    dateFilter: initialDateFilter = '',
    symbolFilter: initialSymbolFilter = '',
    pipelineFilter: initialPipelineFilter = '',
    pipelineMlThresholds = {},
    includeBacktest = true,
    dataHealth,
}: TradeAlertsPageProps) {
    const [refreshing, setRefreshing] = useState(false);
    const [mlMinThreshold, setMlMinThreshold] = useState<number>(
        initialMlMinThreshold,
    );
    const [dateFilter, setDateFilter] = useState<string>(initialDateFilter);
    const [symbolFilter, setSymbolFilter] = useState<string>(initialSymbolFilter);
    const [pipelineFilter, setPipelineFilter] = useState<string>(initialPipelineFilter);
    const { props } = usePage();

    // Auto-refresh when new alerts come in via WebSocket (no interval-based refresh)
    useEffect(() => {
        // Get Echo instance from global window object (set up by Laravel)
        const echo = (window as any).Echo;

        if (!echo) {
            console.warn(
                'Echo WebSocket not available - real-time updates disabled',
            );
            return;
        }

        console.log('Setting up WebSocket listener for trade alerts page');
        console.log('Echo instance:', echo);

        // Store timeout IDs for cleanup
        let mlScoreTimeoutId: NodeJS.Timeout | null = null;

        // Define the alert handler function
        const handleNewAlert = (data: any) => {
            console.log(
                '🔄 New trade alert received on page - refreshing data',
                data,
            );

            // Play notification sound
            playNotificationSound();

            // Show toast notification
            const symbol = data.symbol || 'Unknown';
            const entryType = data.entry_type || 'UNKNOWN';
            const entry = data.entry
                ? `$${parseFloat(data.entry).toFixed(2)}`
                : 'N/A';
            // Try to get percent from change_percent, risk_percent, or atrPct
            let percent = null;
            if (typeof data.change_percent === 'number') {
                percent = data.change_percent;
            } else if (
                data.meta &&
                typeof data.meta.risk_percent === 'number'
            ) {
                percent = data.meta.risk_percent;
            } else if (typeof data.atrPct === 'number') {
                percent = data.atrPct;
            }
            // Risk percent in toast: always round to 2 decimals
            let riskPercent = null;
            if (data.meta && typeof data.meta.risk_percent === 'number') {
                riskPercent = data.meta.risk_percent;
            }
            const riskStr =
                riskPercent !== null
                    ? `\nRisk: ${riskPercent.toFixed(2)}%`
                    : '';
            const percentStr =
                percent !== null ? ` | ${percent.toFixed(2)}%` : '';
            toast.success(`New Trade Alert: ${symbol}`, {
                description: `${entryType.replace('_', ' ')} - Entry: ${entry}${percentStr}${riskStr}`,
                duration: 5000,
                icon: <Bell className="h-4 w-4" />,
            });

            // Add a small delay to ensure the database has been updated
            setTimeout(() => {
                console.log('Executing page refresh...');

                // Use visit with current URL to avoid white screen issues
                router.visit(window.location.href, {
                    only: ['alerts', 'pagination'],
                    preserveState: true,
                    preserveScroll: true,
                    onSuccess: () => {
                        console.log(
                            '✅ Trade alerts page refreshed successfully',
                        );
                    },
                    onError: (errors) => {
                        console.error(
                            '❌ Failed to refresh trade alerts:',
                            errors,
                        );
                    },
                });
            }, 1000); // 1 second delay to ensure database is updated
        };

        // Define the ML score handler function
        const handleMLScored = (data: any) => {
            console.log(
                '🤖 ML score received for alert - scheduling refresh in 30 seconds',
                data,
            );

            // Show toast notification
            const symbol = data.symbol || 'Unknown';
            const winProb = data.ml_win_prob
                ? (data.ml_win_prob * 100).toFixed(1)
                : 'N/A';

            toast.info(`ML Score Updated: ${symbol}`, {
                description: `Win Probability: ${winProb}% - Refreshing in 30 seconds`,
                duration: 5000,
            });

            // Schedule refresh 30 seconds after ML scoring
            mlScoreTimeoutId = setTimeout(() => {
                console.log(
                    '⏰ 30 seconds elapsed - refreshing page to pick up ML scores...',
                );

                router.visit(window.location.href, {
                    only: ['alerts', 'pagination'],
                    preserveState: true,
                    preserveScroll: true,
                    onSuccess: () => {
                        console.log(
                            '✅ Trade alerts page refreshed successfully after ML scoring',
                        );
                    },
                    onError: (errors) => {
                        console.error(
                            '❌ Failed to refresh trade alerts after ML scoring:',
                            errors,
                        );
                    },
                });
            }, 30000); // 30 seconds
        };

        // Subscribe to trade-alerts channel
        const channel = echo.channel('trade-alerts');
        console.log('Channel subscribed:', channel);

        // Listen for alert created events
        channel.listen('.alert.created', (data: any) => {
            console.log('🔥 Received alert.created event:', data);
            handleNewAlert(data);
        });

        // Listen for ML scoring events
        channel.listen('.alert.ml-scored', (data: any) => {
            console.log('🤖 Received alert.ml-scored event:', data);
            handleMLScored(data);
        });

        console.log(
            '✅ WebSocket listeners set up for alert.created and alert.ml-scored events',
        );

        // Cleanup on component unmount
        return () => {
            console.log(
                'Cleaning up WebSocket subscription for trade alerts page',
            );

            // Clear ML score timeout if it exists
            if (mlScoreTimeoutId) {
                clearTimeout(mlScoreTimeoutId);
            }

            try {
                channel.stopListening('.alert.created');
                channel.stopListening('.alert.ml-scored');
                echo.leaveChannel('trade-alerts');
            } catch (error) {
                console.warn('Error during WebSocket cleanup:', error);
            }
        };
    }, []);

    const handleRefresh = () => {
        setRefreshing(true);
        router.reload({
            onFinish: () => setRefreshing(false),
        });
    };

    const handleFilter = () => {
        const params = new URLSearchParams();
        if (mlMinThreshold !== 0) {
            params.set('ml_min', mlMinThreshold.toString());
        }
        if (dateFilter) {
            params.set('date', dateFilter);
        }
        if (symbolFilter) {
            params.set('symbol', symbolFilter);
        }
        if (pipelineFilter) {
            params.set('pipeline', pipelineFilter);
        }
        if (includeBacktest) {
            params.set('include_backtest', '1');
        }
        const baseRoute = isUnfiltered
            ? '/trade-alerts-unfiltered'
            : '/trade-alerts';
        // Always navigate to apply the filter, even if it's "All" (clearing the filter)
        router.visit(
            `${baseRoute}${params.toString() ? '?' + params.toString() : ''}`,
            {
                preserveState: false,
                preserveScroll: false,
            },
        );
    };

    const renderAlertCard = (alert: TradeAlert) => (
        <div
            key={alert.id}
            className="p-6 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700"
        >
            <div className="flex items-start justify-between">
                <div className="flex flex-1 items-start space-x-4">
                    <div className="flex-shrink-0">
                        <div className="rounded-lg bg-blue-100 p-2 dark:bg-blue-900">
                            {getSignalIcon(alert.signal_type)}
                        </div>
                    </div>

                    <div className="min-w-0 flex-1">
                        <div className="flex items-center space-x-3">
                            {alert.asset_id ? (
                                <a
                                    href={`/market-data/assets/${alert.asset_id}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-lg font-semibold text-blue-600 hover:text-blue-800 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
                                >
                                    {alert.symbol}
                                </a>
                            ) : (
                                <span className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                    {alert.symbol}
                                </span>
                            )}
                            <span
                                className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${getRiskColor(alert.risk_level)}`}
                            >
                                {alert.risk_level} Risk
                            </span>
                            <span className="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800 dark:bg-gray-700 dark:text-gray-200">
                                {alert.signal_type
                                    .replace('_', ' ')
                                    .toUpperCase()}
                            </span>
                            <span className="inline-flex items-center rounded-full border border-blue-200 bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800 dark:border-blue-700 dark:bg-blue-900 dark:text-blue-200">
                                {alert.entry_type
                                    .replace('_', ' ')
                                    .toUpperCase()}
                            </span>
                            <span className="inline-flex items-center rounded-full border border-purple-200 bg-purple-100 px-2.5 py-0.5 text-xs font-medium text-purple-800 dark:border-purple-700 dark:bg-purple-900 dark:text-purple-200">
                                {alert.version || 'unknown'}
                            </span>
                            <span className="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-800 dark:border-indigo-700 dark:bg-indigo-900 dark:text-indigo-200">
                                {getPipelineLabel(alert.pipeline_run || 'A')}
                            </span>
                            {alert.ml_win_prob !== null &&
                                alert.ml_win_prob !== undefined && (
                                    <span
                                        className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${
                                            alert.ml_win_prob >= 0.65
                                                ? 'border-emerald-200 bg-emerald-100 text-emerald-800 dark:border-emerald-700 dark:bg-emerald-900 dark:text-emerald-200'
                                                : alert.ml_win_prob >= 0.5
                                                  ? 'border-blue-200 bg-blue-100 text-blue-800 dark:border-blue-700 dark:bg-blue-900 dark:text-blue-200'
                                                  : 'border-gray-200 bg-gray-100 text-gray-800 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200'
                                        }`}
                                    >
                                        🤖 ML:{' '}
                                        {(alert.ml_win_prob * 100).toFixed(1)}%
                                    </span>
                                )}
                            {alert.ml_live_win_prob != null && (
                                <span
                                    title={`Live-rescored at order time${alert.ml_live_scored_at ? ' · ' + alert.ml_live_scored_at : ''}`}
                                    className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${
                                        alert.ml_live_win_prob >= 0.7
                                            ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-600 dark:bg-emerald-950 dark:text-emerald-300'
                                            : alert.ml_live_win_prob >= 0.5
                                              ? 'border-blue-300 bg-blue-50 text-blue-700 dark:border-blue-600 dark:bg-blue-950 dark:text-blue-300'
                                              : 'border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-700 dark:bg-orange-950 dark:text-orange-300'
                                    }`}
                                >
                                    🔴 Live:{' '}
                                    {(alert.ml_live_win_prob * 100).toFixed(1)}%
                                </span>
                            )}
                        </div>

                        <div className="mt-2 grid grid-cols-3 gap-x-6 gap-y-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-9">
                            {/* Fix: Show Risk in details with 2 decimals if present */}
                            {/* Removed duplicate 'Risk' field, keep only 'Risk %' below */}
                            {alert.meta?.price && (
                                <div>
                                    <p className="text-xs text-gray-500 dark:text-gray-400">
                                        Entry Price
                                    </p>
                                    <p className="font-medium text-gray-900 dark:text-white">
                                        {formatCurrency(alert.meta.price)}
                                    </p>
                                </div>
                            )}

                            {alert.meta?.stop_loss && (
                                <div>
                                    <p className="text-xs text-gray-500 dark:text-gray-400">
                                        Stop Loss
                                    </p>
                                    <p className="font-medium text-red-600 dark:text-red-400">
                                        {formatCurrency(alert.meta.stop_loss)}
                                    </p>
                                </div>
                            )}

                            {alert.meta?.risk_percent && (
                                <div>
                                    <p className="text-xs text-gray-500 dark:text-gray-400">
                                        Risk %
                                    </p>
                                    <p className="font-medium text-gray-900 dark:text-white">
                                        {formatPercent(alert.meta.risk_percent)}
                                    </p>
                                </div>
                            )}

                            {alert.atr && (
                                <div>
                                    <p className="text-xs text-gray-500 dark:text-gray-400">
                                        ATR
                                    </p>
                                    <p className="font-medium text-gray-900 dark:text-white">
                                        {formatCurrency(alert.atr)}
                                    </p>
                                </div>
                            )}

                            {alert.atrPct && (
                                <div>
                                    <p className="text-xs text-gray-500 dark:text-gray-400">
                                        ATR %
                                    </p>
                                    <p className="font-medium text-gray-900 dark:text-white">
                                        {formatPercent(alert.atrPct)}
                                    </p>
                                </div>
                            )}

                            {alert.ml_win_prob !== null &&
                                alert.ml_win_prob !== undefined && (
                                    <div>
                                        <p className="text-xs text-gray-500 dark:text-gray-400">
                                            ML Win Prob
                                        </p>
                                        <p
                                            className={`font-semibold ${
                                                alert.ml_win_prob >= 0.65
                                                    ? 'text-emerald-600 dark:text-emerald-400'
                                                    : alert.ml_win_prob >= 0.5
                                                      ? 'text-blue-600 dark:text-blue-400'
                                                      : 'text-gray-600 dark:text-gray-400'
                                            }`}
                                        >
                                            {(alert.ml_win_prob * 100).toFixed(
                                                1,
                                            )}
                                            %
                                        </p>
                                    </div>
                                )}

                            {alert.suggestedTrailingStop && (
                                <div>
                                    <p className="text-xs text-gray-500 dark:text-gray-400">
                                        Trailing Stop
                                    </p>
                                    <p className="font-medium text-orange-600 dark:text-orange-400">
                                        {formatCurrency(
                                            alert.suggestedTrailingStop,
                                        )}
                                    </p>
                                </div>
                            )}

                            {alert.suggestedTrailingStopPct && (
                                <div className="flex flex-col">
                                    <p className="mb-0 text-xs whitespace-nowrap text-gray-500 dark:text-gray-400">
                                        Trailing Stop %
                                    </p>
                                    <span className="font-medium whitespace-nowrap text-orange-600 dark:text-orange-400">
                                        {formatPercent(
                                            alert.suggestedTrailingStopPct,
                                        )}
                                    </span>
                                </div>
                            )}

                            {alert.meta?.score && (
                                <div>
                                    <p className="text-xs text-gray-500 dark:text-gray-400">
                                        Entry Score
                                    </p>
                                    <p className="font-medium text-gray-900 dark:text-white">
                                        {formatNumber(alert.meta.score, 1)}
                                    </p>
                                </div>
                            )}

                            {alert.meta?.vol_ratio && (
                                <div>
                                    <p className="text-xs text-gray-500 dark:text-gray-400">
                                        Vol Ratio
                                    </p>
                                    <p className="font-medium text-gray-900 dark:text-white">
                                        {formatRatio(alert.meta.vol_ratio)}
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {/* Time in top right */}
                <div className="ml-4 flex-shrink-0 text-right">
                    <div className="text-sm text-gray-500 dark:text-gray-400">
                        {/* Signal Time */}
                        <div className="mb-2">
                            <div className="text-xs font-medium text-gray-600 dark:text-gray-500">Signal Time</div>
                            <div className="flex items-center justify-end">
                                <Clock className="mr-1 h-4 w-4" />
                                <span>{alert.signal_time_est}</span>
                            </div>
                            <div className="mt-1 text-xs">{alert.signal_time_ago}</div>
                        </div>
                        {/* Alert Created Time */}
                        <div className="border-t border-gray-200 pt-2 dark:border-gray-700">
                            <div className="text-xs font-medium text-gray-600 dark:text-gray-500">Alert Detection</div>
                            <div className="flex items-center justify-end">
                                <Bell className="mr-1 h-4 w-4" />
                                <span>{alert.alert_created_time_est}</span>
                            </div>
                            <div className="mt-1 text-xs">{alert.alert_created_time_ago}</div>
                        </div>
                        <div className="border-t border-gray-200 pt-2 dark:border-gray-700">
                            <div className="text-xs font-medium text-gray-600 dark:text-gray-500">Alert Created (DB)</div>
                            <div className="flex items-center justify-end">
                                <Bell className="mr-1 h-4 w-4" />
                                <span>{alert.db_created_time_est}</span>
                            </div>
                            <div className="mt-1 text-xs">{alert.db_created_time_ago}</div>
                        </div>
                        {/* Staleness */}
                        <div className="mt-2 border-t border-gray-200 pt-2 dark:border-gray-700">
                            <div className="text-xs font-semibold text-orange-600 dark:text-orange-400">Staleness: {alert.staleness_formatted}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head
                title={
                    isUnfiltered ? 'Unfiltered Trade Alerts' : 'Trade Alerts'
                }
            />
            <Toaster position="top-right" />

            <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                {/* Header */}
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                            {isUnfiltered
                                ? 'Unfiltered Trade Alerts'
                                : 'Trade Alerts'}
                        </h1>
                        <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            {isUnfiltered && (
                                <span className="mr-2 inline-flex items-center rounded-md border border-amber-200 bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800 dark:border-amber-700 dark:bg-amber-900 dark:text-amber-200">
                                    Includes all entries (filtered & unfiltered)
                                </span>
                            )}
                            Showing {pagination.from ?? 0}-{pagination.to ?? 0}{' '}
                            of {pagination.total} alerts
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <button
                            onClick={handleRefresh}
                            disabled={refreshing}
                            className="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                        >
                            <Clock
                                className={`mr-2 h-4 w-4 ${refreshing ? 'animate-spin' : ''}`}
                            />
                            {refreshing ? 'Refreshing...' : 'Refresh'}
                        </button>
                    </div>
                </div>

                {/* ML Filter Section */}
                <div className="mb-6 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <div className="flex items-end gap-4">
                        <div>
                            <label className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                                Date
                            </label>
                            <input
                                type="date"
                                value={dateFilter}
                                onChange={(e) => setDateFilter(e.target.value)}
                                className="rounded border bg-white px-2 py-1 text-sm text-gray-900 dark:bg-gray-900 dark:text-gray-100"
                            />
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                                Symbol
                            </label>
                            <input
                                type="text"
                                value={symbolFilter}
                                onChange={(e) =>
                                    setSymbolFilter(
                                        e.target.value.toUpperCase(),
                                    )
                                }
                                placeholder="e.g., AAPL"
                                className="w-28 rounded border bg-white px-2 py-1 font-mono text-sm text-gray-900 dark:bg-gray-900 dark:text-gray-100"
                            />
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                                ML Min %
                            </label>
                            <select
                                value={mlMinThreshold}
                                onChange={(e) =>
                                    setMlMinThreshold(Number(e.target.value))
                                }
                                className="rounded border bg-white px-2 py-1 text-sm text-gray-900 dark:bg-gray-900 dark:text-gray-100"
                            >
                                <option value={0}>All</option>
                                <option value={-1}>.env ({pipelineFilter ? Math.round((pipelineMlThresholds[pipelineFilter] ?? 0.65) * 100) : 'per pipeline'}%)</option>
                                <option value={50}>≥50%</option>
                                <option value={52.5}>≥52.5%</option>
                                <option value={55}>≥55%</option>
                                <option value={57.5}>≥57.5%</option>
                                <option value={60}>≥60%</option>
                                <option value={62.5}>≥62.5%</option>
                                <option value={65}>≥65%</option>
                                <option value={67.5}>≥67.5%</option>
                                <option value={70}>≥70%</option>
                                <option value={72.5}>≥72.5%</option>
                                <option value={75}>≥75%</option>
                                <option value={77.5}>≥77.5%</option>
                                <option value={80}>≥80%</option>
                                <option value={82.5}>≥82.5%</option>
                                <option value={85}>≥85%</option>
                                <option value={87.5}>≥87.5%</option>
                                <option value={90}>≥90%</option>
                                <option value={92.5}>≥92.5%</option>
                                <option value={95}>≥95%</option>
                                <option value={97.5}>≥97.5%</option>
                            </select>
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                                Pipeline
                            </label>
                            <select
                                value={pipelineFilter}
                                onChange={(e) =>
                                    setPipelineFilter(e.target.value)
                                }
                                className="rounded border bg-white px-2 py-1 text-sm text-gray-900 dark:bg-gray-900 dark:text-gray-100"
                            >
                                <option value="">All Pipelines</option>
                                <option value="A">Pipeline A</option>
                                <option value="B">Pipeline B</option>
                                <option value="C">Pipeline C</option>
                                <option value="D">Pipeline D</option>
                                <option value="E">Pipeline E</option>
                                <option value="F">Pipeline F</option>
                                <option value="G">Pipeline G</option>
                                <option value="H">Pipeline H</option>
                                <option value="I">Pipeline I</option>
                                <option value="J">Pipeline J</option>
                                <option value="K">Pipeline K</option>
                                <option value="L">Pipeline L</option>
                                <option value="M">Pipeline M</option>
                                <option value="N">Pipeline N</option>
                                <option value="O">Pipeline O</option>
                                <option value="P">Pipeline P</option>
                                <option value="Q">Pipeline Q</option>
                                <option value="R">Pipeline R</option>
                                <option value="S">Pipeline S</option>
                                <option value="MANUAL">Manual</option>
                                <option value="EXTERNAL">External</option>
                            </select>
                        </div>
                        <div>
                            <button
                                onClick={handleFilter}
                                className="inline-flex items-center rounded-md border border-blue-600 bg-blue-50 px-4 py-2 text-sm font-medium text-blue-700 shadow-sm hover:bg-blue-100 focus:ring-2 focus:ring-blue-500 focus:outline-none dark:bg-blue-900/20 dark:text-blue-300 dark:hover:bg-blue-800/40"
                            >
                                Apply Filter
                            </button>
                        </div>
                        <div>
                            <button
                                onClick={() => {
                                    const now = new Date();
                                    const estDate = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/New_York' }).format(now);
                                    setDateFilter(estDate);
                                }}
                                className="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                            >
                                Today
                            </button>
                        </div>
                        {(mlMinThreshold > 0 || dateFilter || symbolFilter || pipelineFilter) && (
                            <div className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                                {dateFilter && <span>📅 {dateFilter}</span>}
                                {symbolFilter && <span>🏷️ {symbolFilter}</span>}
                                {mlMinThreshold > 0 && (
                                    <span>🤖 ML ≥{mlMinThreshold}%</span>
                                )}
                                {pipelineFilter && <span>🔷 {pipelineFilter}</span>}
                                <button
                                    onClick={() => {
                                        setDateFilter('');
                                        setSymbolFilter('');
                                        setMlMinThreshold(0);
                                        setPipelineFilter('');
                                        const baseRoute = isUnfiltered
                                            ? '/trade-alerts-unfiltered'
                                            : '/trade-alerts';
                                        router.visit(baseRoute, {
                                            preserveState: false,
                                        });
                                    }}
                                    className="text-xs text-gray-400 underline hover:text-gray-600 dark:hover:text-gray-200"
                                >
                                    Clear
                                </button>
                            </div>
                        )}
                    </div>
                </div>

                {/* Data Health Warning Banner */}
                {dataHealth && !dataHealth.daily_prices_healthy && (
                    <div className="mb-6 rounded-lg border-2 border-red-500 bg-red-50 p-4 dark:bg-red-900/20 dark:border-red-700">
                        <div className="flex items-start">
                            <AlertTriangle className="h-6 w-6 text-red-600 dark:text-red-400 mr-3 mt-0.5 flex-shrink-0" />
                            <div className="flex-1">
                                <h3 className="text-lg font-semibold text-red-800 dark:text-red-300">
                                    ⚠️ Data Pipeline Issue Detected
                                </h3>
                                <p className="mt-1 text-sm text-red-700 dark:text-red-400">
                                    {dataHealth.message}
                                </p>
                                <p className="mt-2 text-xs text-red-600 dark:text-red-500">
                                    Expected last trading day: <strong>{dataHealth.last_trading_day}</strong> | 
                                    Actual last date: <strong>{dataHealth.actual_last_daily_price_date || 'N/A'}</strong>
                                </p>
                                <p className="mt-2 text-xs text-red-600 dark:text-red-500 font-medium">
                                    Fix: Run <code className="bg-red-100 dark:bg-red-800 px-1.5 py-0.5 rounded">php artisan market:generate-daily-prices --days=3</code>
                                </p>
                            </div>
                        </div>
                    </div>
                )}

                {/* Combined Alerts Section */}
                <div className="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                    {alerts.length === 0 ? (
                        <div className="py-12 text-center">
                            <AlertTriangle className="mx-auto h-12 w-12 text-gray-400" />
                            <h3 className="mt-2 text-sm font-medium text-gray-900 dark:text-white">
                                No alerts
                            </h3>
                            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                No trade alerts found in the last 24 hours.
                            </p>
                        </div>
                    ) : (
                        <div className="divide-y divide-gray-200 dark:divide-gray-700">
                            {alerts.map(renderAlertCard)}
                        </div>
                    )}
                </div>

                {/* Pagination */}
                {pagination.last_page > 1 && (
                    <div className="mt-6 flex items-center justify-between">
                        <div className="flex flex-1 justify-between sm:hidden">
                            <button
                                onClick={() =>
                                    router.get(
                                        `${isUnfiltered ? '/trade-alerts-unfiltered' : '/trade-alerts'}?page=${pagination.current_page - 1}`,
                                    )
                                }
                                disabled={pagination.current_page === 1}
                                className="relative inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                            >
                                Previous
                            </button>
                            <button
                                onClick={() =>
                                    router.get(
                                        `${isUnfiltered ? '/trade-alerts-unfiltered' : '/trade-alerts'}?page=${pagination.current_page + 1}`,
                                    )
                                }
                                disabled={
                                    pagination.current_page ===
                                    pagination.last_page
                                }
                                className="relative ml-3 inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                            >
                                Next
                            </button>
                        </div>
                        <div className="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                            <div>
                                <p className="text-sm text-gray-700 dark:text-gray-300">
                                    Page{' '}
                                    <span className="font-medium">
                                        {pagination.current_page}
                                    </span>{' '}
                                    of{' '}
                                    <span className="font-medium">
                                        {pagination.last_page}
                                    </span>
                                </p>
                            </div>
                            <div>
                                <nav
                                    className="relative z-0 inline-flex -space-x-px rounded-md shadow-sm"
                                    aria-label="Pagination"
                                >
                                    <button
                                        onClick={() =>
                                            router.get(
                                                `${isUnfiltered ? '/trade-alerts-unfiltered' : '/trade-alerts'}?page=${pagination.current_page - 1}`,
                                            )
                                        }
                                        disabled={pagination.current_page === 1}
                                        className="relative inline-flex items-center rounded-l-md border border-gray-300 bg-white px-2 py-2 text-sm font-medium text-gray-500 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700"
                                    >
                                        <span className="sr-only">
                                            Previous
                                        </span>
                                        <svg
                                            className="h-5 w-5"
                                            xmlns="http://www.w3.org/2000/svg"
                                            viewBox="0 0 20 20"
                                            fill="currentColor"
                                            aria-hidden="true"
                                        >
                                            <path
                                                fillRule="evenodd"
                                                d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z"
                                                clipRule="evenodd"
                                            />
                                        </svg>
                                    </button>

                                    {[...Array(pagination.last_page)].map(
                                        (_, i) => {
                                            const pageNum = i + 1;
                                            const isCurrentPage =
                                                pageNum ===
                                                pagination.current_page;

                                            // Show first page, last page, current page, and pages around current
                                            const shouldShow =
                                                pageNum === 1 ||
                                                pageNum ===
                                                    pagination.last_page ||
                                                (pageNum >=
                                                    pagination.current_page -
                                                        1 &&
                                                    pageNum <=
                                                        pagination.current_page +
                                                            1);

                                            if (!shouldShow) {
                                                // Show ellipsis for gaps
                                                if (
                                                    pageNum === 2 &&
                                                    pagination.current_page > 3
                                                ) {
                                                    return (
                                                        <span
                                                            key={`ellipsis-${pageNum}`}
                                                            className="relative inline-flex items-center border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300"
                                                        >
                                                            ...
                                                        </span>
                                                    );
                                                }
                                                if (
                                                    pageNum ===
                                                        pagination.last_page -
                                                            1 &&
                                                    pagination.current_page <
                                                        pagination.last_page - 2
                                                ) {
                                                    return (
                                                        <span
                                                            key={`ellipsis-${pageNum}`}
                                                            className="relative inline-flex items-center border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300"
                                                        >
                                                            ...
                                                        </span>
                                                    );
                                                }
                                                return null;
                                            }

                                            return (
                                                <button
                                                    key={pageNum}
                                                    onClick={() =>
                                                        router.get(
                                                            `${isUnfiltered ? '/trade-alerts-unfiltered' : '/trade-alerts'}?page=${pageNum}`,
                                                        )
                                                    }
                                                    className={`relative inline-flex items-center border px-4 py-2 text-sm font-medium ${
                                                        isCurrentPage
                                                            ? 'z-10 border-blue-500 bg-blue-50 text-blue-600 dark:bg-blue-900/20 dark:text-blue-400'
                                                            : 'border-gray-300 bg-white text-gray-500 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700'
                                                    }`}
                                                >
                                                    {pageNum}
                                                </button>
                                            );
                                        },
                                    )}

                                    <button
                                        onClick={() =>
                                            router.get(
                                                `${isUnfiltered ? '/trade-alerts-unfiltered' : '/trade-alerts'}?page=${pagination.current_page + 1}`,
                                            )
                                        }
                                        disabled={
                                            pagination.current_page ===
                                            pagination.last_page
                                        }
                                        className="relative inline-flex items-center rounded-r-md border border-gray-300 bg-white px-2 py-2 text-sm font-medium text-gray-500 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700"
                                    >
                                        <span className="sr-only">Next</span>
                                        <svg
                                            className="h-5 w-5"
                                            xmlns="http://www.w3.org/2000/svg"
                                            viewBox="0 0 20 20"
                                            fill="currentColor"
                                            aria-hidden="true"
                                        >
                                            <path
                                                fillRule="evenodd"
                                                d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                                clipRule="evenodd"
                                            />
                                        </svg>
                                    </button>
                                </nav>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
