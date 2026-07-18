<?php

namespace App\Services\Trading;

/**
 * Version 800.0 - Higher-Low Breakout Setup Scanner (LONG)
 *
 * Goal: Identify 5-minute setups that show:
 * - Strong trend (EMA9 > EMA21, above VWAP)
 * - Proximity to recent high (0.20-2.00% away from 30m high)
 * - Healthy pullback with floor protection (15m pullback floor)
 * - Controlled RSI (40-65) and ATR (0.30-8.00%)
 * - Position in candle (>= 50% of bar range)
 *
 * This picker identifies setups for the subsequent 1-minute entry logic.
 *
 * ENV / config('trading.*'):
 * - v800.entry_score_min (default 80)
 * - v800.entry_score_max (default 100)
 * - v800.entry_score_limit (default 15)
 * - v800.min_price (default 3.0)
 * - v800.max_price (default 500.0)
 * - v800.time_window_start (default '09:40:00')
 * - v800.time_window_end (default '10:10:00')
 */
class FiveMinuteSignalScannerV800_0
{
    use HasPriceTables;

    private string $version = 'v800.0';

    private string $name = 'Mean Reversion / Fade';

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Scan for Higher-Low Breakout Setup candidates (LONG)
     */
    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minMovePct = -0.5,
        float $volMult = 1.0,
        int $limit = 30
    ): array {
        $minScore = (float) config('trading.v800.entry_score_min', 80);
        $maxScore = (float) config('trading.v800.entry_score_max', 100);
        $topN = (int) config('trading.v800.entry_score_limit', 15);

        $minPrice = (float) config('trading.v800.min_price', 3.0);
        $maxPrice = (float) config('trading.v800.max_price', 500.0);

        $timeWindowStart = (string) config('trading.v800.time_window_start', '09:40:00');
        $timeWindowEnd = (string) config('trading.v800.time_window_end', '10:10:00');

        if ($topN <= 0) {
            $topN = 15;
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

        LAG(price, 1) OVER (PARTITION BY symbol, asset_type, trading_date_est ORDER BY ts_est) AS prev_price,
        LAG(price, 2) OVER (PARTITION BY symbol, asset_type, trading_date_est ORDER BY ts_est) AS prev2_price,
        LAG(high, 1)  OVER (PARTITION BY symbol, asset_type, trading_date_est ORDER BY ts_est) AS prev_high,
        LAG(low, 1)   OVER (PARTITION BY symbol, asset_type, trading_date_est ORDER BY ts_est) AS prev_low,
        LAG(low, 2)   OVER (PARTITION BY symbol, asset_type, trading_date_est ORDER BY ts_est) AS prev2_low,
        LAG(volume, 1) OVER (PARTITION BY symbol, asset_type, trading_date_est ORDER BY ts_est) AS prev_volume,
        LAG(vwap_dist_pct, 1) OVER (PARTITION BY symbol, asset_type, trading_date_est ORDER BY ts_est) AS prev_vwap_dist_pct,

        MAX(high) OVER (
            PARTITION BY symbol, asset_type, trading_date_est
            ORDER BY ts_est
            ROWS BETWEEN 6 PRECEDING AND 1 PRECEDING
        ) AS prior_30m_high,

        MIN(low) OVER (
            PARTITION BY symbol, asset_type, trading_date_est
            ORDER BY ts_est
            ROWS BETWEEN 3 PRECEDING AND 1 PRECEDING
        ) AS pullback_floor_15m

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
    prior_30m_high,
    ROUND((prior_30m_high - price) / NULLIF(price, 0) * 100, 3) AS distance_from_recent_high_pct,
    pullback_floor_15m
FROM f
WHERE
    ema9_above_ema21 = 1
    AND above_vwap = 1
    AND vwap_dist_pct BETWEEN 0.00 AND 0.50
    AND rsi_14 BETWEEN 35 AND 70
    AND atr_pct BETWEEN 0.15 AND 5.00
    AND price >= low + ((high - low) * 0.50)
    AND prior_30m_high IS NOT NULL
    AND prior_30m_high > price
    AND ((prior_30m_high - price) / NULLIF(price, 0) * 100) BETWEEN 0.10 AND 3.00
    AND pullback_floor_15m IS NOT NULL
    AND low >= pullback_floor_15m * 0.985
LIMIT ?
';

        $params = [
            $assetType,
            $tradeDate,
            $asOfTsEst,
            $timeWindowStart,
            $timeWindowEnd,
            $minPrice,
            $maxPrice,
            $limit * 3,
        ];

        $rows = $this->dbSelect($sql, $params);

        if (empty($rows)) {
            return [];
        }

        // Score candidates
        $cands = [];
        foreach ($rows as $r) {
            $symbol = (string) $r->symbol;
            $setupPrice = (float) ($r->setup_price ?? 0);
            $vwapDistPct = (float) ($r->vwap_dist_pct ?? 0);
            $rsi = (float) ($r->rsi_14 ?? 50);
            $atrPct = (float) ($r->atr_pct ?? 0);
            $distFromHighPct = (float) ($r->distance_from_recent_high_pct ?? 0);
            $emaSpread = (float) ($r->ema9_ema21_spread ?? 0);

            $high = (float) ($r->high ?? 0);
            $low = (float) ($r->low ?? 0);
            $range = max(1e-9, ($high - $low));
            $posInBar = ($setupPrice - $low) / $range; // 0..1

            $score = 0.0;

            // A) Position in candle (0..25) - prefer price near top of range
            $score += min(25.0, max(0.0, ($posInBar - 0.50) * 50.0));

            // B) Distance from recent high (0..20) - closer is better
            // 0.20% => 20, 2.00% => 0
            $score += min(20.0, max(0.0, 20.0 - (($distFromHighPct - 0.20) * 11.11)));

            // C) VWAP proximity (0..15) - closer is better
            // 0.00% => 15, 0.40% => 0
            $score += min(15.0, max(0.0, 15.0 - ($vwapDistPct * 37.5)));

            // D) RSI optimal range (0..15) - prefer 50-55
            // 40 => 0, 50 => 10, 52.5 => 15, 65 => 5
            if ($rsi >= 40 && $rsi <= 52.5) {
                $score += min(15.0, ($rsi - 40) * 1.2);
            } elseif ($rsi > 52.5 && $rsi <= 65) {
                $score += min(15.0, 15.0 - (($rsi - 52.5) * 0.8));
            }

            // E) ATR range (0..12) - prefer 1-4%
            // 0.30% => 0, 1.00% => 6, 4.00% => 12, 8.00% => 6
            if ($atrPct >= 0.30 && $atrPct <= 4.00) {
                $score += min(12.0, ($atrPct - 0.30) * 3.24);
            } elseif ($atrPct > 4.00 && $atrPct <= 8.00) {
                $score += min(12.0, 12.0 - (($atrPct - 4.00) * 1.5));
            }

            // F) EMA spread strength (0..8) - prefer wider spread
            $score += min(8.0, max(0.0, ($emaSpread * 100) * 8.0));

            // G) Trend alignment bonus (0..5)
            $score += 5.0; // All candidates already filtered for EMA9 > EMA21 and above VWAP

            $score = round(min(100.0, $score));

            $atr = ($atrPct && $setupPrice) ? round(($atrPct / 100) * $setupPrice, 6) : null;

            $cands[] = [
                'symbol' => $symbol,
                'asset_type' => (string) $r->asset_type,
                'signal_ts_est' => (string) $r->signal_ts_est,
                'score' => $score,
                'setup_price' => $setupPrice,
                'vwap' => (float) ($r->vwap ?? 0),
                'vwap_dist_pct' => $vwapDistPct,
                'rsi_14' => $rsi,
                'atr' => $atr,
                'atr_pct' => $atrPct,
                'prior_30m_high' => (float) ($r->prior_30m_high ?? 0),
                'distance_from_recent_high_pct' => $distFromHighPct,
                'ema9_ema21_spread' => $emaSpread,
                'pos_in_bar' => $posInBar,
                'pullback_floor_15m' => (float) ($r->pullback_floor_15m ?? 0),
            ];
        }

        if (empty($cands)) {
            return [];
        }

        // Filter by score window and rank
        $ranked = [];
        foreach ($cands as $c) {
            $s = (float) $c['score'];
            if ($s < $minScore || $s > $maxScore) {
                continue;
            }
            $ranked[] = $c;
        }

        if (empty($ranked)) {
            return [];
        }

        usort($ranked, fn ($a, $b) => ($b['score'] <=> $a['score']));

        $finalLimit = min($limit, $topN, count($ranked));
        $ranked = array_slice($ranked, 0, $finalLimit);

        // Format output
        $out = [];
        foreach ($ranked as $r) {
            $out[] = [
                'symbol' => (string) $r['symbol'],
                'asset_type' => (string) $r['asset_type'],
                'signal_type' => 'HIGHER_LOW_BREAKOUT_SETUP',
                'signal_ts_est' => (string) $r['signal_ts_est'],
                'score' => (int) $r['score'],
                'atr' => $r['atr'] ?? null,
                'atr_pct' => $r['atr_pct'] ?? null,
                'meta' => [
                    'version' => $this->version,
                    'goal' => 'higher-low breakout setup candidates',
                    'setup_price' => round((float) $r['setup_price'], 4),
                    'vwap' => round((float) $r['vwap'], 4),
                    'vwap_dist_pct' => round((float) $r['vwap_dist_pct'], 2),
                    'rsi_14' => round((float) $r['rsi_14'], 1),
                    'prior_30m_high' => round((float) $r['prior_30m_high'], 4),
                    'distance_from_recent_high_pct' => round((float) $r['distance_from_recent_high_pct'], 2),
                    'ema9_ema21_spread' => round((float) $r['ema9_ema21_spread'], 4),
                    'pos_in_bar' => round((float) $r['pos_in_bar'], 3),
                    'pullback_floor_15m' => round((float) $r['pullback_floor_15m'], 4),
                    'score_min' => $minScore,
                    'score_max' => $maxScore,
                    'time_window' => [$timeWindowStart, $timeWindowEnd],
                ],
            ];
        }

        return $out;
    }
}
