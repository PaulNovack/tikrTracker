<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class StagnationController extends Controller
{
    public function index(?Request $request = null): \Inertia\Response
    {
        $user = auth()->user();

        // Get stagnation thresholds from .env
        $shortDays = (int) config('app.stagnation_short_days', 1);
        $longDays = (int) config('app.stagnation_long_days', 3);
        $flatThresholdPct = (float) config('app.stagnation_threshold_pct', 1.0);
        $goodPositivePct = (float) config('app.good_positive_pct', 2.5);
        $greatPositivePct = (float) config('app.great_positive_pct', 5.0);
        $negativeAlertPct = (float) config('app.negative_alert_pct', -2.5);

        // Check if symbols are provided via request (for cache warming)
        $requestSymbols = $request ? $request->get('symbols') : null;

        if ($requestSymbols) {
            // Use provided symbols (for cache warming)
            $symbols = is_string($requestSymbols) ? explode(',', $requestSymbols) : $requestSymbols;
            $symbols = array_map('trim', $symbols);
        } else {
            // Use user's watched assets (normal operation)
            if (! $user) {
                return Inertia::render('stagnation', [
                    'stagnationData' => [],
                    'shortDays' => $shortDays,
                    'longDays' => $longDays,
                    'flatThresholdPct' => $flatThresholdPct,
                    'goodPositivePct' => $goodPositivePct,
                    'greatPositivePct' => $greatPositivePct,
                    'negativeAlertPct' => $negativeAlertPct,
                    'marketSchedule' => $this->getRecentMarketSchedule(),
                    'tradingDates' => $this->calculateTradingDates(),
                ]);
            }

            $watches = $user->watches()
                ->with('asset')
                ->get();

            if ($watches->isEmpty()) {
                return Inertia::render('stagnation', [
                    'stagnationData' => [],
                    'shortDays' => $shortDays,
                    'longDays' => $longDays,
                    'flatThresholdPct' => $flatThresholdPct,
                    'goodPositivePct' => $goodPositivePct,
                    'greatPositivePct' => $greatPositivePct,
                    'negativeAlertPct' => $negativeAlertPct,
                    'marketSchedule' => $this->getRecentMarketSchedule(),
                    'tradingDates' => $this->calculateTradingDates(),
                ]);
            }

            // Extract symbols to scan
            $symbols = $watches->map(fn ($w) => $w->asset->symbol)->filter()->values()->toArray();
        }

        if (empty($symbols)) {
            return Inertia::render('stagnation', [
                'stagnationData' => [],
                'shortDays' => $shortDays,
                'longDays' => $longDays,
                'flatThresholdPct' => $flatThresholdPct,
                'goodPositivePct' => $goodPositivePct,
                'greatPositivePct' => $greatPositivePct,
                'negativeAlertPct' => $negativeAlertPct,
                'marketSchedule' => $this->getRecentMarketSchedule(),
                'tradingDates' => $this->calculateTradingDates(),
            ]);
        }

        // Generate cache key based on symbols and current 5-minute interval
        $sortedSymbols = $symbols;
        sort($sortedSymbols);
        $symbolsKey = md5(implode(',', $sortedSymbols));
        $cacheKey = "stagnation-data-{$symbolsKey}-".now()->format('Y-m-d-H-').floor(now()->minute / 5) * 5;

        // Cache for 2 minutes (intraday data)
        $stagnationData = Cache::remember($cacheKey, now()->addMinutes(2), function () use ($symbols, $shortDays, $longDays, $flatThresholdPct, $goodPositivePct) {
            return $this->scanStagnationBulk($symbols, $shortDays, $longDays, $flatThresholdPct, $goodPositivePct);
        });

        return Inertia::render('stagnation', [
            'stagnationData' => $stagnationData,
            'shortDays' => $shortDays,
            'longDays' => $longDays,
            'flatThresholdPct' => $flatThresholdPct,
            'goodPositivePct' => $goodPositivePct,
            'greatPositivePct' => $greatPositivePct,
            'negativeAlertPct' => $negativeAlertPct,
            'marketSchedule' => $this->getRecentMarketSchedule(),
            'tradingDates' => $this->calculateTradingDates(),
        ]);
    }

    private function scanStagnationBulk(array $symbols, int $shortDays, int $longDays, float $flatThresholdPct, float $goodPositivePct): array
    {
        if (empty($symbols)) {
            return [];
        }

        $results = [];
        $lookbackPeriods = [1, 3, 5, 15, 30];

        // Get latest prices for all symbols in one query
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

        // Get asset IDs for all symbols
        $assets = DB::table('asset_info')
            ->whereIn('symbol', $symbols)
            ->whereNull('deleted_at')
            ->pluck('id', 'symbol');

        // Process each symbol with bulk queries for historical data
        foreach ($symbols as $symbol) {
            $latest = $latestPrices->get($symbol);
            if (! $latest) {
                continue;
            }

            $currentPrice = (float) $latest->price;
            $latestTs = Carbon::parse($latest->ts);
            $assetType = $latest->asset_type;
            $assetId = $assets->get($symbol);

            // Check for special session and get appropriate current/previous prices
            $specialSessionData = $this->checkSpecialSession($symbol, $latestTs);
            if ($specialSessionData) {
                $currentPrice = $specialSessionData['current_price'];
            }

            // Get historical prices with five-minute fallback logic
            $historicalPrices = $this->getHistoricalPricesWithFallback($symbol, $latestTs, array_merge($lookbackPeriods, [$shortDays, $longDays]));

            // Calculate percentage changes for all periods
            $changes = [];
            foreach ($lookbackPeriods as $days) {
                $pastPrice = $historicalPrices->get("{$days}d");

                if ($days === 1 && $specialSessionData && $specialSessionData['is_special_session']) {
                    // For 1-day during special sessions, use intraday calculation
                    $pastPrice = $specialSessionData['opening_price'];
                }

                $changes["{$days}d"] = [
                    'percent' => $this->pctChange($pastPrice, $currentPrice),
                    'price' => $pastPrice ? round($pastPrice, 2) : null,
                ];
            }

            // Calculate short and long changes
            $shortChange = $this->pctChange($historicalPrices->get("{$shortDays}d"), $currentPrice);
            $longChange = $this->pctChange($historicalPrices->get("{$longDays}d"), $currentPrice);

            // Determine stagnation status
            $isStagnant = false;
            if ($shortChange !== null && $longChange !== null) {
                $shortFlat = abs($shortChange) <= $flatThresholdPct;
                $longFlat = abs($longChange) <= $flatThresholdPct;
                $isStagnant = $shortFlat && $longFlat;
            }

            // Determine downtrend status (5d, 3d, 1d all negative = short-term downtrend)
            $isDowntrend = false;
            if ($changes['5d']['percent'] !== null && $changes['3d']['percent'] !== null && $changes['1d']['percent'] !== null) {
                $isDowntrend = $changes['5d']['percent'] < 0 && $changes['3d']['percent'] < 0 && $changes['1d']['percent'] < 0;
            }

            // Get day's range optimized
            $dayRange = $this->getDayRangeOptimized($symbol);

            // Determine significant gain status (similar to notable assets)
            $hasSignificantGain = false;
            if ($changes['1d']['percent'] !== null) {
                $hasSignificantGain = $changes['1d']['percent'] >= $goodPositivePct;
            }

            // Calculate intraday change
            $intradayChange = $this->calculateIntradayChange($symbol, $latestTs);

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

        // Sort by 1d performance (best to worst: highest positive gains first)
        usort($results, function ($a, $b) {
            $aChange = $a['1d_change_pct']['percent'] ?? 0;
            $bChange = $b['1d_change_pct']['percent'] ?? 0;

            return $bChange <=> $aChange;
        });

        return $results;
    }

    private function getHistoricalPricesBulk(string $symbol, Carbon $latestTs, array $periods): \Illuminate\Support\Collection
    {
        $prices = collect();

        // Create cutoff timestamps for all periods
        $cutoffs = [];
        foreach ($periods as $days) {
            $lookbackMinutes = $days * 24 * 60;
            $cutoffs["{$days}d"] = $latestTs->copy()->subMinutes($lookbackMinutes);
        }

        // Get the earliest cutoff to optimize query
        $earliestCutoff = collect($cutoffs)->min();

        // Get all relevant historical prices in one query
        $historicalData = DB::table('five_minute_prices')
            ->where('symbol', $symbol)
            ->where('ts', '>=', $earliestCutoff)
            ->orderBy('ts')
            ->get(['ts', 'price']);

        // Find the closest price for each period
        foreach ($cutoffs as $periodKey => $cutoff) {
            $closestPrice = $historicalData
                ->where('ts', '<=', $cutoff)
                ->last();

            $prices->put($periodKey, $closestPrice ? (float) $closestPrice->price : null);
        }

        return $prices;
    }

    private function getDayRangeOptimized(string $symbol): ?float
    {
        $today = now()->toDateString();
        $todayStats = DB::table('five_minute_prices')
            ->where('symbol', $symbol)
            ->whereDate('ts', $today)
            ->selectRaw('MAX(price) as day_high, MIN(price) as day_low')
            ->first();

        if (! $todayStats || ! $todayStats->day_high || ! $todayStats->day_low) {
            return null;
        }

        return (float) (($todayStats->day_high - $todayStats->day_low) / $todayStats->day_low) * 100;
    }

    private function getHistoricalPricesWithFallback(string $symbol, Carbon $latestTs, array $periods): \Illuminate\Support\Collection
    {
        $prices = collect();

        // Try five-minute data first for all periods
        $fiveMinPrices = $this->getHistoricalPricesBulk($symbol, $latestTs, $periods);

        // For each period, check if five-minute data exists, otherwise use daily fallback
        foreach ($periods as $days) {
            $periodKey = "{$days}d";
            $fiveMinPrice = $fiveMinPrices->get($periodKey);

            if ($fiveMinPrice !== null) {
                // Five-minute data exists, use it
                $prices->put($periodKey, $fiveMinPrice);
            } else {
                // Fall back to daily prices
                $dailyPrice = $this->getFiveMinuteFallbackData($symbol, $days);
                $prices->put($periodKey, $dailyPrice);
            }
        }

        return $prices;
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

    private function pctChange(?float $past, float $current): ?float
    {
        if ($past === null || $past == 0.0) {
            return null;
        }

        return (($current - $past) / $past) * 100.0;
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

    private function calculateIntradayChange(string $symbol, Carbon $latestTs): ?array
    {
        $now = Carbon::now('America/New_York');
        $todayTradingDate = $now->format('Y-m-d');

        // Check if we have trading data for today instead of just checking weekends
        $todayDataCount = DB::table('five_minute_prices')
            ->where('symbol', $symbol)
            ->where('trading_date_est', $todayTradingDate)
            ->count();

        if ($todayDataCount === 0) {
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

    private function getTradingDaysAgo(\Carbon\Carbon $currentDate, int $tradingDays, array $marketSchedule): \Carbon\Carbon
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

        // Use current time in EST since US stock markets operate in Eastern time
        $referenceDate = now()->setTimezone('America/New_York');

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
