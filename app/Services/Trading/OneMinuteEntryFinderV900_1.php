<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * One-Minute Entry Finder V900.1 - Momentum Continuation Entry (AGGRESSIVE)
 *
 * Goal: Find 1-minute entries after a 5-minute momentum setup that show:
 * - Continued price strength (allows chase entries on explosive moves)
 * - Volume confirmation (1.2x+ recent average)
 * - EMA9 > EMA21 (momentum confirmation)
 * - Position in candle >= 30% (not bottom wicking)
 *
 * Takes first qualifying entry within 10 minutes of setup (momentum trades fast).
 * Designed for explosive momentum stocks that already moved 20-40%+.
 */
class OneMinuteEntryFinderV900_1
{
    use HasPriceTables;

    private string $version = 'v900.1';

    public function getVersion(): string
    {
        return $this->version;
    }

    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        int $beforeMinutes = 10,
        int $afterMinutes = 10,
        int $volLookback = 10,
        int $pivotLookback = 5,
        string $fillModel = 'next_open' // next_open|close
    ): array {
        $minScore = (float) config('trading.v900.entry_score_min', 40);  // Aggressive: allow chase entries
        $maxScore = (float) config('trading.v900.entry_score_max', 100);
        $minRsi = (float) config('trading.v900.entry_min_rsi', 70);

        if ($maxScore <= 0) {
            $maxScore = 100.0;
        }
        if ($minScore > $maxScore) {
            [$minScore, $maxScore] = [$maxScore, $minScore];
        }

        // Entry window: setup time + 10 minutes (momentum is fast)
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
            ROWS BETWEEN 10 PRECEDING AND 1 PRECEDING
        ) AS avg_volume_10,

        LAG(o.high, 1) OVER (
            PARTITION BY o.symbol, o.asset_type, o.trading_date_est
            ORDER BY o.ts_est
        ) AS prev_1m_high,

        LAG(o.price, 1) OVER (
            PARTITION BY o.symbol, o.asset_type, o.trading_date_est
            ORDER BY o.ts_est
        ) AS prev_1m_close

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
        CASE
            WHEN
                entry_ema9 > entry_ema21
                AND entry_price >= entry_low + ((entry_high - entry_low) * 0.30)
                AND (entry_volume / NULLIF(avg_volume_10, 0)) >= 1.2
            THEN 1
            ELSE 0
        END AS is_momentum_continuation
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
    WHERE is_momentum_continuation = 1
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
                'error' => 'No qualifying momentum continuation entry found within window.',
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_ts_est' => $signalTsEst,
                'entry_window' => [$signalTsEst, $entryWindowEnd],
            ];
        }

        $entry = $rows[0];

        // Calculate volume ratio
        $entryVolume = (float) ($entry->entry_volume ?? 0);
        $avgVolume = (float) ($entry->avg_volume_10 ?? 1);
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
        $entryScore = $this->computeEntryScore($entry, $volRatio);

        if ($entryScore < $minScore || $entryScore > $maxScore) {
            return [
                'ok' => false,
                'error' => sprintf('Entry score %.2f outside range [%.2f, %.2f]', $entryScore, $minScore, $maxScore),
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_ts_est' => $signalTsEst,
                'entry_ts_est' => $entryTs,
                'entry_score' => $entryScore,
            ];
        }

        // Calculate targets
        $atrPct = (float) ($entry->atr_pct ?? 0);
        $atr = (float) ($entry->atr ?? 0);

        $targets = $this->computeTargets($entryPx, $atr, $atrPct);

        // Calculate suggested trailing stop (2.5x ATR)
        $suggestedTrailingStop = $atr > 0 ? round($atr * 2.5, 6) : null;
        $suggestedTrailingStopPct = ($suggestedTrailingStop && $entryPx > 0)
            ? round(($suggestedTrailingStop / $entryPx) * 100, 6)
            : null;

        return [
            'ok' => true,
            'best_entry' => [
                'type' => 'MOMENTUM_CONTINUATION',
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'entry_ts_est' => $entryTs,
                'entry_price' => round($entryPx, 4),
                'entry_score' => round($entryScore, 2),
                'score' => round($entryScore, 2), // For TradeAlertWriter
                'entry_volume' => (int) $entryVolume,
                'volume_ratio' => round($volRatio, 2),
                'vol_ratio' => round($volRatio, 2), // For TradeAlertWriter
                'stop' => $targets['stop_loss'], // For TradeAlertWriter
                'stop_loss' => $targets['stop_loss'],
                'risk_pct' => round((($entryPx - $targets['stop_loss']) / $entryPx) * 100, 4),
                'targets' => $targets,
                'atr' => round($atr, 4),
                'atr_pct' => round($atrPct, 4),
                'suggested_trailing_stop' => $suggestedTrailingStop,
                'suggested_trailing_stop_pct' => $suggestedTrailingStopPct,
                'vwap' => round((float) $entry->entry_vwap, 4),
                'ema9' => round((float) $entry->entry_ema9, 4),
                'ema21' => round((float) $entry->entry_ema21, 4),
                'prev_1m_high' => isset($entry->prev_1m_high) ? round((float) $entry->prev_1m_high, 4) : null,
                'notes' => '1m momentum continuation breakout',
            ],
            'meta' => [
                'version' => $this->version,
                'fill_model' => $fillModel,
                'entry_window' => [$signalTsEst, $entryWindowEnd],
            ],
        ];
    }

    /**
     * Compute entry score based on momentum factors
     */
    private function computeEntryScore($entry, float $volRatio): float
    {
        $score = 70; // Base score

        // Volume confirmation (0-15 points)
        if ($volRatio >= 3.0) {
            $score += 15;
        } elseif ($volRatio >= 2.0) {
            $score += 10;
        } elseif ($volRatio >= 1.5) {
            $score += 5;
        }

        // VWAP position (5 points)
        if ($entry->above_vwap == 1) {
            $score += 5;
        }

        // EMA trend (5 points)
        if ($entry->ema9_above_ema21 == 1) {
            $score += 5;
        }

        // Position in candle (0-5 points)
        $high = (float) $entry->entry_high;
        $low = (float) $entry->entry_low;
        $close = (float) $entry->entry_price;
        if ($high > $low) {
            $candlePosition = ($close - $low) / ($high - $low);
            if ($candlePosition >= 0.80) {
                $score += 5;
            } elseif ($candlePosition >= 0.60) {
                $score += 3;
            }
        }

        return min(100, $score);
    }

    /**
     * Compute stop loss and profit targets
     */
    private function computeTargets(float $entryPx, float $atr, float $atrPct): array
    {
        // Momentum trades: tighter stop (0.5 ATR), aggressive targets
        $stopLoss = max(0.01, round($entryPx - ($atr * 0.5), 2));
        $riskPerShare = $entryPx - $stopLoss;

        return [
            'stop_loss' => $stopLoss,
            'risk_per_share' => round($riskPerShare, 2),
            '1R' => round($entryPx + ($riskPerShare * 1), 2),
            '2R' => round($entryPx + ($riskPerShare * 2), 2),
            '3R' => round($entryPx + ($riskPerShare * 3), 2),
            '2pct' => round($entryPx * 1.02, 2),
            '3pct' => round($entryPx * 1.03, 2),
            '4pct' => round($entryPx * 1.04, 2),
            '5pct' => round($entryPx * 1.05, 2),
            '7pct' => round($entryPx * 1.07, 2),
            '10pct' => round($entryPx * 1.10, 2),
        ];
    }
}
