<?php

namespace App\Services\Trading;

/**
 * Version 1500.0 - Opening Range Breakout (ORB) Scanner
 *
 * Strategy: Detect breakouts above first 30-minute opening range with volume confirmation
 *
 * Requirements:
 * - Source: Market movers from market_movers table (2%+ intraday gainers)
 * - Opening Range: 9:30-10:00 AM EST high/low range
 * - Pattern: Price breaks above OR high with strong volume
 * - Time: After 10:05 AM (allows 5min confirmation after range completion)
 * - Volume: Breakout volume > 1.5x opening range average volume
 * - Range Quality: Range must be 1-4% of price (not too tight, not too wide)
 *
 * This captures institutional positioning revealed in first 30min of trading.
 */
class FiveMinuteSignalScannerV1500_0
{
    use HasPriceTables;

    private string $version = 'v1500.0';

    private string $name = 'Opening Range Breakout';

    // ── Scanner Configuration (public so entry finders can read) ──
    /** @var int Number of top market movers to pull */
    public int $topMovers = 25;

    /** @var float Minimum intraday gain % for inclusion */
    public float $minGainPct = 2.0;

    /** @var float Minimum share price */
    public float $minPrice = 5.0;

    /** @var float Maximum share price */
    public float $maxPrice = 100.0;

    /** @var float Minimum volume ratio for breakout */
    public float $minVolRatio = 1.5;

    /** @var float Minimum opening range % of price */
    public float $minRangePct = 1.0;

    /** @var float Maximum opening range % of price */
    public float $maxRangePct = 4.0;

    /** @var string Start of trading window (HH:MM:SS) */
    public string $timeWindowStart = '10:05:00';

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
            'min_range_pct' => $this->minRangePct,
            'max_range_pct' => $this->maxRangePct,
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
        // Load all config from trading.v1500
        $topMovers = $this->topMovers;
        $minMovePct = $this->minGainPct;
        $minPrice = $this->minPrice;
        $maxPrice = $this->maxPrice;
        $minVolRatio = $this->minVolRatio;
        $minRangePct = $this->minRangePct;
        $maxRangePct = $this->maxRangePct;
        $timeWindowStart = $this->timeWindowStart;
        $timeWindowEnd = $this->timeWindowEnd;

        // \Log::debug("[V1500 Scanner] ORB minMove>={$minMovePct}%, volRatio>={$minVolRatio}, asOf={$asOfTsEst}");

        $tradeDate = substr($asOfTsEst, 0, 10);
        $currentTime = substr($asOfTsEst, 11, 8);

        // Don't run before 10:05 AM (need opening range complete + 5min confirmation)
        if ($currentTime < $timeWindowStart) {
            \Log::debug("[V1500 Scanner] Too early ({$currentTime}), need >= {$timeWindowStart}");

            return [];
        }

        // Get market movers for today
        $moverSymbols = app(\App\Services\MarketMoversService::class)
            ->getTodaysTopMoversFromCache($tradeDate, $topMovers);

        if (empty($moverSymbols)) {
            // \Log::debug('[V1500 Scanner] No market movers found for date: '.$tradeDate);

            // Auto-populate if missing (useful for backtesting)
            // \Log::debug('[V1500 Scanner] Attempting to auto-populate market_movers for '.$tradeDate);

            try {
                \Artisan::call('market-movers:populate', [
                    '--date' => $tradeDate,
                    '--no-interaction' => true,
                ]);

                // Retry after population
                $moverSymbols = app(\App\Services\MarketMoversService::class)
                    ->getTodaysTopMoversFromCache($tradeDate, $topMovers * 2);

                if (empty($moverSymbols)) {
                    // \Log::debug('[V1500 Scanner] Still no movers after auto-populate for: '.$tradeDate);

                    return [];
                }

                \Log::info('[V1500 Scanner] Successfully auto-populated and found '.count($moverSymbols).' movers');
            } catch (\Exception $e) {
                \Log::error('[V1500 Scanner] Failed to auto-populate market_movers: '.$e->getMessage());

                return [];
            }
        }

        $symbolPlaceholders = implode(',', array_fill(0, count($moverSymbols), '?'));

        // Calculate opening range (9:30-10:00 AM) and detect breakouts
        $sql = "
WITH opening_range AS (
    SELECT 
        f.symbol,
        f.asset_type,
        f.trading_date_est,
        MAX(f.high) as or_high,
        MIN(f.low) as or_low,
        AVG(f.volume) as or_avg_volume,
        COUNT(*) as or_bar_count
    FROM five_minute_prices f
    WHERE f.asset_type = ?
        AND f.trading_date_est = ?
        AND f.symbol IN ($symbolPlaceholders)
        AND f.trading_time_est BETWEEN '09:30:00' AND '10:00:00'
        AND f.open > 0
    GROUP BY f.symbol, f.asset_type, f.trading_date_est
    HAVING COUNT(*) >= 5  -- Need at least 5 bars for 30min range (some might be missing)
),
range_quality AS (
    SELECT 
        symbol,
        asset_type,
        trading_date_est,
        or_high,
        or_low,
        or_avg_volume,
        (or_high - or_low) as or_range,
        100.0 * (or_high - or_low) / or_high as range_pct
    FROM opening_range
    WHERE (100.0 * (or_high - or_low) / or_high) BETWEEN ? AND ?  -- 1-4% range
        AND or_high BETWEEN ? AND ?  -- Price filter
),
current_bar AS (
    SELECT 
        f.symbol,
        f.asset_type,
        f.trading_date_est,
        f.ts_est as signal_ts_est,
        f.trading_time_est,
        f.price as current_close,
        f.high as current_high,
        f.volume as current_volume,
        f.atr,
        f.atr_pct
    FROM five_minute_prices f
    WHERE f.asset_type = ?
        AND f.trading_date_est = ?
        AND f.ts_est <= ?
        AND f.symbol IN ($symbolPlaceholders)
        AND f.trading_time_est BETWEEN ? AND ?
        AND f.open > 0
    AND EXISTS (
        SELECT 1 
        FROM five_minute_prices sub
        WHERE sub.symbol = f.symbol 
            AND sub.asset_type = f.asset_type
            AND sub.trading_date_est = f.trading_date_est
            AND sub.ts_est = f.ts_est
    )
),
breakout_candidates AS (
    SELECT 
        c.symbol,
        c.asset_type,
        c.trading_date_est,
        c.signal_ts_est,
        c.current_close as setup_price,
        c.current_high,
        c.current_volume,
        c.atr,
        c.atr_pct,
        r.or_high,
        r.or_low,
        r.or_range,
        r.range_pct,
        r.or_avg_volume,
        -- Breakout confirmation: current high > OR high
        (c.current_high > r.or_high) as is_breakout,
        -- Volume confirmation: current volume > 1.5x OR average
        (c.current_volume / NULLIF(r.or_avg_volume, 0)) as vol_ratio,
        -- Breakout strength: distance above OR high
        100.0 * ((c.current_high - r.or_high) / r.or_high) as breakout_pct,
        ROW_NUMBER() OVER (PARTITION BY c.symbol ORDER BY c.signal_ts_est DESC) as recency_rank
    FROM current_bar c
    INNER JOIN range_quality r 
        ON c.symbol = r.symbol 
        AND c.asset_type = r.asset_type
        AND c.trading_date_est = r.trading_date_est
    WHERE c.current_high > r.or_high  -- Must break above OR high
        AND (c.current_volume / NULLIF(r.or_avg_volume, 0)) >= ?  -- Volume confirmation
)
SELECT 
    symbol,
    asset_type,
    trading_date_est,
    signal_ts_est,
    setup_price,
    or_high,
    or_low,
    or_range,
    range_pct,
    vol_ratio,
    breakout_pct,
    atr,
    atr_pct,
    -- Score: prioritize strong breakouts with volume
    (vol_ratio * 10 + breakout_pct * 5 + range_pct) as score
FROM breakout_candidates
WHERE recency_rank = 1  -- Most recent bar for each symbol
    AND vol_ratio >= ?  -- Ensure volume threshold met
ORDER BY score DESC
LIMIT ?
        ";

        // Build parameter array
        $params = array_merge(
            [$assetType, $tradeDate], // opening_range CTE
            $moverSymbols,
            [$minRangePct, $maxRangePct, $minPrice, $maxPrice], // range_quality CTE
            [$assetType, $tradeDate, $asOfTsEst], // current_bar CTE
            $moverSymbols,
            [$timeWindowStart, $timeWindowEnd],
            [$minVolRatio], // breakout_candidates WHERE
            [$minVolRatio], // final WHERE
            [$topMovers * 2] // LIMIT (2x to have more candidates)
        );

        $results = $this->dbSelect($sql, $params);

        if (empty($results)) {
            // \Log::debug('[V1500 Scanner] No ORB signals found');

            return [];
        }

        $signals = [];
        foreach ($results as $row) {
            $signals[] = [
                'symbol' => $row->symbol,
                'asset_type' => $row->asset_type,
                'trading_date_est' => $row->trading_date_est,
                'signal_ts_est' => $row->signal_ts_est,
                'signal_type' => 'ORB_BREAKOUT',
                'setup_price' => (float) $row->setup_price,
                'atr' => (float) $row->atr,
                'atr_pct' => (float) $row->atr_pct,
                'score' => (float) $row->score,
                'context' => [
                    'or_high' => (float) $row->or_high,
                    'or_low' => (float) $row->or_low,
                    'or_range' => (float) $row->or_range,
                    'range_pct' => (float) $row->range_pct,
                    'vol_ratio' => (float) $row->vol_ratio,
                    'breakout_pct' => (float) $row->breakout_pct,
                ],
                'strategy_name' => 'ORB_V1500',
            ];
        }

        \Log::debug('[V1500 Scanner] Found '.count($signals).' ORB signals');

        return $signals;
    }
}
