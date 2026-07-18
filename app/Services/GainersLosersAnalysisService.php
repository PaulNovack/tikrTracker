<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Trading\HasPriceTables;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * GainersLosersAnalysisService
 *
 * Service for finding the top gainers and losers for a given trading day
 * using the five_minute_prices table to compare market open to market close.
 *
 * Strategy:
 * - Get the first 5-minute price entry of the day (market open ~9:30 AM EST)
 * - Get the last 5-minute price entry of the day (market close ~3:55 PM EST)
 * - Calculate percentage change from open to close
 * - Return top 50 gainers and bottom 50 losers
 */
class GainersLosersAnalysisService
{
    use HasPriceTables;

    /**
     * Get top gainers and losers for a specific trading day.
     *
     * @param  string|null  $tradingDate  Date in Y-m-d format, null for latest trading day
     * @param  string  $assetType  'stock' or 'crypto'
     * @param  int  $topCount  Number of top gainers/losers to return
     * @return array ['gainers' => [...], 'losers' => [...], 'trading_date' => '...']
     */
    public function getGainersAndLosers(
        ?string $tradingDate = null,
        string $assetType = 'stock',
        int $topCount = 50,
        float $minPrice = 0,
        float $maxPrice = 999999
    ): array {
        if ($topCount <= 0) {
            throw new InvalidArgumentException('topCount must be > 0');
        }

        if (! in_array($assetType, ['stock', 'crypto'])) {
            throw new InvalidArgumentException('assetType must be stock or crypto');
        }

        // Get the latest trading day if not specified
        if ($tradingDate === null) {
            $tradingDate = $this->getLatestTradingDay($assetType);
        }

        // Get open and close prices for all symbols on the trading day
        $priceChanges = $this->calculateDailyPriceChanges($tradingDate, $assetType, $minPrice, $maxPrice);

        // Sort and get top gainers (highest percentage change)
        $gainers = collect($priceChanges)
            ->filter(fn ($item) => $item['change_pct'] > 0)
            ->sortByDesc('change_pct')
            ->take($topCount)
            ->values()
            ->toArray();

        // Sort and get top losers (lowest percentage change)
        $losers = collect($priceChanges)
            ->filter(fn ($item) => $item['change_pct'] < 0)
            ->sortBy('change_pct')
            ->take($topCount)
            ->values()
            ->toArray();

        return [
            'gainers' => $gainers,
            'losers' => $losers,
            'trading_date' => $tradingDate,
            'asset_type' => $assetType,
        ];
    }

    /**
     * Get the latest trading day with data.
     */
    private function getLatestTradingDay(string $assetType): string
    {
        $latestDate = DB::table($this->fiveMinuteTable)
            ->where('asset_type', $assetType)
            ->whereNotNull('trading_date_est')
            ->orderBy('trading_date_est', 'desc')
            ->value('trading_date_est');

        if (! $latestDate) {
            throw new InvalidArgumentException("No trading data found for asset type: {$assetType}");
        }

        return $latestDate;
    }

    /**
     * Calculate daily price changes for all symbols on a given trading day.
     *
     * Cached per (tradingDate, assetType) for 24 hours — prior-day data is immutable.
     */
    private function calculateDailyPriceChanges(string $tradingDate, string $assetType, float $minPrice = 0, float $maxPrice = 999999): array
    {
        $cacheKey = "gainers_losers:{$assetType}:{$tradingDate}:price_changes";
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            if ($minPrice > 0 || $maxPrice < 999999) {
                return array_values(array_filter($cached, fn ($r) => $r['close_price'] >= $minPrice && $r['close_price'] <= $maxPrice));
            }

            return $cached;
        }

        $lock = Cache::lock("lock:{$cacheKey}", 60);

        if ($lock->get()) {
            try {
                $result = $this->computeDailyPriceChanges($tradingDate, $assetType);
                Cache::put($cacheKey, $result, 86400); // 24 hours

                if ($minPrice > 0 || $maxPrice < 999999) {
                    return array_values(array_filter($result, fn ($r) => $r['close_price'] >= $minPrice && $r['close_price'] <= $maxPrice));
                }

                return $result;
            } finally {
                $lock->release();
            }
        }

        $result = Cache::get($cacheKey) ?? $this->computeDailyPriceChanges($tradingDate, $assetType);

        if ($minPrice > 0 || $maxPrice < 999999) {
            return array_values(array_filter($result, fn ($r) => $r['close_price'] >= $minPrice && $r['close_price'] <= $maxPrice));
        }

        return $result;
    }

    private function computeDailyPriceChanges(string $tradingDate, string $assetType): array
    {
        // Use a single optimized query to get both open and close prices
        $priceData = $this->dbSelect('
        WITH first_last_prices AS (
            SELECT
                symbol,
                MIN(ts_est) as first_ts,
                MAX(ts_est) as last_ts
            FROM '.$this->fiveMinuteTable.'
            WHERE asset_type = ?
              AND trading_date_est = ?
            GROUP BY symbol
        ),
        open_prices AS (
            SELECT
                fmp.symbol,
                fmp.price as open_price,
                ai.id as asset_id
            FROM '.$this->fiveMinuteTable.' fmp
            JOIN first_last_prices flp ON fmp.symbol = flp.symbol AND fmp.ts_est = flp.first_ts
            LEFT JOIN asset_info ai ON fmp.symbol = ai.symbol
                AND fmp.asset_type = ai.asset_type
                AND ai.deleted_at IS NULL
            WHERE fmp.asset_type = ?
        ),
        close_prices AS (
            SELECT
                fmp.symbol,
                fmp.price as close_price
            FROM '.$this->fiveMinuteTable.' fmp
            JOIN first_last_prices flp ON fmp.symbol = flp.symbol AND fmp.ts_est = flp.last_ts
            WHERE fmp.asset_type = ?
        )
        SELECT
            op.symbol,
            op.open_price,
            cp.close_price,
            op.asset_id
        FROM open_prices op
        JOIN close_prices cp ON op.symbol = cp.symbol
        WHERE op.open_price > 0
        ORDER BY op.symbol
    ', [$assetType, $tradingDate, $assetType, $assetType]);

        // Calculate percentage changes
        foreach ($priceData as $row) {
            $openPrice = (float) $row->open_price;
            $closePrice = (float) $row->close_price;

            if ($openPrice <= 0) {
                continue;
            }

            $changePct = (($closePrice - $openPrice) / $openPrice) * 100;
            $changeAbs = $closePrice - $openPrice;

            $results[] = [
                'symbol' => $row->symbol,
                'asset_type' => $assetType,
                'asset_id' => $row->asset_id,
                'open_price' => $openPrice,
                'close_price' => $closePrice,
                'change_abs' => $changeAbs,
                'change_pct' => $changePct,
            ];
        }

        return $results;
    }

    /**
     * Get analysis summary for display.
     */
    public function getAnalysisSummary(string $tradingDate, string $assetType): array
    {
        $totalSymbols = DB::table($this->fiveMinuteTable)
            ->where('asset_type', $assetType)
            ->where('trading_date_est', $tradingDate)
            ->distinct('symbol')
            ->count('symbol');

        return [
            'trading_date' => $tradingDate,
            'asset_type' => $assetType,
            'total_symbols_analyzed' => $totalSymbols,
            'analysis_description' => 'Daily gainers and losers based on market open to close price changes',
        ];
    }
}
