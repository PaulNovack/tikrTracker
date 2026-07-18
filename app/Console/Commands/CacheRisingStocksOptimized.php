<?php

namespace App\Console\Commands;

use App\Models\AssetInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CacheRisingStocksOptimized extends Command
{
    protected $signature = 'cache:rising-stocks-optimized {asset_type=stock}';

    protected $description = 'Cache rising stocks data using optimized GROUP_CONCAT approach with EST columns';

    public function handle(): int
    {
        $assetType = $this->argument('asset_type');
        $this->info("Caching rising stocks for {$assetType} assets using optimized approach...");

        $startTime = microtime(true);

        // Get the latest 10 trading dates for comparison
        $latestDates = DB::select('
            SELECT DISTINCT trading_date_est
            FROM five_minute_prices
            WHERE asset_type = ?
            AND trading_date_est IS NOT NULL
            ORDER BY trading_date_est DESC
            LIMIT 10
        ', [$assetType]);

        if (empty($latestDates)) {
            $this->error('No trading dates found');

            return 1;
        }

        $datesList = array_map(fn ($d) => $d->trading_date_est, $latestDates);
        $this->info('Latest trading dates: '.implode(', ', array_slice($datesList, 0, 5)));

        // Use GROUP_CONCAT to get key trading times efficiently, but only for symbols with active asset_info
        $query = "
            WITH price_data AS (
                SELECT 
                    f.symbol,
                    f.trading_date_est,
                    GROUP_CONCAT(f.trading_time_est ORDER BY f.trading_time_est) AS trading_times,
                    GROUP_CONCAT(f.price ORDER BY f.trading_time_est) AS prices
                FROM five_minute_prices f
                INNER JOIN asset_info ai ON ai.symbol = f.symbol AND ai.deleted_at IS NULL
                WHERE f.trading_time_est IN ('09:30:00', '12:55:00', '15:55:00')
                    AND f.trading_date_est IN ('".implode("','", $datesList)."')
                    AND f.asset_type = ?
                    AND ai.asset_type = ?
                GROUP BY f.symbol, f.trading_date_est
            ),
            ranked_dates AS (
                SELECT 
                    symbol,
                    trading_date_est,
                    trading_times,
                    prices,
                    ROW_NUMBER() OVER (PARTITION BY symbol ORDER BY trading_date_est DESC) as date_rank
                FROM price_data
            )
            SELECT 
                symbol,
                trading_date_est,
                trading_times,
                prices,
                date_rank
            FROM ranked_dates
            WHERE date_rank <= 7
            ORDER BY symbol, date_rank
        ";

        $results = DB::select($query, [$assetType, $assetType]);
        $queryTime = round(microtime(true) - $startTime, 2);
        $this->info("Query executed in {$queryTime} seconds, got ".count($results).' rows');

        // Process results into structured data
        $stockData = [];
        foreach ($results as $row) {
            $symbol = $row->symbol;
            $dateRank = (int) $row->date_rank;

            // Parse the concatenated data
            $times = explode(',', $row->trading_times);
            $prices = array_map('floatval', explode(',', $row->prices));

            // Create time => price mapping
            $dayPrices = array_combine($times, $prices);

            // Determine current price (prefer close, fallback to mid, then open)
            $currentPrice = $dayPrices['15:55:00'] ?? $dayPrices['12:55:00'] ?? $dayPrices['09:30:00'] ?? null;

            // Opening price (market open)
            $openPrice = $dayPrices['09:30:00'] ?? null;

            if (! isset($stockData[$symbol])) {
                $stockData[$symbol] = [];
            }

            $stockData[$symbol][$dateRank] = [
                'trading_date' => $row->trading_date_est,
                'times' => $times,
                'prices' => $dayPrices,
                'current_price' => $currentPrice,
                'open_price' => $openPrice,
            ];
        }

        // Calculate percentage changes for each timeframe
        $timeRanges = [1 => '1d', 2 => '2d', 3 => '3d', 5 => '5d', 7 => '7d'];
        $risingStocks = [];

        foreach ($stockData as $symbol => $dateData) {
            if (! isset($dateData[1])) {
                continue; // Skip if no current data
            }

            $currentPrice = $dateData[1]['current_price'];
            if (! $currentPrice || $currentPrice <= 0) {
                continue;
            }

            $changes = [];
            $hasSignificantRise = false;

            foreach ($timeRanges as $days => $label) {
                if ($days === 1) {
                    // For 1-day change, use same-day open to close
                    if (isset($dateData[1]) && $dateData[1]['current_price']) {
                        $currentPrice = $dateData[1]['current_price'];
                        $openPrice = $dateData[1]['open_price'] ?? null;

                        if ($openPrice && $openPrice > 0) {
                            $percentChange = (($currentPrice - $openPrice) / $openPrice) * 100;

                            $changes[$days] = [
                                'percent' => round($percentChange, 2),
                                'price' => round($currentPrice, 2),
                                'open' => round($openPrice, 2),
                                'close' => round($currentPrice, 2),
                            ];

                            if ($percentChange >= 1.0) {
                                $hasSignificantRise = true;
                            }
                        } else {
                            $changes[$days] = null;
                        }
                    } else {
                        $changes[$days] = null;
                    }
                } else {
                    // For multi-day changes, use previous trading day close to current close
                    $targetRank = $days + 1; // day 1 = current, day 2 = 1 trading day back, etc.

                    if (isset($dateData[$targetRank]) && $dateData[$targetRank]['current_price']) {
                        $comparisonPrice = $dateData[$targetRank]['current_price'];
                        $percentChange = (($currentPrice - $comparisonPrice) / $comparisonPrice) * 100;

                        $changes[$days] = [
                            'percent' => round($percentChange, 2),
                            'price' => round($currentPrice, 2),
                            'open' => round($comparisonPrice, 2),
                            'close' => round($currentPrice, 2),
                        ];

                        if ($percentChange >= 1.0) {
                            $hasSignificantRise = true;
                        }
                    } else {
                        $changes[$days] = null;
                    }
                }
            }

            // Only include stocks with significant rises
            if ($hasSignificantRise) {
                $risingStocks[] = [
                    'symbol' => $symbol,
                    'current_price' => round($currentPrice, 2),
                    'changes' => $changes,
                    'latest_date' => $dateData[1]['trading_date'],
                ];
            }
        }

        // Sort by best 1-day performance
        usort($risingStocks, function ($a, $b) {
            $aPercent = $a['changes'][1]['percent'] ?? 0;
            $bPercent = $b['changes'][1]['percent'] ?? 0;

            return $bPercent <=> $aPercent;
        });

        // Limit to top 100
        $risingStocks = array_slice($risingStocks, 0, 100);

        // Get company names and IDs (only active records since we filtered in the main query)
        $symbols = array_column($risingStocks, 'symbol');
        $assetInfo = AssetInfo::whereIn('symbol', $symbols)->get()->keyBy('symbol');

        foreach ($risingStocks as &$stock) {
            $info = $assetInfo->get($stock['symbol']);
            $stock['company_name'] = $info?->common_name ?? $stock['symbol'];
            $stock['asset_info_id'] = $info?->id ?? null;
            $stock['asset_type'] = $info?->asset_type ?? 'stock';
        }

        // Cache the results
        $cacheKey = "rising_stocks_optimized_{$assetType}";
        $cacheData = [
            'stocks' => $risingStocks,
            'timestamp' => now()->format('Y-m-d H:i:s'),
            'timeRanges' => $timeRanges,
            'dataSource' => 'est_optimized',
            'query_time' => $queryTime,
            'total_processed' => count($stockData),
        ];

        Cache::put($cacheKey, $cacheData, now()->addMinutes(5));

        $totalTime = round(microtime(true) - $startTime, 2);
        $this->info("Cached {$assetType} rising stocks data in {$totalTime} seconds");
        $this->info('Top 5 risers:');

        foreach (array_slice($risingStocks, 0, 5) as $stock) {
            $change = $stock['changes'][1]['percent'] ?? 0;
            $price = $stock['current_price'];
            $this->info("  {$stock['symbol']}: {$change}% (\${$price})");
        }

        return 0;
    }
}
