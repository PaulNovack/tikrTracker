<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class NotableAssetController extends Controller
{
    public function index(Request $request): \Inertia\Response
    {
        // Generate cache key based on current hour
        $cacheKey = 'notable-assets-'.now()->format('Y-m-d-H');

        // Try to get from cache first
        $cachedData = Cache::get($cacheKey);

        if ($cachedData === null) {
            // Cache miss - generate fresh data
            $cachedData = $this->calculateStagnationData();

            // Cache the fresh data for 30 minutes
            Cache::put($cacheKey, $cachedData, now()->addMinutes(30));

            // Optional: Log cache miss for monitoring
            \Log::info('Cache miss for notable-assets');
        }

        return Inertia::render('notable-assets', $cachedData);
    }

    private function calculateStagnationData(): array
    {
        // Get stagnation thresholds from .env
        $shortDays = (int) config('app.stagnation_short_days', 1);
        $longDays = (int) config('app.stagnation_long_days', 3);
        $flatThresholdPct = (float) config('app.stagnation_threshold_pct', 1.0);
        $goodPositivePct = (float) config('app.good_positive_pct', 2.5);
        $greatPositivePct = (float) config('app.great_positive_pct', 5.0);
        $negativeAlertPct = (float) config('app.negative_alert_pct', -2.5);

        // Get top 500 most active symbols from five_minute_prices, excluding soft deleted assets
        // Prioritize symbols with recent activity and higher trading volumes
        $symbols = DB::table('five_minute_prices', 'fmp')
            ->join('asset_info as ai', function ($join) {
                $join->on('fmp.symbol', '=', 'ai.symbol')
                    ->on('fmp.asset_type', '=', 'ai.asset_type');
            })
            ->whereNull('ai.deleted_at')
            ->where('fmp.ts', '>=', now()->subDays(7)) // Only consider symbols with data in last 7 days
            ->select('fmp.symbol')
            ->selectRaw('COUNT(*) as data_points')
            ->selectRaw('AVG(fmp.volume) as avg_volume')
            ->selectRaw('MAX(fmp.ts) as latest_data')
            ->groupBy('fmp.symbol')
            ->having('data_points', '>=', 50) // Must have at least 50 data points in last 7 days
            ->orderByDesc('latest_data') // Prioritize symbols with most recent data
            ->orderByDesc('avg_volume') // Then by average volume
            ->limit(500) // Limit to top 500 most active symbols
            ->pluck('fmp.symbol')
            ->toArray();

        if (empty($symbols)) {
            return [
                'stagnationData' => [],
                'shortDays' => $shortDays,
                'longDays' => $longDays,
                'flatThresholdPct' => $flatThresholdPct,
                'goodPositivePct' => $goodPositivePct,
                'greatPositivePct' => $greatPositivePct,
                'negativeAlertPct' => $negativeAlertPct,
                'marketSchedule' => $this->getRecentMarketSchedule(),
            ];
        }

        // Get symbols for stagnation analysis (volume-based for normal assets)
        $volumeBasedSymbols = $symbols;

        // Get additional symbols for high performers (performance-based)
        $highPerformerSymbols = $this->getTopPerformers();

        // Merge and deduplicate symbols
        $allSymbols = array_unique(array_merge($volumeBasedSymbols, $highPerformerSymbols));

        // Scan for stagnation on all symbols
        $stagnationData = $this->scanStagnation($allSymbols, $shortDays, $longDays, $flatThresholdPct);

        // Sort high performers by performance within the results
        $stagnationData = $this->sortHighPerformersByPerformance($stagnationData);

        // Get recent market schedules for frontend date calculations
        $marketSchedule = $this->getRecentMarketSchedule();

        // Calculate actual trading dates on backend for consistency
        $tradingDates = $this->calculateTradingDates();

        return [
            'stagnationData' => $stagnationData,
            'shortDays' => $shortDays,
            'longDays' => $longDays,
            'flatThresholdPct' => $flatThresholdPct,
            'goodPositivePct' => $goodPositivePct,
            'greatPositivePct' => $greatPositivePct,
            'negativeAlertPct' => $negativeAlertPct,
            'marketSchedule' => $marketSchedule,
            'tradingDates' => $tradingDates,
        ];
    }

    private function scanStagnation(array $symbols, int $shortDays, int $longDays, float $flatThresholdPct): array
    {
        $results = [];

        // Define all lookback periods (in days) - removed 7d
        $lookbackPeriods = [1, 3, 5, 15, 30];

        // Process symbols in optimized batches
        $symbolChunks = array_chunk($symbols, 100); // Increase batch size for better performance

        foreach ($symbolChunks as $symbolBatch) {
            $batchResults = $this->processBatchStagnationOptimized($symbolBatch, $shortDays, $longDays, $flatThresholdPct, $lookbackPeriods);
            $results = array_merge($results, $batchResults);
        }

        // Sort by intraday performance first (if available), then 5d performance
        usort($results, function ($a, $b) {
            // Prioritize intraday performance during market hours
            $aIntraday = $a['intraday_change_pct']['percent'] ?? null;
            $bIntraday = $b['intraday_change_pct']['percent'] ?? null;

            // If both have intraday data, sort by intraday performance
            if ($aIntraday !== null && $bIntraday !== null) {
                return $bIntraday <=> $aIntraday; // Highest intraday gains first
            }

            // If only one has intraday data, prioritize it
            if ($aIntraday !== null && $bIntraday === null) {
                return -1; // A comes first
            }
            if ($bIntraday !== null && $aIntraday === null) {
                return 1; // B comes first
            }

            // Fall back to 5d performance if no intraday data
            $aChange = $a['5d_change_pct']['percent'] ?? 0;
            $bChange = $b['5d_change_pct']['percent'] ?? 0;

            return $bChange <=> $aChange;
        });

        return $results;
    }

    private function processBatchStagnationOptimized(array $symbols, int $shortDays, int $longDays, float $flatThresholdPct, array $lookbackPeriods): array
    {
        $results = [];

        if (empty($symbols)) {
            return $results;
        }

        // Get latest prices for all symbols in batch in one query
        $latestPrices = DB::table('five_minute_prices')
            ->whereIn('symbol', $symbols)
            ->select('symbol', 'ts', 'price', 'asset_type')
            ->whereIn(DB::raw('(symbol, ts)'), function ($query) use ($symbols) {
                $query->select('symbol', DB::raw('MAX(ts)'))
                    ->from('five_minute_prices')
                    ->whereIn('symbol', $symbols)
                    ->groupBy('symbol');
            })
            ->get()
            ->keyBy('symbol');

        // Get asset IDs for all symbols in batch
        $assets = DB::table('asset_info')
            ->whereIn('symbol', $symbols)
            ->whereNull('deleted_at')
            ->pluck('id', 'symbol');

        // Get today's price ranges for all symbols in one query
        $todayRanges = $this->getTodayRangesBulk($symbols);

        // Process each symbol
        foreach ($symbols as $symbol) {
            $latest = $latestPrices->get($symbol);
            if (! $latest) {
                continue;
            }

            $currentPrice = (float) $latest->price;
            $latestTs = Carbon::parse($latest->ts);
            $assetType = $latest->asset_type;
            $assetId = $assets->get($symbol);

            // Check for special session and get appropriate current price
            $specialSessionData = $this->checkSpecialSession($symbol, $latestTs);
            if ($specialSessionData) {
                $currentPrice = $specialSessionData['current_price'];
            }

            // Get historical prices using optimized method with five-minute fallback
            $changes = $this->calculatePriceChangesWithFallback($symbol, $latestTs, $currentPrice, $lookbackPeriods, $shortDays, $longDays, $specialSessionData);

            // Extract specific period changes
            $shortChange = $changes['short'];
            $longChange = $changes['long'];

            // Determine stagnation status or significant positive gains
            $isStagnant = false;
            $hasSignificantGain = false;
            if ($shortChange['percent'] !== null && $longChange['percent'] !== null) {
                $shortFlat = abs($shortChange['percent']) <= $flatThresholdPct;
                $longFlat = abs($longChange['percent']) <= $flatThresholdPct;
                $isStagnant = $shortFlat && $longFlat;

                // Also include stocks with significant positive daily gains (>2%)
                $hasSignificantGain = $shortChange['percent'] > 2.0;
            }

            // Include stock if it's either stagnant OR has significant positive gains
            $shouldInclude = $isStagnant || $hasSignificantGain;

            // Determine downtrend status (5d, 3d, 1d all negative = short-term downtrend)
            $isDowntrend = false;
            if ($changes['5d']['percent'] !== null && $changes['3d']['percent'] !== null && $changes['1d']['percent'] !== null) {
                $isDowntrend = $changes['5d']['percent'] < 0 && $changes['3d']['percent'] < 0 && $changes['1d']['percent'] < 0;
            }

            // Get day range from bulk query result
            $dayRange = $todayRanges->get($symbol);

            // Calculate intraday performance (9:30 AM EST to now) if market is open after 10 AM EST
            $intradayChange = $this->calculateIntradayChange($symbol, $latestTs);

            // Only include stocks that meet our criteria (stagnant OR significant positive gains)
            if ($shouldInclude) {
                $results[] = [
                    'id' => $assetId,
                    'symbol' => $symbol,
                    'asset_type' => $assetType,
                    'current_price' => $currentPrice,
                    'short_change_pct' => $shortChange,
                    'long_change_pct' => $longChange,
                    'is_stagnant' => $isStagnant,
                    'has_significant_gain' => $hasSignificantGain,
                    'is_downtrend' => $isDowntrend,
                    'day_range_pct' => $dayRange,
                    'latest_ts' => $latestTs->toDateTimeString(),
                    'intraday_change_pct' => $intradayChange,
                    '1d_change_pct' => $changes['1d'],
                    '3d_change_pct' => $changes['3d'],
                    '5d_change_pct' => $changes['5d'],
                    '15d_change_pct' => $changes['15d'],
                    '30d_change_pct' => $changes['30d'],
                ];
            }
        }

        return $results;
    }

    private function calculatePriceChangesOptimized(string $symbol, Carbon $latestTs, float $currentPrice, array $lookbackPeriods, int $shortDays, int $longDays, ?array $specialSessionData = null): array
    {
        $changes = [];

        // Get market schedule data for trading days calculation
        $marketSchedule = collect($this->getRecentMarketSchedule())
            ->keyBy('date')
            ->toArray();

        // Calculate actual trading days for all periods
        $allPeriods = array_unique(array_merge($lookbackPeriods, [$shortDays, $longDays]));
        $targetDates = [];
        foreach ($allPeriods as $days) {
            $targetDates[$days] = $this->getTradingDaysAgo($latestTs, $days, $marketSchedule);
        }

        // Calculate the earliest cutoff based on actual target dates
        $earliestDate = collect($targetDates)->min();
        $earliestCutoff = $earliestDate->copy()->subDay(); // Add small buffer

        // Get all historical data in one efficient query
        $historicalData = DB::table('five_minute_prices')
            ->where('symbol', $symbol)
            ->where('ts', '>=', $earliestCutoff)
            ->orderBy('ts')
            ->get(['ts', 'price']);

        // Find closest prices for each trading day period
        $historicalPrices = collect();
        foreach ($targetDates as $days => $targetDate) {
            $cutoff = $targetDate->copy()->endOfDay();

            $closestPrice = $historicalData
                ->where('ts', '<=', $cutoff)
                ->last();

            $historicalPrices->put($days, $closestPrice ? (float) $closestPrice->price : null);
        }

        // Calculate percentage changes for all lookback periods
        foreach ($lookbackPeriods as $days) {
            $pastPrice = $historicalPrices->get($days);

            // For 1-day during special sessions, use intraday calculation instead
            if ($days === 1 && $specialSessionData && $specialSessionData['is_special_session']) {
                $pastPrice = $specialSessionData['opening_price'];
            }

            $changes["{$days}d"] = [
                'percent' => $this->pctChange($pastPrice, $currentPrice),
                'price' => $pastPrice ? round($pastPrice, 2) : null,
            ];
        }

        // Handle short and long periods
        $shortPrice = $historicalPrices->get($shortDays);
        $longPrice = $historicalPrices->get($longDays);

        // For 1-day short period during special sessions, use intraday calculation
        if ($shortDays === 1 && $specialSessionData && $specialSessionData['is_special_session']) {
            $shortPrice = $specialSessionData['opening_price'];
        }

        $changes['short'] = [
            'percent' => $this->pctChange($shortPrice, $currentPrice),
            'price' => $shortPrice ? round($shortPrice, 2) : null,
        ];
        $changes['long'] = [
            'percent' => $this->pctChange($longPrice, $currentPrice),
            'price' => $longPrice ? round($longPrice, 2) : null,
        ];

        return $changes;
    }

    private function getTodayRangesBulk(array $symbols): \Illuminate\Support\Collection
    {
        $today = now()->toDateString();

        return DB::table('five_minute_prices')
            ->whereIn('symbol', $symbols)
            ->whereDate('ts', $today)
            ->select('symbol')
            ->selectRaw('MAX(price) as day_high, MIN(price) as day_low')
            ->groupBy('symbol')
            ->get()
            ->mapWithKeys(function ($item) {
                if (! $item->day_high || ! $item->day_low) {
                    return [$item->symbol => null];
                }

                $dayRange = (($item->day_high - $item->day_low) / $item->day_low) * 100;

                return [$item->symbol => (float) $dayRange];
            });
    }

    private function calculatePriceChangesWithFallback(string $symbol, Carbon $latestTs, float $currentPrice, array $lookbackPeriods, int $shortDays, int $longDays, ?array $specialSessionData = null): array
    {
        $changes = [];
        $allPeriods = array_unique(array_merge($lookbackPeriods, [$shortDays, $longDays]));

        // First try the optimized five-minute approach
        $fiveMinChanges = $this->calculatePriceChangesOptimized($symbol, $latestTs, $currentPrice, $lookbackPeriods, $shortDays, $longDays, $specialSessionData);

        // Check each period and use daily fallback if five-minute data is missing
        foreach ($lookbackPeriods as $days) {
            $periodKey = "{$days}d";
            if ($fiveMinChanges[$periodKey]['percent'] !== null) {
                $changes[$periodKey] = $fiveMinChanges[$periodKey];
            } else {
                // Fall back to daily data
                $dailyPrice = $this->getFiveMinuteFallbackData($symbol, $days);

                // For 1-day during special sessions, use intraday calculation
                if ($days === 1 && $specialSessionData && $specialSessionData['is_special_session']) {
                    $dailyPrice = $specialSessionData['opening_price'];
                }

                $changes[$periodKey] = [
                    'percent' => $this->pctChange($dailyPrice, $currentPrice),
                    'price' => $dailyPrice ? round($dailyPrice, 2) : null,
                ];
            }
        }

        // Handle short and long periods with fallback
        if ($fiveMinChanges['short']['percent'] !== null) {
            $changes['short'] = $fiveMinChanges['short'];
        } else {
            $dailyPrice = $this->getFiveMinuteFallbackData($symbol, $shortDays);

            // For 1-day during special sessions, use intraday calculation
            if ($shortDays === 1 && $specialSessionData && $specialSessionData['is_special_session']) {
                $dailyPrice = $specialSessionData['opening_price'];
            }

            $changes['short'] = [
                'percent' => $this->pctChange($dailyPrice, $currentPrice),
                'price' => $dailyPrice ? round($dailyPrice, 2) : null,
            ];
        }

        if ($fiveMinChanges['long']['percent'] !== null) {
            $changes['long'] = $fiveMinChanges['long'];
        } else {
            $dailyPrice = $this->getFiveMinuteFallbackData($symbol, $longDays);
            $changes['long'] = [
                'percent' => $this->pctChange($dailyPrice, $currentPrice),
                'price' => $dailyPrice ? round($dailyPrice, 2) : null,
            ];
        }

        return $changes;
    }

    private function getFiveMinuteFallbackData(string $symbol, int $daysBack): ?float
    {
        // Calculate the target date for comparison
        $targetDate = now()->subDays($daysBack)->toDateString();

        // Get the closest daily price data for this symbol around the target date
        $dailyPrice = DB::table('daily_prices')
            ->where('symbol', $symbol)
            ->where('asset_type', 'stock')
            ->where('date', '<=', $targetDate)
            ->orderBy('date', 'desc')
            ->first(['price', 'date']);

        if (! $dailyPrice) {
            // If no daily data found, try to get any price within a reasonable range
            $dailyPrice = DB::table('daily_prices')
                ->where('symbol', $symbol)
                ->where('asset_type', 'stock')
                ->whereBetween('date', [
                    now()->subDays($daysBack + 5)->toDateString(),
                    now()->subDays($daysBack - 2)->toDateString(),
                ])
                ->orderByRaw('ABS(DATEDIFF(date, ?)) ASC', [$targetDate])
                ->first(['price', 'date']);
        }

        return $dailyPrice ? (float) $dailyPrice->price : null;
    }

    private function checkSpecialSession(string $symbol, Carbon $latestTs): ?array
    {
        // Check if we have five-minute data for today
        $todayData = DB::table('five_minute_prices')
            ->where('symbol', $symbol)
            ->whereDate('ts', $latestTs->toDateString())
            ->orderBy('ts')
            ->get(['ts', 'price', 'open']);

        if ($todayData->isEmpty()) {
            return null;
        }

        $first = $todayData->first();
        $last = $todayData->last();

        // Convert to EST for proper special session detection
        $startTimeEst = Carbon::parse($first->ts)->setTimezone('America/New_York');
        $endTimeEst = Carbon::parse($last->ts)->setTimezone('America/New_York');

        // Apply enhanced special session detection
        $lateStartThreshold = $startTimeEst->copy()->setTime(13, 0);
        $earlyEndThreshold = $startTimeEst->copy()->setTime(14, 0);
        $sessionDuration = $startTimeEst->diffInHours($endTimeEst, false);

        $isLateStart = $startTimeEst->isAfter($lateStartThreshold);
        $isEarlyEnd = $endTimeEst->isBefore($earlyEndThreshold);
        $isShortSession = $sessionDuration < 4;

        $isSpecialSession = $isLateStart || $isEarlyEnd || $isShortSession;

        return [
            'current_price' => (float) $last->price,
            'opening_price' => (float) ($first->open ?: $first->price),
            'is_special_session' => $isSpecialSession,
        ];
    }

    /**
     * Get top performing symbols based on 1-day percentage gains using optimized query
     */
    private function getTopPerformers(): array
    {
        // Get trading dates with substantial data (>1000 symbols indicates a full trading day)
        $recentDates = DB::table('five_minute_prices')
            ->selectRaw('trading_date_est, COUNT(DISTINCT symbol) as symbol_count')
            ->groupBy('trading_date_est')
            ->having('symbol_count', '>', 1000)
            ->orderByDesc('trading_date_est')
            ->limit(5)
            ->pluck('trading_date_est')
            ->toArray();

        if (count($recentDates) < 2) {
            return []; // Not enough full trading days
        }

        $currentTradingDate = $recentDates[0];
        $previousTradingDate = $recentDates[1];

        // Optimized query to get top performers
        $topPerformers = DB::select('
            SELECT 
                p.symbol,
                ((p.current_price - p.previous_price) / p.previous_price) * 100 AS performance_pct
            FROM (
                SELECT 
                    t.symbol,
                    t.asset_type,
                    t.current_price,
                    y.previous_price
                FROM (
                    SELECT 
                        symbol,
                        asset_type,
                        MAX(price) AS current_price
                    FROM five_minute_prices
                    WHERE trading_date_est = ?
                    GROUP BY symbol, asset_type
                ) AS t
                JOIN (
                    SELECT 
                        symbol,
                        asset_type,
                        MAX(price) AS previous_price
                    FROM five_minute_prices
                    WHERE trading_date_est = ?
                      AND price > 0
                    GROUP BY symbol, asset_type
                ) AS y
                    ON t.symbol = y.symbol
                   AND t.asset_type = y.asset_type
                JOIN asset_info AS ai
                    ON t.symbol = ai.symbol
                   AND t.asset_type = ai.asset_type
                WHERE ai.deleted_at IS NULL
                  AND y.previous_price > 0
            ) AS p
            WHERE ((p.current_price - p.previous_price) / p.previous_price) * 100 > 2
            ORDER BY ((p.current_price - p.previous_price) / p.previous_price) * 100 DESC
            LIMIT 200
        ', [$currentTradingDate, $previousTradingDate]);

        return array_column($topPerformers, 'symbol');
    }

    /**
     * Sort high performers within the results by their 1-day performance
     */
    private function sortHighPerformersByPerformance(array $stagnationData): array
    {
        // Separate high performers from other assets
        $highPerformers = [];
        $otherAssets = [];

        foreach ($stagnationData as $asset) {
            if ($asset['has_significant_gain'] && ! $asset['is_downtrend']) {
                $highPerformers[] = $asset;
            } else {
                $otherAssets[] = $asset;
            }
        }

        // Sort high performers by 1-day percentage change (highest first)
        usort($highPerformers, function ($a, $b) {
            $aChange = $a['1d_change_pct']['percent'] ?? 0;
            $bChange = $b['1d_change_pct']['percent'] ?? 0;

            return $bChange <=> $aChange; // Descending order
        });

        // Merge back: high performers first (sorted by performance), then others
        return array_merge($highPerformers, $otherAssets);
    }

    private function pctChange(?float $past, float $current): ?float
    {
        if ($past === null || $past == 0.0) {
            return null;
        }

        return (($current - $past) / $past) * 100.0;
    }

    /**
     * Calculate intraday performance from 9:30 AM EST to current time
     * Only returns data on market open days after 10:00 AM EST
     */
    private function calculateIntradayChange(string $symbol, Carbon $latestTs): ?array
    {
        $now = Carbon::now('America/New_York');
        $todayTradingDate = $now->format('Y-m-d');

        // Only show intraday data on weekdays (Monday-Friday)
        if ($now->isWeekend()) {
            return null;
        }

        // Only show after 10:00 AM EST on market days
        $tenAM = $now->copy()->setTime(10, 0, 0);
        if ($now->isBefore($tenAM)) {
            return null;
        }

        // Get opening price (closest to 9:30 AM EST)
        $marketOpen = $now->copy()->setTime(9, 30, 0);
        $openingPrice = DB::table('five_minute_prices')
            ->where('symbol', $symbol)
            ->where('trading_date_est', $todayTradingDate)
            ->where('ts_est', '>=', $marketOpen->format('Y-m-d H:i:s'))
            ->orderBy('ts_est')
            ->value('price');

        if (! $openingPrice) {
            return null;
        }

        // Get current price (most recent data)
        $currentPrice = DB::table('five_minute_prices')
            ->where('symbol', $symbol)
            ->where('trading_date_est', $todayTradingDate)
            ->orderByDesc('ts_est')
            ->value('price');

        if (! $currentPrice || $openingPrice <= 0) {
            return null;
        }

        $percentChange = (($currentPrice - $openingPrice) / $openingPrice) * 100;
        $priceChange = $currentPrice - $openingPrice;

        return [
            'percent' => $percentChange,
            'price' => $priceChange,
        ];
    }

    private function getRecentMarketSchedule(): array
    {
        // Get market schedules for the past 60 calendar days to cover 30+ trading days
        $startDate = now()->subDays(60)->format('Y-m-d');
        $endDate = now()->format('Y-m-d');

        return DB::table('market_schedules')
            ->where('market_type', 'stock')
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date', 'desc')
            ->get(['date', 'status', 'reason'])
            ->map(fn ($row) => [
                'date' => $row->date,
                'status' => $row->status,
                'reason' => $row->reason,
            ])
            ->toArray();
    }

    private function getTradingDaysAgo(Carbon $currentDate, int $tradingDays, array $marketSchedule): Carbon
    {
        $date = $currentDate->copy();
        $daysFound = 0;
        $safeguard = 0;

        while ($daysFound < $tradingDays && $safeguard < 100) {
            $date->subDay();
            $dateString = $date->format('Y-m-d');

            // Check if this date is a trading day
            if (isset($marketSchedule[$dateString])) {
                $status = $marketSchedule[$dateString]['status'];
                if ($status === 'open' || $status === 'early_close') {
                    $daysFound++;
                }
            } else {
                // If not in market schedule, assume weekdays are trading days (fallback)
                if (! $date->isWeekend()) {
                    $daysFound++;
                }
            }

            $safeguard++;
        }

        return $date;
    }

    private function calculateTradingDates(): array
    {
        // Get market schedule data
        $marketSchedule = collect($this->getRecentMarketSchedule())
            ->keyBy('date')
            ->toArray();

        // Use December 1, 2025 as the reference date since that's "today"
        $referenceDate = Carbon::parse('2025-12-01');

        $tradingDates = [];

        // Calculate actual trading dates for common lookback periods
        $periods = [1, 3, 5, 15, 30];

        foreach ($periods as $days) {
            $tradingDate = $this->getTradingDaysAgo($referenceDate, $days, $marketSchedule);
            $tradingDates["{$days}d"] = $tradingDate->format('n/j'); // e.g., "11/28"
        }

        return $tradingDates;
    }
}
