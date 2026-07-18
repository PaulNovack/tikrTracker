<?php

namespace App\Services\Trading;

use App\Services\TradingSettingService;
use Illuminate\Support\Facades\DB;

/**
 * One-Minute Entry Finder V800.0 - Higher-Low Breakout Entry
 *
 * Goal: Find 1-minute entries after a 5-minute setup that show:
 * - Price > prev 1m high (breakout)
 * - Prev 1m low > prev2 1m low (higher low)
 * - Price above VWAP
 * - EMA9 > EMA21
 * - Price >= 60% of bar range
 *
 * Takes first qualifying entry within 15 minutes of setup.
 */
class OneMinuteEntryFinderV800_0
{
    use HasPriceTables;

    private string $version = 'v800.0';

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
        int $afterMinutes = 15,
        int $volLookback = 20,
        int $pivotLookback = 15,
        string $fillModel = 'next_open' // next_open|close
    ): array {
        $minScore = (float) config('trading.v800.entry_score_min', 90);
        $maxScore = (float) config('trading.v800.entry_score_max', 100);
        if ($maxScore <= 0) {
            $maxScore = 100.0;
        }
        if ($minScore > $maxScore) {
            [$minScore, $maxScore] = [$maxScore, $minScore];
        }

        // Entry window: setup time + 15 minutes
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

        LAG(o.high, 1) OVER (
            PARTITION BY o.symbol, o.asset_type, o.trading_date_est
            ORDER BY o.ts_est
        ) AS prev_1m_high,

        LAG(o.low, 1) OVER (
            PARTITION BY o.symbol, o.asset_type, o.trading_date_est
            ORDER BY o.ts_est
        ) AS prev_1m_low,

        LAG(o.low, 2) OVER (
            PARTITION BY o.symbol, o.asset_type, o.trading_date_est
            ORDER BY o.ts_est
        ) AS prev2_1m_low

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
                entry_price >= entry_vwap
                AND entry_ema9 > entry_ema21
                AND entry_price >= entry_low + ((entry_high - entry_low) * 0.60)
                AND prev_1m_high IS NOT NULL
                AND entry_price > prev_1m_high
                AND prev2_1m_low IS NOT NULL
                AND prev_1m_low > prev2_1m_low
            THEN 1
            ELSE 0
        END AS is_higher_low_break
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
    WHERE is_higher_low_break = 1
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
                'error' => 'No qualifying higher-low breakout entry found within window.',
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
                'score' => $entryScore,
            ];
        }

        // Calculate stop loss
        $atr = (float) ($entry->atr ?? 0);
        $atrPct = (float) ($entry->atr_pct ?? 0);

        $atrMultiplier = TradingSettingService::getStopLossAtrMultiplier();
        $minPct = TradingSettingService::getStopLossAtrMinPct();
        $maxPct = TradingSettingService::getStopLossAtrMaxPct();

        $calculatedPct = ($atr > 0 && $entryPx > 0)
            ? (($atr * $atrMultiplier) / $entryPx) * 100.0
            : $minPct;
        $trailPct = max($minPct, min($maxPct, $calculatedPct));

        $stopLoss = $entryPx * (1 - ($trailPct / 100.0));
        $riskPerShare = $entryPx - $stopLoss;
        $riskPct = ($riskPerShare / $entryPx) * 100.0;

        // Calculate targets
        $r = $riskPerShare;
        $targets = [
            '1R' => round($entryPx + 1.0 * $r, 6),
            '2R' => round($entryPx + 2.0 * $r, 6),
            '3R' => round($entryPx + 3.0 * $r, 6),
            '3pct' => round($entryPx * 1.03, 6),
            '4pct' => round($entryPx * 1.04, 6),
            '5pct' => round($entryPx * 1.05, 6),
        ];

        return [
            'ok' => true,
            'symbol' => $symbol,
            'asset_type' => $assetType,
            'signal_ts_est' => $signalTsEst,
            'entry_window' => [$signalTsEst, $entryWindowEnd],
            'best_entry' => [
                'type' => 'HIGHER_LOW_BREAKOUT_1M',
                'trigger_ts_est' => (string) $entry->entry_ts_est,
                'entry_ts_est' => $entryTs,
                'entry' => round($entryPx, 6),
                'stop' => round($stopLoss, 6),
                'score' => round($entryScore, 2),
                'pattern_score' => 3.5,
                'atr' => round($atr, 6),
                'atr_pct' => $atrPct,
                'risk_per_share' => round($riskPerShare, 6),
                'risk_pct' => round($riskPct, 3),
                'suggested_trailing_stop' => round($stopLoss, 6),
                'suggested_trailing_stop_pct' => round($trailPct, 2),
                'targets' => $targets,
                'vwap' => round((float) $entry->entry_vwap, 6),
                'ema9' => round((float) $entry->entry_ema9, 6),
                'ema21' => round((float) $entry->entry_ema21, 6),
                'prev_1m_high' => round((float) $entry->prev_1m_high, 6),
                'prev_1m_low' => round((float) $entry->prev_1m_low, 6),
                'prev2_1m_low' => round((float) $entry->prev2_1m_low, 6),
                'vol_ratio' => $volRatio > 0 ? round($volRatio, 2) : null,
                'breakout_volume_ratio' => $volRatio > 0 ? round($volRatio, 2) : null,
                'notes' => '1m higher-low breakout: price > prev high, prev low > prev2 low.',
            ],
            'candidates' => [
                [
                    'type' => 'HIGHER_LOW_BREAKOUT_1M',
                    'trigger_ts_est' => (string) $entry->entry_ts_est,
                    'entry_ts_est' => $entryTs,
                    'entry' => round($entryPx, 6),
                    'stop' => round($stopLoss, 6),
                    'score' => round($entryScore, 2),
                    'pattern_score' => 3.5,
                    'vol_ratio' => $volRatio > 0 ? round($volRatio, 2) : null,
                    'breakout_volume_ratio' => $volRatio > 0 ? round($volRatio, 2) : null,
                ],
            ],
            'meta' => [
                'entry_score_min' => $minScore,
                'entry_score_max' => $maxScore,
                'version' => $this->version,
                'fill_model' => $fillModel,
                'goal' => 'Higher-low breakout 1m entry',
            ],
        ];
    }

    private function computeEntryScore(object $entry): float
    {
        $score = 40.0; // Lower base score for more selectivity

        // A) Above VWAP (0..20) - increased weight
        $entryPrice = (float) $entry->entry_price;
        $vwap = (float) ($entry->entry_vwap ?? 0);
        if ($vwap > 0) {
            $vwapDist = (($entryPrice - $vwap) / $vwap) * 100.0;
            $score += min(20.0, max(0.0, $vwapDist * 40.0)); // 0.5% => 20
        }

        // B) EMA strength (0..20) - increased weight
        $ema9 = (float) ($entry->entry_ema9 ?? 0);
        $ema21 = (float) ($entry->entry_ema21 ?? 0);
        if ($ema21 > 0) {
            $emaSpread = (($ema9 - $ema21) / $ema21) * 100.0;
            $score += min(20.0, max(0.0, $emaSpread * 200.0)); // 0.1% => 20
        }

        // C) Position in bar (0..10) - reward buying at top
        $high = (float) $entry->entry_high;
        $low = (float) $entry->entry_low;
        $range = max(1e-9, ($high - $low));
        $posInBar = ($entryPrice - $low) / $range;
        $score += min(10.0, max(0.0, ($posInBar - 0.50) * 20.0)); // 50% => 0, 100% => 10

        // D) Higher low confirmation (0..10)
        $prevLow = (float) ($entry->prev_1m_low ?? 0);
        $prev2Low = (float) ($entry->prev2_1m_low ?? 0);
        if ($prev2Low > 0 && $prevLow > 0) {
            $lowImprovement = (($prevLow - $prev2Low) / $prev2Low) * 100.0;
            $score += min(10.0, max(0.0, $lowImprovement * 100.0)); // 0.1% => 10
        }

        return round(min(100.0, $score), 2);
    }

    private function clamp(float $val, float $min = 0.0, float $max = 1.0): float
    {
        return max($min, min($max, $val));
    }
}
