import { edit, update, updateMlThresholds, updatePipelines, updateTimeSlots } from '@/actions/App/Http/Controllers/TradingSettingsController';
import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, ShieldAlert, ShieldCheck, XCircle } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'System', href: '/mysql-health' },
    { title: 'Trading Settings', href: edit().url },
];

type PipelineSettings = {
    run_cron: boolean;
    live_rescore_enabled: boolean | null;
};

type MaxAgeSettings = Record<string, number>;

type Props = {
    isPaperTrading: boolean;
    settings: {
        orders_enabled: boolean;
        daily_loss_limit: number;
        consecutive_loss_days: number;
        intraday_halt_pre_11am: number;
        intraday_halt_11am_1pm: number;
        intraday_halt_post_1pm: number;
        paper_resume_min_profit: number;
        paper_bypass_ml_threshold: boolean;
        nightly_analyze_thresholds: boolean;
        max_spread_pct: number;
        max_quote_age_seconds: number;
        retrade_symbol_wait_minutes: number;
        max_age_minutes: number;
        skip_next_alert_after_ml_passed_minutes: number;
        circuit_breaker_enabled: boolean;
        circuit_breaker_stops_threshold: number;
        circuit_breaker_window_minutes: number;
        circuit_breaker_pause_minutes: number;
        max_position_pct_of_liquidity: number;
        min_position_size: number;
        max_position_size: number;
        min_dollar_volume_per_minute: number;
        position_slippage_rule: {
            enabled: boolean;
            window_days: number;
            min_samples: number;
            cache_seconds: number;
            include_paper_orders: boolean;
            low_liquidity_pct: number;
            medium_liquidity_pct: number;
            high_liquidity_pct: number;
            medium_risk_avg_slippage_pct: number;
            medium_risk_worst_slippage_pct: number;
            high_risk_avg_slippage_pct: number;
            high_risk_worst_slippage_pct: number;
            min_liquidity_pct: number;
            max_liquidity_pct: number;
        };
    };
    positionSizingStatus: {
        mode: string;
        is_dynamic: boolean;
        slippage_rule_enabled: boolean;
        active_position_size: number | null;
        active_liquidity_pct: number | null;
        active_tier: 'fixed' | 'base' | 'low' | 'medium' | 'high' | 'clamped';
        has_metrics: boolean;
        metrics: {
            sample_count: number;
            avg_adverse_slippage_pct: number;
            worst_adverse_slippage_pct: number;
            enough_data: boolean;
        } | null;
    };
    pipelines: Record<string, PipelineSettings>;
    pipelineDisplayNames: Record<string, string>;
    pipelineAucValues: Record<string, number | null>;
    precisionAtK: Record<string, number | null>;
    pipelineMlUpdatedAt: Record<string, string | null>;
    pipelineMinAuc: number;
    pipelineMinPrecisionAtK: number;
    maxAgeSettings: MaxAgeSettings;
    mlThresholds: Record<string, number>;
    timeSlots: Record<string, boolean>;
    realtimeSlots: Record<string, boolean>;
    circuitBreakerEvents: {
        id: number;
        symbol: string;
        losing_stops_count: number;
        window_minutes: number;
        pause_minutes: number;
        tripped_at: string;
        pause_expires_at: string;
        is_paper: boolean;
        is_active: boolean;
    }[];
    stopLossSettings: {
        mode: string;
        profit_protection_enabled: boolean;
        fixed_pct: number;
        atr_multiplier: number;
        atr_min_pct: number;
        atr_max_pct: number;
    };
    limitOrderSettings: {
        use_limit_orders: boolean;
        slippage_pct: number;
        slippage_pct_stale_price: number;
        partial_fill_stop_timeout_minutes: number;
        pipeline_overrides: Record<string, number | null>;
    };
    tradingHours: {
        start_time: string;
        end_time: string;
    };
    staleRescoreSettings: {
        enabled: boolean;
        paper_only: boolean;
        max_age_minutes: number;
    };
    benchmarkVwapGate: {
        enabled: boolean;
        symbol: string;
        max_pct_below_high: number | null;
        pipeline_overrides: Record<string, boolean | null>;
    };
    benchmarkVwapBars: {
        ts_est: string;
        price: string;
        vwap: string;
        above_vwap: number;
        vwap_dist_pct: string;
    }[];
    realtimeSettings: {
        max_quote_age_seconds: number;
        max_spread_pct: number;
        candidate_ttl_seconds: number;
        early_score_min: number;
        min_dollar_volume_1m: number;
        min_rvol: number;
        min_atr_pct: number;
        min_move_30m_pct: number;
        max_vwap_extension_pct: number;
        max_entry_age_seconds: number;
        skip_first_minutes: number;
        // Entry trigger gates (DB-backed)
        entry_candidate_max_age_seconds: number;
        entry_final_score_min: number;
        entry_min_price: number;
        entry_max_price: number;
        entry_require_vwap: boolean;
        entry_return_1m_min_pct: number;
        entry_return_3m_min_pct: number;
        entry_volume_ratio_min: number;
        entry_min_dollar_volume_1m: number;
        max_move_since_candidate_pct: number;
        entry_above_candidate_min_pct: number;
        entry_close_position_min: number;
        entry_upper_wick_max: number;
        entry_bid_ask_imbalance_min: number;
        entry_require_ema9_above_ema21: boolean;
        // Momentum Continuation Finder
        consolidation_max_range_pct: number;
        breakout_min_vol_ratio: number;
        max_vwap_extension_pct_finder: number;
        structure_lookback_bars: number;
        consolidation_bar_count: number;
    };
};

function ToggleSwitch({
    checked,
    onChange,
    id,
}: {
    checked: boolean;
    onChange: (val: boolean) => void;
    id: string;
}) {
    return (
        <button
            type="button"
            id={id}
            role="switch"
            aria-checked={checked}
            onClick={() => onChange(!checked)}
            className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 ${
                checked ? 'bg-green-500 dark:bg-green-600' : 'bg-gray-300 dark:bg-gray-600'
            }`}
        >
            <span
                className={`pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow-lg ring-0 transition-transform ${
                    checked ? 'translate-x-5' : 'translate-x-0'
                }`}
            />
        </button>
    );
}

function SavedBadge({ show }: { show: boolean }) {
    if (!show) {
        return null;
    }

    return (
        <Badge variant="default" className="bg-green-500 text-white">
            <CheckCircle2 className="mr-1 h-3 w-3" />
            Saved
        </Badge>
    );
}

/** Format an ISO datetime string for display in America/New_York timezone. */
function formatDatetime(iso: string): string {
    const d = new Date(iso);
    return d.toLocaleString('en-US', {
        timeZone: 'America/New_York',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function TradingSettings({ settings, pipelines, pipelineDisplayNames, pipelineAucValues, precisionAtK, pipelineMlUpdatedAt, pipelineMinAuc, pipelineMinPrecisionAtK, maxAgeSettings, mlThresholds, timeSlots, realtimeSlots, circuitBreakerEvents, isPaperTrading, positionSizingStatus, stopLossSettings, limitOrderSettings, tradingHours, staleRescoreSettings, benchmarkVwapGate, benchmarkVwapBars, realtimeSettings }: Props) {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    const _referenceMap = { stopLossSettings, limitOrderSettings, tradingHours, staleRescoreSettings, benchmarkVwapGate };
    const generalForm = useForm({
        orders_enabled: settings.orders_enabled,
        daily_loss_limit: settings.daily_loss_limit,
        consecutive_loss_days: settings.consecutive_loss_days,
        intraday_halt_pre_11am: settings.intraday_halt_pre_11am,
        intraday_halt_11am_1pm: settings.intraday_halt_11am_1pm,
        intraday_halt_post_1pm: settings.intraday_halt_post_1pm,
        paper_resume_min_profit: settings.paper_resume_min_profit,
        paper_bypass_ml_threshold: settings.paper_bypass_ml_threshold,
        nightly_analyze_thresholds: settings.nightly_analyze_thresholds,
        max_spread_pct: settings.max_spread_pct,
        max_quote_age_seconds: settings.max_quote_age_seconds,
        retrade_symbol_wait_minutes: settings.retrade_symbol_wait_minutes,
        max_age_minutes: settings.max_age_minutes,
        skip_next_alert_after_ml_passed_minutes: settings.skip_next_alert_after_ml_passed_minutes,
        circuit_breaker_enabled: settings.circuit_breaker_enabled,
        circuit_breaker_stops_threshold: settings.circuit_breaker_stops_threshold,
        circuit_breaker_window_minutes: settings.circuit_breaker_window_minutes,
        circuit_breaker_pause_minutes: settings.circuit_breaker_pause_minutes,
        max_position_pct_of_liquidity: settings.max_position_pct_of_liquidity,
        min_position_size: settings.min_position_size,
        max_position_size: settings.max_position_size,
        min_dollar_volume_per_minute: settings.min_dollar_volume_per_minute,
        position_slippage_rule: settings.position_slippage_rule,
    });

    const pipelinesForm = useForm({ pipelines });

    const maxAgeForm = useForm({ max_age_minutes: maxAgeSettings });

    const mlThresholdsForm = useForm({ ml_thresholds: mlThresholds });

    const pipelineMlGatesForm = useForm({
        min_auc: pipelineMinAuc,
        min_precision_at_10: pipelineMinPrecisionAtK,
    });

    const timeSlotsForm = useForm({ slots: timeSlots });
    const realtimeSlotsForm = useForm({ slots: realtimeSlots });

    const stopLossForm = useForm(stopLossSettings);
    const limitOrdersForm = useForm(limitOrderSettings);
    const tradingHoursForm = useForm(tradingHours);
    const staleRescoreForm = useForm(staleRescoreSettings);
    const benchmarkVwapGateForm = useForm(benchmarkVwapGate);
    const realtimeForm = useForm(realtimeSettings);

    function saveGeneral(e: React.FormEvent) {
        e.preventDefault();
        generalForm.patch(update().url, { preserveScroll: true });
    }

    function savePipelines(e: React.FormEvent) {
        e.preventDefault();
        pipelinesForm.patch(updatePipelines().url, { preserveScroll: true });
    }

    function saveMaxAgeSettings(e: React.FormEvent) {
        e.preventDefault();
        maxAgeForm.patch('/trading-settings/max-age', { preserveScroll: true });
    }

    function saveMlThresholds(e: React.FormEvent) {
        e.preventDefault();
        mlThresholdsForm.patch(updateMlThresholds().url, { preserveScroll: true });
    }

    function savePipelineMlGates(e: React.FormEvent) {
        e.preventDefault();
        pipelineMlGatesForm.patch('/trading-settings/pipeline-ml-gates', { preserveScroll: true });
    }

    function saveTimeSlots(e: React.FormEvent) {
        e.preventDefault();
        timeSlotsForm.patch(updateTimeSlots().url, { preserveScroll: true });
    }

    function saveRealtimeSlots(e: React.FormEvent) {
        e.preventDefault();
        realtimeSlotsForm.patch('/trading-settings/realtime-slots', { preserveScroll: true });
    }

    function saveStopLoss(e: React.FormEvent) {
        e.preventDefault();
        stopLossForm.patch('/trading-settings/stop-loss', { preserveScroll: true });
    }

    function saveLimitOrders(e: React.FormEvent) {
        e.preventDefault();
        limitOrdersForm.patch('/trading-settings/limit-orders', { preserveScroll: true });
    }

    function saveTradingHours(e: React.FormEvent) {
        e.preventDefault();
        tradingHoursForm.patch('/trading-settings/trading-hours', { preserveScroll: true });
    }

    function saveStaleRescore(e: React.FormEvent) {
        e.preventDefault();
        staleRescoreForm.patch('/trading-settings/stale-rescore', { preserveScroll: true });
    }

    function saveBenchmarkVwapGate(e: React.FormEvent) {
        e.preventDefault();
        benchmarkVwapGateForm.patch('/trading-settings/benchmark-vwap-gate', { preserveScroll: true });
    }

    function saveRealtime(e: React.FormEvent) {
        e.preventDefault();
        realtimeForm.patch('/trading-settings/realtime', { preserveScroll: true });
    }

    const pipelineLetters = Object.keys(pipelines);
    const maxAgePipelineLetters = Object.keys(maxAgeSettings);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Trading Settings" />

            <div className="px-4 py-6">
                {isPaperTrading ? (
                    <div className="mb-4 flex items-center gap-3 rounded-lg border border-yellow-300 bg-yellow-50 px-4 py-3 dark:border-yellow-700 dark:bg-yellow-900/30">
                        <ShieldAlert className="h-5 w-5 shrink-0 text-yellow-600 dark:text-yellow-400" />
                        <div>
                            <span className="font-semibold text-yellow-800 dark:text-yellow-300">Paper Trading Mode</span>
                            <span className="ml-2 text-sm text-yellow-700 dark:text-yellow-400">Orders are simulated — no real money is at risk.</span>
                        </div>
                    </div>
                ) : (
                    <div className="mb-4 flex items-center gap-3 rounded-lg border border-red-300 bg-red-50 px-4 py-3 dark:border-red-700 dark:bg-red-900/30">
                        <ShieldCheck className="h-5 w-5 shrink-0 text-red-600 dark:text-red-400" />
                        <div>
                            <span className="font-semibold text-red-800 dark:text-red-300">🔴 Live Production Trading</span>
                            <span className="ml-2 text-sm text-red-700 dark:text-red-400">Real money orders are active on Alpaca.</span>
                        </div>
                    </div>
                )}

                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 dark:text-white">Trading Settings</h1>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Runtime configuration — changes take effect within 60 seconds without restarting workers.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        {settings.orders_enabled ? (
                            <Badge variant="default" className="bg-green-500 text-white px-3 py-1 text-sm">
                                <CheckCircle2 className="mr-1 h-4 w-4" />
                                Orders Active
                            </Badge>
                        ) : (
                            <Badge variant="destructive" className="px-3 py-1 text-sm">
                                <XCircle className="mr-1 h-4 w-4" />
                                Orders Disabled
                            </Badge>
                        )}
                    </div>
                </div>

                <Tabs defaultValue="risk">
                    <TabsList className="mb-6">
                        <TabsTrigger value="risk">Risk Controls</TabsTrigger>
                        <TabsTrigger value="pipelines">Pipelines</TabsTrigger>
                        <TabsTrigger value="max-age">Max Age</TabsTrigger>
                        <TabsTrigger value="ml-thresholds">ML Thresholds</TabsTrigger>
                        <TabsTrigger value="time-slots">Time Slots</TabsTrigger>
                        <TabsTrigger value="realtime-slots">RT Slots (R)</TabsTrigger>
                        <TabsTrigger value="position-sizing">Position Sizing</TabsTrigger>
                        <TabsTrigger value="circuit-breaker">
                            Circuit Breaker
                            {circuitBreakerEvents.find((e) => e.is_active && !e.is_paper) && (
                                <span className="ml-2 inline-flex h-2 w-2 rounded-full bg-red-500" />
                            )}
                        </TabsTrigger>
                        <TabsTrigger value="stop-loss">Stop Loss</TabsTrigger>
                        <TabsTrigger value="limit-orders">Limit Orders</TabsTrigger>
                        <TabsTrigger value="trading-hours">Trading Hours</TabsTrigger>
                        <TabsTrigger value="stale-rescore">Stale Rescore</TabsTrigger>
                        <TabsTrigger value="benchmark-vwap">VWAP Gate</TabsTrigger>
                        <TabsTrigger value="realtime">Realtime</TabsTrigger>
                    </TabsList>

                    {/* ── Tab 1: Risk Controls ── */}
                    <TabsContent value="risk">
                        <form onSubmit={saveGeneral} className="space-y-6 max-w-3xl">
                            {/* Global Controls */}
                            <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                                <HeadingSmall
                                    title="Global Trading Controls"
                                    description="Order of precedence: (1) Auto Order Placement must be ON → (2) ML threshold must pass (unless Paper Bypass is ON) → (3) Time slot must be enabled (unless Paper Bypass is ON) → (4) Circuit breaker must not be tripped → then an order is placed."
                                />

                                <div className="mt-4 space-y-5">
                                    {/* Orders Enabled */}
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <Label htmlFor="orders_enabled" className="font-medium">
                                                1. Auto Order Placement
                                            </Label>
                                            <p className="text-sm text-gray-500 dark:text-gray-400">
                                                Master kill-switch. When OFF, no orders are placed regardless of any other setting.
                                            </p>
                                        </div>
                                        <ToggleSwitch
                                            id="orders_enabled"
                                            checked={generalForm.data.orders_enabled}
                                            onChange={(val) => generalForm.setData('orders_enabled', val)}
                                        />
                                    </div>

                                    <Separator />

                                    {/* Paper Bypass ML Threshold */}
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <Label htmlFor="paper_bypass_ml_threshold" className="font-medium">
                                                2. Paper Trading: Bypass ML Thresholds &amp; Time Slots
                                            </Label>
                                            <p className="text-sm text-gray-500 dark:text-gray-400">
                                                Only active in paper trading mode. When ON, skips both the ML score check (step 2) and the time slot gate (step 3) so every alert places an order — useful for collecting unfiltered outcome data across all pipelines.
                                            </p>
                                        </div>
                                        <ToggleSwitch
                                            id="paper_bypass_ml_threshold"
                                            checked={generalForm.data.paper_bypass_ml_threshold}
                                            onChange={(val) => generalForm.setData('paper_bypass_ml_threshold', val)}
                                        />
                                    </div>

                                    <Separator />

                                    {/* Nightly Analyze Thresholds */}
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <Label htmlFor="nightly_analyze_thresholds" className="font-medium">
                                                3. Nightly Analyze Thresholds
                                            </Label>
                                            <p className="text-sm text-gray-500 dark:text-gray-400">
                                                When ON, the two nightly <code>analyze:ml-thresholds</code> scheduled commands run at 6:00 PM ET to recalibrate per-pipeline ML thresholds based on recent trade outcomes.
                                            </p>
                                        </div>
                                        <ToggleSwitch
                                            id="nightly_analyze_thresholds"
                                            checked={generalForm.data.nightly_analyze_thresholds}
                                            onChange={(val) => generalForm.setData('nightly_analyze_thresholds', val)}
                                        />
                                    </div>

                                    <Separator />

                                    {/* Max Spread Pct */}
                                    <div className="grid gap-2">
                                        <Label htmlFor="max_spread_pct">Max Spread (%)</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400">
                                            Maximum bid-ask spread allowed before skipping order placement. Prevents entering illiquid or wide-spread positions.
                                        </p>
                                        <Input
                                            id="max_spread_pct"
                                            type="number"
                                            min="0.01"
                                            max="100"
                                            step="0.01"
                                            className="w-28"
                                            value={generalForm.data.max_spread_pct}
                                            onChange={(e) => generalForm.setData('max_spread_pct', parseFloat(e.target.value))}
                                        />
                                        {generalForm.errors.max_spread_pct && (
                                            <p className="text-sm text-red-500">{generalForm.errors.max_spread_pct}</p>
                                        )}
                                    </div>

                                    <Separator />

                                    {/* Max Quote Age Seconds */}
                                    <div className="grid gap-2">
                                        <Label htmlFor="max_quote_age_seconds">Max Quote Age (seconds)</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400">
                                            Maximum age of a live SIP quote before skipping order placement. If the quote is older than this, the order is skipped with reason <code>quote_stale</code>.
                                        </p>
                                        <Input
                                            id="max_quote_age_seconds"
                                            type="number"
                                            min="1"
                                            max="3600"
                                            step="1"
                                            className="w-24"
                                            value={generalForm.data.max_quote_age_seconds}
                                            onChange={(e) => generalForm.setData('max_quote_age_seconds', parseInt(e.target.value))}
                                        />
                                        {generalForm.errors.max_quote_age_seconds && (
                                            <p className="text-sm text-red-500">{generalForm.errors.max_quote_age_seconds}</p>
                                        )}
                                    </div>

                                    <Separator />

                                    {/* Retrade Symbol Wait Minutes */}
                                    <div className="grid gap-2">
                                        <Label htmlFor="retrade_symbol_wait_minutes">Same-Symbol Retrade Wait (minutes)</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400">
                                            Minimum minutes before the same symbol can be traded again. 0 = once per day per symbol.
                                        </p>
                                        <Input
                                            id="retrade_symbol_wait_minutes"
                                            type="number"
                                            min="0"
                                            max="480"
                                            step="5"
                                            className="w-24"
                                            value={generalForm.data.retrade_symbol_wait_minutes}
                                            onChange={(e) => generalForm.setData('retrade_symbol_wait_minutes', parseInt(e.target.value))}
                                        />
                                        {generalForm.errors.retrade_symbol_wait_minutes && (
                                            <p className="text-sm text-red-500">{generalForm.errors.retrade_symbol_wait_minutes}</p>
                                        )}
                                    </div>

                                    {/* Global Max Age Minutes */}
                                    <div className="grid gap-2">
                                        <Label htmlFor="max_age_minutes">Max Alert Age (minutes)</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400">
                                            Global fallback for pipelines without a per-pipeline max age override. Alerts older than this are skipped.
                                        </p>
                                        <Input
                                            id="max_age_minutes"
                                            type="number"
                                            min="1"
                                            max="120"
                                            className="w-24"
                                            value={generalForm.data.max_age_minutes}
                                            onChange={(e) => generalForm.setData('max_age_minutes', parseInt(e.target.value))}
                                        />
                                        {generalForm.errors.max_age_minutes && (
                                            <p className="text-sm text-red-500">{generalForm.errors.max_age_minutes}</p>
                                        )}
                                    </div>

                                    {/* Skip Next Alert After ML Passed (minutes) */}
                                    <div className="grid gap-2">
                                        <Label htmlFor="skip_next_alert_after_ml_passed_minutes">Skip Next Alert After ML Passed (minutes)</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400">
                                            Suppress new alerts for a symbol after a recent alert passed ML scoring (passed_ml=1). Set to 0 to disable.
                                        </p>
                                        <Input
                                            id="skip_next_alert_after_ml_passed_minutes"
                                            type="number"
                                            min="0"
                                            max="480"
                                            step="5"
                                            className="w-24"
                                            value={generalForm.data.skip_next_alert_after_ml_passed_minutes}
                                            onChange={(e) => generalForm.setData('skip_next_alert_after_ml_passed_minutes', parseInt(e.target.value))}
                                        />
                                        {generalForm.errors.skip_next_alert_after_ml_passed_minutes && (
                                            <p className="text-sm text-red-500">{generalForm.errors.skip_next_alert_after_ml_passed_minutes}</p>
                                        )}
                                    </div>

                                    <Separator />

                                    {/* Daily Loss Limit */}
                                    <div className="grid gap-2">
                                        <Label htmlFor="daily_loss_limit">Daily Loss Limit ($)</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400">
                                            After-close risk check switches to paper trading if day's P&L falls below this (negative number, e.g. -300)
                                        </p>
                                        <Input
                                            id="daily_loss_limit"
                                            type="number"
                                            step="50"
                                            max="0"
                                            className="w-40"
                                            value={generalForm.data.daily_loss_limit}
                                            onChange={(e) => generalForm.setData('daily_loss_limit', parseFloat(e.target.value))}
                                        />
                                        {generalForm.errors.daily_loss_limit && (
                                            <p className="text-sm text-red-500">{generalForm.errors.daily_loss_limit}</p>
                                        )}
                                    </div>

                                    {/* Consecutive Loss Days */}
                                    <div className="grid gap-2">
                                        <Label htmlFor="consecutive_loss_days">Consecutive Loss Days Threshold</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400">
                                            Switch to paper trading after this many consecutive losing live days
                                        </p>
                                        <Input
                                            id="consecutive_loss_days"
                                            type="number"
                                            min="1"
                                            max="14"
                                            className="w-24"
                                            value={generalForm.data.consecutive_loss_days}
                                            onChange={(e) => generalForm.setData('consecutive_loss_days', parseInt(e.target.value))}
                                        />
                                        {generalForm.errors.consecutive_loss_days && (
                                            <p className="text-sm text-red-500">{generalForm.errors.consecutive_loss_days}</p>
                                        )}
                                    </div>

                                    {/* Intraday Loss Halt Limits */}
                                    <div className="grid gap-2">
                                        <Label>Intraday Loss Halt Limits ($)</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400">
                                            Disables orders mid-day if actual closed P&L drops at or below the active threshold (runs every 15 min, 9:45 AM–2:30 PM ET). Looser early to allow volatile opens to recover.
                                        </p>
                                        <div className="grid grid-cols-3 gap-4">
                                            <div className="grid gap-1">
                                                <Label htmlFor="intraday_halt_pre_11am" className="text-xs text-gray-500 dark:text-gray-400">
                                                    Before 11 AM
                                                </Label>
                                                <Input
                                                    id="intraday_halt_pre_11am"
                                                    type="number"
                                                    step="50"
                                                    max="0"
                                                    value={generalForm.data.intraday_halt_pre_11am}
                                                    onChange={(e) => generalForm.setData('intraday_halt_pre_11am', parseFloat(e.target.value))}
                                                />
                                                {generalForm.errors.intraday_halt_pre_11am && (
                                                    <p className="text-sm text-red-500">{generalForm.errors.intraday_halt_pre_11am}</p>
                                                )}
                                            </div>
                                            <div className="grid gap-1">
                                                <Label htmlFor="intraday_halt_11am_1pm" className="text-xs text-gray-500 dark:text-gray-400">
                                                    11 AM – 1 PM
                                                </Label>
                                                <Input
                                                    id="intraday_halt_11am_1pm"
                                                    type="number"
                                                    step="50"
                                                    max="0"
                                                    value={generalForm.data.intraday_halt_11am_1pm}
                                                    onChange={(e) => generalForm.setData('intraday_halt_11am_1pm', parseFloat(e.target.value))}
                                                />
                                                {generalForm.errors.intraday_halt_11am_1pm && (
                                                    <p className="text-sm text-red-500">{generalForm.errors.intraday_halt_11am_1pm}</p>
                                                )}
                                            </div>
                                            <div className="grid gap-1">
                                                <Label htmlFor="intraday_halt_post_1pm" className="text-xs text-gray-500 dark:text-gray-400">
                                                    After 1 PM
                                                </Label>
                                                <Input
                                                    id="intraday_halt_post_1pm"
                                                    type="number"
                                                    step="50"
                                                    max="0"
                                                    value={generalForm.data.intraday_halt_post_1pm}
                                                    onChange={(e) => generalForm.setData('intraday_halt_post_1pm', parseFloat(e.target.value))}
                                                />
                                                {generalForm.errors.intraday_halt_post_1pm && (
                                                    <p className="text-sm text-red-500">{generalForm.errors.intraday_halt_post_1pm}</p>
                                                )}
                                            </div>
                                        </div>
                                    </div>

                                    {/* Paper Resume Min Profit */}
                                    <div className="grid gap-2">
                                        <Label htmlFor="paper_resume_min_profit">Paper Resume Min Profit ($)</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400">
                                            Minimum paper P&L required the day before to switch back to live trading (pre-open resume check)
                                        </p>
                                        <Input
                                            id="paper_resume_min_profit"
                                            type="number"
                                            step="25"
                                            min="0"
                                            className="w-40"
                                            value={generalForm.data.paper_resume_min_profit}
                                            onChange={(e) => generalForm.setData('paper_resume_min_profit', parseFloat(e.target.value))}
                                        />
                                        {generalForm.errors.paper_resume_min_profit && (
                                            <p className="text-sm text-red-500">{generalForm.errors.paper_resume_min_profit}</p>
                                        )}
                                    </div>

                                </div>
                            </div>

                            <div className="flex items-center gap-3">
                                <Button type="submit" disabled={generalForm.processing}>
                                    {generalForm.processing ? 'Saving…' : 'Save Settings'}
                                </Button>
                                <SavedBadge show={generalForm.recentlySuccessful} />
                            </div>
                        </form>
                    </TabsContent>

                    {/* ── Tab 2: Pipelines ── */}
                    <TabsContent value="pipelines">
                        <div className="max-w-3xl space-y-6">
                            <form onSubmit={savePipelineMlGates}>
                                <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                                    <HeadingSmall
                                        title="ML Quality Gates"
                                        description="Global thresholds that apply to all pipelines. Pipelines whose AUC falls below the minimum or Precision@10 falls below the minimum are flagged."
                                    />

                                    <div className="mt-4 grid grid-cols-2 gap-6">
                                        <div className="grid gap-2">
                                            <Label htmlFor="min_auc">Minimum AUC</Label>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                                Pipelines with AUC below this threshold are flagged.
                                            </p>
                                            <Input
                                                id="min_auc"
                                                type="number"
                                                min="0"
                                                max="1"
                                                step="0.001"
                                                className="w-32"
                                                value={pipelineMlGatesForm.data.min_auc}
                                                onChange={(e) => pipelineMlGatesForm.setData('min_auc', Number.parseFloat(e.target.value))}
                                            />
                                            {pipelineMlGatesForm.errors.min_auc && (
                                                <p className="text-sm text-red-500">{pipelineMlGatesForm.errors.min_auc}</p>
                                            )}
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="min_precision_at_10">Minimum Precision@10</Label>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                                Pipelines with Precision@10 below this threshold are flagged.
                                            </p>
                                            <Input
                                                id="min_precision_at_10"
                                                type="number"
                                                min="0"
                                                max="1"
                                                step="0.01"
                                                className="w-32"
                                                value={pipelineMlGatesForm.data.min_precision_at_10}
                                                onChange={(e) => pipelineMlGatesForm.setData('min_precision_at_10', Number.parseFloat(e.target.value))}
                                            />
                                            {pipelineMlGatesForm.errors.min_precision_at_10 && (
                                                <p className="text-sm text-red-500">{pipelineMlGatesForm.errors.min_precision_at_10}</p>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                <div className="mt-4 flex items-center gap-3">
                                    <Button type="submit" disabled={pipelineMlGatesForm.processing}>
                                        {pipelineMlGatesForm.processing ? 'Saving…' : 'Save ML Gates'}
                                    </Button>
                                    <SavedBadge show={pipelineMlGatesForm.recentlySuccessful} />
                                </div>
                            </form>

                            <form onSubmit={savePipelines}>
                                <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                                    <HeadingSmall
                                        title="Pipeline Controls"
                                        description="Enable/disable each pipeline's alert creation and live ML rescore"
                                    />

                                <div className="mt-4 overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b border-gray-200 dark:border-gray-700">
                                                <th className="pb-3 pr-6 text-left font-medium text-gray-600 dark:text-gray-400">Pipeline</th>
                                                <th className="pb-3 px-4 text-center font-medium text-gray-600 dark:text-gray-400">Create Alerts</th>
                                                <th className="pb-3 px-4 text-center font-medium text-gray-600 dark:text-gray-400">
                                                    Live Rescore
                                                    <span className="ml-1 text-xs text-gray-400">(null = global)</span>
                                                </th>
                                                <th className="pb-3 px-4 text-center font-medium text-gray-600 dark:text-gray-400">AUC</th>
                                                <th className="pb-3 px-4 text-center font-medium text-gray-600 dark:text-gray-400">P@10</th>
                                                <th className="pb-3 px-4 text-center font-medium text-gray-600 dark:text-gray-400">ML Updated</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                                            {pipelineLetters.map((p) => {
                                                const pipeline = pipelinesForm.data.pipelines[p];
                                                const hasLiveRescoreOverride = pipeline.live_rescore_enabled !== null;

                                                return (
                                                    <tr key={p} className="py-3">
                                                        <td className="py-3 pr-6 font-medium text-gray-900 dark:text-gray-100">
                                                            {pipelineDisplayNames[p] ?? `Pipeline ${p.toUpperCase()}`}
                                                        </td>
                                                        <td className="py-3 px-4 text-center">
                                                            <div className="flex justify-center">
                                                                <ToggleSwitch
                                                                    id={`run_cron_${p}`}
                                                                    checked={pipeline.run_cron}
                                                                    onChange={(val) =>
                                                                        pipelinesForm.setData('pipelines', {
                                                                            ...pipelinesForm.data.pipelines,
                                                                            [p]: { ...pipeline, run_cron: val },
                                                                        })
                                                                    }
                                                                />
                                                            </div>
                                                        </td>
                                                        <td className="py-3 px-4 text-center">
                                                            <div className="flex items-center justify-center gap-2">
                                                                {hasLiveRescoreOverride ? (
                                                                    <>
                                                                        <ToggleSwitch
                                                                            id={`live_rescore_${p}`}
                                                                            checked={pipeline.live_rescore_enabled === true}
                                                                            onChange={(val) =>
                                                                                pipelinesForm.setData('pipelines', {
                                                                                    ...pipelinesForm.data.pipelines,
                                                                                    [p]: { ...pipeline, live_rescore_enabled: val },
                                                                                })
                                                                            }
                                                                        />
                                                                        <button
                                                                            type="button"
                                                                            title="Remove override (use global default)"
                                                                            className="text-xs text-gray-400 underline hover:text-gray-600 dark:hover:text-gray-300"
                                                                            onClick={() =>
                                                                                pipelinesForm.setData('pipelines', {
                                                                                    ...pipelinesForm.data.pipelines,
                                                                                    [p]: { ...pipeline, live_rescore_enabled: null },
                                                                                })
                                                                            }
                                                                        >
                                                                            reset
                                                                        </button>
                                                                    </>
                                                                ) : (
                                                                    <button
                                                                        type="button"
                                                                        title="Set pipeline-specific override"
                                                                        className="rounded border border-dashed border-gray-300 px-2 py-0.5 text-xs text-gray-400 hover:border-gray-500 hover:text-gray-600 dark:border-gray-600 dark:hover:border-gray-400 dark:hover:text-gray-300"
                                                                        onClick={() =>
                                                                            pipelinesForm.setData('pipelines', {
                                                                                ...pipelinesForm.data.pipelines,
                                                                                [p]: { ...pipeline, live_rescore_enabled: true },
                                                                            })
                                                                        }
                                                                    >
                                                                        global
                                                                    </button>
                                                                )}
                                                            </div>
                                                        </td>
                                                        <td className="py-3 px-4 text-center text-sm">
                                                            {pipelineAucValues[p] !== null && pipelineAucValues[p] !== undefined ? (
                                                                <span className={
                                                                    pipelineAucValues[p]! >= 0.70 ? 'font-medium text-green-600 dark:text-green-400' :
                                                                    pipelineAucValues[p]! >= 0.65 ? 'text-yellow-600 dark:text-yellow-400' :
                                                                    'text-red-600 dark:text-red-400'
                                                                }>
                                                                    {(pipelineAucValues[p]!).toFixed(3)}
                                                                </span>
                                                            ) : (
                                                                <span className="text-gray-400">—</span>
                                                            )}
                                                        </td>
                                                        <td className="py-3 px-4 text-center text-sm">
                                                            {precisionAtK[p] !== null && precisionAtK[p] !== undefined ? (
                                                                <span className={
                                                                    precisionAtK[p]! >= 0.7 ? 'font-medium text-green-600 dark:text-green-400' :
                                                                    precisionAtK[p]! >= 0.4 ? 'text-yellow-600 dark:text-yellow-400' :
                                                                    'text-red-600 dark:text-red-400'
                                                                }>
                                                                    {(precisionAtK[p]! * 100).toFixed(0)}%
                                                                </span>
                                                            ) : (
                                                                <span className="text-gray-400">—</span>
                                                            )}
                                                        </td>
                                                        <td className="py-3 px-4 text-center text-xs text-gray-500 dark:text-gray-400">
                                                            {pipelineMlUpdatedAt[p] ? (
                                                                <span className="whitespace-nowrap">{formatDatetime(pipelineMlUpdatedAt[p])}</span>
                                                            ) : (
                                                                <span className="text-gray-400">—</span>
                                                            )}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div className="flex items-center gap-3">
                                <Button type="submit" disabled={pipelinesForm.processing}>
                                    {pipelinesForm.processing ? 'Saving…' : 'Save Pipelines'}
                                </Button>
                                <SavedBadge show={pipelinesForm.recentlySuccessful} />
                            </div>
                            </form>
                        </div>
                    </TabsContent>

                    {/* ── Tab 3: Max Age Settings ── */}
                    <TabsContent value="max-age">
                        <form onSubmit={saveMaxAgeSettings} className="max-w-3xl">
                            <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                                <HeadingSmall
                                    title="Pipeline Max Age"
                                    description="DB-backed freshness windows for order placement. Changes apply immediately through the cached trading settings service."
                                />

                                <div className="mt-4 overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b border-gray-200 dark:border-gray-700">
                                                <th className="pb-3 text-left font-medium text-gray-600 dark:text-gray-400">Pipeline</th>
                                                <th className="pb-3 text-left font-medium text-gray-600 dark:text-gray-400">Max Age (minutes)</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                                            {maxAgePipelineLetters.map((p) => (
                                                <tr key={p}>
                                                    <td className="py-3 pr-4 font-medium text-gray-900 dark:text-gray-100">
                                                        {pipelineDisplayNames[p] ?? `Pipeline ${p.toUpperCase()}`}
                                                    </td>
                                                    <td className="py-3">
                                                        <Input
                                                            type="number"
                                                            min="1"
                                                            max="120"
                                                            step="1"
                                                            className="w-32"
                                                            value={maxAgeForm.data.max_age_minutes[p]}
                                                            onChange={(e) =>
                                                                maxAgeForm.setData('max_age_minutes', {
                                                                    ...maxAgeForm.data.max_age_minutes,
                                                                    [p]: Number.parseInt(e.target.value, 10),
                                                                })
                                                            }
                                                        />
                                                        {maxAgeForm.errors[`max_age_minutes.${p}` as keyof typeof maxAgeForm.errors] && (
                                                            <p className="mt-1 text-sm text-red-500">
                                                                {maxAgeForm.errors[`max_age_minutes.${p}` as keyof typeof maxAgeForm.errors]}
                                                            </p>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div className="mt-4 flex items-center gap-3">
                                <Button type="submit" disabled={maxAgeForm.processing}>
                                    {maxAgeForm.processing ? 'Saving…' : 'Save Max Age Settings'}
                                </Button>
                                <SavedBadge show={maxAgeForm.recentlySuccessful} />
                            </div>
                        </form>
                    </TabsContent>

                    {/* ── Tab 4: Time Slots ── */}
                    <TabsContent value="ml-thresholds">
                        <form onSubmit={saveMlThresholds} className="max-w-3xl">
                            <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                                <HeadingSmall
                                    title="Per-Pipeline ML Thresholds"
                                    description="Per-pipeline ML thresholds are set nightly by `php artisan analyze:ml-thresholds`, which sweeps historical trade outcomes (both live and backtest) to find the optimal threshold that maximizes picks while meeting minimum win rate and avg PnL/trade gates. Pipelines with no ML model or below-baseline AUC/P@10 are forced to 0.99."
                                />

                                <div className="mt-4 overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b border-gray-200 dark:border-gray-700">
                                                <th className="pb-3 pr-6 text-left font-medium text-gray-600 dark:text-gray-400">Pipeline</th>
                                                <th className="pb-3 px-4 text-center font-medium text-gray-600 dark:text-gray-400">AUC</th>
                                                <th className="pb-3 px-4 text-center font-medium text-gray-600 dark:text-gray-400">P@10</th>
                                                <th className="pb-3 px-4 text-center font-medium text-gray-600 dark:text-gray-400">ML Updated</th>
                                                <th className="pb-3 text-left font-medium text-gray-600 dark:text-gray-400">ML Threshold (0.000 - 1.100)</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                                            {Object.keys(mlThresholdsForm.data.ml_thresholds).map((p) => (
                                                <tr key={p}>
                                                    <td className="py-3 pr-6 font-medium text-gray-900 dark:text-gray-100">
                                                        {pipelineDisplayNames[p] ?? `Pipeline ${p.toUpperCase()}`}
                                                    </td>
                                                    <td className="py-3 px-4 text-center text-sm">
                                                        {pipelineAucValues[p] !== null && pipelineAucValues[p] !== undefined ? (
                                                            <span className={
                                                                pipelineAucValues[p]! >= 0.70 ? 'font-medium text-green-600 dark:text-green-400' :
                                                                pipelineAucValues[p]! >= 0.65 ? 'text-yellow-600 dark:text-yellow-400' :
                                                                'text-red-600 dark:text-red-400'
                                                            }>
                                                                {(pipelineAucValues[p]!).toFixed(3)}
                                                            </span>
                                                        ) : (
                                                            <span className="text-gray-400">—</span>
                                                        )}
                                                    </td>
                                                    <td className="py-3 px-4 text-center text-sm">
                                                        {precisionAtK[p] !== null && precisionAtK[p] !== undefined ? (
                                                            <span className={
                                                                precisionAtK[p]! >= 0.7 ? 'font-medium text-green-600 dark:text-green-400' :
                                                                precisionAtK[p]! >= 0.4 ? 'text-yellow-600 dark:text-yellow-400' :
                                                                'text-red-600 dark:text-red-400'
                                                            }>
                                                                {(precisionAtK[p]! * 100).toFixed(0)}%
                                                            </span>
                                                        ) : (
                                                            <span className="text-gray-400">—</span>
                                                        )}
                                                    </td>
                                                    <td className="py-3 px-4 text-center text-xs text-gray-500 dark:text-gray-400">
                                                        {pipelineMlUpdatedAt[p] ? (
                                                            <span className="whitespace-nowrap">{formatDatetime(pipelineMlUpdatedAt[p])}</span>
                                                        ) : (
                                                            <span className="text-gray-400">—</span>
                                                        )}
                                                    </td>
                                                    <td className="py-3">
                                                        <Input
                                                            type="number"
                                                            min="0"
                                                            max="1.1"
                                                            step="0.001"
                                                            className="w-40"
                                                            value={mlThresholdsForm.data.ml_thresholds[p]}
                                                            onChange={(e) =>
                                                                mlThresholdsForm.setData('ml_thresholds', {
                                                                    ...mlThresholdsForm.data.ml_thresholds,
                                                                    [p]: Number.parseFloat(e.target.value),
                                                                })
                                                            }
                                                        />
                                                        {mlThresholdsForm.errors[`ml_thresholds.${p}` as keyof typeof mlThresholdsForm.errors] && (
                                                            <p className="mt-1 text-sm text-red-500">
                                                                {mlThresholdsForm.errors[`ml_thresholds.${p}` as keyof typeof mlThresholdsForm.errors]}
                                                            </p>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div className="mt-4 flex items-center gap-3">
                                <Button type="submit" disabled={mlThresholdsForm.processing}>
                                    {mlThresholdsForm.processing ? 'Saving…' : 'Save ML Thresholds'}
                                </Button>
                                <SavedBadge show={mlThresholdsForm.recentlySuccessful} />
                            </div>
                        </form>
                    </TabsContent>

                    {/* ── Tab 5: Time Slots ── */}
                    <TabsContent value="time-slots">
                        <form onSubmit={saveTimeSlots} className="max-w-2xl">
                            <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                                <HeadingSmall
                                    title="15-Minute Entry Windows"
                                    description="Gate order placement by 15-minute entry window (step 3 in precedence). Alerts are always processed — only order placement is blocked during disabled slots. Bypassed entirely when 'Paper Trading: Bypass ML Thresholds &amp; Time Slots' is ON."
                                />

                                <div className="mt-5 grid grid-cols-2 gap-x-8 gap-y-3 sm:grid-cols-3">
                                    {Object.entries(timeSlotsForm.data.slots).map(([slot, enabled]) => {
                                        const [hStr, mStr] = slot.split(':');
                                        const h = parseInt(hStr);
                                        const m = parseInt(mStr);
                                        const suffix = h >= 12 ? 'PM' : 'AM';
                                        const h12 = h > 12 ? h - 12 : h === 0 ? 12 : h;
                                        const startLabel = `${h12}:${mStr} ${suffix}`;
                                        const endH = m === 45 ? h + 1 : h;
                                        const endM = m === 45 ? 0 : m + 15;
                                        const endSuffix = endH >= 12 ? 'PM' : 'AM';
                                        const endH12 = endH > 12 ? endH - 12 : endH === 0 ? 12 : endH;
                                        const endMStr = String(endM).padStart(2, '0');
                                        const rangeLabel = `${startLabel} – ${endH12}:${endMStr} ${endSuffix}`;

                                        return (
                                            <div key={slot} className="flex items-center justify-between rounded-md border border-gray-100 px-3 py-2 dark:border-gray-700">
                                                <span className={`text-sm font-mono ${enabled ? 'text-gray-800 dark:text-gray-200' : 'text-gray-400 dark:text-gray-500 line-through'}`}>
                                                    {rangeLabel}
                                                </span>
                                                <ToggleSwitch
                                                    id={`slot-${slot}`}
                                                    checked={enabled}
                                                    onChange={(val) =>
                                                        timeSlotsForm.setData('slots', {
                                                            ...timeSlotsForm.data.slots,
                                                            [slot]: val,
                                                        })
                                                    }
                                                />
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>

                            <div className="mt-6 flex items-center gap-3">
                                <Button type="submit" disabled={timeSlotsForm.processing}>
                                    {timeSlotsForm.processing ? 'Saving…' : 'Save Time Slots'}
                                </Button>
                                <SavedBadge show={timeSlotsForm.recentlySuccessful} />
                                <span className="text-xs text-gray-400">
                                    {Object.values(timeSlotsForm.data.slots).filter(Boolean).length} of{' '}
                                    {Object.keys(timeSlotsForm.data.slots).length} slots enabled
                                </span>
                            </div>
                        </form>
                    </TabsContent>

                    {/* ── Tab: Realtime Slots (Pipeline R only) ── */}
                    <TabsContent value="realtime-slots">
                        <form onSubmit={saveRealtimeSlots} className="max-w-2xl">
                            <HeadingSmall
                                title="Pipeline R Realtime Time Slots"
                                description="Gate Pipeline R order placement by 15-minute entry window. Pipeline R needs independent active windows from the global time slots. Changes take effect immediately (60s cache)."
                            />
                            <div className="mt-4 grid grid-cols-4 gap-2 sm:grid-cols-6 md:grid-cols-8">
                                {Object.entries(realtimeSlotsForm.data.slots).map(([slot, enabled]) => {
                                    const [h, m] = slot.split(':').map(Number);
                                    const isEarly = h < 10 || (h === 10 && m === 0);
                                    const isLate = h > 14 || (h === 14 && m > 30);
                                    const isLunch = h === 12;

                                    return (
                                        <button
                                            type="button"
                                            key={slot}
                                            onClick={() =>
                                                realtimeSlotsForm.setData('slots', {
                                                    ...realtimeSlotsForm.data.slots,
                                                    [slot]: !enabled,
                                                })
                                            }
                                            className={`flex flex-col items-center justify-center rounded-lg border px-2 py-2 text-xs transition-all ${
                                                enabled
                                                    ? 'border-green-300 bg-green-50 text-green-700 dark:border-green-700 dark:bg-green-950/40 dark:text-green-300'
                                                    : 'border-gray-200 bg-white text-gray-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-600'
                                            } ${(isEarly || isLate) && !enabled ? 'bg-red-50 dark:bg-red-950/20' : ''} hover:border-green-400`}
                                        >
                                            <span className="font-medium">{slot}</span>
                                            <span className="mt-0.5 text-[10px] opacity-70">
                                                {isLunch ? 'Lunch' : isEarly ? '(early)' : isLate ? '(late)' : ''}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                            <div className="mt-6 flex items-center gap-3">
                                <Button type="submit" disabled={realtimeSlotsForm.processing}>
                                    {realtimeSlotsForm.processing ? 'Saving…' : 'Save R Slots'}
                                </Button>
                                <SavedBadge show={realtimeSlotsForm.recentlySuccessful} />
                                <span className="text-xs text-gray-400">
                                    {Object.values(realtimeSlotsForm.data.slots).filter(Boolean).length} of{' '}
                                    {Object.keys(realtimeSlotsForm.data.slots).length} slots enabled
                                </span>
                            </div>
                        </form>
                    </TabsContent>

                    {/* ── Tab 6: Circuit Breaker Settings ── */}
                    <TabsContent value="position-sizing">
                        <form onSubmit={saveGeneral} className="space-y-6 max-w-4xl">
                            <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950/40">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-sm font-semibold text-emerald-900 dark:text-emerald-200">Active Position Sizing</span>
                                    <Badge variant="outline" className="border-emerald-400/60 text-emerald-900 dark:border-emerald-700 dark:text-emerald-200">
                                        Mode: {positionSizingStatus.is_dynamic ? 'Dynamic' : 'Fixed'}
                                    </Badge>
                                    {positionSizingStatus.is_dynamic ? (
                                        <Badge variant="outline" className="border-emerald-400/60 text-emerald-900 dark:border-emerald-700 dark:text-emerald-200">
                                            Active Liquidity %: {positionSizingStatus.active_liquidity_pct?.toFixed(2)}%
                                        </Badge>
                                    ) : (
                                        <Badge variant="outline" className="border-emerald-400/60 text-emerald-900 dark:border-emerald-700 dark:text-emerald-200">
                                            Fixed Position: ${positionSizingStatus.active_position_size?.toLocaleString()}
                                        </Badge>
                                    )}
                                    {positionSizingStatus.is_dynamic && (
                                        <Badge variant="outline" className="border-emerald-400/60 text-emerald-900 capitalize dark:border-emerald-700 dark:text-emerald-200">
                                            Tier: {positionSizingStatus.active_tier}
                                        </Badge>
                                    )}
                                </div>

                                <p className="mt-2 text-xs text-emerald-800 dark:text-emerald-300">
                                    {positionSizingStatus.is_dynamic && !positionSizingStatus.slippage_rule_enabled
                                        ? 'Using base liquidity percentage because slippage rule is disabled.'
                                        : positionSizingStatus.is_dynamic && positionSizingStatus.slippage_rule_enabled && !positionSizingStatus.has_metrics
                                          ? 'Using base liquidity percentage until enough recent slippage metrics are cached.'
                                          : positionSizingStatus.is_dynamic && positionSizingStatus.metrics
                                            ? `Derived from ${positionSizingStatus.metrics.sample_count} samples (avg adverse ${positionSizingStatus.metrics.avg_adverse_slippage_pct.toFixed(4)}%, worst ${positionSizingStatus.metrics.worst_adverse_slippage_pct.toFixed(4)}%).`
                                            : 'Using fixed position size from configuration.'}
                                </p>
                            </div>

                            <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                                <HeadingSmall
                                    title="Position Sizing"
                                    description="DB-backed runtime settings for liquidity-based sizing and slippage risk tiers"
                                />

                                <div className="mt-4 space-y-5">
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="max_position_pct_of_liquidity">Base Max % of Liquidity</Label>
                                            <Input
                                                id="max_position_pct_of_liquidity"
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="0.1"
                                                value={generalForm.data.max_position_pct_of_liquidity}
                                                onChange={(e) => generalForm.setData('max_position_pct_of_liquidity', Number.parseFloat(e.target.value))}
                                            />
                                            {generalForm.errors.max_position_pct_of_liquidity && (
                                                <p className="text-sm text-red-500">{generalForm.errors.max_position_pct_of_liquidity}</p>
                                            )}
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="min_position_size">Minimum Position Size ($)</Label>
                                            <Input
                                                id="min_position_size"
                                                type="number"
                                                min="1"
                                                step="1"
                                                value={generalForm.data.min_position_size}
                                                onChange={(e) => generalForm.setData('min_position_size', Number.parseFloat(e.target.value))}
                                            />
                                            {generalForm.errors.min_position_size && (
                                                <p className="text-sm text-red-500">{generalForm.errors.min_position_size}</p>
                                            )}
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="max_position_size">Maximum Position Size ($)</Label>
                                            <Input
                                                id="max_position_size"
                                                type="number"
                                                min="1"
                                                step="1"
                                                value={generalForm.data.max_position_size}
                                                onChange={(e) => generalForm.setData('max_position_size', Number.parseFloat(e.target.value))}
                                            />
                                            {generalForm.errors.max_position_size && (
                                                <p className="text-sm text-red-500">{generalForm.errors.max_position_size}</p>
                                            )}
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="min_dollar_volume_per_minute">Minimum Dollar Volume / Minute ($)</Label>
                                            <Input
                                                id="min_dollar_volume_per_minute"
                                                type="number"
                                                min="0"
                                                step="100"
                                                value={generalForm.data.min_dollar_volume_per_minute}
                                                onChange={(e) => generalForm.setData('min_dollar_volume_per_minute', Number.parseFloat(e.target.value))}
                                            />
                                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                                Hard liquidity gate for order placement. Set to 0 to disable.
                                            </p>
                                            {generalForm.errors.min_dollar_volume_per_minute && (
                                                <p className="text-sm text-red-500">{generalForm.errors.min_dollar_volume_per_minute}</p>
                                            )}
                                        </div>
                                    </div>

                                    <Separator />

                                    <div className="flex items-center justify-between">
                                        <div>
                                            <Label htmlFor="slippage_rule_enabled" className="font-medium">Slippage Rule Enabled</Label>
                                            <p className="text-sm text-gray-500 dark:text-gray-400">
                                                Auto-select LOW / MEDIUM / HIGH liquidity tiers from rolling adverse slippage.
                                            </p>
                                        </div>
                                        <ToggleSwitch
                                            id="slippage_rule_enabled"
                                            checked={generalForm.data.position_slippage_rule.enabled}
                                            onChange={(val) =>
                                                generalForm.setData('position_slippage_rule', {
                                                    ...generalForm.data.position_slippage_rule,
                                                    enabled: val,
                                                })
                                            }
                                        />
                                    </div>

                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="slippage_window_days">Window Days</Label>
                                            <Input
                                                id="slippage_window_days"
                                                type="number"
                                                min="1"
                                                max="365"
                                                value={generalForm.data.position_slippage_rule.window_days}
                                                onChange={(e) =>
                                                    generalForm.setData('position_slippage_rule', {
                                                        ...generalForm.data.position_slippage_rule,
                                                        window_days: Number.parseInt(e.target.value),
                                                    })
                                                }
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="slippage_min_samples">Minimum Samples</Label>
                                            <Input
                                                id="slippage_min_samples"
                                                type="number"
                                                min="1"
                                                value={generalForm.data.position_slippage_rule.min_samples}
                                                onChange={(e) =>
                                                    generalForm.setData('position_slippage_rule', {
                                                        ...generalForm.data.position_slippage_rule,
                                                        min_samples: Number.parseInt(e.target.value),
                                                    })
                                                }
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="slippage_cache_seconds">Cache Seconds</Label>
                                            <Input
                                                id="slippage_cache_seconds"
                                                type="number"
                                                min="10"
                                                max="3600"
                                                value={generalForm.data.position_slippage_rule.cache_seconds}
                                                onChange={(e) =>
                                                    generalForm.setData('position_slippage_rule', {
                                                        ...generalForm.data.position_slippage_rule,
                                                        cache_seconds: Number.parseInt(e.target.value),
                                                    })
                                                }
                                            />
                                        </div>

                                        <div className="flex items-end justify-between rounded-md border border-gray-100 px-3 py-2 dark:border-gray-700">
                                            <div>
                                                <Label htmlFor="slippage_include_paper" className="font-medium">Include Paper Orders</Label>
                                            </div>
                                            <ToggleSwitch
                                                id="slippage_include_paper"
                                                checked={generalForm.data.position_slippage_rule.include_paper_orders}
                                                onChange={(val) =>
                                                    generalForm.setData('position_slippage_rule', {
                                                        ...generalForm.data.position_slippage_rule,
                                                        include_paper_orders: val,
                                                    })
                                                }
                                            />
                                        </div>
                                    </div>

                                    <Separator />

                                    <div className="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-200">
                                        <p className="font-medium">How tier selection works</p>
                                        <p className="mt-1">
                                            The system evaluates rolling adverse slippage and selects one tier:
                                            high risk = <span className="font-semibold">Low Tier</span>,
                                            medium risk = <span className="font-semibold">Medium Tier</span>,
                                            low risk = <span className="font-semibold">High Tier</span>.
                                            The selected tier is then clamped by Min/Max Liquidity.
                                        </p>
                                    </div>

                                    <div className="grid grid-cols-3 gap-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="low_liquidity_pct">Low Tier (%)</Label>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                                Used when risk is highest (worst execution quality).
                                            </p>
                                            <Input
                                                id="low_liquidity_pct"
                                                type="number"
                                                step="0.1"
                                                min="0"
                                                max="100"
                                                value={generalForm.data.position_slippage_rule.low_liquidity_pct}
                                                onChange={(e) =>
                                                    generalForm.setData('position_slippage_rule', {
                                                        ...generalForm.data.position_slippage_rule,
                                                        low_liquidity_pct: Number.parseFloat(e.target.value),
                                                    })
                                                }
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="medium_liquidity_pct">Medium Tier (%)</Label>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                                Used when slippage is elevated but not severe.
                                            </p>
                                            <Input
                                                id="medium_liquidity_pct"
                                                type="number"
                                                step="0.1"
                                                min="0"
                                                max="100"
                                                value={generalForm.data.position_slippage_rule.medium_liquidity_pct}
                                                onChange={(e) =>
                                                    generalForm.setData('position_slippage_rule', {
                                                        ...generalForm.data.position_slippage_rule,
                                                        medium_liquidity_pct: Number.parseFloat(e.target.value),
                                                    })
                                                }
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="high_liquidity_pct">High Tier (%)</Label>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                                Used when recent execution quality is healthy.
                                            </p>
                                            <Input
                                                id="high_liquidity_pct"
                                                type="number"
                                                step="0.1"
                                                min="0"
                                                max="100"
                                                value={generalForm.data.position_slippage_rule.high_liquidity_pct}
                                                onChange={(e) =>
                                                    generalForm.setData('position_slippage_rule', {
                                                        ...generalForm.data.position_slippage_rule,
                                                        high_liquidity_pct: Number.parseFloat(e.target.value),
                                                    })
                                                }
                                            />
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="medium_risk_avg_slippage_pct">Medium Risk Avg Slippage (%)</Label>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                                If rolling average adverse slippage reaches this, move to at least Medium Tier.
                                            </p>
                                            <Input
                                                id="medium_risk_avg_slippage_pct"
                                                type="number"
                                                step="0.001"
                                                min="0"
                                                max="10"
                                                value={generalForm.data.position_slippage_rule.medium_risk_avg_slippage_pct}
                                                onChange={(e) =>
                                                    generalForm.setData('position_slippage_rule', {
                                                        ...generalForm.data.position_slippage_rule,
                                                        medium_risk_avg_slippage_pct: Number.parseFloat(e.target.value),
                                                    })
                                                }
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="medium_risk_worst_slippage_pct">Medium Risk Worst Slippage (%)</Label>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                                If worst adverse slippage reaches this, move to at least Medium Tier.
                                            </p>
                                            <Input
                                                id="medium_risk_worst_slippage_pct"
                                                type="number"
                                                step="0.001"
                                                min="0"
                                                max="10"
                                                value={generalForm.data.position_slippage_rule.medium_risk_worst_slippage_pct}
                                                onChange={(e) =>
                                                    generalForm.setData('position_slippage_rule', {
                                                        ...generalForm.data.position_slippage_rule,
                                                        medium_risk_worst_slippage_pct: Number.parseFloat(e.target.value),
                                                    })
                                                }
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="high_risk_avg_slippage_pct">High Risk Avg Slippage (%)</Label>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                                If rolling average adverse slippage reaches this, force Low Tier.
                                            </p>
                                            <Input
                                                id="high_risk_avg_slippage_pct"
                                                type="number"
                                                step="0.001"
                                                min="0"
                                                max="10"
                                                value={generalForm.data.position_slippage_rule.high_risk_avg_slippage_pct}
                                                onChange={(e) =>
                                                    generalForm.setData('position_slippage_rule', {
                                                        ...generalForm.data.position_slippage_rule,
                                                        high_risk_avg_slippage_pct: Number.parseFloat(e.target.value),
                                                    })
                                                }
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="high_risk_worst_slippage_pct">High Risk Worst Slippage (%)</Label>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                                If worst adverse slippage reaches this, force Low Tier.
                                            </p>
                                            <Input
                                                id="high_risk_worst_slippage_pct"
                                                type="number"
                                                step="0.001"
                                                min="0"
                                                max="10"
                                                value={generalForm.data.position_slippage_rule.high_risk_worst_slippage_pct}
                                                onChange={(e) =>
                                                    generalForm.setData('position_slippage_rule', {
                                                        ...generalForm.data.position_slippage_rule,
                                                        high_risk_worst_slippage_pct: Number.parseFloat(e.target.value),
                                                    })
                                                }
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="min_liquidity_pct">Minimum Liquidity (%)</Label>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                                Hard floor: selected tier cannot go below this percentage.
                                            </p>
                                            <Input
                                                id="min_liquidity_pct"
                                                type="number"
                                                step="0.1"
                                                min="0"
                                                max="100"
                                                value={generalForm.data.position_slippage_rule.min_liquidity_pct}
                                                onChange={(e) =>
                                                    generalForm.setData('position_slippage_rule', {
                                                        ...generalForm.data.position_slippage_rule,
                                                        min_liquidity_pct: Number.parseFloat(e.target.value),
                                                    })
                                                }
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="max_liquidity_pct">Maximum Liquidity (%)</Label>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                                Hard ceiling: selected tier cannot exceed this percentage.
                                            </p>
                                            <Input
                                                id="max_liquidity_pct"
                                                type="number"
                                                step="0.1"
                                                min="0"
                                                max="100"
                                                value={generalForm.data.position_slippage_rule.max_liquidity_pct}
                                                onChange={(e) =>
                                                    generalForm.setData('position_slippage_rule', {
                                                        ...generalForm.data.position_slippage_rule,
                                                        max_liquidity_pct: Number.parseFloat(e.target.value),
                                                    })
                                                }
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center gap-3">
                                <Button type="submit" disabled={generalForm.processing}>
                                    {generalForm.processing ? 'Saving…' : 'Save Position Sizing'}
                                </Button>
                                <SavedBadge show={generalForm.recentlySuccessful} />
                            </div>
                        </form>
                    </TabsContent>

                    {/* ── Tab 7: Circuit Breaker ── */}
                    <TabsContent value="circuit-breaker">
                        <div className="space-y-8">
                            {/* Settings Form */}
                            <form onSubmit={saveGeneral} className="space-y-6 max-w-3xl">
                                <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                                    <HeadingSmall
                                        title="Circuit Breaker Settings"
                                        description="Pause new entries when losing stops fire rapidly"
                                    />

                                    <div className="mt-4 space-y-5">
                                        <div className="flex items-center justify-between">
                                            <div>
                                                <Label htmlFor="circuit_breaker_enabled" className="font-medium">
                                                    Circuit Breaker Enabled
                                                </Label>
                                                <p className="text-sm text-gray-500 dark:text-gray-400">
                                                    Blocks new orders after too many losing stops in a short window
                                                </p>
                                            </div>
                                            <ToggleSwitch
                                                id="circuit_breaker_enabled"
                                                checked={generalForm.data.circuit_breaker_enabled}
                                                onChange={(val) => generalForm.setData('circuit_breaker_enabled', val)}
                                            />
                                        </div>

                                        <Separator />

                                        <div className="grid grid-cols-3 gap-4">
                                            <div className="grid gap-2">
                                                <Label htmlFor="cb_threshold">Stops Threshold</Label>
                                                <p className="text-xs text-gray-500 dark:text-gray-400"># losing stops to trip breaker</p>
                                                <Input
                                                    id="cb_threshold"
                                                    type="number"
                                                    min="1"
                                                    max="20"
                                                    value={generalForm.data.circuit_breaker_stops_threshold}
                                                    onChange={(e) => generalForm.setData('circuit_breaker_stops_threshold', parseInt(e.target.value))}
                                                />
                                                {generalForm.errors.circuit_breaker_stops_threshold && (
                                                    <p className="text-sm text-red-500">{generalForm.errors.circuit_breaker_stops_threshold}</p>
                                                )}
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="cb_window">Window (minutes)</Label>
                                                <p className="text-xs text-gray-500 dark:text-gray-400">Look-back window for stop count</p>
                                                <Input
                                                    id="cb_window"
                                                    type="number"
                                                    min="1"
                                                    max="120"
                                                    value={generalForm.data.circuit_breaker_window_minutes}
                                                    onChange={(e) => generalForm.setData('circuit_breaker_window_minutes', parseInt(e.target.value))}
                                                />
                                                {generalForm.errors.circuit_breaker_window_minutes && (
                                                    <p className="text-sm text-red-500">{generalForm.errors.circuit_breaker_window_minutes}</p>
                                                )}
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="cb_pause">Pause (minutes)</Label>
                                                <p className="text-xs text-gray-500 dark:text-gray-400">How long to block new orders</p>
                                                <Input
                                                    id="cb_pause"
                                                    type="number"
                                                    min="1"
                                                    max="480"
                                                    value={generalForm.data.circuit_breaker_pause_minutes}
                                                    onChange={(e) => generalForm.setData('circuit_breaker_pause_minutes', parseInt(e.target.value))}
                                                />
                                                {generalForm.errors.circuit_breaker_pause_minutes && (
                                                    <p className="text-sm text-red-500">{generalForm.errors.circuit_breaker_pause_minutes}</p>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div className="flex items-center gap-3">
                                    <Button type="submit" disabled={generalForm.processing}>
                                        {generalForm.processing ? 'Saving…' : 'Save Settings'}
                                    </Button>
                                    <SavedBadge show={generalForm.recentlySuccessful} />
                                </div>
                            </form>

                            <Separator />

                            {/* History Table */}
                            <div className="max-w-4xl">
                                <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                                    <div className="flex items-center justify-between">
                                        <HeadingSmall
                                            title="Circuit Breaker History"
                                            description="Last 20 trips — paper trips are recorded but do not block orders"
                                        />
                                        {circuitBreakerEvents.find((e) => e.is_active && !e.is_paper) ? (
                                            <div className="flex items-center gap-2 rounded-md bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 dark:bg-red-900/30 dark:text-red-400">
                                                <ShieldAlert className="h-4 w-4" />
                                                TRIPPED — expires{' '}
                                                {new Date(circuitBreakerEvents.find((e) => e.is_active && !e.is_paper)!.pause_expires_at).toLocaleTimeString([], {
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                })}
                                            </div>
                                        ) : (
                                            <div className="flex items-center gap-2 rounded-md bg-green-50 px-3 py-1.5 text-sm font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                                <ShieldCheck className="h-4 w-4" />
                                                Clear
                                            </div>
                                        )}
                                    </div>

                                    {circuitBreakerEvents.length === 0 ? (
                                        <p className="mt-4 text-sm text-gray-400 dark:text-gray-500">No circuit breaker trips recorded yet.</p>
                                    ) : (
                                        <div className="mt-4 overflow-x-auto">
                                            <table className="w-full text-sm">
                                                <thead>
                                                    <tr className="border-b border-gray-200 dark:border-gray-700">
                                                        <th className="pb-2 text-left font-medium text-gray-500 dark:text-gray-400">Tripped At</th>
                                                        <th className="pb-2 text-left font-medium text-gray-500 dark:text-gray-400">Symbol</th>
                                                        <th className="pb-2 text-center font-medium text-gray-500 dark:text-gray-400">Stops</th>
                                                        <th className="pb-2 text-center font-medium text-gray-500 dark:text-gray-400">Window</th>
                                                        <th className="pb-2 text-center font-medium text-gray-500 dark:text-gray-400">Pause</th>
                                                        <th className="pb-2 text-left font-medium text-gray-500 dark:text-gray-400">Expires At</th>
                                                        <th className="pb-2 text-center font-medium text-gray-500 dark:text-gray-400">Mode</th>
                                                        <th className="pb-2 text-center font-medium text-gray-500 dark:text-gray-400">Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                                                    {circuitBreakerEvents.map((e) => (
                                                        <tr key={e.id} className={e.is_active && !e.is_paper ? 'bg-red-50 dark:bg-red-900/10' : ''}>
                                                            <td className="py-2 pr-4 font-mono text-xs text-gray-700 dark:text-gray-300">
                                                                {new Date(e.tripped_at).toLocaleString([], {
                                                                    month: 'short',
                                                                    day: 'numeric',
                                                                    hour: '2-digit',
                                                                    minute: '2-digit',
                                                                })}
                                                            </td>
                                                            <td className="py-2 pr-4 font-semibold text-gray-900 dark:text-gray-100">{e.symbol}</td>
                                                            <td className="py-2 pr-4 text-center text-gray-700 dark:text-gray-300">{e.losing_stops_count}</td>
                                                            <td className="py-2 pr-4 text-center text-gray-500 dark:text-gray-400">{e.window_minutes}m</td>
                                                            <td className="py-2 pr-4 text-center text-gray-500 dark:text-gray-400">{e.pause_minutes}m</td>
                                                            <td className="py-2 pr-4 font-mono text-xs text-gray-500 dark:text-gray-400">
                                                                {new Date(e.pause_expires_at).toLocaleTimeString([], {
                                                                    hour: '2-digit',
                                                                    minute: '2-digit',
                                                                })}
                                                            </td>
                                                            <td className="py-2 pr-4 text-center">
                                                                {e.is_paper ? (
                                                                    <Badge variant="secondary" className="text-xs">Paper</Badge>
                                                                ) : (
                                                                    <Badge className="bg-orange-500 text-white text-xs">Live</Badge>
                                                                )}
                                                            </td>
                                                            <td className="py-2 text-center">
                                                                {e.is_active ? (
                                                                    <Badge className="bg-red-500 text-white text-xs">Active</Badge>
                                                                ) : (
                                                                    <Badge variant="secondary" className="text-xs">Expired</Badge>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    </TabsContent>

                    {/* ── Stop Loss Tab ── */}
                    <TabsContent value="stop-loss">
                        <form onSubmit={saveStopLoss} className="max-w-3xl">
                            <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                                <HeadingSmall
                                    title="Stop Loss Configuration"
                                    description="ATR-based or fixed percentage stops."
                                />
                                <div className="mt-4 space-y-5">
                                    {/* Mode Select */}
                                    <div className="grid gap-2">
                                        <Label htmlFor="stopLossMode">Stop Loss Mode</Label>
                                        <select
                                            id="stopLossMode"
                                            className="w-40 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800"
                                            value={stopLossForm.data.mode}
                                            onChange={(e) => stopLossForm.setData('mode', e.target.value)}
                                        >
                                            <option value="atr">ATR-based</option>
                                            <option value="fixed">Fixed %</option>
                                        </select>
                                    </div>

                                    <Separator />

                                    <div className="flex items-center justify-between">
                                        <div>
                                            <Label className="font-medium">Profit Protection Trail</Label>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                                Tiered trailing: +0.75% → -0.25%, +1.25% → +0.50%, +2.00% → +1.00%, above → trail max(1%,2×ATR)
                                            </p>
                                        </div>
                                        <ToggleSwitch
                                            id="profit_protection_enabled"
                                            checked={stopLossForm.data.profit_protection_enabled}
                                            onChange={(val) => stopLossForm.setData('profit_protection_enabled', val)}
                                        />
                                    </div>

                                    <Separator />

                                    {stopLossForm.data.mode === 'fixed' && (
                                        <div className="grid gap-2">
                                            <Label htmlFor="fixed_pct">Fixed Stop Loss (%)</Label>
                                            <Input id="fixed_pct" type="number" min="0.1" max="5" step="0.05" className="w-32"
                                                value={stopLossForm.data.fixed_pct}
                                                onChange={(e) => stopLossForm.setData('fixed_pct', parseFloat(e.target.value))} />
                                        </div>
                                    )}

                                    {stopLossForm.data.mode === 'atr' && (
                                        <div className="grid grid-cols-3 gap-4">
                                            <div className="grid gap-1">
                                                <Label htmlFor="atr_multiplier">ATR Multiplier</Label>
                                                <Input id="atr_multiplier" type="number" min="0.5" max="10" step="0.1" className="w-28"
                                                    value={stopLossForm.data.atr_multiplier}
                                                    onChange={(e) => stopLossForm.setData('atr_multiplier', parseFloat(e.target.value))} />
                                            </div>
                                            <div className="grid gap-1">
                                                <Label htmlFor="atr_min_pct">ATR Min (%)</Label>
                                                <Input id="atr_min_pct" type="number" min="0.1" max="5" step="0.05" className="w-28"
                                                    value={stopLossForm.data.atr_min_pct}
                                                    onChange={(e) => stopLossForm.setData('atr_min_pct', parseFloat(e.target.value))} />
                                            </div>
                                            <div className="grid gap-1">
                                                <Label htmlFor="atr_max_pct">ATR Max (%)</Label>
                                                <Input id="atr_max_pct" type="number" min="0.1" max="10" step="0.05" className="w-28"
                                                    value={stopLossForm.data.atr_max_pct}
                                                    onChange={(e) => stopLossForm.setData('atr_max_pct', parseFloat(e.target.value))} />
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                            <div className="mt-4 flex items-center gap-3">
                                <Button type="submit" disabled={stopLossForm.processing}>
                                    {stopLossForm.processing ? 'Saving…' : 'Save Stop Loss Settings'}
                                </Button>
                                <SavedBadge show={stopLossForm.recentlySuccessful} />
                            </div>
                        </form>
                    </TabsContent>

                    {/* ── Limit Orders Tab ── */}
                    <TabsContent value="limit-orders">
                        <form onSubmit={saveLimitOrders} className="max-w-3xl">
                            <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                                <HeadingSmall
                                    title="Limit Order & Slippage Settings"
                                    description="Per-pipeline and global slippage overrides."
                                />
                                <div className="mt-4 space-y-5">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <Label className="font-medium">Use Limit Orders</Label>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                                When ON, orders cap entry at signal_price + slippage%. When OFF, market orders are used.
                                            </p>
                                        </div>
                                        <ToggleSwitch
                                            id="use_limit_orders"
                                            checked={limitOrdersForm.data.use_limit_orders}
                                            onChange={(val) => limitOrdersForm.setData('use_limit_orders', val)}
                                        />
                                    </div>
                                    <Separator />
                                    <div className="grid grid-cols-3 gap-4">
                                        <div className="grid gap-1">
                                            <Label htmlFor="slippage_pct">Global Slippage (%)</Label>
                                            <Input id="slippage_pct" type="number" min="0" max="5" step="0.05" className="w-28"
                                                value={limitOrdersForm.data.slippage_pct}
                                                onChange={(e) => limitOrdersForm.setData('slippage_pct', parseFloat(e.target.value))} />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="slippage_pct_stale_price">Stale Price Slippage (%)</Label>
                                            <Input id="slippage_pct_stale_price" type="number" min="0" max="10" step="0.05" className="w-28"
                                                value={limitOrdersForm.data.slippage_pct_stale_price}
                                                onChange={(e) => limitOrdersForm.setData('slippage_pct_stale_price', parseFloat(e.target.value))} />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="partial_fill_stop_timeout_minutes">Partial Fill Stop Timeout (min)</Label>
                                            <Input id="partial_fill_stop_timeout_minutes" type="number" min="0.1" max="30" step="0.1" className="w-28"
                                                value={limitOrdersForm.data.partial_fill_stop_timeout_minutes}
                                                onChange={(e) => limitOrdersForm.setData('partial_fill_stop_timeout_minutes', parseFloat(e.target.value))} />
                                        </div>
                                    </div>

                                    <Separator />
                                    <Label className="font-medium">Per-Pipeline Slippage Overrides</Label>
                                    <p className="text-xs text-gray-500 dark:text-gray-400">Leave empty to use global default.</p>
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b border-gray-200 dark:border-gray-700">
                                                    <th className="pb-2 text-left font-medium text-gray-500 dark:text-gray-400">Pipeline</th>
                                                    <th className="pb-2 text-left font-medium text-gray-500 dark:text-gray-400">Slippage (%)</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                                                {Object.entries(limitOrdersForm.data.pipeline_overrides).map(([p, val]) => (
                                                    <tr key={p}>
                                                        <td className="py-2 pr-4 font-medium text-gray-900 dark:text-gray-100">{pipelineDisplayNames[p] ?? `Pipeline ${p.toUpperCase()}`}</td>
                                                        <td className="py-2">
                                                            <Input type="number" min="0" max="5" step="0.05" className="w-28"
                                                                value={val ?? ''}
                                                                placeholder="Global default"
                                                                onChange={(e) =>
                                                                    limitOrdersForm.setData('pipeline_overrides', {
                                                                        ...limitOrdersForm.data.pipeline_overrides,
                                                                        [p]: e.target.value ? parseFloat(e.target.value) : null,
                                                                    })
                                                                } />
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div className="mt-4 flex items-center gap-3">
                                <Button type="submit" disabled={limitOrdersForm.processing}>
                                    {limitOrdersForm.processing ? 'Saving…' : 'Save Limit Order Settings'}
                                </Button>
                                <SavedBadge show={limitOrdersForm.recentlySuccessful} />
                            </div>
                        </form>
                    </TabsContent>

                    {/* ── Trading Hours Tab ── */}
                    <TabsContent value="trading-hours">
                        <form onSubmit={saveTradingHours} className="max-w-xl">
                            <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                                <HeadingSmall
                                    title="Trading Hours"
                                    description="EST timezone — orders are only placed within this window."
                                />
                                <div className="mt-4 grid grid-cols-2 gap-4">
                                    <div className="grid gap-1">
                                        <Label htmlFor="start_time">Start Time (EST)</Label>
                                        <Input id="start_time" type="time" className="w-36"
                                            value={tradingHoursForm.data.start_time}
                                            onChange={(e) => tradingHoursForm.setData('start_time', e.target.value)} />
                                    </div>
                                    <div className="grid gap-1">
                                        <Label htmlFor="end_time">End Time (EST)</Label>
                                        <Input id="end_time" type="time" className="w-36"
                                            value={tradingHoursForm.data.end_time}
                                            onChange={(e) => tradingHoursForm.setData('end_time', e.target.value)} />
                                    </div>
                                </div>
                            </div>
                            <div className="mt-4 flex items-center gap-3">
                                <Button type="submit" disabled={tradingHoursForm.processing}>
                                    {tradingHoursForm.processing ? 'Saving…' : 'Save Trading Hours'}
                                </Button>
                                <SavedBadge show={tradingHoursForm.recentlySuccessful} />
                            </div>
                        </form>
                    </TabsContent>

                    {/* ── Stale Rescore Tab ── */}
                    <TabsContent value="stale-rescore">
                        <form onSubmit={saveStaleRescore} className="max-w-xl">
                            <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                                <HeadingSmall
                                    title="Stale Alert Rescore"
                                    description="When enabled, stale alerts can be re-scored with a fresh ML prediction instead of being rejected."
                                />
                                <div className="mt-4 space-y-5">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <Label className="font-medium">Stale Rescore Enabled</Label>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                                Allows stale alerts beyond max_age to be re-evaluated by the ML scorer.
                                            </p>
                                        </div>
                                        <ToggleSwitch
                                            id="staleRescoreEnabled"
                                            checked={staleRescoreForm.data.enabled}
                                            onChange={(val) => staleRescoreForm.setData('enabled', val)}
                                        />
                                    </div>
                                    <Separator />
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <Label className="font-medium">Paper Only</Label>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                                Only apply stale rescore logic in paper trading mode.
                                            </p>
                                        </div>
                                        <ToggleSwitch
                                            id="staleRescorePaperOnly"
                                            checked={staleRescoreForm.data.paper_only}
                                            onChange={(val) => staleRescoreForm.setData('paper_only', val)}
                                        />
                                    </div>
                                    <Separator />
                                    <div className="grid gap-1">
                                        <Label htmlFor="staleRescoreMaxAge">Max Age for Rescore (minutes)</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400">
                                            Alerts older than this will not be re-scored.
                                        </p>
                                        <Input id="staleRescoreMaxAge" type="number" min="1" max="480" className="w-28"
                                            value={staleRescoreForm.data.max_age_minutes}
                                            onChange={(e) => staleRescoreForm.setData('max_age_minutes', parseInt(e.target.value))} />
                                    </div>
                                </div>
                            </div>
                            <div className="mt-4 flex items-center gap-3">
                                <Button type="submit" disabled={staleRescoreForm.processing}>
                                    {staleRescoreForm.processing ? 'Saving…' : 'Save Stale Rescore Settings'}
                                </Button>
                                <SavedBadge show={staleRescoreForm.recentlySuccessful} />
                            </div>
                        </form>
                    </TabsContent>

                    {/* ── Benchmark VWAP Gate Tab ── */}
                    <TabsContent value="benchmark-vwap">
                        <form onSubmit={saveBenchmarkVwapGate} className="max-w-3xl">
                            <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                                <HeadingSmall
                                    title="Benchmark VWAP Gate"
                                    description="Skip new orders when the benchmark symbol is below its intraday VWAP (bearish market context)."
                                />
                                <div className="mt-4 space-y-5">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <Label className="font-medium">VWAP Gate Enabled</Label>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                                When ON, skips new orders if the benchmark's latest 5-min close is <strong>strictly below</strong> its VWAP
                                                (i.e. <code>vwap_dist_pct &lt; 0</code>). Bars at or above VWAP will pass.
                                            </p>
                                        </div>
                                        <ToggleSwitch
                                            id="benchmarkVwapEnabled"
                                            checked={benchmarkVwapGateForm.data.enabled}
                                            onChange={(val) => benchmarkVwapGateForm.setData('enabled', val)}
                                        />
                                    </div>
                                    <Separator />
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="grid gap-1">
                                            <Label htmlFor="benchmarkSymbol">Benchmark Symbol</Label>
                                            <Input id="benchmarkSymbol" type="text" maxLength={10} className="w-28 uppercase"
                                                value={benchmarkVwapGateForm.data.symbol}
                                                onChange={(e) => benchmarkVwapGateForm.setData('symbol', e.target.value.toUpperCase())} />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="maxPctBelowHigh">
                                                Max % Below High <span className="font-normal text-gray-400">(optional)</span>
                                            </Label>
                                            <p className="-mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                                Also block if the benchmark has fallen more than this % from its intraday high — even if
                                                above VWAP. Leave blank to disable this secondary check.
                                            </p>
                                            <Input id="maxPctBelowHigh" type="number" min="0" max="10" step="0.1" className="w-28"
                                                value={benchmarkVwapGateForm.data.max_pct_below_high ?? ''}
                                                placeholder="Disabled"
                                                onChange={(e) =>
                                                    benchmarkVwapGateForm.setData('max_pct_below_high', e.target.value ? parseFloat(e.target.value) : null)
                                                } />
                                        </div>
                                    </div>

                                    <Separator />
                                    <Label className="font-medium">Per-Pipeline Overrides</Label>
                                    <p className="text-xs text-gray-500 dark:text-gray-400">
                                        Set to On to force enable, Off to force disable, or leave at "Default" to use the global setting.
                                    </p>
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b border-gray-200 dark:border-gray-700">
                                                    <th className="pb-2 text-left font-medium text-gray-500 dark:text-gray-400">Pipeline</th>
                                                    <th className="pb-2 text-left font-medium text-gray-500 dark:text-gray-400">Override</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                                                {Object.entries(benchmarkVwapGateForm.data.pipeline_overrides).map(([p, val]) => (
                                                    <tr key={p}>
                                                        <td className="py-2 pr-4 font-medium text-gray-900 dark:text-gray-100">{pipelineDisplayNames[p] ?? `Pipeline ${p.toUpperCase()}`}</td>
                                                        <td className="py-2">
                                                            <select
                                                                className="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-800"
                                                                value={val === true ? 'true' : val === false ? 'false' : ''}
                                                                onChange={(e) =>
                                                                    benchmarkVwapGateForm.setData('pipeline_overrides', {
                                                                        ...benchmarkVwapGateForm.data.pipeline_overrides,
                                                                        [p]: e.target.value === '' ? null : e.target.value === 'true',
                                                                    })
                                                                }
                                                            >
                                                                <option value="">Default</option>
                                                                <option value="true">On</option>
                                                                <option value="false">Off</option>
                                                            </select>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div className="mt-4 flex items-center gap-3">
                                <Button type="submit" disabled={benchmarkVwapGateForm.processing}>
                                    {benchmarkVwapGateForm.processing ? 'Saving…' : 'Save VWAP Gate Settings'}
                                </Button>
                                <SavedBadge show={benchmarkVwapGateForm.recentlySuccessful} />
                            </div>
                        </form>

                        {/* Today's VWAP Status Table */}
                        <div className="mt-6 rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                            <HeadingSmall
                                title="Today's 5-Min VWAP Status"
                                description={'Each bar\'s position relative to VWAP. Red = blocked by gate (price below VWAP), green = passes.'}
                            />
                            <div className="mt-4 overflow-x-auto">
                                <table className="w-full text-xs sm:text-sm">
                                    <thead>
                                        <tr className="border-b border-gray-200 dark:border-gray-700">
                                            <th className="pb-2 pr-2 text-left font-medium text-gray-500 dark:text-gray-400">Time (ET)</th>
                                            <th className="pb-2 pr-2 text-right font-medium text-gray-500 dark:text-gray-400">Price</th>
                                            <th className="pb-2 pr-2 text-right font-medium text-gray-500 dark:text-gray-400">VWAP</th>
                                            <th className="pb-2 pr-2 text-right font-medium text-gray-500 dark:text-gray-400">Dist%</th>
                                            <th className="pb-2 text-center font-medium text-gray-500 dark:text-gray-400">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                                        {benchmarkVwapBars.map((bar) => {
                                            const dist = parseFloat(bar.vwap_dist_pct);
                                            const passed = dist >= 0;
                                            return (
                                                <tr key={bar.ts_est} className={passed ? '' : 'bg-red-50 dark:bg-red-900/10'}>
                                                    <td className="py-1.5 pr-2 font-medium text-gray-900 dark:text-gray-100">
                                                        {bar.ts_est.slice(11, 16)}
                                                    </td>
                                                    <td className="py-1.5 pr-2 text-right text-gray-700 dark:text-gray-300">
                                                        {parseFloat(bar.price).toFixed(2)}
                                                    </td>
                                                    <td className="py-1.5 pr-2 text-right text-gray-700 dark:text-gray-300">
                                                        {parseFloat(bar.vwap).toFixed(2)}
                                                    </td>
                                                    <td className={`py-1.5 pr-2 text-right ${dist < 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'}`}>
                                                        {dist.toFixed(3)}%
                                                    </td>
                                                    <td className="py-1.5 text-center">
                                                        {passed ? (
                                                            <span className="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                                                Pass
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-400">
                                                                Blocked
                                                            </span>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                        {benchmarkVwapBars.length === 0 && (
                                            <tr>
                                                <td colSpan={5} className="py-8 text-center text-sm text-gray-400">
                                                    No bars found for today.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </TabsContent>

                    {/* ── Realtime ── */}
                    <TabsContent value="realtime">
                        <form onSubmit={saveRealtime} className="space-y-6 max-w-3xl">
                            <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                                <HeadingSmall
                                    title="Candidate Detection"
                                    description="Gates applied in EarlyCandidateDetectorService before a candidate is created. Restart the watcher after changes."
                                />
                                <div className="mt-4 grid grid-cols-2 gap-4">
                                    <div>
                                        <Label htmlFor="rt-early-score">Early Score Minimum</Label>
                                        <Input id="rt-early-score" type="number" step="1" value={realtimeForm.data.early_score_min} onChange={(e) => realtimeForm.setData('early_score_min', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-candidate-ttl">Candidate TTL (seconds)</Label>
                                        <Input id="rt-candidate-ttl" type="number" step="1" value={realtimeForm.data.candidate_ttl_seconds} onChange={(e) => realtimeForm.setData('candidate_ttl_seconds', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-min-dollar-vol">Min Dollar Volume/min</Label>
                                        <Input id="rt-min-dollar-vol" type="number" step="100" value={realtimeForm.data.min_dollar_volume_1m} onChange={(e) => realtimeForm.setData('min_dollar_volume_1m', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-min-rvol">Min Relative Volume (RVOL)</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Minimum volume ratio vs 20-bar avg.</p>
                                        <Input id="rt-min-rvol" type="number" step="0.1" value={realtimeForm.data.min_rvol} onChange={(e) => realtimeForm.setData('min_rvol', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-min-atr">Min ATR%</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Minimum average true range percentage.</p>
                                        <Input id="rt-min-atr" type="number" step="0.05" value={realtimeForm.data.min_atr_pct} onChange={(e) => realtimeForm.setData('min_atr_pct', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-min-move-30m">Min 30m Move %</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Minimum price movement over last 30 minutes.</p>
                                        <Input id="rt-min-move-30m" type="number" step="0.1" value={realtimeForm.data.min_move_30m_pct} onChange={(e) => realtimeForm.setData('min_move_30m_pct', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-max-vwap-ext">Max VWAP Extension %</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Reject candidates extended too far above VWAP.</p>
                                        <Input id="rt-max-vwap-ext" type="number" step="0.05" value={realtimeForm.data.max_vwap_extension_pct} onChange={(e) => realtimeForm.setData('max_vwap_extension_pct', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-max-entry-age">Max Entry Age (seconds)</Label>
                                        <Input id="rt-max-entry-age" type="number" step="1" value={realtimeForm.data.max_entry_age_seconds} onChange={(e) => realtimeForm.setData('max_entry_age_seconds', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-skip-first">Skip First X Minutes</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Block Pipeline R orders within this many minutes after 9:30 AM ET. Overrides time slots for the opening minutes. 0 = disabled.</p>
                                        <Input id="rt-skip-first" type="number" step="1" min="0" max="30" value={realtimeForm.data.skip_first_minutes} onChange={(e) => realtimeForm.setData('skip_first_minutes', Number(e.target.value))} />
                                    </div>
                                </div>
                            </div>

                            <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                                <HeadingSmall
                                    title="Entry Trigger Gates"
                                    description="Gates applied in RealtimeEntryTriggerService when deciding whether to create a trade_alert for Pipeline R. DB-backed — changes take effect immediately (60s cache)."
                                />
                                <div className="mt-4 grid grid-cols-2 gap-4">
                                    <div>
                                        <Label htmlFor="rt-candidate-max-age">Candidate Max Age (seconds)</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Drop candidates older than this.</p>
                                        <Input id="rt-candidate-max-age" type="number" step="10" value={realtimeForm.data.entry_candidate_max_age_seconds} onChange={(e) => realtimeForm.setData('entry_candidate_max_age_seconds', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-final-score-min">Final Score Minimum</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Composite score must be ≥ this to trigger.</p>
                                        <Input id="rt-final-score-min" type="number" step="1" value={realtimeForm.data.entry_final_score_min} onChange={(e) => realtimeForm.setData('entry_final_score_min', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-min-price">Min Entry Price ($)</Label>
                                        <Input id="rt-min-price" type="number" step="0.50" value={realtimeForm.data.entry_min_price} onChange={(e) => realtimeForm.setData('entry_min_price', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-max-price">Max Entry Price ($)</Label>
                                        <Input id="rt-max-price" type="number" step="10" value={realtimeForm.data.entry_max_price} onChange={(e) => realtimeForm.setData('entry_max_price', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-return-1m">Min 1m Return (%)</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Partial 1m bar must show at least this move.</p>
                                        <Input id="rt-return-1m" type="number" step="0.01" value={realtimeForm.data.entry_return_1m_min_pct} onChange={(e) => realtimeForm.setData('entry_return_1m_min_pct', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-return-3m">Min 3m Return (%)</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Saved candidate 3m return must meet this.</p>
                                        <Input id="rt-return-3m" type="number" step="0.05" value={realtimeForm.data.entry_return_3m_min_pct} onChange={(e) => realtimeForm.setData('entry_return_3m_min_pct', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-vol-ratio">Min Volume Ratio</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Volume vs average required to confirm.</p>
                                        <Input id="rt-vol-ratio" type="number" step="0.1" value={realtimeForm.data.entry_volume_ratio_min} onChange={(e) => realtimeForm.setData('entry_volume_ratio_min', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-dollar-vol">Min Dollar Volume 1m</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Minimum notional traded in the partial bar.</p>
                                        <Input id="rt-dollar-vol" type="number" step="500" value={realtimeForm.data.entry_min_dollar_volume_1m} onChange={(e) => realtimeForm.setData('entry_min_dollar_volume_1m', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-max-move-candidate">Max Move Since Candidate (%)</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Reject if price moved too far up from detection.</p>
                                        <Input id="rt-max-move-candidate" type="number" step="0.05" value={realtimeForm.data.max_move_since_candidate_pct} onChange={(e) => realtimeForm.setData('max_move_since_candidate_pct', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-min-move-candidate">Min Move Since Candidate (%)</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Reject if price dropped below detection (can be negative).</p>
                                        <Input id="rt-min-move-candidate" type="number" step="0.05" value={realtimeForm.data.entry_above_candidate_min_pct} onChange={(e) => realtimeForm.setData('entry_above_candidate_min_pct', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-close-pos">Min Close Position</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Close must be this far above the bar low (0-1).</p>
                                        <Input id="rt-close-pos" type="number" step="0.05" value={realtimeForm.data.entry_close_position_min} onChange={(e) => realtimeForm.setData('entry_close_position_min', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-upper-wick">Max Upper Wick Ratio</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Reject large rejection wicks (0-1).</p>
                                        <Input id="rt-upper-wick" type="number" step="0.05" value={realtimeForm.data.entry_upper_wick_max} onChange={(e) => realtimeForm.setData('entry_upper_wick_max', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-ba-imbalance">Min Bid/Ask Imbalance</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Bid pressure vs ask (can be negative).</p>
                                        <Input id="rt-ba-imbalance" type="number" step="0.05" value={realtimeForm.data.entry_bid_ask_imbalance_min} onChange={(e) => realtimeForm.setData('entry_bid_ask_imbalance_min', Number(e.target.value))} />
                                    </div>
                                    <div className="flex flex-col justify-end gap-2">
                                        <ToggleSwitch
                                            id="rt-require-vwap"
                                            checked={realtimeForm.data.entry_require_vwap}
                                            onChange={(val) => realtimeForm.setData('entry_require_vwap', val)}
                                        />
                                        <div>
                                            <Label htmlFor="rt-require-vwap" className="text-sm">Require VWAP</Label>
                                            <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Reject if VWAP is missing or price below VWAP.</p>
                                        </div>
                                    </div>
                                    <div className="flex flex-col justify-end gap-2">
                                        <ToggleSwitch
                                            id="rt-require-ema"
                                            checked={realtimeForm.data.entry_require_ema9_above_ema21}
                                            onChange={(val) => realtimeForm.setData('entry_require_ema9_above_ema21', val)}
                                        />
                                        <div>
                                            <Label htmlFor="rt-require-ema" className="text-sm">Require EMA9 Above EMA21</Label>
                                            <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Reject if short-term trend is bearish.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                                <HeadingSmall
                                    title="Momentum Continuation Finder"
                                    description="Settings for RealtimeMomentumContinuationFinder — consolidation detection, breakout confirmation, and structure validation."
                                />
                                <div className="mt-4 grid grid-cols-2 gap-4">
                                    <div>
                                        <Label htmlFor="rt-consol-range">Consolidation Max Range (%)</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Max bar range % during consolidation (tighter = stricter).</p>
                                        <Input id="rt-consol-range" type="number" step="0.1" value={realtimeForm.data.consolidation_max_range_pct} onChange={(e) => realtimeForm.setData('consolidation_max_range_pct', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-breakout-vol">Breakout Min Volume Ratio</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Volume surge needed to confirm breakout.</p>
                                        <Input id="rt-breakout-vol" type="number" step="0.05" value={realtimeForm.data.breakout_min_vol_ratio} onChange={(e) => realtimeForm.setData('breakout_min_vol_ratio', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-vwap-ext">Max VWAP Extension (%)</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Reject if already too extended above VWAP.</p>
                                        <Input id="rt-vwap-ext" type="number" step="0.05" value={realtimeForm.data.max_vwap_extension_pct_finder} onChange={(e) => realtimeForm.setData('max_vwap_extension_pct_finder', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-structure-bars">Structure Lookback (bars)</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Number of bars to check for HH/HL structure.</p>
                                        <Input id="rt-structure-bars" type="number" step="1" value={realtimeForm.data.structure_lookback_bars} onChange={(e) => realtimeForm.setData('structure_lookback_bars', Number(e.target.value))} />
                                    </div>
                                    <div>
                                        <Label htmlFor="rt-consol-bars">Consolidation Bar Count</Label>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Number of tight-range bars before breakout.</p>
                                        <Input id="rt-consol-bars" type="number" step="1" value={realtimeForm.data.consolidation_bar_count} onChange={(e) => realtimeForm.setData('consolidation_bar_count', Number(e.target.value))} />
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center gap-3">
                                <Button type="submit" disabled={realtimeForm.processing}>
                                    {realtimeForm.processing ? 'Saving…' : 'Save Realtime Settings'}
                                </Button>
                                <SavedBadge show={realtimeForm.recentlySuccessful} />
                            </div>
                        </form>
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
