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
     * The final fallback is EMA9_PULLBACK — matching V1 behavior where
     * that is the implicit default.
     *
     * @var array<string, callable(array): ?string>
     */
    public const RULES = [
        'ORB_RETEST' => [self::class, 'isOrbRetest'],
        'VWAP_RECLAIM_STRONG' => [self::class, 'isVwapReclaimStrong'],
        'VWAP_RECLAIM' => [self::class, 'isVwapReclaim'],
        'SIMPLE_MOMENTUM' => [self::class, 'isSimpleMomentum'],
    ];

    /**
     * Classify entry type from gate values.
     *
     * Matches V1 entry-finder behavior:
     * - ORB_RETEST takes highest priority (checks opening range breakout/retest)
     * - VWAP_RECLAIM_STRONG / VWAP_RECLAIM require VWAP cross + body confirmation
     * - SIMPLE_MOMENTUM catches basic momentum with volume
     * - Default fallback is EMA9_PULLBACK (never returns UNCLASSIFIED)
     *
     * @param  array<string, float|int|null>  $gates
     */
    public function classify(array $gates): string
    {
        // Most specific patterns checked first, matching V1 priority:
        // ORB_RETEST > VWAP_RECLAIM > EMA9_PULLBACK (default)
        foreach (self::RULES as $type => $rule) {
            if ($result = $rule($gates)) {
                return $result;
            }
        }

        // V1 default: always EMA9_PULLBACK
        // Never return UNCLASSIFIED — even weak signals get a label
        return 'EMA9_PULLBACK';
    }

    // ── Rule implementations ──

    /**
     * ORB_RETEST: Opening Range Breakout retest — close recovered above OR high
     * after a dip, with strong volume confirmation.
     *
     * Matches V1 OneMinuteEntryFinderV25_2 logic:
     *   orHigh > 0 && low <= orHigh && close > orHigh && volRatio >= min_vol_ratio_1m
     *
     * Without direct orHigh access, approximate using close_position (close in
     * upper half after a dip) + high volume + EMA alignment.
     */
    private static function isOrbRetest(array $g): ?string
    {
        $volRatio = $g['vol_ratio_1m'] ?? $g['rvol_ratio'] ?? 0;
        $closePos = $g['close_position'] ?? 0;
        $bodyPct = $g['body_pct'] ?? 0;

        // Strong close in upper 50% + high vol + meaningful body = breakout retest
        if ($volRatio >= 2.5 && $closePos >= 0.60 && $bodyPct >= 0.30) {
            return 'ORB_RETEST';
        }

        return null;
    }

    /**
     * VWAP_RECLAIM_STRONG: crossed above VWAP with strong volume and body.
     *
     * Matches V1: strong VWAP cross with volume surge.
     */
    private static function isVwapReclaimStrong(array $g): ?string
    {
        $aboveVwapPct = $g['above_vwap_entry_pct'] ?? 0;
        $volRatio = $g['vol_ratio_1m'] ?? $g['rvol_ratio'] ?? 0;
        $bodyPct = $g['body_pct'] ?? 0;

        if ($aboveVwapPct > 0 && $volRatio >= 2.0 && $bodyPct >= 0.30) {
            return 'VWAP_RECLAIM_STRONG';
        }

        return null;
    }

    /**
     * VWAP_RECLAIM: crossed above VWAP with body confirmation.
     *
     * Matches V1 OneMinuteEntryFinderV25_2 logic:
     *   prev_close <= prev_vwap && last_close > last_vwap && bodyPct > min_body_pct
     *
     * Without prev-bar data, use above_vwap_entry_pct > 0 as a VWAP-cross proxy,
     * requiring the min_body_pct (0.40) that V25.2 uses.
     */
    private static function isVwapReclaim(array $g): ?string
    {
        $aboveVwapPct = $g['above_vwap_entry_pct'] ?? 0;
        $bodyPct = $g['body_pct'] ?? 0;

        // V25.2 requires body_pct > 0.40 for VWAP_RECLAIM
        if ($aboveVwapPct > 0 && $bodyPct >= 0.40) {
            return 'VWAP_RECLAIM';
        }

        return null;
    }

    /**
     * SIMPLE_MOMENTUM: basic momentum with positive move and volume.
     * Matches V1's generic momentum detection.
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

    // ══════════════════════════════════════════════════════════
    // ENTRY SCORE COMPONENTS (matches V1 computeEntryScoreComponents)
    // ══════════════════════════════════════════════════════════

    /**
     * Compute all six entry sub-score components from gate values.
     *
     * Matches the V1 OneMinuteEntryFinderV27_0::computeEntryScoreComponents()
     * which feeds into the trade_alerts ML feature columns:
     *
     *   entry_spread_strength  — EMA9/EMA21 spread quality  (0-1)
     *   entry_vwap_dist_score  — VWAP distance sweet spot   (0-1)
     *   entry_atr_score        — ATR% sweet spot            (0-1)
     *   entry_vol_score        — Volume ratio quality        (0-1)
     *   entry_candle_score     — Candle body quality         (0-1)
     *   entry_time_bonus       — Early-entry bonus           (0-10)
     *
     * @param  array<string, float|int|null>  $g  Gate values (1m eval output)
     * @return array{entry_spread_strength:float,entry_vwap_dist_score:float,entry_atr_score:float,
     *               entry_vol_score:float,entry_candle_score:float,entry_time_bonus:float}
     */
    public static function computeScoreComponents(array $g): array
    {
        $price = (float) ($g['price'] ?? 0);
        if ($price <= 0) {
            return [
                'entry_spread_strength' => 0.0,
                'entry_vwap_dist_score' => 0.0,
                'entry_atr_score' => 0.0,
                'entry_vol_score' => 0.0,
                'entry_candle_score' => 0.0,
                'entry_time_bonus' => 0.0,
            ];
        }

        // EMA9/EMA21 spread (trend quality) — from 1m incremental EMA
        $emaF = (float) ($g['ema9'] ?? 0);
        $emaS = (float) ($g['ema21'] ?? 0);
        $spreadRaw = ($emaS > 0) ? ($emaF - $emaS) / $emaS : 0;
        $spreadStrength = max(0, min(1, ($spreadRaw - 0.0005) / (0.0030 - 0.0005)));

        // VWAP distance (sweet spot 0.05%–0.30% above)
        $vwapDist = (float) ($g['above_vwap_entry_pct'] ?? 0);
        $vwapDistRaw = abs($vwapDist) / 100.0;
        $vwapDistScore = max(0, 1 - ($vwapDistRaw / 0.0030));

        // ATR% at entry (sweet spot 0.08%–0.50%)
        $atrPct = (float) ($g['atr_pct'] ?? 0);
        $atrScore = max(0, min(1, ($atrPct - 0.08) / (0.20 - 0.08)))
            * (1 - max(0, min(1, ($atrPct - 0.50) / (1.50 - 0.50))));

        // Volume ratio (sweet spot 0.8–2.5x)
        $volRatio = (float) ($g['vol_ratio_1m'] ?? $g['rvol_ratio'] ?? 0);
        $volScore = max(0, min(1, ($volRatio - 0.8) / (2.5 - 0.8)));

        // Candle body quality (sweet spot 0.05%–0.30% body/open)
        $bodyPctRaw = (float) ($g['body_pct'] ?? 0);
        $candleScore = max(0, min(1, ($bodyPctRaw - 0.05) / (0.30 - 0.05)));

        // Upper wick penalty
        $upperWickFrac = (float) ($g['upper_wick_fraction'] ?? 0);
        if ($upperWickFrac > 0.50) {
            $candleScore *= 0.6;
        }

        // Time bonus (prefer early entries)
        $timeBonus = 0;
        $ts = (string) ($g['ts_est'] ?? '');
        if ($ts !== '') {
            $hour = (int) substr($ts, 11, 2);
            if ($hour >= 9 && $hour < 10) {
                $timeBonus = 10;
            } elseif ($hour >= 10 && $hour < 11) {
                $timeBonus = 5;
            }
        }

        return [
            'entry_spread_strength' => round($spreadStrength, 6),
            'entry_vwap_dist_score' => round($vwapDistScore, 6),
            'entry_atr_score' => round($atrScore, 6),
            'entry_vol_score' => round($volScore, 6),
            'entry_candle_score' => round($candleScore, 6),
            'entry_time_bonus' => $timeBonus,
        ];
    }
}
