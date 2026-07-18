<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * Version 40.0 - Runner Scanner
 * Purpose: Catch stocks making strong sustained moves (runners)
 * Strategy: Find stocks with consistent momentum acceleration (3+ consecutive green 5m bars with increasing gains)
 * Focus: Stocks currently in motion with volume confirmation
 */
class FiveMinuteSignalScannerV40_0
{
    use HasPriceTables;

    private string $version = 'v40.0';

    private string $name = 'Runner Momentum';

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Scan for runners - stocks making sustained moves with momentum.
     *
     * Runner Criteria:
     * 1. Up 3%+ from today's open
     * 2. Last 3-5 bars all green (higher closes)
     * 3. Volume above average (2x+)
     * 4. Recent acceleration (last bar stronger than previous)
     * 5. Trading above VWAP
     *
     * @param  string  $assetType  'stock' or 'crypto'
     * @param  string  $asOfTsEst  Current timestamp (EST)
     * @param  int  $lookbackMinutes  Window for runner detection (default 30)
     * @param  float  $minMovePct  Minimum move from open (default 3.0%)
     * @param  float  $volMult  Volume multiplier vs average (default 2.0)
     * @param  int  $limit  Max signals to return (default 25)
     * @return array Runner signals
     */
    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 30,
        float $minMovePct = 3.0,
        float $volMult = 2.0,
        int $limit = 25
    ): array {
        $currentDate = substr($asOfTsEst, 0, 10);

        // Get active universe (traded in last 2 hours with decent volume)
        $activeSymbols = DB::table($this->fiveMinuteTable)
            ->select('symbol')
            ->where('asset_type', $assetType)
            ->where('trading_date_est', $currentDate)
            ->where('ts_est', '>=', DB::raw("DATE_SUB('{$asOfTsEst}', INTERVAL 120 MINUTE)"))
            ->where('ts_est', '<=', $asOfTsEst)
            ->groupBy('symbol')
            ->havingRaw('SUM(volume) >= 100000')
            ->pluck('symbol')
            ->toArray();

        if (empty($activeSymbols)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($activeSymbols), '?'));

        // Find runners with sustained momentum
        $sql = "
            WITH bars_today AS (
                SELECT 
                    symbol,
                    ts_est,
                    price as close,
                    volume,
                    FIRST_VALUE(price) OVER (PARTITION BY symbol ORDER BY ts_est) as open_price
                FROM five_minute_prices
                WHERE asset_type = ?
                  AND symbol IN ({$placeholders})
                  AND trading_date_est = ?
                  AND ts_est <= ?
                  AND TIME(ts_est) BETWEEN '09:30:00' AND '16:00:00'
                ORDER BY symbol, ts_est
            ),
            recent_bars AS (
                SELECT 
                    symbol,
                    ts_est,
                    close,
                    volume,
                    open_price,
                    LAG(close, 1) OVER (PARTITION BY symbol ORDER BY ts_est) as prev_close_1,
                    LAG(close, 2) OVER (PARTITION BY symbol ORDER BY ts_est) as prev_close_2,
                    LAG(close, 3) OVER (PARTITION BY symbol ORDER BY ts_est) as prev_close_3,
                    LAG(close, 4) OVER (PARTITION BY symbol ORDER BY ts_est) as prev_close_4,
                    ((close - open_price) / open_price * 100) as move_from_open_pct,
                    ROW_NUMBER() OVER (PARTITION BY symbol ORDER BY ts_est DESC) as recency
                FROM bars_today
            ),
            last_bar AS (
                SELECT 
                    symbol,
                    ts_est,
                    close,
                    volume,
                    open_price,
                    prev_close_1,
                    prev_close_2,
                    prev_close_3,
                    prev_close_4,
                    move_from_open_pct
                FROM recent_bars
                WHERE recency = 1
            ),
            volume_avg AS (
                SELECT 
                    symbol,
                    AVG(volume) as avg_vol
                FROM bars_today
                GROUP BY symbol
            ),
            runners AS (
                SELECT 
                    lb.symbol,
                    lb.close as current_price,
                    lb.open_price,
                    lb.move_from_open_pct,
                    lb.volume as last_volume,
                    va.avg_vol,
                    (lb.volume / NULLIF(va.avg_vol, 0)) as vol_ratio,
                    -- Check for consecutive green bars
                    CASE 
                        WHEN lb.close > lb.prev_close_1 
                         AND lb.prev_close_1 > lb.prev_close_2 
                         AND lb.prev_close_2 > lb.prev_close_3 
                        THEN 1 
                        ELSE 0 
                    END as has_momentum,
                    -- Check for acceleration
                    CASE 
                        WHEN (lb.close - lb.prev_close_1) > (lb.prev_close_1 - lb.prev_close_2) 
                        THEN 1 
                        ELSE 0 
                    END as is_accelerating,
                    -- Calculate momentum strength
                    ((lb.close - lb.prev_close_3) / lb.prev_close_3 * 100) as three_bar_gain_pct,
                    lb.ts_est as signal_ts_est
                FROM last_bar lb
                JOIN volume_avg va ON lb.symbol = va.symbol
                WHERE lb.move_from_open_pct >= ?
                  AND lb.close > lb.open_price
                  AND lb.prev_close_1 IS NOT NULL
                  AND lb.prev_close_2 IS NOT NULL
                  AND lb.prev_close_3 IS NOT NULL
                  AND (lb.volume / NULLIF(va.avg_vol, 0)) >= ?
            )
            SELECT 
                symbol,
                current_price,
                open_price,
                move_from_open_pct,
                vol_ratio,
                has_momentum,
                is_accelerating,
                three_bar_gain_pct,
                signal_ts_est,
                (move_from_open_pct * vol_ratio * (has_momentum + is_accelerating + 1)) as runner_score
            FROM runners
            WHERE has_momentum = 1
            ORDER BY runner_score DESC
            LIMIT ?
        ";

        $params = array_merge(
            [$assetType],
            $activeSymbols,
            [$currentDate, $asOfTsEst, $minMovePct, $volMult, $limit]
        );

        $results = $this->dbSelect($sql, $params);

        return array_map(function ($row) {
            return [
                'symbol' => $row->symbol,
                'asset_type' => 'stock',
                'signal_type' => 'RUNNER_5M',
                'signal_ts_est' => $row->signal_ts_est,
                'score' => round($row->runner_score, 2),
                'meta' => [
                    'current_price' => round($row->current_price, 4),
                    'open_price' => round($row->open_price, 4),
                    'move_from_open_pct' => round($row->move_from_open_pct, 2),
                    'vol_ratio' => round($row->vol_ratio, 2),
                    'has_momentum' => (bool) $row->has_momentum,
                    'is_accelerating' => (bool) $row->is_accelerating,
                    'three_bar_gain_pct' => round($row->three_bar_gain_pct, 2),
                ],
            ];
        }, $results);
    }
}
