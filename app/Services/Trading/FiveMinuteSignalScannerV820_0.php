<?php

namespace App\Services\Trading;

/**
 * Version 820.0 - EMA Momentum Pullback Scanner with Pattern Filters (LONG)
 *
 * Enhanced v810 with price pattern analysis to filter out pump exhaustion setups.
 *
 * Strategy: Identify strong uptrends and trade pullbacks to EMA9 support
 *
 * 5-Minute Setup Requirements:
 * - Strong trend structure: EMA9 > EMA21 with widening spread
 * - Price above VWAP (institutional support)
 * - Positive momentum: RSI 50-70 (not overbought, not weak)
 * - Recent strength: Up 0.5-5% from day open
 * - Controlled volatility: ATR 0.20-3.00%
 * - Price near EMA9 (0.10-0.50% above = pullback zone)
 *
 * PHASE 1 PATTERN FILTERS:
 * - Reject pump exhaustion: Price spiked >2% in 100-50m window then pulled back
 * - Reject inverted V: Pump-dump pattern (spike then continuous fade)
 *
 * This filters out exhausted setups, keeping only those with building momentum.
 */
class FiveMinuteSignalScannerV820_0
{
    use HasPriceTables;

    private string $version = 'v820.0';

    private string $name = 'Pattern-Based Fade Detection';

    public function __construct(
        private PricePatternAnalyzer $patternAnalyzer
    ) {}

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minMovePct = -0.5,
        float $volMult = 1.0,
        int $limit = 50
    ): array {
        $minScore = (float) config('trading.v820.entry_score_min', 50);
        $maxScore = (float) config('trading.v820.entry_score_max', 100);
        $topN = (int) config('trading.v820.entry_score_limit', 25);
        $minPrice = (float) config('trading.v820.min_price', 5.0);
        $maxPrice = (float) config('trading.v820.max_price', 300.0);
        $timeWindowStart = (string) config('trading.v820.time_window_start', '09:50:00');
        $timeWindowEnd = (string) config('trading.v820.time_window_end', '14:30:00');

        // Pattern filter settings
        $pumpThreshold = (float) config('trading.v820.pattern_filters.pump_exhaustion_threshold', 1.020);
        $rejectInvertedV = (bool) config('trading.v820.pattern_filters.reject_inverted_v', true);

        \Log::info("[V820 Scanner] minScore={$minScore}, maxScore={$maxScore}, topN={$topN}, asOf={$asOfTsEst}");

        if ($topN <= 0) {
            $topN = 25;
        }
        if ($maxScore <= 0) {
            $maxScore = 100.0;
        }
        if ($minScore > $maxScore) {
            [$minScore, $maxScore] = [$maxScore, $minScore];
        }

        $limit = max(1, (int) $limit);
        $tradeDate = substr($asOfTsEst, 0, 10);

        $sql = '
WITH f AS (
    SELECT
        symbol,
        asset_type,
        ts_est,
        trading_date_est,
        trading_time_est,
        price,
        open,
        high,
        low,
        volume,
        vwap,
        vwap_dist_pct,
        above_vwap,
        ema9,
        ema21,
        ema9_ema21_spread,
        ema9_above_ema21,
        atr,
        atr_pct,
        rsi_14,

        FIRST_VALUE(open) OVER (
            PARTITION BY symbol, asset_type, trading_date_est
            ORDER BY ts_est
            ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING
        ) AS day_open,

        AVG(volume) OVER (
            PARTITION BY symbol, asset_type, trading_date_est
            ORDER BY ts_est
            ROWS BETWEEN 20 PRECEDING AND 1 PRECEDING
        ) AS avg_volume_20

    FROM five_minute_prices
    WHERE asset_type = ?
      AND trading_date_est = ?
      AND ts_est <= ?
      AND trading_time_est BETWEEN ? AND ?
      AND price BETWEEN ? AND ?
)
SELECT
    symbol,
    asset_type,
    trading_date_est,
    ts_est AS signal_ts_est,
    trading_time_est,
    price AS setup_price,
    open,
    high,
    low,
    volume,
    vwap,
    vwap_dist_pct,
    ema9,
    ema21,
    ema9_ema21_spread,
    atr,
    atr_pct,
    rsi_14,
    day_open,
    ROUND((price - day_open) / NULLIF(day_open, 0) * 100, 3) AS day_change_pct,
    avg_volume_20,
    CASE WHEN avg_volume_20 > 0 THEN ROUND(volume / avg_volume_20, 2) ELSE 0 END AS vol_ratio
FROM f
WHERE
    ema9 > ema21
    AND ema9_above_ema21 = 1
    AND ema9_ema21_spread > 0
    AND above_vwap = 1
    AND rsi_14 BETWEEN 50 AND 70
    AND day_open > 0
    AND ((price - day_open) / day_open * 100) BETWEEN 0.2 AND 8.0
    AND atr_pct BETWEEN 0.20 AND 3.00
    AND ema9 > 0
    AND ((price - ema9) / ema9 * 100) BETWEEN 0.10 AND 0.50
    AND volume > 0
    AND avg_volume_20 > 0
ORDER BY 
    (ema9_ema21_spread / NULLIF(price, 0) * 100) DESC,
    ((price - ema9) / NULLIF(ema9, 0) * 100) ASC,
    vol_ratio DESC
LIMIT ?
';

        $params = [$assetType, $tradeDate, $asOfTsEst, $timeWindowStart, $timeWindowEnd, $minPrice, $maxPrice, $limit * 2];
        $rows = $this->dbSelect($sql, $params);

        if (empty($rows)) {
            \Log::info('[V820 Scanner] No rows returned from SQL query');

            return [];
        }

        \Log::info('[V820 Scanner] Processing '.count($rows).' raw rows');

        $cands = [];
        $filteredByPattern = 0;

        foreach ($rows as $r) {
            $symbol = (string) $r->symbol;
            $setupPrice = (float) ($r->setup_price ?? 0);
            $ema9 = (float) ($r->ema9 ?? 0);
            $ema21 = (float) ($r->ema21 ?? 0);
            $vwapDistPct = (float) ($r->vwap_dist_pct ?? 0);
            $rsi = (float) ($r->rsi_14 ?? 50);
            $atrPct = (float) ($r->atr_pct ?? 0);
            $dayChangePct = (float) ($r->day_change_pct ?? 0);
            $volRatio = (float) ($r->vol_ratio ?? 0);

            $ema9DistPct = ($ema9 > 0) ? (($setupPrice - $ema9) / $ema9) * 100 : 999;
            $ema9_21_spread = ($ema21 > 0) ? (($ema9 - $ema21) / $ema21) * 100 : 0;

            // PATTERN FILTERS - Apply before scoring (performance optimization)
            $snapshots = $this->patternAnalyzer->getHistoricalSnapshots(
                $symbol,
                (string) $r->asset_type,
                (string) $r->signal_ts_est,
                $setupPrice,
                [100, 90, 80, 70, 60, 50, 40, 30, 20, 10]
            );

            // Filter 1: Reject pump exhaustion (spike in 100-50m window)
            if ($this->patternAnalyzer->hasPumpExhaustion($snapshots, $pumpThreshold)) {
                $filteredByPattern++;

                continue;
            }

            // Filter 2: Reject inverted V pattern (pump-dump)
            if ($rejectInvertedV && $this->patternAnalyzer->hasInvertedV($snapshots)) {
                $filteredByPattern++;

                continue;
            }

            // Passed pattern filters, proceed with scoring
            $score = 0.0;

            // A) EMA9 proximity (0..30)
            if ($ema9DistPct >= 0.10 && $ema9DistPct <= 0.50) {
                $score += 30.0 - (($ema9DistPct - 0.10) * 75.0);
            }

            // B) EMA trend strength (0..25)
            if ($ema9_21_spread >= 0.3) {
                $score += min(25.0, ($ema9_21_spread / 2.0) * 25.0);
            }

            // C) Day momentum (0..20)
            if ($dayChangePct >= 0.2 && $dayChangePct <= 8.0) {
                if ($dayChangePct <= 2.0) {
                    $score += ($dayChangePct / 2.0) * 20.0;
                } else {
                    $score += 20.0 - (($dayChangePct - 2.0) / 6.0) * 10.0;
                }
            }

            // D) Volume confirmation (0..15)
            if ($volRatio >= 1.0) {
                if ($volRatio <= 3.0) {
                    $score += min(15.0, (($volRatio - 1.0) / 2.0) * 15.0);
                } else {
                    $score += 15.0;
                }
            }

            // E) RSI sweet spot (0..10)
            if ($rsi >= 50 && $rsi <= 70) {
                if ($rsi >= 55 && $rsi <= 65) {
                    $score += 10.0;
                } else {
                    $score += 10.0 - abs($rsi - 60) * 0.5;
                }
            }

            $score = round(min(100.0, max(0.0, $score)), 2);

            if ($score < $minScore || $score > $maxScore) {
                continue;
            }

            $cands[] = [
                'symbol' => $symbol,
                'asset_type' => $r->asset_type,
                'signal_type' => 'ema_pullback',
                'signal_ts_est' => $r->signal_ts_est,
                'trading_date_est' => $r->trading_date_est,
                'trading_time_est' => $r->trading_time_est,
                'setup_price' => $setupPrice,
                'score' => $score,
                'meta' => [
                    'vwap' => (float) $r->vwap,
                    'vwap_dist_pct' => $vwapDistPct,
                    'ema9' => $ema9,
                    'ema21' => $ema21,
                    'ema9_dist_pct' => round($ema9DistPct, 3),
                    'ema9_21_spread_pct' => round($ema9_21_spread, 3),
                    'rsi_14' => $rsi,
                    'atr' => (float) $r->atr,
                    'atr_pct' => $atrPct,
                    'day_change_pct' => $dayChangePct,
                    'vol_ratio' => $volRatio,
                ],
            ];
        }

        \Log::info('[V820 Scanner] Scored '.count($cands)." candidates (filtered {$filteredByPattern} by pattern), returning top {$topN}");

        usort($cands, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($cands, 0, $topN);
    }
}
