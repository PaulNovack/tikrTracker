import { Head } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import { type BreadcrumbItem } from '@/types'
import { useEffect, useState } from 'react'

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'System', href: '/mysql-health' },
    { title: 'Pipeline Observability', href: '/pipeline-observability' },
]

interface PipelineStatus {
    pipeline_run: string
    label: string
    version: string
    ml_threshold: number
    enabled: boolean
    health: 'active' | 'stale' | 'no_alerts_today' | 'waiting' | 'disabled'
    realtime_count: number
    backtest_count: number
    last_realtime_at: string | null
    minutes_since_last: number | null
}

interface ThroughputHour {
    hour: number
    label: string
    realtime: number
    backtest: number
}

interface SkipReason {
    id: number
    version: string
    symbol: string
    reason: string
    count: number
    skip_price: number | null
    entry: number | null
    extension_pct: number | null
    age_minutes: number | null
    avg_dollar_volume_per_minute: number | null
    ml_live_win_prob: number | null
    ml_win_prob: number | null
    skipped_at: string | null
}

interface GapAlert {
    symbol: string
    version: string
    pipeline_run: string
    entry_ts_est: string
    entry_score: number | null
    ml_win_prob: number | null
}

interface MissRate {
    version: string
    backtest_total: number
    also_realtime: number
    missed: number
    miss_rate_pct: number
}

interface Metrics {
    pipelines: PipelineStatus[]
    throughput: ThroughputHour[]
    skip_reasons: SkipReason[]
    gap_analysis: GapAlert[]
    miss_rate: MissRate[]
    is_market_hours: boolean
    today_est: string
}

interface Props {
    metrics: Metrics
    lastUpdated: string
    selectedDate: string
}

const healthConfig = {
    active:          { label: 'Active',              color: 'text-green-700 bg-green-50 border-green-200 dark:text-green-400 dark:bg-green-900/20 dark:border-green-800',   dot: 'bg-green-500' },
    stale:           { label: 'Stale',                color: 'text-yellow-700 bg-yellow-50 border-yellow-200 dark:text-yellow-400 dark:bg-yellow-900/20 dark:border-yellow-800', dot: 'bg-yellow-500' },
    no_alerts_today: { label: 'No Realtime Alerts',   color: 'text-orange-700 bg-orange-50 border-orange-200 dark:text-orange-400 dark:bg-orange-900/20 dark:border-orange-800', dot: 'bg-orange-500' },
    waiting:         { label: 'Waiting',              color: 'text-blue-700 bg-blue-50 border-blue-200 dark:text-blue-400 dark:bg-blue-900/20 dark:border-blue-800',     dot: 'bg-blue-400' },
    disabled:        { label: 'Disabled',             color: 'text-gray-500 bg-gray-50 border-gray-200 dark:text-gray-500 dark:bg-gray-800 dark:border-gray-700',         dot: 'bg-gray-400' },
}

export default function PipelineObservability({ metrics: initialMetrics, lastUpdated: initialLastUpdated, selectedDate: initialSelectedDate }: Props) {
    const [metrics, setMetrics] = useState(initialMetrics)
    const [lastUpdated, setLastUpdated] = useState(initialLastUpdated)
    const [selectedDate, setSelectedDate] = useState(initialSelectedDate)
    const [autoRefresh, setAutoRefresh] = useState(true)
    const [isLoading, setIsLoading] = useState(false)

    const todayEst = new Date().toLocaleDateString('en-CA', { timeZone: 'America/New_York' })
    const isViewingToday = selectedDate === todayEst
    const skipReasonsLabel = isViewingToday ? 'Today' : selectedDate

    const fetchDate = async (date: string, showLoading = true) => {
        if (isLoading) return
        if (showLoading) setIsLoading(true)
        try {
            const res = await fetch(`/api/pipeline-observability?date=${date}`)
            const data = await res.json()
            setMetrics(data.metrics)
            setLastUpdated(data.lastUpdated)
        } catch {
            // silently ignore — keep showing stale data
        } finally {
            if (showLoading) setIsLoading(false)
        }
    }

    const refresh = () => fetchDate(selectedDate)

    const handleDateChange = (date: string) => {
        setSelectedDate(date)
        fetchDate(date)
    }

    useEffect(() => {
        if (!autoRefresh || !isViewingToday) return
        const interval = setInterval(() => fetchDate(selectedDate, false), 15000)
        return () => clearInterval(interval)
    }, [autoRefresh, isViewingToday, selectedDate, isLoading])

    const enabledPipelines = metrics.pipelines.filter((p) => p.enabled)
    const disabledPipelines = metrics.pipelines.filter((p) => !p.enabled)
    const totalRealtimeToday = metrics.pipelines.reduce((s, p) => s + p.realtime_count, 0)
    const totalBacktestToday = metrics.pipelines.reduce((s, p) => s + p.backtest_count, 0)
    const stalenessCount = enabledPipelines.filter((p) => p.health === 'stale' || p.health === 'no_alerts_today').length

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pipeline Observability" />

            <div className="p-6">
                <div className="mx-auto max-w-7xl space-y-6">

                    {/* Header */}
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900 dark:text-white">Pipeline Observability</h1>
                            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Last updated: {lastUpdated}
                                {metrics.is_market_hours && (
                                    <span className="ml-2 inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                        <span className="size-1.5 animate-pulse rounded-full bg-green-500" />
                                        Market Hours
                                    </span>
                                )}
                            </p>
                        </div>
                        <div className="flex items-center gap-3">
                            <div className="flex items-center gap-2">
                                <label className="text-sm text-gray-600 dark:text-gray-400">Date:</label>
                                <input
                                    type="date"
                                    value={selectedDate}
                                    max={todayEst}
                                    onChange={(e) => e.target.value && handleDateChange(e.target.value)}
                                    className="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                />
                                {!isViewingToday && (
                                    <button
                                        onClick={() => handleDateChange(todayEst)}
                                        className="rounded-lg bg-gray-100 px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                                    >
                                        Back to Today
                                    </button>
                                )}
                            </div>
                            <label className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                                <input
                                    type="checkbox"
                                    checked={autoRefresh && isViewingToday}
                                    disabled={!isViewingToday}
                                    onChange={(e) => setAutoRefresh(e.target.checked)}
                                    className="rounded"
                                />
                                Auto-refresh (15s)
                            </label>
                            <button
                                onClick={refresh}
                                disabled={isLoading}
                                className="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
                            >
                                {isLoading ? 'Refreshing…' : 'Refresh Now'}
                            </button>
                        </div>
                    </div>

                    {/* Summary cards */}
                    <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                        <div className="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                            <p className="text-xs font-medium text-gray-500 dark:text-gray-400">Enabled Pipelines</p>
                            <p className="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{enabledPipelines.length}</p>
                            <p className="text-xs text-gray-400">{disabledPipelines.length} disabled</p>
                        </div>
                        <div className="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                            <p className="text-xs font-medium text-gray-500 dark:text-gray-400">Real-time Alerts Today</p>
                            <p className="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{totalRealtimeToday}</p>
                            <p className="text-xs text-gray-400">{metrics.today_est}</p>
                        </div>
                        <div className="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                            <p className="text-xs font-medium text-gray-500 dark:text-gray-400">Backtest Alerts Today</p>
                            <p className="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{totalBacktestToday}</p>
                            <p className="text-xs text-gray-400">continuous loop</p>
                        </div>
                        <div className={`rounded-lg border p-4 ${stalenessCount > 0 ? 'border-yellow-200 bg-yellow-50 dark:border-yellow-800 dark:bg-yellow-900/20' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'}`}>
                            <p className="text-xs font-medium text-gray-500 dark:text-gray-400">Stale / No Alerts</p>
                            <p className={`mt-1 text-2xl font-bold ${stalenessCount > 0 ? 'text-yellow-700 dark:text-yellow-400' : 'text-gray-900 dark:text-white'}`}>
                                {stalenessCount}
                            </p>
                            <p className="text-xs text-gray-400">enabled pipelines</p>
                        </div>
                    </div>

                    {/* Pipeline status grid */}
                    <div className="rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                        <div className="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                            <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Pipeline Status</h2>
                        </div>
                        <div className="divide-y divide-gray-100 dark:divide-gray-700">
                            {metrics.pipelines.map((p) => {
                                const cfg = healthConfig[p.health]
                                return (
                                    <div key={p.pipeline_run} className={`flex items-center gap-4 px-6 py-3 ${!p.enabled ? 'opacity-50' : ''}`}>
                                        {/* dot */}
                                        <span className={`size-2.5 shrink-0 rounded-full ${cfg.dot}`} />

                                        {/* label (letter + version + name) */}
                                        <div className="min-w-[340px] shrink-0">
                                            <span className="font-medium text-gray-900 dark:text-white text-nowrap">{p.label}</span>
                                        </div>

                                        {/* health badge */}
                                        <span className={`rounded border px-2 py-0.5 text-xs font-medium ${p.health === 'no_alerts_today' && p.backtest_count > 0 ? 'text-green-700 bg-green-50 border-green-200 dark:text-green-400 dark:bg-green-900/20 dark:border-green-800' : cfg.color}`}>
                                            {p.health === 'no_alerts_today' && p.backtest_count > 0
                                                ? 'Backtest Alerts Only'
                                                : cfg.label}
                                        </span>

                                        {/* counts */}
                                        <div className="flex gap-4 text-sm">
                                            <span className="text-gray-600 dark:text-gray-400">
                                                <span className="font-semibold text-gray-900 dark:text-white">{p.realtime_count}</span> realtime
                                            </span>
                                            <span className="text-gray-600 dark:text-gray-400">
                                                <span className="font-semibold text-gray-900 dark:text-white">{p.backtest_count}</span> backtest
                                            </span>
                                            <span className="text-gray-600 dark:text-gray-400">
                                                <span className="font-semibold text-gray-900 dark:text-white">{(p.ml_threshold * 100).toFixed(0)}%</span> ML limit
                                            </span>
                                        </div>

                                        {/* last seen */}
                                        <div className="ml-auto text-right text-xs text-gray-400">
                                            {p.last_realtime_at ? (
                                                <>
                                                    Last: <span className="font-medium text-gray-600 dark:text-gray-300">{p.last_realtime_at}</span>
                                                    {p.minutes_since_last !== null && (
                                                        <span className="ml-1">({(() => { const totalSecs = Math.round(Number(p.minutes_since_last) * 60); const m = Math.floor(totalSecs / 60); const sec = totalSecs % 60; return m > 0 ? `${m}m ${sec}s ago` : `${sec}s ago`; })()})</span>
                                                    )}
                                                </>
                                            ) : (
                                                <span className="italic">no alerts yet today</span>
                                            )}
                                        </div>
                                    </div>
                                )
                            })}
                        </div>
                    </div>

                    {/* Throughput + Gap side-by-side */}
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">

                        {/* Hourly throughput */}
                        <div className="rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                            <div className="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                                <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Hourly Throughput (Today)</h2>
                            </div>
                            {metrics.throughput.length === 0 ? (
                                <p className="px-6 py-8 text-center text-sm text-gray-400">No alerts generated today yet.</p>
                            ) : (
                                <div className="divide-y divide-gray-100 dark:divide-gray-700">
                                    {metrics.throughput.map((h) => (
                                        <div key={h.hour} className="flex items-center gap-4 px-6 py-2 text-sm">
                                            <span className="w-14 font-mono text-gray-500 dark:text-gray-400">{h.label}</span>
                                            <div className="flex flex-1 gap-2">
                                                {h.realtime > 0 && (
                                                    <span className="rounded bg-blue-100 px-1.5 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                                        {h.realtime} realtime
                                                    </span>
                                                )}
                                                {h.backtest > 0 && (
                                                    <span className="rounded bg-purple-100 px-1.5 py-0.5 text-xs font-medium text-purple-700 dark:bg-purple-900/30 dark:text-purple-300">
                                                        {h.backtest} backtest
                                                    </span>
                                                )}
                                            </div>
                                            <div className="w-32">
                                                <div className="flex h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                                    {(h.realtime + h.backtest) > 0 && (
                                                        <div
                                                            className="bg-blue-500"
                                                            style={{ width: `${(h.realtime / (h.realtime + h.backtest)) * 100}%` }}
                                                        />
                                                    )}
                                                    {(h.realtime + h.backtest) > 0 && (
                                                        <div
                                                            className="bg-purple-400"
                                                            style={{ width: `${(h.backtest / (h.realtime + h.backtest)) * 100}%` }}
                                                        />
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>

                        {/* Gap analysis */}
                        <div className="rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                            <div className="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                                <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
                                    Gap Analysis — Today
                                </h2>
                                <p className="mt-0.5 text-xs text-gray-400">Backtest found signal; real-time cron missed it</p>
                            </div>
                            {metrics.gap_analysis.length === 0 ? (
                                <p className="px-6 py-8 text-center text-sm text-gray-400">No gaps detected today 🎉</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <div className="max-h-64 overflow-y-auto">
                                    <table className="w-full text-sm">
                                        <thead className="sticky top-0 bg-gray-50 dark:bg-gray-700/50">
                                            <tr>
                                                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Symbol</th>
                                                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Version</th>
                                                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Entry Time</th>
                                                <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Score</th>
                                                <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400">ML%</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                                            {[...metrics.gap_analysis]
                                                .sort((a, b) => (b.entry_ts_est ?? '').localeCompare(a.entry_ts_est ?? ''))
                                                .map((g, i) => (
                                                <tr key={i} className="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                                    <td className="px-4 py-2 font-mono font-semibold text-gray-900 dark:text-white">{g.symbol}</td>
                                                    <td className="px-4 py-2 text-gray-500 dark:text-gray-400">{g.version}</td>
                                                    <td className="px-4 py-2 font-mono text-xs text-gray-500 dark:text-gray-400">
                                                        {g.entry_ts_est?.slice(11, 19) ?? '—'}
                                                    </td>
                                                    <td className="px-4 py-2 text-right text-gray-700 dark:text-gray-300">
                                                        {g.entry_score?.toFixed(1) ?? '—'}
                                                    </td>
                                                    <td className="px-4 py-2 text-right text-gray-700 dark:text-gray-300">
                                                        {g.ml_win_prob != null ? `${(g.ml_win_prob * 100).toFixed(0)}%` : '—'}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Skip reasons + 7-day miss rate side-by-side */}
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">

                        {/* Skip reasons */}
                        <div className="rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                            <div className="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                                <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Skip Reasons — {skipReasonsLabel}</h2>
                            </div>
                            {metrics.skip_reasons.length === 0 ? (
                                <p className="px-6 py-8 text-center text-sm text-gray-400">No skipped alerts for {skipReasonsLabel.toLowerCase()}.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <div className="max-h-[600px] overflow-y-auto">
                                    <table className="w-full text-xs">
                                        <thead className="sticky top-0 bg-gray-50 dark:bg-gray-700/50">
                                            <tr>
                                                <th className="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Time</th>
                                                <th className="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Alert ID</th>
                                                <th className="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Symbol</th>
                                                <th className="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Version</th>
                                                <th className="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Reason</th>
                                                <th className="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Signal → Skip</th>
                                                <th className="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Ext%</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                                            {metrics.skip_reasons.map((s, i) => (
                                                <tr key={i} className="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                                    <td className="px-3 py-2 font-mono text-gray-500 dark:text-gray-400">
                                                        {s.skipped_at ? String(s.skipped_at).slice(11, 19) : '—'}
                                                    </td>
                                                    <td className="px-3 py-2 font-mono text-gray-500 dark:text-gray-400">#{s.id}</td>
                                                    <td className="px-3 py-2 font-mono font-semibold text-gray-900 dark:text-white">{s.symbol}</td>
                                                    <td className="px-3 py-2 text-gray-500 dark:text-gray-400">{s.version}</td>
                                                    <td className="px-3 py-2 text-gray-700 dark:text-gray-300">{s.reason}</td>
                                                    <td className="px-3 py-2 text-right text-gray-600 dark:text-gray-400">
                                                        {s.entry != null ? `$${s.entry.toFixed(2)}` : '—'}
                                                        {s.skip_price != null && <> → {'$'}{s.skip_price.toFixed(2)}</>}
                                                    </td>
                                                    <td className="px-3 py-2 text-right font-medium text-orange-600 dark:text-orange-400">
                                                        {s.extension_pct != null
                                                            ? `+${s.extension_pct.toFixed(2)}%`
                                                            : s.age_minutes != null
                                                              ? (() => {
                                                                    const totalSecs = Math.round(s.age_minutes * 60);
                                                                    const m = Math.floor(totalSecs / 60);
                                                                    const sec = totalSecs % 60;
                                                                    return m > 0 ? `${m}m ${sec}s` : `${sec}s`;
                                                                })()
                                                              : s.reason === 'ml_rescore_low_score' || s.reason === 'ml_rescore_failed'
                                                                ? `${s.ml_win_prob != null ? (s.ml_win_prob * 100).toFixed(1) + '%' : '?'} → ${s.ml_live_win_prob != null ? (s.ml_live_win_prob * 100).toFixed(1) + '%' : '?'}`
                                                                : s.reason === 'low_liquidity' && s.avg_dollar_volume_per_minute != null
                                                                ? `$${Math.round(s.avg_dollar_volume_per_minute).toLocaleString()}/min`
                                                                : '—'}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* 7-day miss rate */}
                        <div className="rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                            <div className="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                                <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Real-time Miss Rate — 7 Days</h2>
                                <p className="mt-0.5 text-xs text-gray-400">Per version: how often backtest fires but cron doesn't</p>
                            </div>
                            {metrics.miss_rate.length === 0 ? (
                                <p className="px-6 py-8 text-center text-sm text-gray-400">No backtest data in last 7 days.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead className="bg-gray-50 dark:bg-gray-700/50">
                                            <tr>
                                                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Version</th>
                                                <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400">BT Total</th>
                                                <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Matched RT</th>
                                                <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Missed</th>
                                                <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Miss %</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                                            {metrics.miss_rate.map((m, i) => (
                                                <tr key={i} className="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                                    <td className="px-4 py-2 font-medium text-gray-900 dark:text-white">{m.version}</td>
                                                    <td className="px-4 py-2 text-right text-gray-600 dark:text-gray-400">{m.backtest_total}</td>
                                                    <td className="px-4 py-2 text-right text-gray-600 dark:text-gray-400">{m.also_realtime}</td>
                                                    <td className="px-4 py-2 text-right text-gray-600 dark:text-gray-400">{m.missed}</td>
                                                    <td className="px-4 py-2 text-right">
                                                        <span className={`rounded px-1.5 py-0.5 text-xs font-semibold ${
                                                            m.miss_rate_pct >= 50
                                                                ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                                                                : m.miss_rate_pct >= 25
                                                                    ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400'
                                                                    : 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                                        }`}>
                                                            {m.miss_rate_pct}%
                                                        </span>
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
            </div>
        </AppLayout>
    )
}
