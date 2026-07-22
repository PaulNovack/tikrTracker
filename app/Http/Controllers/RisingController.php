<?php

namespace App\Http\Controllers;

use App\Models\AssetInfo;
use App\Models\DailyPrice;
use App\Models\FiveMinutePrice;
use App\Models\HourlyPrice;
use App\Models\MarketSchedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class RisingController extends Controller
{
    public function index(): \Inertia\Response|\Illuminate\Http\RedirectResponse
    {
        ini_set('memory_limit', '2048M');

        $assetTypeFilter = request('filter', 'stock');
        $selectedDate = request('date'); // YYYY-MM-DD, null = today

        if (! in_array($assetTypeFilter, ['stock', 'crypto'])) {
            return redirect('/rising?filter=stock');
        }

        $cacheKey = $selectedDate
            ? "rising_simple_{$assetTypeFilter}_{$selectedDate}"
            : "rising_simple_{$assetTypeFilter}";

        $data = Cache::get($cacheKey);

        if ($data === null) {
            $data = $this->getSimpleRisingStocksFromCache($assetTypeFilter, $selectedDate);
            Cache::put($cacheKey, $data, 120);
            \Log::info("Cache miss for rising: {$assetTypeFilter}".($selectedDate ? " date:{$selectedDate}" : ''));
        }

        return Inertia::render('Rising', [
            'stocks' => $data['stocks'],
            'timeRanges' => $data['timeRanges'] ?? [1 => '1d', 2 => '2d', 3 => '3d', 5 => '5d', 7 => '7d'],
            'selectedTimestamp' => $data['timestamp'],
            'selectedTimestampEst' => now()->setTimezone('America/New_York')->format('Y-m-d\TH:i'),
            'dataSource' => $data['dataSource'] ?? 'hourly',
            'assetTypeFilter' => $assetTypeFilter,
            'filters' => [
                'date' => $selectedDate,
            ],
        ]);
    }

    private function getSimpleRisingStocks(string $assetTypeFilter, ?string $selectedDate = null): array
    {
        ini_set('memory_limit', '2048M');

        $timeRanges = [1 => '1d', 2 => '2d', 3 => '3d', 5 => '5d', 7 => '7d'];

        $now = $selectedDate ? \Carbon\Carbon::parse($selectedDate) : now();
        $cutoffDate = $now->copy()->subDays(7)->format('Y-m-d');
        $lookbackDate = $now->copy()->subDays(14)->format('Y-m-d');

        // Get active symbols that have data up to the selected date
        $activeSymbols = DailyPrice::query()
            ->select('symbol')
            ->where('date', '>=', $cutoffDate)
            ->where('date', '<=', $selectedDate ?: now()->format('Y-m-d'))
            ->when($assetTypeFilter !== 'all', fn ($q) => $q->where('asset_type', $assetTypeFilter))
            ->distinct()
            ->orderBy('symbol')
            ->pluck('symbol')
            ->toArray();

        if (empty($activeSymbols)) {
            return [
                'stocks' => [],
                'timestamp' => null,
                'timeRanges' => $timeRanges,
                'dataSource' => 'daily',
            ];
        }

        // Get price data for active symbols
        $allPrices = DailyPrice::query()
            ->select(['symbol', 'asset_type', 'date', 'price'])
            ->where('date', '>=', $lookbackDate)
            ->whereIn('symbol', $activeSymbols)
            ->when($assetTypeFilter !== 'all', fn ($q) => $q->where('asset_type', $assetTypeFilter))
            ->orderBy('symbol')
            ->orderBy('date')
            ->get();

        // Get asset info
        $assetInfoMap = AssetInfo::whereIn('symbol', $activeSymbols)
            ->get(['symbol', 'id', 'asset_type', 'common_name'])
            ->keyBy('symbol');

        // Group by symbol
        $pricesBySymbol = $allPrices->groupBy('symbol');
        $stocks = [];

        foreach ($pricesBySymbol as $symbol => $prices) {
            if ($prices->count() < 2) {
                continue;
            }

            $pricesByDate = $prices->keyBy('date');
            $latestPrice = $prices->last();
            $currentPrice = (float) $latestPrice->price;

            // Calculate changes for each time range
            $changes = [];
            $hasSignificantRise = false;

            foreach ($timeRanges as $days => $label) {
                // Find opening price by going back the specified number of BUSINESS days
                $openingPrice = null;
                $openingDate = null;

                // Start from the latest price date and go back looking for the right business day using market schedule
                $businessDaysBack = 0;
                $checkDate = clone $latestPrice->date;
                $cutoffDate = now()->subMonths(1);

                while ($businessDaysBack < $days && $checkDate->isAfter($cutoffDate)) {
                    $checkDate = $checkDate->subDay();

                    // Check if this date is a market open day using market_schedules table
                    $marketSchedule = MarketSchedule::where('date', $checkDate->format('Y-m-d'))
                        ->where('market_type', 'stock')
                        ->where('status', 'open')
                        ->first();

                    if ($marketSchedule) {
                        $businessDaysBack++;

                        $dateString = $checkDate->format('Y-m-d');
                        if ($pricesByDate->has($dateString)) {
                            $openingPrice = (float) $pricesByDate->get($dateString)->price;
                            $openingDate = $dateString;
                            break;
                        }
                    }
                }

                if ($openingPrice && $openingPrice > 0) {
                    $percentChange = (($currentPrice - $openingPrice) / $openingPrice) * 100;
                    $changes[$days] = [
                        'percent' => round($percentChange, 2),
                        'price' => round($currentPrice, 2),
                        'open' => round($openingPrice, 2),  // Opening price (X business days ago)
                        'close' => round($currentPrice, 2),  // Current price (closing)
                    ];

                    // Check if this is a significant rise (1%+ in any timeframe)
                    if ($percentChange >= 1.0) {
                        $hasSignificantRise = true;
                    }
                } else {
                    $changes[$days] = null;
                }
            }

            // Only include stocks with significant rises
            if ($hasSignificantRise) {
                $assetInfo = $assetInfoMap->get($symbol);

                $stocks[] = [
                    'symbol' => $symbol,
                    'asset_type' => $assetInfo ? $assetInfo->asset_type : $latestPrice->asset_type,
                    'current_price' => round($currentPrice, 2),
                    'changes' => $changes,
                    'asset_info_id' => $assetInfo ? $assetInfo->id : null,
                    'common_name' => $assetInfo ? $assetInfo->common_name : $symbol,
                ];
            }
        }

        // Sort by best 1-day performance and take top 100
        usort($stocks, function ($a, $b) {
            $aPercent = $a['changes'][1]['percent'] ?? 0;
            $bPercent = $b['changes'][1]['percent'] ?? 0;

            return $bPercent <=> $aPercent;
        });

        $stocks = array_slice($stocks, 0, 100);

        return [
            'stocks' => $stocks,
            'timestamp' => now()->format('Y-m-d H:i:s'),
            'timeRanges' => $timeRanges,
            'dataSource' => 'daily_cached',
        ];
    }

    private function getSimpleRisingStocksFromCache(string $assetTypeFilter, ?string $selectedDate = null): array
    {
        $optimizedCacheKey = "rising_stocks_optimized_{$assetTypeFilter}".($selectedDate ? "_{$selectedDate}" : '');
        $cachedData = Cache::get($optimizedCacheKey);

        if ($cachedData) {
            return $cachedData;
        }

        return $this->calculateRisingStocksOptimized($assetTypeFilter, $selectedDate);
    }

    private function calculateRisingStocksOptimized(string $assetTypeFilter): array
    {
        $timeRanges = [1 => '1d', 2 => '2d', 3 => '3d', 5 => '5d', 7 => '7d'];

        // Get latest 10 trading dates
        $latestDates = DB::select('
            SELECT DISTINCT trading_date_est
            FROM five_minute_prices
            WHERE asset_type = ? AND trading_date_est IS NOT NULL
            ORDER BY trading_date_est DESC
            LIMIT 10
        ', [$assetTypeFilter]);

        if (empty($latestDates)) {
            return [
                'stocks' => [],
                'timestamp' => null,
                'timeRanges' => $timeRanges,
                'dataSource' => 'no_data',
            ];
        }

        $datesList = array_map(fn ($d) => $d->trading_date_est, $latestDates);

        // Use GROUP_CONCAT for efficient price retrieval, only for symbols with active asset_info
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

        $results = DB::select($query, [$assetTypeFilter, $assetTypeFilter]);

        // Process results into rising stocks
        $stockData = [];
        foreach ($results as $row) {
            $symbol = $row->symbol;
            $dateRank = (int) $row->date_rank;

            $times = explode(',', $row->trading_times);
            $prices = array_map('floatval', explode(',', $row->prices));
            $dayPrices = array_combine($times, $prices);

            // Current price priority: close > mid > open
            $currentPrice = $dayPrices['15:55:00'] ?? $dayPrices['12:55:00'] ?? $dayPrices['09:30:00'] ?? null;

            // Opening price (market open)
            $openPrice = $dayPrices['09:30:00'] ?? null;

            if (! isset($stockData[$symbol])) {
                $stockData[$symbol] = [];
            }

            $stockData[$symbol][$dateRank] = [
                'trading_date' => $row->trading_date_est,
                'current_price' => $currentPrice,
                'open_price' => $openPrice,
            ];
        }

        // Calculate changes and filter for rising stocks
        $risingStocks = [];
        foreach ($stockData as $symbol => $dateData) {
            if (! isset($dateData[1]) || ! $dateData[1]['current_price']) {
                continue;
            }

            $currentPrice = $dateData[1]['current_price'];
            $changes = [];
            $hasSignificantRise = false;

            foreach ($timeRanges as $days => $label) {
                if ($days === 1) {
                    // For 1-day change, use same-day open to close
                    if (isset($dateData[1]) && $dateData[1]['current_price']) {
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
                    $targetRank = $days + 1;

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

            if ($hasSignificantRise) {
                $risingStocks[] = [
                    'symbol' => $symbol,
                    'current_price' => round($currentPrice, 2),
                    'changes' => $changes,
                ];
            }
        }

        // Sort and limit
        usort($risingStocks, fn ($a, $b) => ($b['changes'][1]['percent'] ?? 0) <=> ($a['changes'][1]['percent'] ?? 0));
        $risingStocks = array_slice($risingStocks, 0, 100);

        // Add company names and asset info IDs (only active records since we filtered in the main query)
        $assetInfoMap = AssetInfo::whereIn('symbol', array_column($risingStocks, 'symbol'))->get()->keyBy('symbol');
        foreach ($risingStocks as &$stock) {
            $info = $assetInfoMap->get($stock['symbol']);
            $stock['company_name'] = $info?->common_name ?? $stock['symbol'];
            $stock['asset_info_id'] = $info?->id ?? null;
            $stock['asset_type'] = $info?->asset_type ?? 'stock';
        }

        return [
            'stocks' => $risingStocks,
            'timestamp' => $datesList[0] ?? now()->format('Y-m-d'),
            'timeRanges' => $timeRanges,
            'dataSource' => 'est_optimized_direct',
        ];
    }

    private function getRecentIntradayRisingStocks(string $assetTypeFilter, int $hoursBack = 2): array
    {
        // Cache key for this request
        $cacheKey = "rising_stocks_intraday_{$assetTypeFilter}_{$hoursBack}h";

        // Cache for 2 minutes for intraday data (more frequent updates)
        return Cache::remember($cacheKey, 120, function () use ($assetTypeFilter, $hoursBack) {
            return $this->calculateRecentIntradayBulk($assetTypeFilter, $hoursBack);
        });
    }

    private function calculateRecentIntradayBulk(string $assetTypeFilter, int $hoursBack): array
    {
        // Get 5-minute data from the last X hours
        $timeWindow = now()->subHours($hoursBack)->format('Y-m-d H:i:s');

        // Get latest timestamp with data in our time window
        $latestTimestamp = FiveMinutePrice::query()
            ->where('ts', '>=', $timeWindow)
            ->when($assetTypeFilter !== 'all', fn ($q) => $q->where('asset_type', $assetTypeFilter))
            ->orderByDesc('ts')
            ->value('ts');

        if (! $latestTimestamp) {
            return [
                'stocks' => [],
                'timestamp' => null,
                'timeRanges' => [5 => '5m', 10 => '10m', 15 => '15m'], // Format for React component
                'dataSource' => 'none',
                'hoursBack' => $hoursBack,
                'activeSymbols' => 0,
            ];
        }

        // Focus on short-term movements: 5m, 10m, 15m
        $timeRanges = [5 => '5m', 10 => '10m', 15 => '15m'];
        $stocks = [];

        // Get symbols with recent data (last 30 minutes for active trading)
        $recentCutoff = date('Y-m-d H:i:s', strtotime('-30 minutes', strtotime($latestTimestamp)));

        $activeSymbols = FiveMinutePrice::query()
            ->where('ts', '>=', $recentCutoff)
            ->where('ts', '<=', $latestTimestamp)
            ->when($assetTypeFilter !== 'all', fn ($q) => $q->where('asset_type', $assetTypeFilter))
            ->distinct('symbol')
            ->pluck('symbol');

        foreach ($activeSymbols as $symbol) {
            $stockData = $this->calculateShortTermChanges($symbol, $latestTimestamp);
            if ($stockData && $this->isRisingSignificantly($stockData['changes'], 0.02)) { // Lower threshold for short-term (0.02%)
                $stocks[] = $stockData;
            }
        }

        // Sort by highest 5-minute change, then 10-minute
        usort($stocks, function ($a, $b) {
            $aChange = $a['changes'][5]['percent'] ?? $a['changes'][10]['percent'] ?? 0;
            $bChange = $b['changes'][5]['percent'] ?? $b['changes'][10]['percent'] ?? 0;

            return $bChange <=> $aChange;
        });

        // Determine data freshness label
        $hoursAge = now()->diffInHours($latestTimestamp);
        $freshnessLabel = $hoursAge < 1 ? 'recent' : ($hoursAge < 6 ? 'extended' : 'today');

        return [
            'stocks' => array_slice($stocks, 0, 100), // Top 100 rising
            'timestamp' => $latestTimestamp,
            'timeRanges' => $timeRanges,
            'dataSource' => $freshnessLabel,
            'hoursBack' => $hoursBack,
            'activeSymbols' => count($activeSymbols),
        ];
    }

    private function calculateShortTermChanges(string $symbol, string $currentTimestamp): ?array
    {
        $current = FiveMinutePrice::where('symbol', $symbol)
            ->where('ts', $currentTimestamp)
            ->first();

        if (! $current) {
            return null;
        }

        // Get asset info for this symbol
        $assetInfo = AssetInfo::where('symbol', $symbol)
            ->where('asset_type', $current->asset_type)
            ->first();

        if (! $assetInfo) {
            return null; // Skip if no asset info found
        }

        $stockData = [
            'symbol' => $symbol,
            'asset_type' => $current->asset_type,
            'current_price' => round($current->price, 2),
            'changes' => [],
            'asset_info_id' => $assetInfo->id,
            'company_name' => $assetInfo->common_name,
        ];

        // Focus on short-term timeframes: 5, 10, 15 minutes (using numeric keys for React)
        $timeRanges = [5, 10, 15]; // minutes as numbers

        foreach ($timeRanges as $minutes) {
            $pastTime = date('Y-m-d H:i:s', strtotime("-{$minutes} minutes", strtotime($currentTimestamp)));

            // Find the closest price within 2 minutes of target time
            $pastRecord = FiveMinutePrice::where('symbol', $symbol)
                ->where('ts', '>=', date('Y-m-d H:i:s', strtotime('-2 minutes', strtotime($pastTime))))
                ->where('ts', '<=', date('Y-m-d H:i:s', strtotime('+2 minutes', strtotime($pastTime))))
                ->orderBy(DB::raw("ABS(TIMESTAMPDIFF(SECOND, ts, '{$pastTime}'))"))
                ->first();

            if ($pastRecord && $pastRecord->price > 0) {
                $change = $current->price - $pastRecord->price;
                $percentChange = ($change / $pastRecord->price) * 100;

                // Use numeric keys for React component compatibility
                $stockData['changes'][$minutes] = [
                    'percent' => round($percentChange, 2),
                    'price' => round($pastRecord->price, 2),
                    'timestamp' => $pastRecord->ts,
                    'open' => round($pastRecord->price, 2),  // Baseline (starting point)
                    'close' => round($current->price, 2),   // Current price (ending point)
                ];
            }
        }

        // Only return if we have at least one valid change
        return ! empty($stockData['changes']) ? $stockData : null;
    }

    private function getHourlyRisingStocks(string $assetTypeFilter): array
    {
        // Cache key for this request
        $cacheKey = "rising_stocks_hourly_{$assetTypeFilter}";

        // Cache for 10 minutes for hourly data
        return Cache::remember($cacheKey, 600, function () use ($assetTypeFilter) {
            return $this->calculateHourlyRisingBulk($assetTypeFilter);
        });
    }

    private function calculateHourlyRisingBulk(string $assetTypeFilter): array
    {
        // Get latest hourly data
        $latestTimestamp = HourlyPrice::query()
            ->when($assetTypeFilter !== 'all', fn ($q) => $q->where('asset_type', $assetTypeFilter))
            ->orderByDesc('ts')
            ->value('ts');

        if (! $latestTimestamp) {
            return ['stocks' => [], 'timestamp' => null, 'timeRanges' => []];
        }

        // Only proceed if data is from last 24 hours
        $dayAgo = now()->subDay()->format('Y-m-d H:i:s');
        if ($latestTimestamp < $dayAgo) {
            return ['stocks' => [], 'timestamp' => null, 'timeRanges' => []];
        }

        $timeRanges = ['1h', '2h', '3h', '6h'];
        $stocks = [];

        // Get symbols with recent hourly data
        $symbols = HourlyPrice::query()
            ->where('ts', '>=', now()->subHours(6)->format('Y-m-d H:i:s'))
            ->when($assetTypeFilter !== 'all', fn ($q) => $q->where('asset_type', $assetTypeFilter))
            ->distinct('symbol')
            ->pluck('symbol');

        foreach ($symbols as $symbol) {
            $stockData = $this->calculateHourlyChanges($symbol);
            if ($stockData && $this->isRisingSignificantly($stockData['changes'], 0.25)) { // 0.25% threshold for hourly
                $stocks[] = $stockData;
            }
        }

        // Sort by highest 1h change
        usort($stocks, function ($a, $b) {
            $aChange = $a['changes']['1h']['percent'] ?? 0;
            $bChange = $b['changes']['1h']['percent'] ?? 0;

            return $bChange <=> $aChange;
        });

        return [
            'stocks' => array_slice($stocks, 0, 100),
            'timestamp' => $latestTimestamp,
            'timeRanges' => $timeRanges,
            'dataSource' => 'hourly',
        ];
    }

    private function getDailyRisingStocks(string $assetTypeFilter): array
    {
        // Cache key for this request
        $cacheKey = "rising_stocks_daily_{$assetTypeFilter}";

        // Try to get from cache first (5 minute cache)
        return Cache::remember($cacheKey, 300, function () use ($assetTypeFilter) {
            return $this->calculateDailyRisingStocksBulk($assetTypeFilter);
        });
    }

    private function calculateDailyRisingStocksBulk(string $assetTypeFilter): array
    {
        $timeRanges = [1 => '1d', 2 => '2d', 3 => '3d', 5 => '5d', 7 => '7d'];

        // Get the most recent 7 days of data in bulk (reduced from 10 to manage memory)
        $lookbackDate = now()->subDays(7)->format('Y-m-d');

        // Limit to recent active stocks to prevent memory issues
        $cutoffDate = now()->subDays(2)->format('Y-m-d');
        $activeSymbols = DailyPrice::query()
            ->select('symbol')
            ->where('date', '>=', $cutoffDate)
            ->when($assetTypeFilter !== 'all', fn ($q) => $q->where('asset_type', $assetTypeFilter))
            ->distinct()
            ->orderBy('symbol')
            // Remove limit to analyze ALL stocks (including SMX and others)
            ->pluck('symbol')
            ->toArray();

        if (empty($activeSymbols)) {
            return [
                'stocks' => [],
                'timestamp' => null,
                'timeRanges' => $timeRanges,
                'dataSource' => 'daily',
            ];
        }

        // Targeted query for active symbols only
        $allPrices = DailyPrice::query()
            ->select(['symbol', 'asset_type', 'date', 'price'])
            ->where('date', '>=', $lookbackDate)
            ->whereIn('symbol', $activeSymbols)
            ->when($assetTypeFilter !== 'all', fn ($q) => $q->where('asset_type', $assetTypeFilter))
            ->orderBy('symbol')
            ->orderBy('date')
            ->get()
            ->groupBy('symbol');

        // Pre-fetch all AssetInfo records in one query - ALL stocks
        $assetInfoMap = AssetInfo::query()
            ->when($assetTypeFilter !== 'all', fn ($q) => $q->where('asset_type', $assetTypeFilter))
            ->get()
            ->keyBy(function ($asset) {
                return $asset->symbol.'_'.$asset->asset_type;
            });

        // Check if we need five-minute data fallback
        $latestFiveMinData = $this->getFiveMinuteFallbackData($assetTypeFilter, $allPrices->keys());

        $stocks = [];
        foreach ($allPrices as $symbol => $prices) {
            if ($prices->count() < 2) {
                continue;
            }

            $stockData = $this->processStockDataOptimized($symbol, $prices, $latestFiveMinData, $assetInfoMap);
            if ($stockData) {
                $stocks[] = $stockData;
            }
        }

        // Sort by 1-day change
        usort($stocks, function ($a, $b) {
            return $b['changes'][1]['percent'] <=> $a['changes'][1]['percent'];
        });

        return [
            'stocks' => array_slice($stocks, 0, 100), // Limit to top 100 for performance
            'timestamp' => $allPrices->isNotEmpty() ? $allPrices->first()->last()->date : null,
            'timeRanges' => $timeRanges,
            'dataSource' => 'daily',
        ];
    }

    private function getFiveMinuteFallbackData(string $assetTypeFilter, $symbols): array
    {
        // Get the most recent five-minute date
        $latestFiveMin = FiveMinutePrice::query()
            ->selectRaw('DATE(MAX(ts)) as latest_date')
            ->when($assetTypeFilter !== 'all', fn ($q) => $q->where('asset_type', $assetTypeFilter))
            ->value('latest_date');

        if (! $latestFiveMin) {
            return [];
        }

        // PERFORMANCE OPTIMIZATION: Bulk query instead of N+1 queries
        // Get all latest daily dates for all symbols in one query
        $latestDailyDates = DailyPrice::query()
            ->select('symbol', DB::raw('MAX(date) as latest_date'))
            ->whereIn('symbol', $symbols)
            ->when($assetTypeFilter !== 'all', fn ($q) => $q->where('asset_type', $assetTypeFilter))
            ->groupBy('symbol')
            ->pluck('latest_date', 'symbol');

        // Get all five-minute data for the latest date in bulk
        $allFiveMinPrices = FiveMinutePrice::query()
            ->select('symbol', 'asset_type', 'price', 'open', 'ts')
            ->whereIn('symbol', $symbols)
            ->whereDate('ts', $latestFiveMin)
            ->when($assetTypeFilter !== 'all', fn ($q) => $q->where('asset_type', $assetTypeFilter))
            ->orderBy('symbol')
            ->orderBy('ts')
            ->get()
            ->groupBy('symbol');

        // Get all previous closing prices in bulk
        $allPrevClosingPrices = FiveMinutePrice::query()
            ->select('symbol', 'asset_type', 'price', 'ts')
            ->whereIn('symbol', $symbols)
            ->where('ts', '<', $latestFiveMin.' 00:00:00')
            ->when($assetTypeFilter !== 'all', fn ($q) => $q->where('asset_type', $assetTypeFilter))
            ->orderBy('ts', 'desc')
            ->get()
            ->groupBy('symbol')
            ->map(fn ($prices) => $prices->first()); // Get most recent for each symbol

        $results = [];

        // Process symbols that need five-minute fallback
        foreach ($symbols as $symbol) {
            $latestDailyForSymbol = $latestDailyDates[$symbol] ?? null;

            // If we have five-minute data but no daily data, or five-minute data is newer or same date, process it
            if (! $latestDailyForSymbol || $latestFiveMin >= $latestDailyForSymbol) {
                $prices = $allFiveMinPrices[$symbol] ?? collect();

                if ($prices->isNotEmpty()) {
                    $first = $prices->first();
                    $last = $prices->last();

                    // Convert to EST for proper special session detection
                    $startTimeEst = $first->ts->setTimezone('America/New_York');
                    $endTimeEst = $last->ts->setTimezone('America/New_York');

                    // Apply enhanced special session detection
                    $lateStartThreshold = $startTimeEst->copy()->setTime(13, 0);
                    $earlyEndThreshold = $startTimeEst->copy()->setTime(14, 0);
                    $sessionDuration = $startTimeEst->diffInHours($endTimeEst, false);

                    $isLateStart = $startTimeEst->isAfter($lateStartThreshold);
                    $isEarlyEnd = $endTimeEst->isBefore($earlyEndThreshold);
                    $isShortSession = $sessionDuration < 4;

                    $isSpecialSession = $isLateStart || $isEarlyEnd || $isShortSession;

                    $result = [
                        'current_price' => $last->price,
                        'opening_price' => $first->open ?: $first->price,
                        'date' => $latestFiveMin,
                        'is_special_session' => $isSpecialSession,
                        'asset_type' => $last->asset_type,
                    ];

                    // For normal sessions, get the previous closing price from bulk data
                    if (! $isSpecialSession && isset($allPrevClosingPrices[$symbol])) {
                        $prevClosing = $allPrevClosingPrices[$symbol];
                        $result['previous_close_price'] = $prevClosing->price;
                    }

                    $results[$symbol] = $result;
                }
            }
        }

        return $results;
    }

    private function processStockDataOptimized(string $symbol, $prices, array $fiveMinData, $assetInfoMap): ?array
    {
        $latest = $prices->last();
        $previous = $prices->slice(-2, 1)->first();

        if (! $latest || ! $previous || $previous->price <= 0) {
            return null;
        }

        // Check if data is too stale
        if (now()->diffInDays($latest->date) > 5) {
            return null;
        }

        // Use five-minute data for current price if available and more recent
        $currentPrice = $latest->price;
        $currentDate = $latest->date;

        if (isset($fiveMinData[$symbol])) {
            $fiveMin = $fiveMinData[$symbol];
            $currentPrice = $fiveMin['current_price'];
            $currentDate = $fiveMin['date'];
        }

        // Get proper 1-day baseline: first 5-minute price of the trading day (opening price)
        $oneDayBaseline = $this->getProperOneDayBaseline($symbol, $fiveMinData);

        if (! $oneDayBaseline) {
            // Fallback to daily data if no five-minute baseline available
            $oneDayBaseline = $previous->price;
        }

        $change1d = (($currentPrice - $oneDayBaseline) / $oneDayBaseline) * 100;

        // Get trading dates for open/close price calculation
        $tradingDates = DB::table('five_minute_prices')
            ->where('symbol', $symbol)
            ->selectRaw('DATE(ts) as trading_date')
            ->groupBy(DB::raw('DATE(ts)'))
            ->orderBy('trading_date', 'desc')
            ->take(10)
            ->pluck('trading_date')
            ->toArray();

        // Get open and close prices for 1-day calculation
        // For 1D: Open = baseline (yesterday's close), Close = current price
        $changes = [1 => [
            'percent' => round($change1d, 2),
            'price' => round($oneDayBaseline, 2),
            'open' => round($oneDayBaseline, 2),  // Baseline (starting point)
            'close' => round($currentPrice, 2),    // Current price (ending point)
        ]];

        // Quick check if it's worth calculating other timeframes
        if ($change1d <= 0.5) {
            return null;
        }

        // Calculate other timeframes using proper baselines
        $orderedPrices = $prices->sortBy('date');
        $priceCount = $orderedPrices->count();

        // Add longer timeframes if available
        $timeFrames = [
            2 => 3, 3 => 4, 5 => 6, 7 => 8,  // days => required price count
        ];

        foreach ($timeFrames as $days => $requiredCount) {
            if ($priceCount >= $requiredCount) {
                // Use the trading dates we already calculated to get the baseline
                if (count($tradingDates) > $days) {
                    $targetDate = $tradingDates[$days];

                    // Get the last actual trading price of that specific trading day
                    $multiDayBaseline = FiveMinutePrice::query()
                        ->where('symbol', $symbol)
                        ->whereDate('ts', $targetDate)
                        ->orderBy('ts', 'desc')
                        ->value('price');
                } else {
                    $multiDayBaseline = null;
                }

                if ($multiDayBaseline && $multiDayBaseline > 0) {
                    $totalChange = (($currentPrice - $multiDayBaseline) / $multiDayBaseline) * 100;

                    // Open/Close should represent the actual range being measured
                    // Open = baseline price, Close = current price
                    $changes[$days] = [
                        'percent' => round($totalChange, 2),  // Show total change, not divided by days
                        'price' => round($multiDayBaseline, 2),
                        'open' => round($multiDayBaseline, 2), // Baseline (starting point)
                        'close' => round($currentPrice, 2),     // Current price (ending point)
                    ];
                }
            }
        }

        // Check if stock qualifies (any significant change - positive OR negative)
        $qualifying = abs($change1d) > 0.5;

        // Check all timeframes for qualification (using total percentages)
        foreach ([2, 3, 5, 7] as $days) {
            if (isset($changes[$days])) {
                $threshold = match ($days) {
                    2 => 1.0, 3 => 1.5, 5 => 2.5, 7 => 3.5  // Higher thresholds for longer timeframes
                };
                if (abs($changes[$days]['percent']) > $threshold) {
                    $qualifying = true;
                    break;
                }
            }
        }

        if (! $qualifying) {
            return null;
        }

        // Check AssetInfo exists
        $assetInfoKey = $symbol.'_'.$latest->asset_type;
        $assetInfo = $assetInfoMap->get($assetInfoKey);
        if (! $assetInfo) {
            return null;
        }

        return [
            'symbol' => $symbol,
            'asset_type' => $latest->asset_type,
            'current_price' => round($currentPrice, 2),
            'changes' => $changes,
            'asset_info_id' => $assetInfo->id,
        ];
    }

    private function calculateHourlyChanges(string $symbol): ?array
    {
        $current = HourlyPrice::where('symbol', $symbol)
            ->orderByDesc('ts')
            ->first();

        if (! $current) {
            return null;
        }

        // Get asset info for this symbol
        $assetInfo = AssetInfo::where('symbol', $symbol)
            ->where('asset_type', $current->asset_type)
            ->first();

        if (! $assetInfo) {
            return null; // Skip if no asset info found
        }

        $stockData = [
            'symbol' => $symbol,
            'asset_type' => $current->asset_type,
            'current_price' => round($current->price, 2),
            'changes' => [],
            'asset_info_id' => $assetInfo->id,
            'company_name' => $assetInfo->common_name,
        ];

        $timeRanges = [1, 2, 3, 6]; // hours

        foreach ($timeRanges as $hours) {
            // Look for actual hourly records at specific hour boundaries
            $targetTime = date('Y-m-d H:00:00', strtotime("-{$hours} hours", strtotime($current->ts)));

            // Find the closest hourly record before or at the target time
            $pastRecord = HourlyPrice::where('symbol', $symbol)
                ->where('ts', '<=', $targetTime)
                ->orderByDesc('ts')
                ->first();

            // If no exact match, try finding any record within a reasonable window (±2 hours)
            if (! $pastRecord) {
                $windowStart = date('Y-m-d H:00:00', strtotime('-'.($hours + 2).' hours', strtotime($current->ts)));
                $windowEnd = date('Y-m-d H:00:00', strtotime('-'.($hours - 1).' hours', strtotime($current->ts)));

                $pastRecord = HourlyPrice::where('symbol', $symbol)
                    ->whereBetween('ts', [$windowStart, $windowEnd])
                    ->orderByDesc('ts')
                    ->first();
            }

            if ($pastRecord && $pastRecord->price > 0 && $pastRecord->id !== $current->id) {
                $change = $current->price - $pastRecord->price;
                $percentChange = ($change / $pastRecord->price) * 100;

                $stockData['changes'][$hours.'h'] = [
                    'percent' => round($percentChange, 2),
                    'price' => round($pastRecord->price, 2),
                    'open' => round($pastRecord->price, 2),  // Baseline (starting point)
                    'close' => round($current->price, 2),   // Current price (ending point)
                ];
            }
        }

        // Only return if we have at least one valid change calculation
        return count($stockData['changes']) > 0 ? $stockData : null;
    }

    private function isRisingSignificantly(array $changes, float $threshold = 0.2): bool
    {
        foreach ($changes as $timeframe => $change) {
            if (isset($change['percent']) && $change['percent'] > $threshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get proper 1-day baseline: first 5-minute price of the most recent trading day
     */
    private function getProperOneDayBaseline(string $symbol, array $fiveMinData): ?float
    {
        if (! isset($fiveMinData[$symbol])) {
            return null;
        }

        $fiveMin = $fiveMinData[$symbol];

        // Use the actual first trading price of the most recent trading day
        $firstTradePrice = FiveMinutePrice::query()
            ->where('symbol', $symbol)
            ->whereDate('ts', $fiveMin['date'])
            ->orderBy('ts')
            ->value('price');

        return $firstTradePrice ?? $fiveMin['opening_price'] ?? null;
    }

    /**
     * Get proper multi-day baseline: last trading price N trading days ago (skips weekends and holidays)
     */
    private function getProperMultiDayBaseline(string $symbol, int $days, $orderedPrices, int $requiredCount): ?float
    {
        // Get actual trading dates for this symbol (excludes weekends and holidays automatically)
        $tradingDates = FiveMinutePrice::query()
            ->where('symbol', $symbol)
            ->selectRaw('DATE(ts) as trading_date')
            ->groupBy(DB::raw('DATE(ts)'))
            ->orderBy('trading_date', 'desc')
            ->take($days + 10) // Get extra dates to account for weekends/holidays
            ->pluck('trading_date')
            ->toArray();

        // Get the Nth trading day back (not calendar days)
        // Index 0 = most recent trading day, Index N = N trading days back
        if (count($tradingDates) > $days) {
            $targetDate = $tradingDates[$days];

            // Get the last actual trading price of that specific trading day
            $lastTradePrice = FiveMinutePrice::query()
                ->where('symbol', $symbol)
                ->whereDate('ts', $targetDate)
                ->orderBy('ts', 'desc')
                ->value('price');

            if ($lastTradePrice) {
                return $lastTradePrice;
            }
        }

        // Fallback to daily data (which should represent the closing price)
        $oldPrice = $orderedPrices->slice(-$requiredCount, 1)->first();

        return $oldPrice ? $oldPrice->price : null;
    }
}
