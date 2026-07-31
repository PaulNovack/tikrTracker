import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Checkbox } from '@/components/ui/checkbox';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { type BreadcrumbItem } from '@/types';
import { Plus, Trash2, Copy } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'System', href: '/generic-ta-gate-versions' },
    { title: 'TA Gate Versions', href: '/generic-ta-gate-versions' },
];

// ALL known gate names from COMPREHENSIVE_GATE_LIST.md
const ALL_5M_GATES = [
    'notional', 'price', 'atr_pct', 'rvol_ratio', 'move_30m_pct',
    'move_rvol_composite', 'rs_ratio', 'signal_age_seconds', 'above_vwap', 'ema9_above_ema21',
    'ema_spread_pct', 'ema9_slope_positive', 'green_close', 'green_bar_pct',
    'multi_day_green_count', 'yesterday_move_pct', 'yesterday_vol_mult',
    'pullback_depth_pct', 'directional_changes_max', 'distance_from_high_atr',
    'higher_low_count', 'closes_near_high_count', 'vwap_violation_count',
    'range_contraction', 'market_weakness', 'benchmark_below_vwap',
    'rsi', 'entry_score_min', 'entry_score_max',
    'opening_range_width_pct', 'opening_range_bar_count', 'breakout_detected',
    'net_progress_pct', 'move_from_open_pct', 'dist_to_hod_pct',
    'distance_from_ema9_atr', 'vwap_distance_min', 'sum_vol_5m',
    'consolidation_range_pct', 'bb_position', 'reversal_pattern',
    'pump_exhaustion_threshold', 'reject_inverted_v',
];

const ALL_1M_GATES = [
    'notional_1m', 'vol_ratio_1m', 'body_pct', 'above_vwap_entry_pct',
    'room_to_hod_pct', 'room_atr_mult', 'min_bars',
    'close_position', 'upper_wick_fraction', 'extreme_drop',
    'time_blocked', 'max_entry_age_minutes',
    'ema9_above_ema21_1m', 'ema9_above_ema21_5m',
    'max_above_ema9_bps', 'pullback_max_under_ema21_bps',
    'pullback_depth_impulse_pct', 'higher_low_pct_min',
    'pullback_volume_ratio_max', 'pullback_bear_body_atr_max',
    'stop_buffer_bps', 'stop_atr_mult', 'stop_pct', 'reward_risk_min',
    'choppiness_directional_max', 'max_hour',
    'impulse_move_pct', 'impulse_atr', 'impulse_green',
    'impulse_volume_ratio', 'confirm_volume_ratio',
    'confirm_body_pct', 'confirm_close_position',
];

interface GateRow {
    id?: number;
    gate_name: string;
    enabled: boolean;
    threshold_min: string;
    threshold_max: string;
}

// Gate descriptions for tooltips
const GATE_HELP: Record<string, string> = {
    notional: 'Dollar volume of last 5m bar: price × volume. Filters illiquid stocks.',
    price: 'Last closing price. Floor filters penny stocks; ceiling excludes ultra-high-price names.',
    atr_pct: 'Average True Range as % of price (14-period). Higher = more room to run.',
    rvol_ratio: 'Relative volume: last bar volume ÷ 20-bar average. High = unusual activity.',
    move_30m_pct: '% price change over last 6 bars (~30 min). Positive = upward momentum.',
    rs_ratio: 'Relative strength vs benchmark (SPY/QQQM). > 1 = outperforming the market.',
    signal_age_seconds: 'Max age of the signal bar. Older signals discarded as stale.',
    above_vwap: 'Price above VWAP. True = institutional support confirmed.',
    ema9_above_ema21: 'EMA(9) > EMA(21). Short-term trend above medium-term.',
    ema_spread_pct: '% spread between EMA9 and EMA21. Wider = stronger trend.',
    ema9_slope_positive: 'EMA9 is rising. Confirms uptrend direction.',
    green_close: 'Last bar closed above its open. Green bar = buying pressure.',
    green_bar_pct: '% of recent bars that are green. Higher = sustained buying.',
    multi_day_green_count: 'Consecutive up days. Higher = multi-day momentum.',
    yesterday_move_pct: 'Yesterday intraday % gain. Confirms prior-day strength.',
    yesterday_vol_mult: 'Yesterday volume vs 5-day average. Confirms prior-day participation.',
    pullback_depth_pct: 'Max % decline from recent high. Lower = tighter pullback.',
    directional_changes_max: 'Count of price direction flips. High = choppy, low = trending.',
    distance_from_high_atr: 'Distance from session high in ATR multiples. Closer = stronger.',
    higher_low_count: 'Consecutive bars with higher lows. Indicates uptrend structure.',
    closes_near_high_count: 'Bars closing near their highs. Indicates demand absorption.',
    vwap_violation_count: 'Bars closing below VWAP. Zero = perfect VWAP support.',
    range_contraction: 'Bar ranges are tightening. May signal impending breakout.',
    market_weakness: 'SPY 15m move is negative. Scarcity leaders thrive on market weakness.',
    benchmark_below_vwap: 'SPY below its VWAP. Confirms broad market weakness.',
    rsi: 'Relative Strength Index (14). > 70 overbought; < 30 oversold.',
    entry_score_min: 'Minimum EntryScore composite metric from one_minute_prices.',
    entry_score_max: 'Maximum EntryScore. Caps overly-hot names.',
    opening_range_width_pct: 'Opening range (first 15 min) width %. Too narrow = no opportunity.',
    consolidation_range_pct: 'Max range tightness of recent bars. Tight = potential breakout.',
    dist_to_hod_pct: 'Distance to session high as %. Closer = stronger relative position.',
    distance_from_ema9_atr: 'Distance from EMA9 in ATR multiples. Near = pullback entry zone.',
    vwap_distance_min: 'Min distance above VWAP. Stock has cleared VWAP decisively.',
    sum_vol_5m: 'Total 5m volume over lookback window. Ensures meaningful liquidity.',
    net_progress_pct: 'Net % progress from first to last bar. Positive = upward drift.',
    move_from_open_pct: 'Today move from open %. Filters names that have not moved yet.',
    notional_1m: 'Dollar volume of entry 1m bar: price × volume. Ensures tradable size.',
    vol_ratio_1m: 'Relative volume of entry bar vs 20-bar average. High vol = conviction.',
    body_pct: 'Candle body as % of total range. Larger body = stronger conviction.',
    above_vwap_entry_pct: 'Max % above VWAP at entry. Too far = chasing, risk of pullback.',
    room_to_hod_pct: 'Room from entry to session high %. Must have room for target.',
    room_atr_mult: 'ATR multiplier for room calc. Higher = more conservative estimate.',
    min_bars: 'Min 1m bars from market open. Ensures enough data for indicators.',
    close_position: 'Where close sits within bar range (0-1). Near 1 = close at high.',
    upper_wick_fraction: 'Upper wick as fraction of range. Small = no selling at high.',
    extreme_drop: 'Reject if bar-to-bar drop > 50% (data error or reverse split).',
    time_blocked: 'Block entries during lunch chop window (11:30-13:30 ET).',
};

interface Version {
    id: number;
    pipeline_letter: string;
    version_string: string;
    signal_type: string;
    scanner_score_formula: string | null;
    enabled: boolean;
    gates_5m: GateRow[];
    gates_1m: GateRow[];
}

interface Props { versions: Version[]; allGateNames: string[]; }

export default function GenericTaGateVersions({ versions }: Props) {
    const [expanded, setExpanded] = useState<number | null>(null);
    const [showNewDialog, setShowNewDialog] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="TA Gate Versions" />
            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <HeadingSmall title="TA Gate Versions" description="Manage alert versions and gate thresholds. DB-driven — no code deploys needed." />
                    <Button onClick={() => setShowNewDialog(true)}><Plus className="mr-1 h-4 w-4" /> New Version</Button>
                </div>
                <Separator />

                {showNewDialog && (
                    <CreateDialog onClose={() => setShowNewDialog(false)} onSuccess={() => { setShowNewDialog(false); router.reload(); }} />
                )}

                <div className="space-y-4">
                    {versions.map((v) => (
                        <VersionCard key={v.id} version={v} expanded={expanded} setExpanded={setExpanded} />
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}

function GateHelp({ name, enabled }: { name: string; enabled: boolean }) {
    const desc = GATE_HELP[name];
    if (!desc) return <span className={`w-56 font-mono text-xs ${enabled ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400'}`}>{name}</span>;

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span className={`w-56 cursor-help font-mono text-xs underline decoration-dotted underline-offset-2 ${enabled ? 'text-blue-600 dark:text-blue-400' : 'text-gray-400'}`}>{name}</span>
            </TooltipTrigger>
            <TooltipContent side="right" className="max-w-xs text-xs">{desc}</TooltipContent>
        </Tooltip>
    );
}

/** One expandable version card */
function VersionCard({ version: v, expanded, setExpanded }: { version: Version; expanded: number | null; setExpanded: (n: number | null) => void }) {
    const isOpen = expanded === v.id;
    const [editingFormula, setEditingFormula] = useState(false);
    const [formulaValue, setFormulaValue] = useState(v.scanner_score_formula ?? '');
    const [confirmAction, setConfirmAction] = useState<'delete' | 'clone' | null>(null);

    const saveFormula = () => {
        router.patch(`/generic-ta-gate-versions/${v.id}`, {
            pipeline_letter: v.pipeline_letter,
            version_string: v.version_string,
            signal_type: v.signal_type,
            scanner_score_formula: formulaValue || null,
            enabled: v.enabled,
        }, { preserveScroll: true, preserveState: true, onSuccess: () => setEditingFormula(false) });
    };

    return (
        <div className={`rounded-lg border bg-white dark:bg-gray-900 ${v.enabled ? 'border-gray-200 dark:border-gray-700' : 'border-red-300 dark:border-red-800 opacity-60'}`}>
            <div className="flex cursor-pointer items-center justify-between px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800/50" onClick={() => setExpanded(isOpen ? null : v.id)}>
                <div className="flex items-center gap-3">
                    <span className="font-mono text-lg font-bold text-blue-600 dark:text-blue-400">{v.pipeline_letter}</span>
                    <span className="text-sm text-gray-500">{v.version_string}</span>
                    <span className="rounded bg-gray-100 px-2 py-0.5 text-xs dark:bg-gray-800">{v.signal_type}</span>
                    {editingFormula ? (
                        <span className="flex items-center gap-1" onClick={(e) => e.stopPropagation()}>
                            <Label className="text-xs text-gray-500">Score:</Label>
                            <Input value={formulaValue} onChange={(e) => setFormulaValue(e.target.value)}
                                onBlur={saveFormula} onKeyDown={(e) => { if (e.key === 'Enter') saveFormula(); if (e.key === 'Escape') { setEditingFormula(false); setFormulaValue(v.scanner_score_formula ?? ''); } }}
                                placeholder="e.g. move30m*1.2+rvol*1.0+atrPct*0.8" className="h-7 w-96 font-mono text-xs" autoFocus />
                        </span>
                    ) : (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <span onClick={(e) => { e.stopPropagation(); setEditingFormula(true); setFormulaValue(v.scanner_score_formula ?? ''); }}
                                    className="cursor-pointer text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                                    <span className="font-medium text-gray-500">Score:</span>{' '}
                                    <span className="rounded bg-gray-100 px-2 py-0.5 font-mono text-green-700 dark:bg-gray-800 dark:text-green-400">
                                        {v.scanner_score_formula || '(none — click to set)'}
                                    </span>
                                </span>
                            </TooltipTrigger>
                            <TooltipContent side="bottom" className="max-w-xs text-xs">
                                5m scanner scoring formula. Variables: move30m, rvolRatio, atrPct, greenDays, rvol, notional. Click to edit.
                            </TooltipContent>
                        </Tooltip>
                    )}
                </div>
                <div className="flex items-center gap-2">
                    <button onClick={(e) => { e.stopPropagation(); router.post(`/generic-ta-gate-versions/${v.id}/toggle`); }}
                        className={`rounded px-3 py-1 text-xs font-medium ${v.enabled ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300' : 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300'}`}>
                        {v.enabled ? 'Enabled' : 'Disabled'}
                    </button>
                    <span className="text-gray-400">{isOpen ? '▼' : '▶'}</span>
                </div>
            </div>

            {isOpen && (
                <div className="border-t px-4 py-4 dark:border-gray-700">
                    <div className="flex gap-8">
                        <div className="flex-1">
                            <h3 className="mb-2 text-sm font-semibold text-yellow-600 dark:text-yellow-400">5-Minute Scanner Gates</h3>
                            <GateEditor versionId={v.id} timeframe="5m" gates={v.gates_5m} allGates={ALL_5M_GATES} />
                        </div>
                        <div className="flex-1">
                            <h3 className="mb-2 text-sm font-semibold text-cyan-600 dark:text-cyan-400">1-Minute Entry Gates</h3>
                            <GateEditor versionId={v.id} timeframe="1m" gates={v.gates_1m} allGates={ALL_1M_GATES} />
                        </div>
                    </div>
                    <Separator className="my-4" />
                    <div className="flex gap-2">
                        <Button variant="destructive" size="sm" onClick={() => setConfirmAction('delete')}>Delete Version</Button>
                        <Button variant="outline" size="sm" onClick={() => setConfirmAction('clone')}><Copy className="mr-1 h-4 w-4" /> Clone Version</Button>
                    </div>

                    {/* Confirm Dialog */}
                    <Dialog open={confirmAction !== null} onOpenChange={(open) => { if (!open) setConfirmAction(null); }}>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>{confirmAction === 'delete' ? 'Delete Version' : 'Clone Version'}</DialogTitle>
                                <DialogDescription>
                                    {confirmAction === 'delete'
                                        ? `Are you sure you want to delete version "${v.pipeline_letter}"? This action cannot be undone.`
                                        : `Clone version "${v.pipeline_letter}" with the next available pipeline letter? All gates will be copied.`}
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter className="gap-2">
                                <Button variant="outline" onClick={() => setConfirmAction(null)}>Cancel</Button>
                                <Button
                                    variant={confirmAction === 'delete' ? 'destructive' : 'default'}
                                    onClick={() => {
                                        if (confirmAction === 'delete') {
                                            router.delete(`/generic-ta-gate-versions/${v.id}`);
                                        } else {
                                            router.post(`/generic-ta-gate-versions/${v.id}/clone`);
                                        }
                                        setConfirmAction(null);
                                    }}
                                >
                                    {confirmAction === 'delete' ? 'Delete' : 'Clone'}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            )}
        </div>
    );
}

function round3(val: string): number | null {
    const n = parseFloat(val);
    return isNaN(n) ? null : Math.round(n * 1000) / 1000;
}

/** ALL gates for a timeframe — checkbox to enable, min/max inputs */
function GateEditor({ versionId, timeframe, gates, allGates }: { versionId: number; timeframe: string; gates: GateRow[]; allGates: string[] }) {
    const gateMap = new Map(gates.map((g) => [g.gate_name, g]));

    // Local "saved" values — updated from server props but also on fetch success.
    // This allows the UI to reflect persisted values immediately without a page reload.
    const [savedValues, setSavedValues] = useState(() => {
        const init: Record<string, { min: string; max: string; enabled: boolean }> = {};
        for (const g of gates) {
            init[g.gate_name] = {
                min: g.threshold_min !== undefined && g.threshold_min !== null ? String(g.threshold_min) : '',
                max: g.threshold_max !== undefined && g.threshold_max !== null ? String(g.threshold_max) : '',
                enabled: g.enabled,
            };
        }
        return init;
    });
    const [saving, setSaving] = useState<string | null>(null);

    // Local editing state so number inputs can accept typing and spinner arrows.
    // Keyed by gate name, synced back to server on blur.
    const [localValues, setLocalValues] = useState<Record<string, { min: string; max: string }>>({});

    const csrfToken = (): string =>
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

    const saveGate = async (name: string, enabled: boolean, min: string, max: string) => {
        // Optimistically update the UI immediately so checkboxes and inputs
        // reflect the new value without waiting for the server round-trip.
        setSavedValues((prev) => ({
            ...prev,
            [name]: { min, max, enabled },
        }));
        setLocalValues((prev) => {
            const next = { ...prev };
            delete next[name];
            return next;
        });
        setSaving(name);
        try {
            const res = await fetch(`/generic-ta-gate-versions/${versionId}/gates`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    timeframe,
                    gate_name: name,
                    enabled,
                    threshold_min: min === '' ? null : round3(min),
                    threshold_max: max === '' ? null : round3(max),
                }),
            });
            setSaving(null);
            // On failure, revert to the previous server value by reloading.
            if (!res.ok) {
                router.reload({ preserveScroll: true });
            }
        } catch {
            setSaving(null);
        }
    };

    return (
        <div className="space-y-1">
            {allGates.map((name) => {
                const existing = gateMap.get(name);
                // Use savedValues (updated on every successful save) rather than
                // stale server props, so the UI reflects the last persisted state.
                const sv = savedValues[name];
                const enabled = sv?.enabled ?? existing?.enabled ?? false;
                const serverMin = existing?.threshold_min !== undefined && existing?.threshold_min !== null
                    ? String(existing.threshold_min) : '';
                const serverMax = existing?.threshold_max !== undefined && existing?.threshold_max !== null
                    ? String(existing.threshold_max) : '';

                // Use local values if being edited, otherwise use saved values
                const local = localValues[name];
                const min = local !== undefined ? local.min : (sv ? sv.min : serverMin);
                const max = local !== undefined ? local.max : (sv ? sv.max : serverMax);

                return (
                    <div key={name} className="flex items-center gap-2 rounded py-0.5 hover:bg-gray-50 dark:hover:bg-gray-800/50">
                        <Checkbox
                            checked={enabled}
                            onCheckedChange={(c) => saveGate(name, !!c, min, max)}
                            className="h-4 w-4"
                        />
                        <GateHelp name={name} enabled={enabled} />
                        <Input type="number" step="any" value={min}
                            onChange={(e) => {
                                setLocalValues((prev) => ({
                                    ...prev,
                                    [name]: { min: e.target.value, max: max },
                                }));
                            }}
                            onBlur={(e) => { if (e.target.value !== serverMin) saveGate(name, enabled, e.target.value, max); }}
                            placeholder="min" disabled={!enabled} className="h-7 w-32 text-xs" />
                        <span className="text-xs text-gray-400">to</span>
                        <Input type="number" step="any" value={max}
                            onChange={(e) => {
                                setLocalValues((prev) => ({
                                    ...prev,
                                    [name]: { min: min, max: e.target.value },
                                }));
                            }}
                            onBlur={(e) => { if (e.target.value !== serverMax) saveGate(name, enabled, min, e.target.value); }}
                            placeholder="max" disabled={!enabled} className="h-7 w-32 text-xs" />
                        {saving === name && <span className="text-xs text-blue-500">saving...</span>}
                    </div>
                );
            })}
        </div>
    );
}

/** Dialog to create a new version */
function CreateDialog({ onClose, onSuccess }: { onClose: () => void; onSuccess: () => void }) {
    const [letter, setLetter] = useState('');
    const [ver, setVer] = useState('');
    const [signalType, setSignalType] = useState('');
    const [formula, setFormula] = useState('');

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        router.post('/generic-ta-gate-versions', {
            pipeline_letter: letter, version_string: ver, signal_type: signalType,
            scanner_score_formula: formula || null, enabled: true,
        }, { onSuccess: () => onSuccess() });
    };

    return (
        <Dialog open onOpenChange={onClose}>
            <DialogContent className="max-w-md">
                <DialogHeader><DialogTitle>New Alert Version</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div><Label>Pipeline Letter</Label><Input value={letter} onChange={(e) => setLetter(e.target.value)} placeholder="H" required /></div>
                    <div><Label>Version String</Label><Input value={ver} onChange={(e) => setVer(e.target.value)} placeholder="v25.2" required /></div>
                    <div><Label>Signal Type</Label><Input value={signalType} onChange={(e) => setSignalType(e.target.value)} placeholder="MOMO_5M_V25" required /></div>
                    <div><Label>Score Formula</Label><Input value={formula} onChange={(e) => setFormula(e.target.value)} placeholder="move30m*1.2+min(6,rvol)*1.0+atrPct*0.8" className="font-mono" /></div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
                        <Button type="submit">Create</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
