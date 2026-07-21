<?php

namespace App\Services\Trading;

/**
 * Version 1200.0 - Market Movers Two-Bar Momentum Scanner
 *
 * Strategy: Detect continuation momentum when last 2 of 3 five-minute bars are rising
 *
 * Requirements:
 * - Source: Market movers from market_movers table (4%+ intraday gainers)
 * - Pattern: Last 2 bars closing higher (bar[n-1] > bar[n-2] AND bar[n] > bar[n-1])
 * - Volume: Above average to confirm momentum
 * - Time: After 10:00 AM to avoid opening volatility
 *
 * This captures strong intraday momentum continuing with fresh buying pressure.
 */
class FiveMinuteSignalScannerV1200_0
{
    use HasPriceTables;

    private string $version = 'v1200.0';

    private string $name = 'Two-Bar Momentum';

    // ── Scanner Configuration (public so entry finders can read) ──
    /** @var int Number of top market movers to pull */
    public int $topMovers = 100;

    /** @var float Minimum intraday gain % for inclusion */
    public float $minGainPct = 4.0;

    /** @var float Minimum share price */
    public float $minPrice = 5.0;

    /** @var float Maximum share price */
    public float $maxPrice = 100.0;

    /** @var float Minimum volume ratio vs average */
    public float $minVolRatio = 1.2;

    /** @var string Start of trading window (HH:MM:SS) */
    public string $timeWindowStart = '10:00:00';

    /** @var string End of trading window (HH:MM:SS) */
    public string $timeWindowEnd = '15:30:00';

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
            'top_movers' => $this->topMovers,
            'min_gain_pct' => $this->minGainPct,
            'min_price' => $this->minPrice,
            'max_price' => $this->maxPrice,
            'min_vol_ratio' => $this->minVolRatio,
            'time_window_start' => $this->timeWindowStart,
            'time_window_end' => $this->timeWindowEnd,
        ];
    }

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
        string $asOfTsEst
    ): array {
        // Load all config from trading.v1200
        $topMovers = $this->topMovers;
        $minMovePct = $this->minGainPct;
        $minPrice = $this->minPrice;
        $maxPrice = $this->maxPrice;
        $minVolRatio = $this->minVolRatio;
        $timeWindowStart = $this->timeWindowStart;
        $timeWindowEnd = $this->timeWindowEnd;
        $lookbackMinutes = 60;

        // \Log::debug("[V1200 Scanner] minMove>={$minMovePct}%, volRatio>={$minVolRatio}, asOf={$asOfTsEst}");

        $tradeDate = substr($asOfTsEst, 0, 10);

        // Get market movers for today
        $moverSymbols = app(\App\Services\MarketMoversService::class)
            ->getTodaysTopMoversFromCache($tradeDate, $topMovers);

        if (empty($moverSymbols)) {
            // \Log::debug('[V1200 Scanner] No market movers found for date: '.$tradeDate);

            // Auto-populate if missing (useful for backtesting)
            // \Log::debug('[V1200 Scanner] Attempting to auto-populate market_movers for '.$tradeDate);

            try {
                \Artisan::call('market-movers:populate', [
                    '--date' => $tradeDate,
                    '--no-interaction' => true,
                ]);

                // Retry after population
                $moverSymbols = app(\App\Services\MarketMoversService::class)
                    ->getTodaysTopMoversFromCache($tradeDate, $topMovers * 2);

                if (empty($moverSymbols)) {
                    // \Log::debug('[V1200 Scanner] Still no movers after auto-populate for: '.$tradeDate);

                    return [];
                }

                \Log::debug('[V1200 Scanner] Successfully auto-populated and found '.count($moverSymbols).' movers');
            } catch (\Exception $e) {
                \Log::error('[V1200 Scanner] Failed to auto-populate market_movers: '.$e->getMessage());

                return [];
            }
        }

        $symbolPlaceholders = implode(',', array_fill(0, count($moverSymbols), '?'));

        // Get the last 3 bars for each symbol to check pattern
        $sql = "
WITH latest_bars AS (
    SELECT 
        f.symbol,
        f.asset_type,
        f.trading_date_est,
        f.ts_est,
        f.trading_time_est,
        f.price,
        f.open,
        f.high,
        f.low,
        f.volume,
        f.vwap,
        f.atr,
        f.atr_pct,
        ROW_NUMBER() OVER (PARTITION BY f.symbol ORDER BY f.ts_est DESC) as bar_rank
    FROM five_minute_prices f
    WHERE f.asset_type = ?
        AND f.trading_date_est = ?
        AND f.ts_est <= ?
        AND f.symbol IN ($symbolPlaceholders)
        AND f.trading_time_est BETWEEN ? AND ?
        AND f.price BETWEEN ? AND ?
        AND f.open > 0
),
bar_data AS (
    SELECT 
        symbol,
        asset_type,
        trading_date_est,
        MAX(CASE WHEN bar_rank = 1 THEN ts_est END) as ts_bar0,
        MAX(CASE WHEN bar_rank = 1 THEN price END) as close_bar0,
        MAX(CASE WHEN bar_rank = 1 THEN open END) as open_bar0,
        MAX(CASE WHEN bar_rank = 1 THEN volume END) as vol_bar0,
        MAX(CASE WHEN bar_rank = 1 THEN atr END) as atr,
        MAX(CASE WHEN bar_rank = 1 THEN atr_pct END) as atr_pct,
        MAX(CASE WHEN bar_rank = 2 THEN price END) as close_bar1,
        MAX(CASE WHEN bar_rank = 3 THEN price END) as close_bar2,
        MAX(CASE WHEN bar_rank = 3 THEN open END) as open_bar2
    FROM latest_bars
    WHERE bar_rank <= 3
    GROUP BY symbol, asset_type, trading_date_est
    HAVING close_bar0 IS NOT NULL 
        AND close_bar1 IS NOT NULL 
        AND close_bar2 IS NOT NULL
),
avg_volume AS (
    SELECT 
        symbol,
        AVG(volume) as avg_vol_20
    FROM five_minute_prices
    WHERE asset_type = ?
        AND trading_date_est = ?
        AND ts_est < ?
        AND symbol IN ($symbolPlaceholders)
    GROUP BY symbol
    HAVING COUNT(*) >= 10
)
SELECT 
    b.symbol,
    b.asset_type,
    b.trading_date_est,
    b.ts_bar0 as signal_ts_est,
    b.close_bar0 as setup_price,
    b.open_bar0,
    b.atr,
    b.atr_pct,
    b.vol_bar0,
    a.avg_vol_20,
    ROUND(((b.close_bar0 - b.open_bar2) / b.open_bar2) * 100, 2) as three_bar_gain_pct,
    ROUND(b.vol_bar0 / NULLIF(a.avg_vol_20, 0), 2) as vol_ratio,
    100 as entry_score
FROM bar_data b
INNER JOIN avg_volume a ON b.symbol = a.symbol
WHERE b.close_bar0 > b.close_bar1  -- Last bar up
    AND b.close_bar1 > b.close_bar2  -- Previous bar up
    AND ((b.close_bar0 - b.open_bar2) / b.open_bar2) * 100 >= ?  -- 3-bar move threshold
    AND b.vol_bar0 / NULLIF(a.avg_vol_20, 0) >= ?  -- Volume confirmation
ORDER BY three_bar_gain_pct DESC, vol_ratio DESC
LIMIT ?
        ";

        $params = [
            $assetType,
            $tradeDate,
            $asOfTsEst,
            ...$moverSymbols,
            $timeWindowStart,
            $timeWindowEnd,
            $minPrice,
            $maxPrice,
            $assetType,
            $tradeDate,
            $asOfTsEst,
            ...$moverSymbols,
            $minMovePct,
            $minVolRatio,
            $topMovers,
        ];

        $rows = $this->dbSelect($sql, $params);

        if (empty($rows)) {
            // \Log::debug('[V1200 Scanner] No signals found matching two-bar momentum pattern');

            return [];
        }

        \Log::debug('[V1200 Scanner] Found '.count($rows).' signals with two-bar momentum');

        return array_map(function ($row) {
            return [
                'symbol' => $row->symbol,
                'asset_type' => $row->asset_type,
                'signal_type' => 'TWO_BAR_MOMENTUM',
                'signal_ts_est' => $row->signal_ts_est,
                'score' => $row->entry_score,
                'setup_price' => (float) $row->setup_price,
                'three_bar_gain_pct' => (float) $row->three_bar_gain_pct,
                'vol_ratio' => (float) $row->vol_ratio,
                'atr' => $row->atr ? (float) $row->atr : null,
                'atr_pct' => $row->atr_pct ? (float) $row->atr_pct : null,
                'meta' => [
                    'setup_price' => (float) $row->setup_price,
                    'three_bar_gain_pct' => (float) $row->three_bar_gain_pct,
                    'vol_ratio' => (float) $row->vol_ratio,
                ],
            ];
        }, $rows);
    }
}
