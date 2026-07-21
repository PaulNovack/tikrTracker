import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';

/** Today's date in America/New_York timezone as YYYY-MM-DD. */
function estToday(): string {
    const fmt = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/New_York',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    });
    return fmt.format(new Date());
}

interface Trade {
    symbol: string;
    asset_id?: number;
    entry_type: string;
    entry_price: number;
    exit_price: number;
    entry_time: string;
    exit_time: string;
    exit_reason: string;
    pnl_percent: number;
    pnl_dollar: number;
    calculated_position_size: number;
    effective_position_size: number;
    position_dollar_pnl: number;
    risk_pct: number;
    risk_level: 'low' | 'medium' | 'high';
    risk_adjusted_return: number;
    score: number;
    vol_ratio: number;
    atr_pct: number;
    atr_trailing_stop_pct: number;
    target_hit: string;
    is_winner: boolean;
    was_stopped_out: boolean;
    version: string;
    pipeline_run: string;
    ml_win_prob?: number | null;
    ml_scored_at?: string | null;
    ml_model_version?: string | null;
}

interface Summary {
    total_trades: number;
    winners: number;
    losers: number;
    win_rate: number;
    avg_pnl: number;
    total_pnl: number;
    avg_winning_trade: number;
    avg_losing_trade: number;
    gross_profit: number;
    gross_loss: number;
    profit_factor: number;
    avg_risk_adjusted_return: number;
    avg_atr_pct: number;
    stop_outs: number;
    stop_out_rate: number;
    total_dollar_pnl: number;
    realized_profit_dollar: number;
    realized_loss_dollar: number;
    total_amount_invested: number;
}

interface TargetBreakdown {
    target: string;
    count: number;
}

interface Props {
    auth: {
        user: any;
    };
    pipeline: string;
    version: string;
    pipelineAVersion: string;
    pipelineALabel: string;
    pipelineBVersion: string;
    pipelineBLabel: string;
    pipelineCVersion: string;
    pipelineCLabel: string;
    pipelineDVersion: string;
    pipelineDLabel: string;
    pipelineEVersion: string;
    pipelineELabel: string;
    pipelineFVersion: string;
    pipelineFLabel: string;
    pipelineGVersion: string;
    pipelineGLabel: string;
    pipelineHVersion: string;
    pipelineHLabel: string;
    pipelineIVersion: string;
    pipelineILabel: string;
    pipelineJVersion: string;
    pipelineJLabel: string;
    pipelineKVersion: string;
    pipelineKLabel: string;
    pipelineLVersion: string;
    pipelineLLabel: string;
    pipelineMVersion: string;
    pipelineMLabel: string;
    pipelineNVersion: string;
    pipelineNLabel: string;
    pipelineOVersion: string;
    pipelineOLabel: string;
    pipelinePVersion: string;
    pipelinePLabel: string;
    pipelineQVersion: string;
    pipelineQLabel: string;
    pipelineRVersion: string;
    pipelineRLabel: string;
    pipelineSVersion: string;
    pipelineSLabel: string;
    pipelineBiased1Version: string;
    pipelineBiased1Label: string;
    atrMultiplier: number;
    atrMinPct: number;
    atrMaxPct: number;
    summary: Summary | null;
    trades: Trade[];
    targetBreakdown: TargetBreakdown[];
    availableEntryTypes: string[];
    selectedEntryType?: string;
    isUnfiltered?: boolean;
    firstOnly?: boolean;
    hideBlacklisted?: boolean;
    useFullTables?: boolean;
    pipelineMlThresholds?: Record<string, number>;
}

export default function Index({
    auth,
    pipeline,
    version,
    pipelineAVersion,
    pipelineALabel,
    pipelineBVersion,
    pipelineBLabel,
    pipelineCVersion,
    pipelineCLabel,
    pipelineDVersion,
    pipelineDLabel,
    pipelineEVersion,
    pipelineELabel,
    pipelineFVersion,
    pipelineFLabel,
    pipelineGVersion,
    pipelineGLabel,
    pipelineHVersion,
    pipelineHLabel,
    pipelineIVersion,
    pipelineILabel,
    pipelineJVersion,
    pipelineJLabel,
    pipelineKVersion,
    pipelineKLabel,
    pipelineLVersion,
    pipelineLLabel,
    pipelineMVersion,
    pipelineMLabel,
    pipelineNVersion,
    pipelineNLabel,
    pipelineOVersion,
    pipelineOLabel,
    pipelinePVersion,
    pipelinePLabel,
    pipelineQVersion,
    pipelineQLabel,
    pipelineRVersion,
    pipelineRLabel,
    pipelineSVersion,
    pipelineSLabel,
    pipelineBiased1Version,
    pipelineBiased1Label,
    atrMultiplier,
    atrMinPct,
    atrMaxPct,
    summary,
    trades,
    targetBreakdown,
    availableEntryTypes,
    selectedEntryType,
    isUnfiltered = false,
    firstOnly = false,
    hideBlacklisted = true,
    useFullTables = false,
    pipelineMlThresholds = {},
}: Props) {
    // Date filter state
    const params =
        typeof window !== 'undefined'
            ? new URLSearchParams(window.location.search)
            : new URLSearchParams();
    const todayDate = estToday();
    const [startDate, setStartDate] = useState<string | undefined>(
        params.get('start_date') || todayDate,
    );
    const [endDate, setEndDate] = useState<string | undefined>(
        params.get('end_date') || todayDate,
    );
    const [entryType, setEntryType] = useState<string>(selectedEntryType || '');
    const [mlMinThreshold, setMlMinThreshold] = useState<number>(
        params.get('ml_min') !== null ? Number(params.get('ml_min')) : -1,
    );
    const [minScore, setMinScore] = useState<number>(
        params.get('min_score') !== null ? Number(params.get('min_score')) : 0,
    );
    const [firstOnlyEnabled, setFirstOnlyEnabled] =
        useState<boolean>(firstOnly);
    const [hideBlacklistedEnabled, setHideBlacklistedEnabled] =
        useState<boolean>(hideBlacklisted);
    const [readMeOpen, setReadMeOpen] = useState(false);
    const [useFullTablesEnabled, setUseFullTablesEnabled] =
        useState<boolean>(useFullTables);
    const [useTimeSlotsEnabled, setUseTimeSlotsEnabled] = useState<boolean>(
        params.get('use_time_slots') === 'true',
    );

    const handlePipelineChange = (newPipeline: string) => {
        const params = new URLSearchParams();
        params.set('pipeline', newPipeline);
        if (startDate) params.set('start_date', startDate);
        if (endDate) params.set('end_date', endDate);
        if (mlMinThreshold !== -1) params.set('ml_min', mlMinThreshold.toString());
        if (minScore > 0) params.set('min_score', minScore.toString());
        if (firstOnlyEnabled) params.set('first_only', 'true');
        params.set(
            'hide_blacklisted',
            hideBlacklistedEnabled ? 'true' : 'false',
        );
        params.set(
            'use_full_tables',
            useFullTablesEnabled ? 'true' : 'false',
        );
        params.set(
            'use_time_slots',
            useTimeSlotsEnabled ? 'true' : 'false',
        );
        // Don't preserve entry_type when switching pipelines - reset to "All"
        const baseRoute = isUnfiltered
            ? '/backtest-results-unfiltered'
            : '/backtest-results';
        router.visit(`${baseRoute}?${params.toString()}`, {
            preserveState: false,
            preserveScroll: false,
        });
    };

    const handleDateChange = (which: 'start' | 'end', value: string) => {
        if (which === 'start') setStartDate(value);
        else setEndDate(value);
    };

    const handleFilter = () => {
        const params = new URLSearchParams();
        params.set('pipeline', pipeline);
        if (startDate) params.set('start_date', startDate);
        if (endDate) params.set('end_date', endDate);
        if (entryType) params.set('entry_type', entryType);
        if (mlMinThreshold !== -1) params.set('ml_min', mlMinThreshold.toString());
        if (firstOnlyEnabled) params.set('first_only', 'true');
        params.set(
            'hide_blacklisted',
            hideBlacklistedEnabled ? 'true' : 'false',
        );
        params.set(
            'use_full_tables',
            useFullTablesEnabled ? 'true' : 'false',
        );
        params.set(
            'use_time_slots',
            useTimeSlotsEnabled ? 'true' : 'false',
        );
        const baseRoute = isUnfiltered
            ? '/backtest-results-unfiltered'
            : '/backtest-results';
        router.visit(`${baseRoute}?${params.toString()}`, {
            preserveState: false,
            preserveScroll: false,
        });
    };

    const navigateDate = (direction: 'back' | 'forward') => {
        const currentDate =
            startDate || endDate || estToday();

        // Parse as UTC to avoid DST/timezone shifting the date
        const [year, month, day] = currentDate.split('-').map(Number);
        let ms = Date.UTC(year, month - 1, day);

        // Move one day
        ms += (direction === 'forward' ? 1 : -1) * 86400000;

        // Skip weekends using UTC day
        const dow = new Date(ms).getUTCDay(); // 0=Sun, 6=Sat
        if (direction === 'forward') {
            if (dow === 6) ms += 2 * 86400000; // Sat → Mon
            else if (dow === 0) ms += 1 * 86400000; // Sun → Mon
        } else {
            if (dow === 0) ms -= 2 * 86400000; // Sun → Fri
            else if (dow === 6) ms -= 1 * 86400000; // Sat → Fri
        }

        const d = new Date(ms);
        const newDate = [
            d.getUTCFullYear(),
            String(d.getUTCMonth() + 1).padStart(2, '0'),
            String(d.getUTCDate()).padStart(2, '0'),
        ].join('-');

        const params = new URLSearchParams();
        params.set('pipeline', pipeline);
        params.set('start_date', newDate);
        params.set('end_date', newDate);
        if (entryType) params.set('entry_type', entryType);
        if (mlMinThreshold !== -1) params.set('ml_min', mlMinThreshold.toString());
        if (firstOnlyEnabled) params.set('first_only', 'true');
        params.set(
            'hide_blacklisted',
            hideBlacklistedEnabled ? 'true' : 'false',
        );
        params.set(
            'use_full_tables',
            useFullTablesEnabled ? 'true' : 'false',
        );
        params.set(
            'use_time_slots',
            useTimeSlotsEnabled ? 'true' : 'false',
        );
        const baseRoute = isUnfiltered
            ? '/backtest-results-unfiltered'
            : '/backtest-results';
        router.visit(`${baseRoute}?${params.toString()}`, {
            preserveState: false,
            preserveScroll: false,
        });
    };

    // Filter trades based on ML threshold and min score (client-side)
    const filteredTrades = useMemo(() => {
        return trades.filter((trade) => {
            // If ML prediction doesn't exist, exclude when filter is active
            if (trade.ml_win_prob === null || trade.ml_win_prob === undefined) {
                return mlMinThreshold === 0; // Only include if no filter applied
            }
            const mlPercent = trade.ml_win_prob * 100;
            if (mlMinThreshold === -1) {
                // .env mode: use each trade's own pipeline threshold
                const tradeThreshold = (pipelineMlThresholds[trade.pipeline_run] ?? 0.65) * 100;
                if (mlPercent < tradeThreshold) return false;
            } else if (mlPercent < mlMinThreshold) {
                return false;
            }
            // Filter by minimum score
            if (minScore > 0 && (trade.score === null || trade.score === undefined || trade.score < minScore)) {
                return false;
            }

            return true;
        });
    }, [trades, mlMinThreshold, minScore, pipelineMlThresholds]);

    // Recalculate stats based on filtered trades
    const filteredSummary = useMemo(() => {
        const uniqueSymbols = new Set(filteredTrades.map((trade) => trade.symbol)).size;
        const zeroTargetAchievement = {
            '3R': 0,
            '2R': 0,
            '1R': 0,
            none: 0,
        };

        if (filteredTrades.length === 0) {
            return {
                total_trades: 0,
                winners: 0,
                losers: 0,
                win_rate: 0,
                avg_pnl: 0,
                total_pnl: 0,
                avg_winning_trade: 0,
                avg_losing_trade: 0,
                gross_profit: 0,
                gross_loss: 0,
                profit_factor: 0,
                avg_risk_adjusted_return: 0,
                avg_atr_pct: 0,
                stop_outs: 0,
                stop_out_rate: 0,
                unique_symbols: 0,
                total_dollar_pnl: 0,
                realized_profit_dollar: 0,
                realized_loss_dollar: 0,
                total_amount_invested: 0,
                target_achievement: zeroTargetAchievement,
            };
        }

        const winners = filteredTrades.filter((t) => t.pnl_percent > 0);
        const losers = filteredTrades.filter((t) => t.pnl_percent < 0);
        const winCount = winners.length;
        const lossCount = losers.length;
        const winRate =
            filteredTrades.length > 0
                ? (winCount / filteredTrades.length) * 100
                : 0;

        const totalPnl = filteredTrades.reduce(
            (sum, t) => sum + t.pnl_percent,
            0,
        );
        const avgPnl =
            filteredTrades.length > 0 ? totalPnl / filteredTrades.length : 0;

        const avgWinningTrade =
            winCount > 0
                ? winners.reduce((sum, t) => sum + t.pnl_percent, 0) / winCount
                : 0;
        const avgLosingTrade =
            lossCount > 0
                ? losers.reduce((sum, t) => sum + t.pnl_percent, 0) / lossCount
                : 0;

        const grossProfit = winners.reduce((sum, t) => sum + t.pnl_percent, 0);
        const grossLoss = Math.abs(
            losers.reduce((sum, t) => sum + t.pnl_percent, 0),
        );
        const profitFactor = grossLoss > 0 ? grossProfit / grossLoss : 0;

        const avgRiskAdjustedReturn =
            filteredTrades.length > 0
                ? filteredTrades.reduce(
                      (sum, t) => sum + t.risk_adjusted_return,
                      0,
                  ) / filteredTrades.length
                : 0;

        const avgAtr =
            filteredTrades.length > 0
                ? filteredTrades.reduce((sum, t) => sum + t.atr_pct, 0) /
                  filteredTrades.length
                : 0;

        const atrStopOuts = filteredTrades.filter(
            (t) => t.was_stopped_out,
        ).length;

        const targetAchievement = {
            '3R': filteredTrades.filter((t) => t.risk_adjusted_return >= 3).length,
            '2R': filteredTrades.filter(
                (t) => t.risk_adjusted_return >= 2 && t.risk_adjusted_return < 3,
            ).length,
            '1R': filteredTrades.filter(
                (t) => t.risk_adjusted_return >= 1 && t.risk_adjusted_return < 2,
            ).length,
            none: filteredTrades.filter((t) => t.risk_adjusted_return < 1).length,
        };

        // Calculate dollar-based metrics
        const totalDollarPnl = filteredTrades.reduce(
            (sum, t) => sum + t.position_dollar_pnl,
            0,
        );
        const realizedProfitDollar = winners.reduce(
            (sum, t) => sum + t.position_dollar_pnl,
            0,
        );
        const realizedLossDollar = losers.reduce(
            (sum, t) => sum + t.position_dollar_pnl,
            0,
        );
        const totalAmountInvested = filteredTrades.reduce(
            (sum, t) => sum + t.effective_position_size,
            0,
        );

        return {
            total_trades: filteredTrades.length,
            winners: winCount,
            losers: lossCount,
            win_rate: winRate,
            avg_pnl: avgPnl,
            total_pnl: totalPnl,
            avg_winning_trade: avgWinningTrade,
            avg_losing_trade: avgLosingTrade,
            gross_profit: grossProfit,
            gross_loss: grossLoss,
            profit_factor: profitFactor,
            avg_risk_adjusted_return: avgRiskAdjustedReturn,
            avg_atr_pct: avgAtr,
            stop_outs: atrStopOuts,
            stop_out_rate: filteredTrades.length > 0 ? (atrStopOuts / filteredTrades.length) * 100 : 0,
            unique_symbols: uniqueSymbols,
            target_achievement: targetAchievement,
            total_dollar_pnl: totalDollarPnl,
            realized_profit_dollar: realizedProfitDollar,
            realized_loss_dollar: realizedLossDollar,
            total_amount_invested: totalAmountInvested,
        };
    }, [filteredTrades, summary]);

    // Recalculate target breakdown based on filtered summary
    const filteredTargetBreakdown = useMemo(
        () => [
            {
                target: '3R',
                count: filteredSummary?.target_achievement?.['3R'] || 0,
                label: '3R+',
                bgColor: 'bg-green-100 dark:bg-green-900/30',
            },
            {
                target: '2R',
                count: filteredSummary?.target_achievement?.['2R'] || 0,
                label: '2R',
                bgColor: 'bg-blue-100 dark:bg-blue-900/30',
            },
            {
                target: '1R',
                count: filteredSummary?.target_achievement?.['1R'] || 0,
                label: '1R',
                bgColor: 'bg-yellow-100 dark:bg-yellow-900/30',
            },
            {
                target: 'none',
                count: filteredSummary?.target_achievement?.['none'] || 0,
                label: 'none',
                bgColor: 'bg-red-100 dark:bg-red-900/30',
            },
        ],
        [filteredSummary],
    );

    const getStatusBadge = (winRate: number) => {
        if (winRate >= 50)
            return {
                text: '✅ GOOD',
                color: 'text-green-600 dark:text-green-400',
            };
        if (winRate >= 40)
            return {
                text: '⚠️ OK',
                color: 'text-yellow-600 dark:text-yellow-400',
            };
        return { text: '❌ POOR', color: 'text-red-600 dark:text-red-400' };
    };

    const getProfitFactorStatus = (pf: number) => {
        if (pf > 1.5)
            return {
                text: '✅ EXCELLENT',
                color: 'text-green-600 dark:text-green-400',
            };
        if (pf > 1.0)
            return {
                text: '⚠️ BORDERLINE',
                color: 'text-yellow-600 dark:text-yellow-400',
            };
        return { text: '❌ < 1.0', color: 'text-red-600 dark:text-red-400' };
    };

    const getOverallAssessment = () => {
        if (!filteredSummary) return null;

        if (
            filteredSummary.win_rate >= 55 &&
            filteredSummary.profit_factor > 1.5
        ) {
            return {
                text: '🏆 EXCELLENT: ATR trailing stops performing very well!',
                color: 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
            };
        } else if (
            filteredSummary.win_rate >= 45 &&
            filteredSummary.profit_factor > 1.0
        ) {
            return {
                text: '✅ GOOD: ATR trailing stops are profitable!',
                color: 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300',
            };
        } else if (filteredSummary.win_rate >= 40) {
            return {
                text: '⚠️ BORDERLINE: ATR stops need refinement',
                color: 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300',
            };
        }
        return {
            text: '❌ POOR: ATR trailing stops underperforming',
            color: 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300',
        };
    };

    if (!summary) {
        return (
            <AppLayout
                breadcrumbs={[
                    {
                        label: isUnfiltered
                            ? 'Backtest TA Results (Unfiltered)'
                            : 'Backtest TA Results',
                    },
                ]}
            >
                <Head
                    title={
                        isUnfiltered
                            ? 'Backtest TA Results (Unfiltered)'
                            : 'Backtest TA Results'
                    }
                />
                <div className="py-12">
                    <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                        {/* Date Navigation - Always visible */}
                        <div className="mb-4 flex justify-center gap-2">
                            <button
                                onClick={() => navigateDate('back')}
                                className="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:ring-2 focus:ring-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                                title="Previous day"
                            >
                                ← Prev Day
                            </button>
                            <button
                                onClick={() => navigateDate('forward')}
                                className="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:ring-2 focus:ring-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                                title="Next day"
                            >
                                Next Day →
                            </button>
                        </div>
                        <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                            <div className="p-6">
                                <div className="mb-4 flex items-center justify-between">
                                    <div>
                                        <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                            📊 ATR-Based Trailing Stop
                                            Performance{' '}
                                            {isUnfiltered && (
                                                <span className="text-amber-600 dark:text-amber-400">
                                                    (Unfiltered)
                                                </span>
                                            )}
                                        </h3>
                                        {isUnfiltered && (
                                            <span className="mt-1 inline-flex items-center rounded-md border border-amber-200 bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800 dark:border-amber-700 dark:bg-amber-900 dark:text-amber-200">
                                                Includes all entries (filtered &
                                                unfiltered)
                                            </span>
                                        )}
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <Link
                                            href={
                                                isUnfiltered
                                                    ? `/backtest-results?pipeline=${pipeline}&use_full_tables=${useFullTablesEnabled ? 'true' : 'false'}&use_time_slots=${useTimeSlotsEnabled ? 'true' : 'false'}`
                                                    : `/backtest-results-unfiltered?pipeline=${pipeline}&use_full_tables=${useFullTablesEnabled ? 'true' : 'false'}&use_time_slots=${useTimeSlotsEnabled ? 'true' : 'false'}`
                                            }
                                            className="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                                        >
                                            {isUnfiltered
                                                ? 'View Filtered Only'
                                                : 'View Unfiltered'}
                                        </Link>
                                        <select
                                            value={pipeline}
                                            onChange={(e) =>
                                                handlePipelineChange(
                                                    e.target.value,
                                                )
                                            }
                                            className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:ring-2 focus:ring-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                                        >
                                            <option value="ALL">
                                                All Pipelines
                                            </option>
                                            <option value="A">
                                                Pipeline A — {pipelineALabel}
                                            </option>
                                            <option value="B">
                                                Pipeline B — {pipelineBLabel}
                                            </option>
                                            <option value="C">
                                                Pipeline C — {pipelineCLabel}
                                            </option>
                                            <option value="D">
                                                Pipeline D — {pipelineDLabel}
                                            </option>
                                            <option value="E">
                                                Pipeline E — {pipelineELabel}
                                            </option>
                                            <option value="F">
                                                Pipeline F — {pipelineFLabel}
                                            </option>
                                            <option value="G">
                                                Pipeline G — {pipelineGLabel}
                                            </option>
                                            <option value="H">
                                                Pipeline H — {pipelineHLabel}
                                            </option>
                                            <option value="I">
                                                Pipeline I — {pipelineILabel}
                                            </option>
                                            <option value="J">
                                                Pipeline J — {pipelineJLabel}
                                            </option>
                                            <option value="K">
                                                Pipeline K — {pipelineKLabel}
                                            </option>
                                            <option value="L">
                                                Pipeline L — {pipelineLLabel}
                                            </option>
                                            <option value="M">
                                                Pipeline M — {pipelineMLabel}
                                            </option>
                                            <option value="N">
                                                Pipeline N — {pipelineNLabel}
                                            </option>
                                            <option value="O">
                                                Pipeline O — {pipelineOLabel}
                                            </option>
                                            <option value="P">
                                                Pipeline P — {pipelinePLabel}
                                            </option>
                                            <option value="Q">
                                                Pipeline Q — {pipelineQLabel}
                                            </option>
                                            <option value="R">
                                                Pipeline R — {pipelineRLabel}
                                            </option>
                                            <option value="S">
                                                Pipeline S — {pipelineSLabel}
                                            </option>
                                            <option value="BIASED1">
                                                Pipeline BIASED1 — {pipelineBiased1Label}
                                            </option>
                                            <option value="MANUAL">
                                                Manual
                                            </option>
                                            <option value="EXTERNAL">
                                                External
                                            </option>
                                        </select>
                                    </div>
                                </div>
                                <div className="text-center text-gray-900 dark:text-gray-100">
                                    <p>
                                        No backtest data available for version{' '}
                                        {version}
                                    </p>
                                    <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                        Run backtests with
                                        --store-backtest-results flag to
                                        populate this data.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </AppLayout>
        );
    }

    const assessment = getOverallAssessment();

    return (
        <AppLayout
            breadcrumbs={[
                {
                    label: isUnfiltered
                        ? 'Backtest TA Results (Unfiltered)'
                        : 'Backtest TA Results',
                },
            ]}
        >
            <Head
                title={
                    isUnfiltered
                        ? 'Backtest TA Results (Unfiltered)'
                        : 'Backtest TA Results'
                }
            />

            <div className="py-8">
                {/* Filter UI */}
                <div className="mx-auto mb-4 max-w-full px-3">
                    <div className="flex flex-col gap-4 rounded-lg bg-white p-3 shadow-sm md:flex-row md:items-end dark:bg-gray-800">
                        <div>
                            <label className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                                Start Date
                            </label>
                            <input
                                type="date"
                                className="rounded border bg-white px-2 py-1 text-sm text-gray-900 dark:bg-gray-900 dark:text-gray-100"
                                value={startDate || ''}
                                onChange={(e) =>
                                    handleDateChange('start', e.target.value)
                                }
                            />
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                                End Date
                            </label>
                            <input
                                type="date"
                                className="rounded border bg-white px-2 py-1 text-sm text-gray-900 dark:bg-gray-900 dark:text-gray-100"
                                value={endDate || ''}
                                onChange={(e) =>
                                    handleDateChange('end', e.target.value)
                                }
                            />
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                                Entry Type
                            </label>
                            <select
                                value={entryType}
                                onChange={(e) => setEntryType(e.target.value)}
                                className="rounded border bg-white px-2 py-1 text-sm text-gray-900 dark:bg-gray-900 dark:text-gray-100"
                            >
                                <option value="">All Types</option>
                                {availableEntryTypes.map((type) => (
                                    <option key={type} value={type}>
                                        {type}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                                ML Min %{' '}
                                <span className="text-gray-500 dark:text-gray-400">
                                    (instant filter)
                                </span>
                            </label>
                            <select
                                value={mlMinThreshold}
                                onChange={(e) =>
                                    setMlMinThreshold(Number(e.target.value))
                                }
                                className="rounded border bg-white px-2 py-1 text-sm text-gray-900 dark:bg-gray-900 dark:text-gray-100"
                            >
                                <option value={0}>All</option>
                                <option value={-1}>.env ({Math.round((pipelineMlThresholds[pipeline] ?? 0.65) * 100)}%)</option>
                                <option value={30}>≥30%</option>
                                <option value={32.5}>≥32.5%</option>
                                <option value={35}>≥35%</option>
                                <option value={37.5}>≥37.5%</option>
                                <option value={40}>≥40%</option>
                                <option value={42.5}>≥42.5%</option>
                                <option value={45}>≥45%</option>
                                <option value={47.5}>≥47.5%</option>
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
                                Min Score{' '}
                                <span className="text-gray-500 dark:text-gray-400">
                                    (instant filter)
                                </span>
                            </label>
                            <select
                                value={minScore}
                                onChange={(e) =>
                                    setMinScore(Number(e.target.value))
                                }
                                className="rounded border bg-white px-2 py-1 text-sm text-gray-900 dark:bg-gray-900 dark:text-gray-100"
                            >
                                <option value={0}>All</option>
                                <option value={10}>≥10</option>
                                <option value={20}>≥20</option>
                                <option value={30}>≥30</option>
                                <option value={40}>≥40</option>
                                <option value={50}>≥50</option>
                                <option value={60}>≥60</option>
                                <option value={70}>≥70</option>
                                <option value={80}>≥80</option>
                                <option value={90}>≥90</option>
                                <option value={95}>≥95</option>
                            </select>
                        </div>
                        <div className="flex flex-col">
                            <label className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                                First Alert Only
                                <span
                                    className="ml-1 cursor-help text-gray-500 dark:text-gray-400"
                                    title="Match real trading: only first alert per symbol per day"
                                >
                                    ⓘ
                                </span>
                            </label>
                            <label className="relative inline-flex cursor-pointer items-center">
                                <input
                                    type="checkbox"
                                    checked={firstOnlyEnabled}
                                    onChange={(e) =>
                                        setFirstOnlyEnabled(e.target.checked)
                                    }
                                    className="peer sr-only"
                                />
                                <div className="peer h-6 w-11 rounded-full bg-gray-200 peer-checked:bg-blue-600 peer-focus:ring-4 peer-focus:ring-blue-300 peer-focus:outline-none after:absolute after:start-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:after:translate-x-full peer-checked:after:border-white rtl:peer-checked:after:-translate-x-full dark:border-gray-600 dark:bg-gray-700 dark:peer-focus:ring-blue-800"></div>
                                <span className="ms-3 text-sm font-medium text-gray-900 dark:text-gray-300">
                                    {firstOnlyEnabled ? 'ON' : 'OFF'}
                                </span>
                            </label>
                        </div>
                        <div className="flex flex-col">
                            <label className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                                Hide Blacklisted
                                <span
                                    className="ml-1 cursor-help text-gray-500 dark:text-gray-400"
                                    title="Hide 73 symbols with 3+ losses and 70%+ ML confidence"
                                >
                                    ⓘ
                                </span>
                            </label>
                            <label className="relative inline-flex cursor-pointer items-center">
                                <input
                                    type="checkbox"
                                    checked={hideBlacklistedEnabled}
                                    onChange={(e) =>
                                        setHideBlacklistedEnabled(
                                            e.target.checked,
                                        )
                                    }
                                    className="peer sr-only"
                                />
                                <div className="peer h-6 w-11 rounded-full bg-gray-200 peer-checked:bg-blue-600 peer-focus:ring-4 peer-focus:ring-blue-300 peer-focus:outline-none after:absolute after:start-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:after:translate-x-full peer-checked:after:border-white rtl:peer-checked:after:-translate-x-full dark:border-gray-600 dark:bg-gray-700 dark:peer-focus:ring-blue-800"></div>
                                <span className="ms-3 text-sm font-medium text-gray-900 dark:text-gray-300">
                                    {hideBlacklistedEnabled ? 'ON' : 'OFF'}
                                </span>
                            </label>
                        </div>
                        <div className="flex flex-col">
                            <label className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                                Use Time Slots
                                <span
                                    className="ml-1 cursor-help text-gray-500 dark:text-gray-400"
                                    title="Filter backtest results to only the 15-minute slots enabled in Trading Settings"
                                >
                                    ⓘ
                                </span>
                            </label>
                            <label className="relative inline-flex cursor-pointer items-center">
                                <input
                                    type="checkbox"
                                    checked={useTimeSlotsEnabled}
                                    onChange={(e) =>
                                        setUseTimeSlotsEnabled(e.target.checked)
                                    }
                                    className="peer sr-only"
                                />
                                <div className="peer h-6 w-11 rounded-full bg-gray-200 peer-checked:bg-blue-600 peer-focus:ring-4 peer-focus:ring-blue-300 peer-focus:outline-none after:absolute after:start-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:after:translate-x-full peer-checked:after:border-white rtl:peer-checked:after:-translate-x-full dark:border-gray-600 dark:bg-gray-700 dark:peer-focus:ring-blue-800"></div>
                                <span className="ms-3 text-sm font-medium text-gray-900 dark:text-gray-300">
                                    {useTimeSlotsEnabled ? 'ON' : 'OFF'}
                                </span>
                            </label>
                        </div>
                        <div className="flex flex-col">
                            <label className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                                Use Full Price Tables
                                <span
                                    className="ml-1 cursor-help text-gray-500 dark:text-gray-400"
                                    title="Use one_minute_prices_full for ATR exit simulation on this page"
                                >
                                    ⓘ
                                </span>
                            </label>
                            <label className="relative inline-flex cursor-pointer items-center">
                                <input
                                    type="checkbox"
                                    checked={useFullTablesEnabled}
                                    onChange={(e) =>
                                        setUseFullTablesEnabled(e.target.checked)
                                    }
                                    className="peer sr-only"
                                />
                                <div className="peer h-6 w-11 rounded-full bg-gray-200 peer-checked:bg-blue-600 peer-focus:ring-4 peer-focus:ring-blue-300 peer-focus:outline-none after:absolute after:start-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:after:translate-x-full peer-checked:after:border-white rtl:peer-checked:after:-translate-x-full dark:border-gray-600 dark:bg-gray-700 dark:peer-focus:ring-blue-800"></div>
                                <span className="ms-3 text-sm font-medium text-gray-900 dark:text-gray-300">
                                    {useFullTablesEnabled ? 'ON' : 'OFF'}
                                </span>
                            </label>
                        </div>
                        <div>
                            <button
                                onClick={handleFilter}
                                className="inline-flex items-center rounded-md border border-blue-600 bg-blue-50 px-4 py-2 text-sm font-medium text-blue-700 shadow-sm hover:bg-blue-100 focus:ring-2 focus:ring-blue-500 focus:outline-none dark:bg-blue-900/20 dark:text-blue-300 dark:hover:bg-blue-800/40"
                            >
                                Apply Filter
                            </button>
                        </div>
                        <div className="flex gap-2">
                            <button
                                onClick={() => navigateDate('back')}
                                className="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:ring-2 focus:ring-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                                title="Previous day"
                            >
                                ← Prev Day
                            </button>
                            <button
                                onClick={() => navigateDate('forward')}
                                className="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:ring-2 focus:ring-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                                title="Next day"
                            >
                                Next Day →
                            </button>
                        </div>
                        <div className="ml-auto">
                            <Link
                                href={
                                    isUnfiltered
                                        ? `/backtest-results?pipeline=${pipeline}${startDate ? `&start_date=${startDate}` : ''}${endDate ? `&end_date=${endDate}` : ''}${entryType ? `&entry_type=${entryType}` : ''}&use_full_tables=${useFullTablesEnabled ? 'true' : 'false'}&use_time_slots=${useTimeSlotsEnabled ? 'true' : 'false'}`
                                        : `/backtest-results-unfiltered?pipeline=${pipeline}${startDate ? `&start_date=${startDate}` : ''}${endDate ? `&end_date=${endDate}` : ''}${entryType ? `&entry_type=${entryType}` : ''}&use_full_tables=${useFullTablesEnabled ? 'true' : 'false'}&use_time_slots=${useTimeSlotsEnabled ? 'true' : 'false'}`
                                }
                                className="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                            >
                                {isUnfiltered
                                    ? 'View Filtered Only'
                                    : 'View Unfiltered'}
                            </Link>
                        </div>
                    </div>
                </div>
                <div className="mx-auto max-w-full space-y-4 px-3">
                    {/* Header */}
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                        <div className="p-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                        📊 ATR-Based Trailing Stop Performance{' '}
                                        {isUnfiltered && (
                                            <span className="text-amber-600 dark:text-amber-400">
                                                (Unfiltered)
                                            </span>
                                        )}
                                    </h3>
                                    <div className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                        {isUnfiltered && (
                                            <span className="mr-2 inline-flex items-center rounded-md border border-amber-200 bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800 dark:border-amber-700 dark:bg-amber-900 dark:text-amber-200">
                                                Includes all entries (filtered &
                                                unfiltered)
                                            </span>
                                        )}
                                        <p className="mb-1">
                                            <strong>Version:</strong> {version}{' '}
                                            (All Pipelines A-O)
                                        </p>
                                        <p className="mb-1">
                                            <strong>Stop Loss Strategy:</strong>{' '}
                                            ATR-based adaptive trailing stops
                                        </p>
                                        <p className="text-xs">
                                            • Multiplier: {atrMultiplier}x ATR
                                            (stock-specific volatility) • Range:{' '}
                                            {atrMinPct}% min to {atrMaxPct}% max
                                            <br />
                                            • Trails up with price (never down)
                                            • Each stock gets optimal stop based
                                            on its ATR
                                            <br />• <strong>
                                                Production:
                                            </strong>{' '}
                                            Orders placed on Alpaca with bracket
                                            orders (entry + stop loss)
                                            <br />• Initial stop placed at
                                            entry, trailing activates after +1%
                                            gain and moves up with price
                                        </p>
                                    </div>
                                </div>
                                <select
                                    value={pipeline}
                                    onChange={(e) =>
                                        handlePipelineChange(e.target.value)
                                    }
                                    className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:ring-2 focus:ring-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                                >
                                    <option value="ALL">All Pipelines</option>
                                    <option value="A">
                                        Pipeline A ({pipelineAVersion})
                                    </option>
                                    <option value="B">
                                        Pipeline B ({pipelineBVersion})
                                    </option>
                                    <option value="C">
                                        Pipeline C ({pipelineCVersion})
                                    </option>
                                    <option value="D">
                                        Pipeline D ({pipelineDVersion})
                                    </option>
                                    <option value="E">
                                        Pipeline E ({pipelineEVersion})
                                    </option>
                                    <option value="F">
                                        Pipeline F ({pipelineFVersion})
                                    </option>
                                    <option value="G">
                                        Pipeline G ({pipelineGVersion})
                                    </option>
                                    <option value="H">
                                        Pipeline H ({pipelineHVersion})
                                    </option>
                                    <option value="I">
                                        Pipeline I ({pipelineIVersion})
                                    </option>
                                    <option value="J">
                                        Pipeline J - Recent 4 Percent Plus Movers ({pipelineJVersion})
                                    </option>
                                    <option value="K">
                                        Pipeline K ({pipelineKVersion})
                                    </option>
                                    <option value="L">
                                        Pipeline L ({pipelineLVersion})
                                    </option>
                                    <option value="M">
                                        Pipeline M ({pipelineMVersion})
                                    </option>
                                    <option value="N">
                                        Pipeline N ({pipelineNVersion})
                                    </option>
                                    <option value="O">
                                        Pipeline O ({pipelineOVersion})
                                    </option>
                                    <option value="P">
                                        Pipeline P — {pipelinePLabel} ({pipelinePVersion})
                                    </option>
                                    <option value="Q">
                                        Pipeline Q — {pipelineQLabel} ({pipelineQVersion})
                                    </option>
                                    <option value="R">
                                        Pipeline R — {pipelineRLabel} ({pipelineRVersion})
                                    </option>
                                    <option value="BIASED1">
                                        Pipeline BIASED1 (
                                        {pipelineBiased1Version})
                                    </option>
                                    <option value="MANUAL">
                                        Manual
                                    </option>
                                    <option value="EXTERNAL">
                                        External
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>

                    {/* Read Me Button */}
                    <div className="mb-4 flex justify-start">
                        <Dialog open={readMeOpen} onOpenChange={setReadMeOpen}>
                            <DialogTrigger asChild>
                                <Button
                                    variant="outline"
                                    className="flex items-center gap-2 border-amber-400 bg-amber-50 text-amber-800 hover:bg-amber-100 dark:border-amber-600 dark:bg-amber-900/20 dark:text-amber-300 dark:hover:bg-amber-900/40"
                                >
                                    <span className="text-lg">📖</span> Read Me !
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="max-h-[85vh] max-w-2xl flex flex-col">
                                <DialogHeader className="shrink-0">
                                    <DialogTitle>📊 Understanding Backtest Results</DialogTitle>
                                </DialogHeader>
                                <div className="overflow-y-auto flex-1 pr-2">
                                    <div className="text-left text-sm leading-relaxed text-gray-700 dark:text-gray-300">
                                        <p className="mb-3">
                                            The Backtest Results page shows <strong>theoretical optimal trading performance</strong> —
                                            what your strategy <em>would</em> have achieved if every trade executed perfectly at the
                                            exact signal price with zero friction. These results represent an idealized best-case scenario
                                            and should be interpreted with the understanding that <strong>real-world execution will
                                            always fall short</strong> of these numbers.
                                        </p>

                                        <h4 className="mb-2 mt-4 font-semibold text-gray-900 dark:text-gray-100">What Is Slippage?</h4>
                                        <p className="mb-3">
                                            <strong>Slippage</strong> is the difference between the price you expect to trade at and the
                                            price you actually get filled at. It occurs because prices move in the milliseconds between when
                                            your system detects a signal and when your order reaches the exchange. Slippage can work against
                                            you (buying higher than expected, selling lower) or occasionally in your favor, but on average
                                            it's a cost that eats into your edge. In fast-moving stocks, slippage of $0.05–$0.20 per share
                                            is common — on a $50 stock, that's 0.1%–0.4% right off your profit. Over hundreds of trades,
                                            this compounds into a significant drag.
                                        </p>

                                        <h4 className="mb-2 mt-4 font-semibold text-gray-900 dark:text-gray-100">Precise Entry Times vs. Reality</h4>
                                        <p className="mb-3">
                                            The backtest assumes entries execute at the <strong>exact closing price of the 1-minute signal
                                            bar</strong>, down to the second. In live trading, your system must detect the signal, process
                                            the entry logic, and submit an order — all of which takes time. Even with sub-second processing,
                                            the market has already moved. Additionally, backtests use 1-minute bar closes, but the actual
                                            minute-by-minute price action can be choppy, and the "close" of a bar is an aggregate that
                                            may not reflect the price you can actually get.
                                        </p>

                                        <h4 className="mb-2 mt-4 font-semibold text-gray-900 dark:text-gray-100">Limit Orders on Entry</h4>
                                        <p className="mb-3">
                                            In production, entries are placed using <strong>limit orders</strong> — you specify the maximum
                                            price you're willing to pay. This protects you from runaway fills during volatile spikes, but
                                            it also means you may <strong>miss entries entirely</strong> if price gaps above your limit.
                                            The backtest assumes you always get filled at the signal bar close; in reality, a well-placed
                                            limit order might not get filled if the stock rips past it. Conversely, using market orders
                                            for entries guarantees fills but subjects you to immediate slippage.
                                        </p>

                                        <h4 className="mb-2 mt-4 font-semibold text-gray-900 dark:text-gray-100">Market Orders on Sell (Stop Loss Exits)</h4>
                                        <p className="mb-3">
                                            Stop loss exits use <strong>market orders</strong> once the stop price is triggered. When your
                                            stop is hit, the order converts to a market sell and fills at the next available price — which
                                            can be significantly worse than your stop price during fast drops. This is called <strong>stop
                                            slippage</strong> and is particularly severe during gap-downs or panic selling. The backtest
                                            assumes you exit exactly at the stop price or trailing stop level, but in reality a 1% stop can
                                            easily become a 1.5%–2% loss.
                                        </p>

                                        <h4 className="mb-2 mt-4 font-semibold text-gray-900 dark:text-gray-100">The Bid-Ask Spread</h4>
                                        <p className="mb-3">
                                            Every stock has a <strong>spread</strong> — the gap between the highest price a buyer will pay
                                            (bid) and the lowest price a seller will accept (ask). You buy at the ask and sell at the bid.
                                            The spread is an immediate, invisible cost: on a $100 stock with a $0.10 spread, you're instantly
                                            underwater by 0.1% the moment you enter. The backtest uses a single mid-point or close price and
                                            does not account for the spread. High-spread stocks (small caps, low liquidity) can have spreads
                                            of 0.5% or more, eroding profitability on every round-trip trade.
                                        </p>

                                        <h4 className="mb-2 mt-4 font-semibold text-gray-900 dark:text-gray-100">Realistic Expectations</h4>
                                        <p>
                                            When evaluating backtest results, a good rule of thumb is to <strong>discount the displayed
                                            P&amp;L by 25–40%</strong> to account for slippage, spread costs, missed entries, and stop loss
                                            over-slippage. A strategy showing a 2.0 profit factor in backtest may only deliver 1.3–1.5 in
                                            live trading. Use the backtest results to identify patterns, compare pipeline versions, and filter
                                            for high-probability setups — not as a guarantee of real-world returns.
                                        </p>
                                    </div>
                                </div>
                            </DialogContent>
                        </Dialog>
                    </div>

                    {/* Performance Summary */}
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                        <div className="p-4">
                            <h4 className="mb-3 text-lg font-semibold text-gray-900 dark:text-gray-100">
                                Performance Summary
                            </h4>
                            <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
                                {[
                                    {
                                        label: 'Total Trades',
                                        value: filteredSummary.total_trades,
                                        icon: '📊',
                                    },
                                    {
                                        label: 'Winners',
                                        value: filteredSummary.winners,
                                        icon: '✅',
                                    },
                                    {
                                        label: 'Losers',
                                        value: filteredSummary.losers,
                                        icon: '❌',
                                    },
                                    {
                                        label: 'Win Rate',
                                        value: `${filteredSummary.win_rate.toFixed(1)}%`,
                                        icon: '🎯',
                                        status: getStatusBadge(
                                            filteredSummary.win_rate,
                                        ),
                                    },
                                    {
                                        label: 'Average P&L',
                                        value: `${filteredSummary.avg_pnl >= 0 ? '+' : ''}${filteredSummary.avg_pnl.toFixed(2)}%`,
                                        icon: '💰',
                                        status: {
                                            text:
                                                filteredSummary.avg_pnl > 0
                                                    ? '✅ POSITIVE'
                                                    : '❌ NEGATIVE',
                                            color:
                                                filteredSummary.avg_pnl > 0
                                                    ? 'text-green-600 dark:text-green-400'
                                                    : 'text-red-600 dark:text-red-400',
                                        },
                                    },
                                    {
                                        label: 'Total P&L',
                                        value: `${filteredSummary.total_pnl >= 0 ? '+' : ''}${filteredSummary.total_pnl.toFixed(2)}%`,
                                        icon: '📈',
                                        status: {
                                            text:
                                                filteredSummary.total_pnl > 0
                                                    ? '✅ PROFITABLE'
                                                    : '❌ UNPROFITABLE',
                                            color:
                                                filteredSummary.total_pnl > 0
                                                    ? 'text-green-600 dark:text-green-400'
                                                    : 'text-red-600 dark:text-red-400',
                                        },
                                    },
                                    {
                                        label: 'Total P&L ($)',
                                        value: `${filteredSummary.total_dollar_pnl >= 0 ? '+' : ''}$${filteredSummary.total_dollar_pnl.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
                                        icon: '💵',
                                        status: {
                                            text:
                                                filteredSummary.total_dollar_pnl >
                                                0
                                                    ? '✅ PROFIT'
                                                    : '❌ LOSS',
                                            color:
                                                filteredSummary.total_dollar_pnl >
                                                0
                                                    ? 'text-green-600 dark:text-green-400'
                                                    : 'text-red-600 dark:text-red-400',
                                        },
                                    },
                                    {
                                        label: 'Amount Invested (Total)',
                                        value: `$${filteredSummary.total_amount_invested.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
                                        icon: '💼',
                                        status: {
                                            text: `${filteredSummary.total_trades} positions`,
                                            color: 'text-gray-600 dark:text-gray-400',
                                        },
                                    },
                                    {
                                        label: 'ROI on Capital',
                                        value: `${filteredSummary.total_amount_invested > 0 ? ((filteredSummary.total_dollar_pnl / filteredSummary.total_amount_invested) * 100).toFixed(2) : '0.00'}%`,
                                        icon: '📊',
                                        status: {
                                            text:
                                                filteredSummary.total_dollar_pnl >
                                                0
                                                    ? '✅ POSITIVE'
                                                    : '❌ NEGATIVE',
                                            color:
                                                filteredSummary.total_dollar_pnl >
                                                0
                                                    ? 'text-green-600 dark:text-green-400'
                                                    : 'text-red-600 dark:text-red-400',
                                        },
                                    },
                                    {
                                        label: 'Profit ($)',
                                        value: `$${filteredSummary.realized_profit_dollar.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
                                        icon: '💰',
                                    },
                                    {
                                        label: 'Loss ($)',
                                        value: `$${Math.abs(filteredSummary.realized_loss_dollar).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
                                        icon: '💸',
                                    },
                                    {
                                        label: 'Avg Winning Trade',
                                        value: `${filteredSummary.avg_winning_trade.toFixed(2)}%`,
                                        icon: '📈',
                                    },
                                    {
                                        label: 'Avg Losing Trade',
                                        value: `${filteredSummary.avg_losing_trade.toFixed(2)}%`,
                                        icon: '📉',
                                    },
                                    {
                                        label: 'Gross Profit',
                                        value: `${filteredSummary.gross_profit.toFixed(2)}%`,
                                        icon: '📈',
                                    },
                                    {
                                        label: 'Gross Loss',
                                        value: `-${filteredSummary.gross_loss.toFixed(2)}%`,
                                        icon: '📉',
                                    },
                                    {
                                        label: 'Profit Factor',
                                        value: filteredSummary.profit_factor.toFixed(
                                            2,
                                        ),
                                        icon: '⚡',
                                        status: getProfitFactorStatus(
                                            filteredSummary.profit_factor,
                                        ),
                                    },
                                    {
                                        label: 'Risk-Adjusted Return',
                                        value: `${filteredSummary.avg_risk_adjusted_return.toFixed(2)}R`,
                                        icon: '📊',
                                        status: {
                                            text:
                                                filteredSummary.avg_risk_adjusted_return >
                                                0.5
                                                    ? '✅ GOOD'
                                                    : '⚠️ WEAK',
                                            color:
                                                filteredSummary.avg_risk_adjusted_return >
                                                0.5
                                                    ? 'text-green-600 dark:text-green-400'
                                                    : 'text-yellow-600 dark:text-yellow-400',
                                        },
                                    },
                                    {
                                        label: 'Average ATR%',
                                        value: `${filteredSummary.avg_atr_pct.toFixed(2)}%`,
                                        icon: '📊',
                                    },
                                    {
                                        label: 'ATR Stop Outs',
                                        value: `${filteredSummary.stop_outs} (${filteredSummary.stop_out_rate.toFixed(1)}%)`,
                                        icon: '🛑',
                                    },
                                    {
                                        label: 'Unique Symbols',
                                        value: filteredSummary.unique_symbols,
                                        icon: '🔁',
                                    },
                                ].map((metric, index) => (
                                    <div
                                        key={index}
                                        className="rounded-lg bg-gray-50 p-3 dark:bg-gray-700/50"
                                    >
                                        <div className="flex items-center justify-between">
                                            <span className="text-sm text-gray-600 dark:text-gray-400">
                                                {metric.icon} {metric.label}
                                            </span>
                                        </div>
                                        <div className="mt-2">
                                            <span className="text-xl font-semibold text-gray-900 dark:text-gray-100">
                                                {metric.value}
                                            </span>
                                            {metric.status && (
                                                <span
                                                    className={`ml-2 text-sm font-medium ${metric.status.color}`}
                                                >
                                                    {metric.status.text}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* Target Achievement */}
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                        <div className="p-4">
                            <h4 className="mb-3 text-lg font-semibold text-gray-900 dark:text-gray-100">
                                🎯 Target Achievement
                            </h4>
                            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                                {filteredTargetBreakdown.map((item, index) => {
                                    const percentage =
                                        filteredSummary.total_trades > 0
                                            ? (
                                                  (item.count /
                                                      filteredSummary.total_trades) *
                                                  100
                                              ).toFixed(1)
                                            : '0.0';
                                    return (
                                        <div
                                            key={index}
                                            className="rounded-lg bg-gray-50 p-3 text-center dark:bg-gray-700/50"
                                        >
                                            <div className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                                {item.target}
                                            </div>
                                            <div className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                                {item.count} trades (
                                                {percentage}%)
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    </div>


                    {/* Overall Assessment */}
                    {assessment && (
                        <div className={`rounded-lg p-4 ${assessment.color}`}>
                            <p className="text-center text-lg font-semibold">
                                {assessment.text}
                            </p>
                        </div>
                    )}

                    {/* Detailed Trade Results */}
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                        <div className="p-6">
                            <h4 className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                                🔍 Detailed Trade Results
                                {mlMinThreshold > 0 && (
                                    <span className="ml-2 text-sm font-normal text-gray-600 dark:text-gray-400">
                                        (Showing {filteredTrades.length} of{' '}
                                        {trades.length} trades with ML ≥
                                        {mlMinThreshold}%)
                                    </span>
                                )}
                            </h4>
                            {filteredTrades.length === 0 &&
                            mlMinThreshold > 0 ? (
                                <div className="py-12 text-center">
                                    <p className="text-lg text-gray-500 dark:text-gray-400">
                                        No trades found with ML win probability
                                        ≥ {mlMinThreshold}%
                                    </p>
                                    <p className="mt-2 text-sm text-gray-400 dark:text-gray-500">
                                        Try lowering the ML threshold or
                                        checking a different date range
                                    </p>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead className="bg-gray-50 dark:bg-gray-700/50">
                                            <tr>
                                                <th className="px-2 py-2 text-left text-xs font-medium tracking-tight text-gray-500 uppercase dark:text-gray-400">
                                                    <div>Date / Time</div>
                                                    <div className="mt-0.5 text-[10px]">
                                                        Version
                                                    </div>
                                                </th>
                                                <th className="px-2 py-2 text-left text-xs font-medium tracking-tight text-gray-500 uppercase dark:text-gray-400">
                                                    <div>Symbol</div>
                                                    <div className="mt-0.5 text-[10px]">
                                                        ML Score
                                                    </div>
                                                </th>
                                                <th className="px-2 py-2 text-left text-xs font-medium tracking-tight text-gray-500 uppercase dark:text-gray-400">
                                                    <div>Type</div>
                                                    <div className="mt-0.5 text-[10px]">
                                                        Score
                                                    </div>
                                                </th>
                                                <th className="px-2 py-2 text-right text-xs font-medium tracking-tight text-gray-500 uppercase dark:text-gray-400">
                                                    <div>Entry</div>
                                                    <div className="mt-0.5 text-[10px]">
                                                        Time
                                                    </div>
                                                </th>
                                                <th className="px-2 py-2 text-right text-xs font-medium tracking-tight text-gray-500 uppercase dark:text-gray-400">
                                                    <div>Exit</div>
                                                    <div className="mt-0.5 text-[10px]">
                                                        Time
                                                    </div>
                                                </th>
                                                <th className="px-2 py-2 text-center text-xs font-medium tracking-tight text-gray-500 uppercase dark:text-gray-400">
                                                    Risk
                                                </th>
                                                <th className="px-2 py-2 text-right text-xs font-medium tracking-tight text-gray-500 uppercase dark:text-gray-400">
                                                    P&L%
                                                </th>
                                                <th className="px-2 py-2 text-right text-xs font-medium tracking-tight text-gray-500 uppercase dark:text-gray-400">
                                                    R-Mult
                                                </th>
                                                <th className="px-2 py-2 text-right text-xs font-medium tracking-tight text-gray-500 uppercase dark:text-gray-400">
                                                    Vol
                                                </th>
                                                <th className="px-2 py-2 text-right text-xs font-medium tracking-tight text-gray-500 uppercase dark:text-gray-400">
                                                    ATR%
                                                </th>
                                                <th className="px-2 py-2 text-right text-xs font-medium tracking-tight text-gray-500 uppercase dark:text-gray-400">
                                                    Trail%
                                                </th>
                                                <th className="px-2 py-2 text-center text-xs font-medium tracking-tight text-gray-500 uppercase dark:text-gray-400">
                                                    <div>Target</div>
                                                    <div className="mt-0.5 text-[10px]">
                                                        $/Loss
                                                    </div>
                                                </th>
                                                <th className="px-2 py-2 text-center text-xs font-medium tracking-tight text-gray-500 uppercase dark:text-gray-400">
                                                    <div>Result</div>
                                                    <div className="mt-0.5 text-[10px]">
                                                        Position
                                                    </div>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                            {filteredTrades.map(
                                                (trade, index) => (
                                                    <tr
                                                        key={index}
                                                        className={
                                                            trade.is_winner
                                                                ? 'bg-green-50/50 dark:bg-green-900/10'
                                                                : 'bg-red-50/50 dark:bg-red-900/10'
                                                        }
                                                    >
                                                        <td className="px-2 py-2 text-xs whitespace-nowrap text-gray-900 dark:text-gray-100">
                                                            <div>
                                                                {trade.entry_time.substring(
                                                                    0,
                                                                    16,
                                                                )}
                                                            </div>
                                                            <div className="mt-0.5 text-[10px] text-gray-500 dark:text-gray-400">
                                                                {trade.version}
                                                            </div>
                                                        </td>
                                                        <td className="px-2 py-2 text-xs font-medium whitespace-nowrap">
                                                            <a
                                                                href={
                                                                    trade.asset_id
                                                                        ? `/market-data/assets/${trade.asset_id}?date=${trade.entry_time.substring(0, 10)}`
                                                                        : `/market-data/assets?search=${trade.symbol}`
                                                                }
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                className="text-blue-600 hover:text-blue-800 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
                                                            >
                                                                {trade.symbol}
                                                            </a>
                                                            {trade.ml_win_prob !==
                                                                null &&
                                                                trade.ml_win_prob !==
                                                                    undefined && (
                                                                    <div className="mt-0.5 text-[10px]">
                                                                        <span
                                                                            className={`${trade.ml_win_prob >= 0.7 ? 'text-emerald-600 dark:text-emerald-400' : trade.ml_win_prob >= 0.5 ? 'text-blue-600 dark:text-blue-400' : 'text-gray-500 dark:text-gray-400'}`}
                                                                        >
                                                                            🤖{' '}
                                                                            {(
                                                                                trade.ml_win_prob *
                                                                                100
                                                                            ).toFixed(
                                                                                1,
                                                                            )}
                                                                            %
                                                                        </span>
                                                                    </div>
                                                                )}
                                                        </td>
                                                        <td className="px-2 py-2 text-xs whitespace-nowrap text-gray-900 dark:text-gray-100">
                                                            <div className="text-[10px]">
                                                                {trade.entry_type
                                                                    .replace(
                                                                        '_1M',
                                                                        '',
                                                                    )
                                                                    .replace(
                                                                        '_',
                                                                        ' ',
                                                                    )}
                                                            </div>
                                                            <div className="mt-0.5 text-[10px] text-gray-500 dark:text-gray-400">
                                                                {trade.score}
                                                            </div>
                                                        </td>
                                                        <td className="px-2 py-2 text-right text-xs text-gray-900 dark:text-gray-100">
                                                            <div>
                                                                {trade.entry_price.toFixed(
                                                                    2,
                                                                )}
                                                            </div>
                                                            <div className="mt-0.5 text-[10px] text-gray-500 dark:text-gray-400">
                                                                {(() => {
                                                                    const time =
                                                                        trade.entry_time.substring(
                                                                            11,
                                                                            16,
                                                                        );
                                                                    const [
                                                                        hours,
                                                                        minutes,
                                                                    ] = time
                                                                        .split(
                                                                            ':',
                                                                        )
                                                                        .map(
                                                                            Number,
                                                                        );
                                                                    const period =
                                                                        hours >=
                                                                        12
                                                                            ? 'PM'
                                                                            : 'AM';
                                                                    const displayHours =
                                                                        hours ===
                                                                        0
                                                                            ? 12
                                                                            : hours >
                                                                                12
                                                                              ? hours -
                                                                                12
                                                                              : hours;
                                                                    return `${displayHours}:${minutes.toString().padStart(2, '0')}${period}`;
                                                                })()}
                                                            </div>
                                                        </td>
                                                        <td className="px-2 py-2 text-right text-xs text-gray-900 dark:text-gray-100">
                                                            <div>
                                                                {trade.exit_price.toFixed(
                                                                    2,
                                                                )}
                                                            </div>
                                                            <div className="mt-0.5 text-[10px] text-gray-500 dark:text-gray-400">
                                                                {(() => {
                                                                    const time =
                                                                        trade.exit_time.substring(
                                                                            11,
                                                                            16,
                                                                        );
                                                                    const [
                                                                        hours,
                                                                        minutes,
                                                                    ] = time
                                                                        .split(
                                                                            ':',
                                                                        )
                                                                        .map(
                                                                            Number,
                                                                        );
                                                                    const period =
                                                                        hours >=
                                                                        12
                                                                            ? 'PM'
                                                                            : 'AM';
                                                                    const displayHours =
                                                                        hours ===
                                                                        0
                                                                            ? 12
                                                                            : hours >
                                                                                12
                                                                              ? hours -
                                                                                12
                                                                              : hours;
                                                                    return `${displayHours}:${minutes.toString().padStart(2, '0')}${period}`;
                                                                })()}
                                                            </div>
                                                        </td>
                                                        <td className="px-2 py-2 text-center text-xs whitespace-nowrap">
                                                            <span
                                                                className={`inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium ${
                                                                    trade.risk_level ===
                                                                    'low'
                                                                        ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300'
                                                                        : trade.risk_level ===
                                                                            'medium'
                                                                          ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300'
                                                                          : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300'
                                                                }`}
                                                            >
                                                                {trade.risk_level
                                                                    .charAt(0)
                                                                    .toUpperCase()}
                                                            </span>
                                                        </td>
                                                        <td
                                                            className={`px-2 py-2 text-right text-xs font-semibold whitespace-nowrap ${trade.is_winner ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}
                                                        >
                                                            {trade.pnl_percent >=
                                                            0
                                                                ? '+'
                                                                : ''}
                                                            {trade.pnl_percent.toFixed(
                                                                2,
                                                            )}
                                                            %
                                                        </td>
                                                        <td className="px-2 py-2 text-right text-xs whitespace-nowrap text-gray-900 dark:text-gray-100">
                                                            {trade.risk_adjusted_return.toFixed(
                                                                1,
                                                            )}
                                                            R
                                                        </td>
                                                        <td className="px-2 py-2 text-right text-xs whitespace-nowrap text-gray-900 dark:text-gray-100">
                                                            {trade.vol_ratio.toFixed(
                                                                2,
                                                            )}
                                                            x
                                                        </td>
                                                        <td className="px-2 py-2 text-right text-xs whitespace-nowrap text-gray-900 dark:text-gray-100">
                                                            {trade.atr_pct.toFixed(
                                                                2,
                                                            )}
                                                            %
                                                        </td>
                                                        <td className="px-2 py-2 text-right text-xs whitespace-nowrap text-gray-900 dark:text-gray-100">
                                                            {trade.atr_trailing_stop_pct.toFixed(
                                                                2,
                                                            )}
                                                            %
                                                        </td>
                                                        <td className="px-2 py-2 text-center text-xs whitespace-nowrap text-gray-900 dark:text-gray-100">
                                                            <div>
                                                                {
                                                                    trade.target_hit
                                                                }
                                                            </div>
                                                            <div
                                                                className={`mt-0.5 text-[10px] font-semibold ${trade.is_winner ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}
                                                            >
                                                                {trade.position_dollar_pnl >=
                                                                0
                                                                    ? '+'
                                                                    : ''}
                                                                $
                                                                {trade.position_dollar_pnl.toFixed(
                                                                    0,
                                                                )}
                                                            </div>
                                                        </td>
                                                        <td className="px-2 py-2 text-center text-xs whitespace-nowrap">
                                                            <div className="text-[10px] font-semibold">
                                                                {trade.is_winner
                                                                    ? '✅'
                                                                    : trade.was_stopped_out
                                                                      ? '🛑'
                                                                      : '❌'}
                                                            </div>
                                                            <div className="mt-0.5 text-[11px] font-medium text-blue-600 dark:text-blue-400">
                                                                $
                                                                {(
                                                                    trade.effective_position_size /
                                                                    1000
                                                                ).toFixed(1)}
                                                                k
                                                                {trade.effective_position_size !==
                                                                    trade.calculated_position_size && (
                                                                    <span
                                                                        className="text-orange-500 dark:text-orange-400"
                                                                        title={`Calculated: $${trade.calculated_position_size.toFixed(0)} (capped at max)`}
                                                                    >
                                                                        {' '}
                                                                        ⚠
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
