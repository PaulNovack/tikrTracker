<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Version 900.0 - Momentum Continuation Scanner (LONG)
 *
 * Goal: Identify stocks that:
 * - Had significant move yesterday (+5%+) - AGGRESSIVE
 * - Gap in either direction (allows gap down reversals)
 * - Show EXPLOSIVE continuation (+2%+ in first 15 mins) - KEY SIGNAL
 * - Strong momentum (RSI > 60) - AGGRESSIVE
 * - EMA9 > EMA21 (VWAP not required)
 * - Trading above/near Bollinger upper band (BB position > 75%) - AGGRESSIVE
 * - High volume (2x+ recent average)
 *
 * Pattern discovered from QURE (+37% gap down reversal) and IOT (+11% gap up continuation) on March 6, 2026
 *
 * ENV / config('trading.*'):
 * - v900.entry_score_min (default 40)
 * - v900.entry_score_max (default 100)
 * - v900.entry_score_limit (default 10)
 * - v900.min_price (default 3.0)
 * - v900.max_price (default 500.0)
 * - v900.min_yesterday_move_pct (default -5.0, allows any yesterday)
 * - v900.min_opening_gap_pct (default -10.0, allows gap downs)
 * - v900.min_early_move_pct (default 2.0, aggressive)
 * - v900.min_rsi (default 60, aggressive)
 * - v900.min_bb_position (default 65, aggressive)
 * - v900.min_volume_mult (default 2.0)
 * - v900.time_window_start (default '09:30:00')
 * - v900.time_window_end (default '10:30:00')
 */
class FiveMinuteSignalScannerV900_0
{
    use HasPriceTables;

    private string $version = 'v900.0';

    private string $name = 'Momentum Continuation';

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Scan for Momentum Continuation candidates (LONG)
     */
    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minMovePct = 0.0,
        float $volMult = 1.0,
        int $limit = 20
    ): array {
        $minScore = (float) config('trading.v900.entry_score_min', 40);
        $maxScore = (float) config('trading.v900.entry_score_max', 100);
        $topN = (int) config('trading.v900.entry_score_limit', 10);

        $minPrice = (float) config('trading.v900.min_price', 3.0);
        $maxPrice = (float) config('trading.v900.max_price', 500.0);

        $minYesterdayMovePct = (float) config('trading.v900.min_yesterday_move_pct', -5.0);
        $minOpeningGapPct = (float) config('trading.v900.min_opening_gap_pct', -10.0);
        $minEarlyMovePct = (float) config('trading.v900.min_early_move_pct', 2.0);
        $minRsi = (float) config('trading.v900.min_rsi', 60);
        $minBBPosition = (float) config('trading.v900.min_bb_position', 65);
        $minVolumeMult = (float) config('trading.v900.min_volume_mult', 2.0);

        $timeWindowStart = (string) config('trading.v900.time_window_start', '09:30:00');
        $timeWindowEnd = (string) config('trading.v900.time_window_end', '10:30:00');

        if ($topN <= 0) {
            $topN = 10;
        }
        if ($maxScore <= 0) {
            $maxScore = 100.0;
        }
        if ($minScore > $maxScore) {
            [$minScore, $maxScore] = [$maxScore, $minScore];
        }

        $limit = max(1, (int) $limit);
        $tradeDate = substr($asOfTsEst, 0, 10);

        // Add market movers to universe if enabled
        $moversLimit = (int) config('trading.market_movers.pipeline_f', 0);
        $moverSymbols = [];
        if ($moversLimit > 0) {
            $moverSymbols = app(\App\Services\MarketMoversService::class)->getTodaysTopMoversFromCache(null, $moversLimit);
        }

        // Build SQL movers filter
        $moversFilter = '';
        $moversBindings = [];
        if (! empty($moverSymbols)) {
            $placeholders = implode(',', array_fill(0, count($moverSymbols), '?'));
            $moversFilter = " OR f.symbol IN ($placeholders) ";
            $moversBindings = $moverSymbols;
        }

        // Get previous 2 trading days to calculate day-over-day move correctly
        $prevTradingDates = DB::table($this->fiveMinuteTable)
            ->where('asset_type', $assetType)
            ->where('trading_date_est', '<', $tradeDate)
            ->distinct()
            ->orderBy('trading_date_est', 'desc')
            ->limit(2)
            ->pluck('trading_date_est')
            ->toArray();

        if (count($prevTradingDates) < 2) {
            Log::warning('[V900.0 Scanner] Need 2 previous trading days for day-over-day calc', [
                'asset_type' => $assetType,
                'trade_date' => $tradeDate,
                'found' => count($prevTradingDates),
            ]);

            return [];
        }

        $prevTradingDate = $prevTradingDates[0]; // Yesterday
        $prevPrevTradingDate = $prevTradingDates[1]; // Day before yesterday

        Log::debug('[V900.0 Scanner] Starting momentum continuation scan', [
            'asset_type' => $assetType,
            'as_of' => $asOfTsEst,
            'trade_date' => $tradeDate,
            'prev_trade_date' => $prevTradingDate,
            'prev_prev_trade_date' => $prevPrevTradingDate,
            'min_score' => $minScore,
            'max_score' => $maxScore,
            'limit' => $topN,
        ]);

        $sql = "
WITH two_days_ago AS (
    SELECT
        symbol,
        asset_type,
        price AS close_2d_ago
    FROM five_minute_prices
    WHERE asset_type = ?
      AND trading_date_est = ?
      AND trading_time_est = '15:55:00'
      AND price BETWEEN ? AND ?
      AND price > 0
),
prev_day_close AS (
    SELECT
        p.symbol,
        p.asset_type,
        p.price AS prev_day_close,
        t.close_2d_ago,
        ((p.price - t.close_2d_ago) / t.close_2d_ago * 100) AS yesterday_move_pct
    FROM five_minute_prices p
    INNER JOIN two_days_ago t ON p.symbol = t.symbol AND p.asset_type = t.asset_type
    WHERE p.asset_type = ?
      AND p.trading_date_est = ?
      AND p.trading_time_est = '15:55:00'
      AND p.price BETWEEN ? AND ?
      AND p.price > 0
      AND t.close_2d_ago > 0
      AND ((p.price - t.close_2d_ago) / t.close_2d_ago * 100) >= ?
),
prev_day_opens AS (
    SELECT symbol, asset_type, price AS prev_day_open
    FROM five_minute_prices
    WHERE asset_type = ?
      AND trading_date_est = ?
      AND trading_time_est = '09:30:00'
),
prev_day_vols AS (
    SELECT symbol, asset_type, AVG(volume) AS avg_volume
    FROM five_minute_prices
    WHERE asset_type = ?
      AND trading_date_est = ?
    GROUP BY symbol, asset_type
),
today_opening AS (
    SELECT
        symbol,
        asset_type,
        MAX(CASE WHEN trading_time_est = '09:30:00' THEN open ELSE NULL END) AS today_open_price,
        MAX(CASE WHEN trading_time_est <= '09:45:00' THEN price ELSE NULL END) AS highest_first_15min
    FROM five_minute_prices
    WHERE asset_type = ?
      AND trading_date_est = ?
      AND ts_est <= ?
      AND trading_time_est <= '09:45:00'
    GROUP BY symbol, asset_type
    HAVING today_open_price IS NOT NULL AND highest_first_15min IS NOT NULL
),
today_bars AS (
    SELECT
        f.symbol,
        f.asset_type,
        f.ts_est,
        f.trading_date_est,
        f.trading_time_est,
        f.price,
        f.open,
        f.high,
        f.low,
        f.volume,
        f.vwap,
        f.vwap_dist_pct,
        f.above_vwap,
        f.ema9,
        f.ema21,
        f.ema9_above_ema21,
        f.atr,
        f.atr_pct,
        f.rsi_14,
        f.bb_upper,
        f.bb_middle,
        f.bb_lower,
        CASE
            WHEN (f.bb_upper - f.bb_lower) > 0 THEN
                ((f.price - f.bb_lower) / (f.bb_upper - f.bb_lower)) * 100
            ELSE 0
        END AS bb_position,
        pdo.prev_day_open,
        pd.prev_day_close,
        pd.close_2d_ago,
        pd.yesterday_move_pct,
        pdv.avg_volume,
        to_open.today_open_price,
        to_open.highest_first_15min
    FROM five_minute_prices f
    INNER JOIN prev_day_close pd ON f.symbol = pd.symbol AND f.asset_type = pd.asset_type
    LEFT JOIN prev_day_opens pdo ON f.symbol = pdo.symbol AND f.asset_type = pdo.asset_type
    INNER JOIN prev_day_vols pdv ON f.symbol = pdv.symbol AND f.asset_type = pdv.asset_type
    INNER JOIN today_opening to_open ON f.symbol = to_open.symbol AND f.asset_type = to_open.asset_type
    WHERE f.asset_type = ?
      AND f.trading_date_est = ?
      AND f.ts_est <= ?
      AND f.trading_time_est BETWEEN ? AND ?
      AND f.price BETWEEN ? AND ?
      AND f.ema9_above_ema21 = 1
      ".$moversFilter.'
)
SELECT
    t.symbol,
    t.asset_type,
    t.trading_date_est,
    t.ts_est AS signal_ts_est,
    t.trading_time_est,
    t.price AS setup_price,
    t.open,
    t.high,
    t.low,
    t.volume,
    t.vwap,
    t.vwap_dist_pct,
    t.ema9,
    t.ema21,
    t.atr,
    t.atr_pct,
    t.rsi_14,
    t.bb_position,
    t.bb_upper,
    t.bb_middle,
    t.bb_lower,
    t.yesterday_move_pct,
    ROUND(((t.today_open_price - t.prev_day_close) / t.prev_day_close * 100), 2) AS opening_gap_pct,
    ROUND(((t.highest_first_15min - t.today_open_price) / t.today_open_price * 100), 2) AS early_continuation_pct,
    ROUND((t.volume / t.avg_volume), 2) AS volume_ratio,
    LEAST(20, GREATEST(0, (t.rsi_14 - 60) * 0.5)) AS rsi_score,
    LEAST(25, GREATEST(0, (t.bb_position - 65) * 0.5)) AS bb_score,
    LEAST(15, GREATEST(0, (t.yesterday_move_pct - (-5)) * 0.3)) AS yesterday_score,
    LEAST(20, GREATEST(0, ((t.highest_first_15min - t.today_open_price) / t.today_open_price * 100 - 2) * 2)) AS continuation_score,
    LEAST(10, GREATEST(0, (t.volume / t.avg_volume - 2) * 3)) AS volume_score,
    CASE WHEN t.above_vwap = 1 THEN 5 ELSE 0 END AS vwap_score,
    CASE WHEN t.ema9_above_ema21 = 1 THEN 5 ELSE 0 END AS ema_score
FROM today_bars t
WHERE
    t.rsi_14 >= ?
    AND t.bb_position >= ?
    AND t.yesterday_move_pct >= ?
    AND ((t.today_open_price - t.prev_day_close) / t.prev_day_close * 100) >= ?
    AND ((t.highest_first_15min - t.today_open_price) / t.today_open_price * 100) >= ?
    AND (t.volume / t.avg_volume) >= ?
ORDER BY (
    LEAST(20, GREATEST(0, (t.rsi_14 - 60) * 0.5)) +
    LEAST(25, GREATEST(0, (t.bb_position - 65) * 0.5)) +
    LEAST(15, GREATEST(0, (t.yesterday_move_pct - (-5)) * 0.3)) +
    LEAST(20, GREATEST(0, ((t.highest_first_15min - t.today_open_price) / t.today_open_price * 100 - 2) * 2)) +
    LEAST(10, GREATEST(0, (t.volume / t.avg_volume - 2) * 3)) +
    CASE WHEN t.above_vwap = 1 THEN 5 ELSE 0 END +
    CASE WHEN t.ema9_above_ema21 = 1 THEN 5 ELSE 0 END
) DESC
LIMIT ?
';

        $bindings = [
            // two_days_ago
            $assetType, $prevPrevTradingDate, $minPrice, $maxPrice,
            // prev_day_close
            $assetType, $prevTradingDate, $minPrice, $maxPrice, $minYesterdayMovePct,
            // prev_day_opens
            $assetType, $prevTradingDate,
            // prev_day_vols
            $assetType, $prevTradingDate,
            // today_opening
            $assetType, $tradeDate, $asOfTsEst,
            // today_bars (f)
            $assetType, $tradeDate, $asOfTsEst, $timeWindowStart, $timeWindowEnd, $minPrice, $maxPrice,
            ...$moversBindings,
            // final WHERE
            $minRsi, $minBBPosition, $minYesterdayMovePct, $minOpeningGapPct, $minEarlyMovePct, $minVolumeMult,
            // LIMIT
            $limit,
        ];

        $results = $this->dbSelect($sql, $bindings);

        if (empty($results)) {
            Log::debug('[V900.0 Scanner] No momentum continuation candidates found');

            return [];
        }

        $signals = [];
        foreach ($results as $row) {
            // Calculate total score
            $score = (
                $row->rsi_score +
                $row->bb_score +
                $row->yesterday_score +
                $row->continuation_score +
                $row->volume_score +
                $row->vwap_score +
                $row->ema_score
            );

            // Apply score filters
            if ($score < $minScore || $score > $maxScore) {
                continue;
            }

            $signals[] = [
                'symbol' => $row->symbol,
                'asset_type' => $row->asset_type,
                'signal_type' => 'MOMENTUM_CONTINUATION_SETUP',
                'signal_ts_est' => $row->signal_ts_est,
                'score' => round($score, 2),
                'atr' => round($row->atr, 4),
                'atr_pct' => round($row->atr_pct, 4),
                'meta' => [
                    'version' => $this->version,
                    'setup_price' => round($row->setup_price, 4),
                    'vwap' => round($row->vwap, 4),
                    'ema9' => round($row->ema9, 4),
                    'ema21' => round($row->ema21, 4),
                    'rsi_14' => round($row->rsi_14, 2),
                    'bb_position' => round($row->bb_position, 2),
                    'bb_upper' => round($row->bb_upper, 4),
                    'bb_middle' => round($row->bb_middle, 4),
                    'bb_lower' => round($row->bb_lower, 4),
                    'yesterday_move_pct' => round($row->yesterday_move_pct, 2),
                    'opening_gap_pct' => round($row->opening_gap_pct, 2),
                    'early_continuation_pct' => round($row->early_continuation_pct, 2),
                    'volume_ratio' => round($row->volume_ratio, 2),
                    'score_breakdown' => [
                        'rsi_score' => round($row->rsi_score, 2),
                        'bb_score' => round($row->bb_score, 2),
                        'yesterday_score' => round($row->yesterday_score, 2),
                        'continuation_score' => round($row->continuation_score, 2),
                        'volume_score' => round($row->volume_score, 2),
                        'vwap_score' => round($row->vwap_score, 2),
                        'ema_score' => round($row->ema_score, 2),
                    ],
                ],
            ];
        }

        // Sort by score descending and limit to topN
        usort($signals, fn ($a, $b) => $b['score'] <=> $a['score']);
        $signals = array_slice($signals, 0, $topN);

        // Disabled noisy scanner logs.
        // Log::debug('[V900.0 Scanner] Momentum continuation scan complete', [
        //     'candidates_found' => count($signals),
        //     'top_score' => $signals[0]['score'] ?? null,
        //     'top_symbol' => $signals[0]['symbol'] ?? null,
        // ]);

        return $signals;
    }
}
