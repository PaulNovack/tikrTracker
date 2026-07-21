<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * One-Minute Entry Finder V810.0 - EMA Bounce Entry
 *
 * Strategy: After 5-min setup identifies pullback to EMA9, find 1-min entry when:
 * - Price bounces off EMA9 support (low near EMA9, close above)
 * - Volume confirmation (above average)
 * - Price strength (close in upper 60% of bar)
 * - Still above VWAP
 * - EMA9 > EMA21 (trend intact)
 *
 * Takes first qualifying bounce within 20 minutes of setup.
 */
class OneMinuteEntryFinderV810_0
{
    use HasPriceTables;

    private string $version = 'v810.0';

    public function getVersion(): string
    {
        return $this->version;
    }

    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        int $beforeMinutes = 15,
        int $afterMinutes = 20,
        int $volLookback = 20,
        int $pivotLookback = 15,
        string $fillModel = 'next_open' // next_open|close
    ): array {
        $minScore = (float) config('trading.v810.entry_score_min', 85);
        $maxScore = (float) config('trading.v810.entry_score_max', 100);
        if ($maxScore <= 0) {
            $maxScore = 100.0;
        }
        if ($minScore > $maxScore) {
            [$minScore, $maxScore] = [$maxScore, $minScore];
        }

        // Entry window: setup time + 20 minutes
        $setupTime = strtotime($signalTsEst);
        $entryWindowEnd = date('Y-m-d H:i:s', $setupTime + ($afterMinutes * 60));

        // Don't look beyond asOf time
        if ($entryWindowEnd > $asOfTsEst) {
            $entryWindowEnd = $asOfTsEst;
        }

        $tradeDate = substr($signalTsEst, 0, 10);

        // Get 1-minute bars from setup time onward
        $sql = '
WITH one_minute_candidates AS (
    SELECT
        o.symbol,
        o.asset_type,
        o.trading_date_est,
        o.ts_est AS entry_ts_est,
        o.price AS entry_price,
        o.open AS entry_open,
        o.high AS entry_high,
        o.low AS entry_low,
        o.volume AS entry_volume,
        o.vwap AS entry_vwap,
        o.ema9 AS entry_ema9,
        o.ema21 AS entry_ema21,
        o.above_vwap,
        o.ema9_above_ema21,
        o.atr,
        o.atr_pct,

        AVG(o.volume) OVER (
            PARTITION BY o.symbol, o.asset_type, o.trading_date_est
            ORDER BY o.ts_est
            ROWS BETWEEN 20 PRECEDING AND 1 PRECEDING
        ) AS avg_volume_20,

        -- Previous bar for momentum check
        LAG(o.price, 1) OVER (
            PARTITION BY o.symbol, o.asset_type, o.trading_date_est
            ORDER BY o.ts_est
        ) AS prev_close

    FROM one_minute_prices o
    WHERE o.symbol = ?
      AND o.asset_type = ?
      AND o.trading_date_est = ?
      AND o.ts_est > ?
      AND o.ts_est <= ?
),

qualified_entries AS (
    SELECT
        *,
        -- Bar range and position
        CASE 
            WHEN entry_high > entry_low 
            THEN (entry_price - entry_low) / (entry_high - entry_low)
            ELSE 0.5
        END AS bar_position,
        
        -- Volume ratio
        CASE 
            WHEN avg_volume_20 > 0 
            THEN entry_volume / avg_volume_20
            ELSE 0
        END AS vol_ratio,
        
        -- Distance from EMA9
        CASE 
            WHEN entry_ema9 > 0 
            THEN ((entry_price - entry_ema9) / entry_ema9) * 100
            ELSE 999
        END AS ema9_dist_pct,
        
        -- Low touched EMA9? (bounce signal)
        CASE 
            WHEN entry_ema9 > 0 
            THEN ((entry_low - entry_ema9) / entry_ema9) * 100
            ELSE 999
        END AS low_to_ema9_pct,
        
        CASE
            -- EMA Bounce Entry requirements:
            WHEN
                -- Trend intact
                entry_ema9 > entry_ema21
                AND ema9_above_ema21 = 1
                
                -- Above VWAP
                AND entry_price >= entry_vwap
                AND above_vwap = 1
                
                -- Low touched or came close to EMA9 (within 0.30%)
                AND entry_ema9 > 0
                AND ((entry_low - entry_ema9) / entry_ema9) * 100 BETWEEN -0.30 AND 0.30
                
                -- Close bounced above EMA9 (0.10-0.60% above)
                AND ((entry_price - entry_ema9) / entry_ema9) * 100 BETWEEN 0.10 AND 0.60
                
                -- Strong bar position (close in upper 60% of range)
                AND entry_high > entry_low
                AND (entry_price - entry_low) / (entry_high - entry_low) >= 0.60
                
                -- Volume confirmation (above average)
                AND avg_volume_20 > 0
                AND entry_volume >= avg_volume_20
                
            THEN 1
            ELSE 0
        END AS is_ema_bounce
    FROM one_minute_candidates
)

SELECT *
FROM (
    SELECT
        *,
        ROW_NUMBER() OVER (
            PARTITION BY symbol, asset_type, trading_date_est
            ORDER BY entry_ts_est
        ) AS rn
    FROM qualified_entries
    WHERE is_ema_bounce = 1
) x
WHERE rn = 1
ORDER BY entry_ts_est
LIMIT 1
';

        $params = [
            $symbol,
            $assetType,
            $tradeDate,
            $signalTsEst,
            $entryWindowEnd,
        ];

        $rows = $this->dbSelect($sql, $params);

        if (empty($rows)) {
            return [
                'ok' => false,
                'error' => 'No qualifying EMA bounce entry found within window.',
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_ts_est' => $signalTsEst,
                'entry_window' => [$signalTsEst, $entryWindowEnd],
            ];
        }

        $entry = $rows[0];

        // Calculate volume ratio
        $entryVolume = (float) ($entry->entry_volume ?? 0);
        $avgVolume = (float) ($entry->avg_volume_20 ?? 1);
        $volRatio = ($entryVolume > 0 && $avgVolume > 0) ? ($entryVolume / $avgVolume) : 0.0;

        // Compute fill based on model
        $entryTs = (string) $entry->entry_ts_est;
        $entryPx = (float) $entry->entry_price;

        if ($fillModel === 'next_open') {
            // Get next bar's open
            $nextBar = DB::selectOne('
                SELECT open, ts_est
                FROM one_minute_prices
                WHERE symbol = ?
                  AND asset_type = ?
                  AND trading_date_est = ?
                  AND ts_est > ?
                ORDER BY ts_est ASC
                LIMIT 1
            ', [$symbol, $assetType, $tradeDate, $entryTs]);

            if ($nextBar && (float) $nextBar->open > 0) {
                $entryPx = (float) $nextBar->open;
                $entryTs = (string) $nextBar->ts_est;
            }
        }

        // Calculate entry score
        $entryScore = $this->computeEntryScore($entry);

        if ($entryScore < $minScore || $entryScore > $maxScore) {
            return [
                'ok' => false,
                'error' => sprintf('Entry score %.2f outside range [%.2f, %.2f]', $entryScore, $minScore, $maxScore),
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_ts_est' => $signalTsEst,
                'entry_ts_est' => $entryTs,
                'entry_score' => $entryScore,
                'score_range' => [$minScore, $maxScore],
            ];
        }

        // Calculate stop loss (below EMA9 or ATR-based, whichever is tighter)
        $ema9 = (float) ($entry->entry_ema9 ?? 0);
        $atr = (float) ($entry->atr ?? 0);

        // Option 1: Stop below EMA9 with 0.15% buffer
        $ema9Stop = $ema9 > 0 ? ($ema9 * 0.9985) : 0;

        // Option 2: ATR-based stop (1.5x ATR below entry)
        $atrStop = $atr > 0 ? ($entryPx - ($atr * 1.5)) : 0;

        // Use tighter of the two, but enforce 0.70% minimum and 1.20% maximum risk
        $minStop = $entryPx * 0.988;  // 1.20% max risk
        $maxStop = $entryPx * 0.993;  // 0.70% min risk

        $stop = 0;
        if ($ema9Stop > 0 && $atrStop > 0) {
            $stop = max($ema9Stop, $atrStop); // Tighter stop
        } elseif ($ema9Stop > 0) {
            $stop = $ema9Stop;
        } elseif ($atrStop > 0) {
            $stop = $atrStop;
        } else {
            $stop = $entryPx * 0.99; // Fallback 1%
        }

        // Apply bounds
        if ($stop < $minStop) {
            $stop = $minStop;
        }
        if ($stop > $maxStop) {
            $stop = $maxStop;
        }

        $risk = $entryPx - $stop;
        $riskPct = ($entryPx > 0) ? ($risk / $entryPx) * 100.0 : 0.0;

        // R-multiple targets
        $targets = [
            '1R' => round($entryPx + (1.0 * $risk), 6),
            '2R' => round($entryPx + (2.0 * $risk), 6),
            '3R' => round($entryPx + (3.0 * $risk), 6),
        ];

        // Suggested trailing stop (3x ATR with 1.00% minimum)
        $atrPct = (float) ($entry->atr_pct ?? 0);
        $trailingStopPct = max(1.00, $atrPct * 3.0);
        $trailingStopPrice = $entryPx * ($trailingStopPct / 100.0);

        return [
            'ok' => true,
            'symbol' => $symbol,
            'asset_type' => $assetType,
            'signal_ts_est' => $signalTsEst,
            'best_entry' => [
                'type' => 'EMA_BOUNCE',
                'entry_ts_est' => $entryTs,
                'entry' => round($entryPx, 4),
                'stop' => round($stop, 4),
                'risk_pct' => round($riskPct, 2),
                'risk_per_share' => round($risk, 6),
                'score' => round($entryScore, 2),
                'vol_ratio' => round($volRatio, 2),
                'atr' => round($atr, 6),
                'atr_pct' => round($atrPct, 3),
                'suggested_trailing_stop' => round($trailingStopPrice, 6),
                'suggested_trailing_stop_pct' => round($trailingStopPct, 2),
                'targets' => $targets,
                'ema9' => round($ema9, 4),
                'ema9_dist_pct' => round((float) ($entry->ema9_dist_pct ?? 0), 3),
                'bar_position' => round((float) ($entry->bar_position ?? 0), 3),
                'low_to_ema9_pct' => round((float) ($entry->low_to_ema9_pct ?? 0), 3),
            ],
        ];
    }

    private function computeEntryScore(object $entry): float
    {
        $barPosition = (float) ($entry->bar_position ?? 0.5);
        $volRatio = (float) ($entry->vol_ratio ?? 0);
        $ema9DistPct = (float) ($entry->ema9_dist_pct ?? 0);
        $lowToEma9Pct = abs((float) ($entry->low_to_ema9_pct ?? 0));

        $score = 0.0;

        // A) Bar strength (0..30) - close near top of range
        // 0.60 = 0 pts, 1.00 = 30 pts
        if ($barPosition >= 0.60) {
            $score += (($barPosition - 0.60) / 0.40) * 30.0;
        }

        // B) EMA9 bounce quality (0..30)
        // Low touched EMA9 closely (within 0.10%) = 15 pts
        if ($lowToEma9Pct <= 0.10) {
            $score += 15.0;
        } elseif ($lowToEma9Pct <= 0.30) {
            $score += 15.0 - (($lowToEma9Pct - 0.10) * 75.0);
        }
        // Close above EMA9 (0.10-0.30% = best) = 15 pts
        if ($ema9DistPct >= 0.10 && $ema9DistPct <= 0.30) {
            $score += 15.0;
        } elseif ($ema9DistPct > 0.30 && $ema9DistPct <= 0.60) {
            $score += 15.0 - (($ema9DistPct - 0.30) * 50.0);
        }

        // C) Volume confirmation (0..25)
        // 1.5x-2.5x = full points
        if ($volRatio >= 1.0) {
            if ($volRatio <= 2.5) {
                $score += min(25.0, (($volRatio - 1.0) / 1.5) * 25.0);
            } else {
                $score += 25.0; // Cap at 25
            }
        }

        // D) Base momentum quality (0..15)
        // Combination of position and volume = entry conviction
        $conviction = ($barPosition * 0.6 + min(1.0, $volRatio / 2.0) * 0.4);
        $score += $conviction * 15.0;

        return min(100.0, max(0.0, $score));
    }
}
