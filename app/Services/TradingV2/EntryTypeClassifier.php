<?php

namespace App\Services\TradingV2;

/**
 * Classifies entry types from pre-computed gate values.
 *
 * Each rule inspects a combination of gates from GateEvaluator and
 * assigns the most specific matching entry_type. Rules are evaluated
 * in order — the first match wins.
 *
 * Entry types are the same strings written to trade_alerts.entry_type
 * by TradeAlertWriterV1::upsertAlert().
 */
class EntryTypeClassifier
{
    /**
     * All known entry types with their classification rules.
     *
     * Each rule is a callable that receives the gates array and returns
     * the entry_type string if matched, or null if not.
     *
     * Rules are ordered from most specific to least specific.
     *
     * @var array<string, callable(array): ?string>
     */
    public const RULES = [
        'VWAP_RECLAIM_STRONG' => [self::class, 'isVwapReclaimStrong'],
        'VWAP_RECLAIM' => [self::class, 'isVwapReclaim'],
        'ORB_RETEST' => [self::class, 'isOrbRetest'],
        'EMA9_PULLBACK' => [self::class, 'isEma9Pullback'],
        'SIMPLE_MOMENTUM' => [self::class, 'isSimpleMomentum'],
    ];

    /**
     * Classify entry type from gate values.
     *
     * @param  array<string, float|int|null>  $gates
     */
    public function classify(array $gates): string
    {
        foreach (self::RULES as $type => $rule) {
            if ($result = $rule($gates)) {
                return $result;
            }
        }

        return 'UNCLASSIFIED';
    }

    // ── Rule implementations ──

    /**
     * VWAP_RECLAIM_STRONG: crossed above VWAP with strong volume and body confirmation.
     */
    private static function isVwapReclaimStrong(array $g): ?string
    {
        $aboveVwap = $g['above_vwap'] ?? ($g['above_vwap_entry_pct'] > 0 ? 1 : 0);
        $volRatio = $g['vol_ratio_1m'] ?? $g['rvol_ratio'] ?? 0;
        $bodyPct = $g['body_pct'] ?? 0;

        if ($aboveVwap && $volRatio >= 2.0 && $bodyPct >= 0.03) {
            return 'VWAP_RECLAIM_STRONG';
        }

        return null;
    }

    /**
     * VWAP_RECLAIM: crossed above VWAP with body confirmation.
     */
    private static function isVwapReclaim(array $g): ?string
    {
        $aboveVwap = $g['above_vwap'] ?? ($g['above_vwap_entry_pct'] > 0 ? 1 : 0);
        $bodyPct = $g['body_pct'] ?? 0;

        if ($aboveVwap && $bodyPct >= 0.02) {
            return 'VWAP_RECLAIM';
        }

        return null;
    }

    /**
     * ORB_RETEST: high volume with EMA alignment (Opening Range Breakout retest).
     */
    private static function isOrbRetest(array $g): ?string
    {
        $volRatio = $g['vol_ratio_1m'] ?? $g['rvol_ratio'] ?? 0;
        $emaAligned = $g['ema9_above_ema21_1m'] ?? $g['ema9_above_ema21'] ?? 0;

        if ($volRatio >= 2.5 && $emaAligned) {
            return 'ORB_RETEST';
        }

        return null;
    }

    /**
     * EMA9_PULLBACK: EMA alignment present, reasonable volume.
     */
    private static function isEma9Pullback(array $g): ?string
    {
        $emaAligned = $g['ema9_above_ema21_1m'] ?? $g['ema9_above_ema21'] ?? 0;
        $volRatio = $g['vol_ratio_1m'] ?? $g['rvol_ratio'] ?? 0;
        $closePos = $g['close_position'] ?? 0;

        if ($emaAligned && $volRatio >= 0.8 && $closePos >= 0.40) {
            return 'EMA9_PULLBACK';
        }

        return null;
    }

    /**
     * SIMPLE_MOMENTUM: basic momentum with positive move and volume.
     */
    private static function isSimpleMomentum(array $g): ?string
    {
        $move30m = $g['move_30m_pct'] ?? 0;
        $volRatio = $g['vol_ratio_1m'] ?? $g['rvol_ratio'] ?? 0;

        if ($move30m > 0 && $volRatio >= 1.2) {
            return 'SIMPLE_MOMENTUM';
        }

        return null;
    }
}
