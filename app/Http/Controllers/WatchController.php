<?php

namespace App\Http\Controllers;

use App\Models\AssetInfo;
use App\Models\MarketSchedule;
use App\Models\PriceAlert;
use App\Models\Watch;
use App\Services\OHLCService;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class WatchController extends Controller
{
    use AuthorizesRequests;

    public function index(): \Inertia\Response
    {
        $watches = auth()->user()
            ->watches()
            ->with('asset')
            ->join('asset_info', 'watches.asset_info_id', '=', 'asset_info.id')
            ->orderBy('asset_info.symbol', 'asc')
            ->select('watches.*')
            ->get()
            ->map(function ($watch) {
                return [
                    'id' => $watch->id,
                    'asset' => $watch->asset,
                    'chartData' => $this->getChartData($watch->asset),
                    'latestPrice' => $this->getLatestPrice($watch->asset),
                    'stats' => $this->getStats($watch->asset),
                    'priceStats' => $this->getPriceStats($watch->asset),
                    'hasEnoughHourlyData' => $this->checkHourlyDataAvailability($watch->asset),
                ];
            });

        $marketStatus = $this->getMarketStatus();

        return Inertia::render('watches', [
            'watches' => $watches,
            'marketStatus' => $marketStatus,
        ]);
    }

    public function settings(): \Inertia\Response
    {
        // Cache the assets list for 24 hours since it doesn't change frequently
        $assets = Cache::remember('watch-settings-assets', now()->addDay(), function () {
            return AssetInfo::orderBy('symbol')
                ->select(['id', 'symbol', 'asset_type', 'common_name', 'sector'])
                ->get();
        });

        $userWatches = auth()->user()->watches()->get();
        $watchedAssets = $userWatches->mapWithKeys(function ($watch) {
            return [$watch->asset_info_id => $watch->id];
        })->all();
        $maxWatches = (int) config('app.max_watches');
        $currentWatchCount = count($userWatches);

        return Inertia::render('watches-settings', [
            'assets' => $assets,
            'watchedAssets' => $watchedAssets,
            'maxWatches' => $maxWatches,
            'currentWatchCount' => $currentWatchCount,
        ]);
    }

    public function store(): RedirectResponse
    {
        $assetId = request()->input('asset_info_id');
        $maxWatches = (int) config('app.max_watches', 6);
        $userWatchCount = auth()->user()->watches()->count();

        // Check if user has reached the watch limit
        if ($userWatchCount >= $maxWatches) {
            return back()->with('error', "You've reached the maximum limit of {$maxWatches} watches. Please remove a watch to add a new one.");
        }

        $watch = Watch::firstOrCreate(
            [
                'user_id' => auth()->id(),
                'asset_info_id' => $assetId,
            ]
        );

        // Automatically create a price alert with current price if one doesn't exist
        if ($watch->wasRecentlyCreated) {
            $asset = $watch->asset;
            $currentPrice = $this->getLatestPrice($asset);

            if ($currentPrice) {
                $price = (float) $currentPrice->price;
                $upPercentage = (float) config('app.watch_default_up_pct', 2.5);
                $downPercentage = (float) config('app.watch_default_down_pct', 2.5);

                PriceAlert::updateOrCreate(
                    [
                        'user_id' => auth()->id(),
                        'asset_info_id' => $assetId,
                    ],
                    [
                        'base_price' => $price,
                        'alert_type' => 'percentage',
                        'up_percentage' => $upPercentage,
                        'down_percentage' => $downPercentage,
                        'up_enabled' => true,
                        'down_enabled' => true,
                    ]
                );

                // Calculate trigger prices
                $alert = PriceAlert::where('user_id', auth()->id())
                    ->where('asset_info_id', $assetId)
                    ->first();

                if ($alert) {
                    $alert->calculateTriggerPrices();
                    $alert->save();
                }
            }
        }

        return back()->with('message', 'Watch added successfully');
    }

    /**
     * Show CSV import page for watches
     */
    public function csvShow(): \Inertia\Response
    {
        $maxWatches = (int) config('app.max_watches', 6);
        $currentWatchCount = auth()->user()->watches()->count();

        return Inertia::render('csv-set-watches', [
            'maxWatches' => $maxWatches,
            'currentWatchCount' => $currentWatchCount,
        ]);
    }

    /**
     * Store watches from CSV symbols
     */
    public function csvStore(): RedirectResponse
    {
        request()->validate([
            'symbols' => ['required', 'string', 'max:5000'],
        ]);

        $symbolsInput = request()->input('symbols');
        $maxWatches = (int) config('app.max_watches', 6);
        $userWatchCount = auth()->user()->watches()->count();

        // Parse comma-separated symbols
        $symbols = array_map('trim', explode(',', $symbolsInput));
        $symbols = array_filter($symbols); // Remove empty values
        $symbols = array_map('strtoupper', $symbols); // Convert to uppercase
        $symbols = array_unique($symbols); // Remove duplicates

        if (empty($symbols)) {
            return back()->with('error', 'No valid symbols provided.');
        }

        $addedCount = 0;
        $skippedCount = 0;
        $invalidCount = 0;
        $limitReached = false;

        foreach ($symbols as $symbol) {
            // Check if we've hit the watch limit
            if ($userWatchCount >= $maxWatches) {
                $limitReached = true;
                break;
            }

            // Find asset in database
            $asset = AssetInfo::where('symbol', $symbol)
                ->whereNull('deleted_at')
                ->first();

            if (! $asset) {
                $invalidCount++;

                continue;
            }

            // Check if already watching
            $existingWatch = Watch::where('user_id', auth()->id())
                ->where('asset_info_id', $asset->id)
                ->first();

            if ($existingWatch) {
                $skippedCount++;

                continue;
            }

            // Create the watch
            $watch = Watch::create([
                'user_id' => auth()->id(),
                'asset_info_id' => $asset->id,
            ]);

            // Create default price alert like in the store method
            $currentPrice = $this->getLatestPrice($asset);
            if ($currentPrice) {
                $price = (float) $currentPrice->price;
                $upPercentage = (float) config('app.watch_default_up_pct', 2.5);
                $downPercentage = (float) config('app.watch_default_down_pct', 2.5);

                PriceAlert::updateOrCreate(
                    [
                        'user_id' => auth()->id(),
                        'asset_info_id' => $asset->id,
                    ],
                    [
                        'base_price' => $price,
                        'alert_type' => 'percentage',
                        'up_percentage' => $upPercentage,
                        'down_percentage' => $downPercentage,
                        'up_enabled' => true,
                        'down_enabled' => true,
                    ]
                );

                // Calculate trigger prices
                $alert = PriceAlert::where('user_id', auth()->id())
                    ->where('asset_info_id', $asset->id)
                    ->first();

                if ($alert) {
                    $alert->calculateTriggerPrices();
                    $alert->save();
                }
            }

            $addedCount++;
            $userWatchCount++;
        }

        // Build success message
        $messages = [];
        if ($addedCount > 0) {
            $messages[] = "Added {$addedCount} new ".($addedCount === 1 ? 'watch' : 'watches');
        }
        if ($skippedCount > 0) {
            $messages[] = "Skipped {$skippedCount} duplicate ".($skippedCount === 1 ? 'symbol' : 'symbols');
        }
        if ($invalidCount > 0) {
            $messages[] = "Ignored {$invalidCount} invalid ".($invalidCount === 1 ? 'symbol' : 'symbols');
        }
        if ($limitReached) {
            $messages[] = "Watch limit of {$maxWatches} reached";
        }

        $message = implode('. ', $messages);

        return back()->with('message', $message);
    }

    /**
     * Get current market status to determine default time range
     */
    private function getMarketStatus(): array
    {
        $today = now()->toDateString();
        $currentTime = now();

        // Get today's market schedule for stocks (main market)
        $stockMarket = MarketSchedule::where('date', $today)
            ->where('market_type', 'stock')
            ->first();

        $isMarketOpen = false;
        $isMarketDay = true;
        $defaultTimeRange = 'Last Open Day';

        if ($stockMarket) {
            $isMarketDay = $stockMarket->isOpen();

            if ($isMarketDay && $stockMarket->opens_at && $stockMarket->closes_at) {
                // Market times are stored in EST, so we need to create proper EST times
                $marketOpen = Carbon::today('America/New_York')
                    ->setTimeFromTimeString($stockMarket->opens_at->format('H:i:s'));

                $marketClose = Carbon::today('America/New_York')
                    ->setTimeFromTimeString($stockMarket->closes_at->format('H:i:s'));

                $currentEST = $currentTime->copy()->setTimezone('America/New_York');

                $isMarketOpen = $currentEST->between($marketOpen, $marketClose);

                // If it's a market day (trading day), use 1D regardless of current open/closed status
                // This ensures that on trading days, we default to 1D even after market closes
                $defaultTimeRange = '1D';
            }
        }

        return [
            'isMarketDay' => $isMarketDay,
            'isMarketOpen' => $isMarketOpen,
            'defaultTimeRange' => $defaultTimeRange,
            'stockMarketStatus' => $stockMarket?->status ?? 'closed',
            'reason' => $stockMarket?->reason,
        ];
    }

    public function destroy(Watch $watch): RedirectResponse
    {
        $this->authorize('delete', $watch);
        $watch->delete();

        return back()->with('message', 'Watch removed successfully');
    }

    public function getMaxChartData(Watch $watch)
    {
        $this->authorize('view', $watch);

        $asset = $watch->asset;

        // Fetch all historical daily prices (no filtering, all data)
        $maxData = $asset->dailyPrices()
            ->orderBy('date', 'asc')
            ->get(['date as time', 'price']);

        return response()->json([
            'MAX' => $maxData->map(fn ($item) => [
                'time' => $item->time,
                'price' => $item->price,
            ])->values()->all(),
        ]);
    }

    public function getCandlestickData(Watch $watch)
    {
        $this->authorize('view', $watch);

        $asset = $watch->asset;
        $ohlcService = new OHLCService;

        $timeRange = request()->query('range', '1D');
        $interval = request()->query('interval');

        // Determine time range
        $now = now('UTC');
        $startTime = match ($timeRange) {
            '1D' => $now->clone()->startOfDay(),
            '5D' => $now->clone()->subDays(5)->startOfDay(),
            '1M' => $now->clone()->subMonth(),
            '3M' => $now->clone()->subMonths(3),
            '6M' => $now->clone()->subMonths(6),
            '1Y' => $now->clone()->subYear(),
            'MAX' => $asset->fiveMinutePrices()
                ->orderBy('ts', 'asc')
                ->first()?->ts ? Carbon::parse($asset->fiveMinutePrices()->orderBy('ts', 'asc')->first()->ts) : $now->clone()->subYear(),
            default => $now->clone()->startOfDay(),
        };

        // Use provided interval or get recommended
        if (! $interval) {
            $interval = $ohlcService->getRecommendedInterval($startTime, $now);
        }

        $data = $ohlcService->getOHLCData($asset, $startTime, $now, $interval);

        return response()->json([
            'data' => $data,
            'interval' => $interval,
            'timeRange' => $timeRange,
        ]);
    }

    private function getLatestPrice(AssetInfo $asset)
    {
        $cacheKey = sprintf('watch-latest-price:%s:%s', $asset->symbol, $asset->asset_type);

        return Cache::remember($cacheKey, 300, function () use ($asset) {
            $todayStart = now('UTC')->startOfDay();

            // Try to get today's five-minute data first (if market is open)
            $latestFiveMinutePrice = $asset->fiveMinutePrices()
                ->where('ts', '>=', $todayStart)
                ->orderBy('ts', 'desc')
                ->first();

            if ($latestFiveMinutePrice) {
                return $latestFiveMinutePrice;
            }

            // If no today's data, get the most recent five-minute price from any day
            $latestFiveMinutePrice = $asset->fiveMinutePrices()
                ->orderBy('ts', 'desc')
                ->first();

            if ($latestFiveMinutePrice) {
                return $latestFiveMinutePrice;
            }

            // Final fallback to daily prices
            $latestDailyPrice = $asset->dailyPrices()
                ->orderBy('date', 'desc')
                ->first();

            return $latestDailyPrice;
        });
    }

    private function checkHourlyDataAvailability(AssetInfo $asset): bool
    {
        $cacheKey = sprintf('watch-hourly-availability:%s:%s', $asset->symbol, $asset->asset_type);

        return Cache::remember($cacheKey, 3600, function () use ($asset) {
            $hourlyDataCount1M = $asset->hourlyPrices()
                ->where('ts', '>=', now('UTC')->subMonth())
                ->count();

            return $hourlyDataCount1M > 150;
        });
    }

    private function getChartData(AssetInfo $asset): array
    {
        $cacheKey = sprintf('watch-chart-data:%s:%s', $asset->symbol, $asset->asset_type);

        return Cache::remember($cacheKey, 900, function () use ($asset) {
            return $this->computeChartData($asset);
        });
    }

    private function computeChartData(AssetInfo $asset): array
    {
        $todayStart = now('UTC')->startOfDay();
        $fiveDaysStart = now('UTC')->subDays(7)->startOfDay();
        $oneMonthStart = now('UTC')->subMonth();
        $threeMonthStart = now('UTC')->subMonths(3);
        $sixMonthStart = now('UTC')->subMonths(6);
        $oneYearStart = now('UTC')->subYear();

        $marketOpenUTC = now('UTC')->setTime(14, 30, 0);
        $marketCloseUTC = now('UTC')->setTime(21, 0, 0);
        $currentTimeUTC = now('UTC');

        $chartData = [
            '1D' => [],
            'Last Open Day' => [],
            '5D' => [],
            '1M' => [],
            '3M' => [],
            '6M' => [],
            '1Y' => [],
        ];

        // 1D - Today's trading
        $actualOneDayData = $asset->fiveMinutePrices()
            ->where('ts', '>=', $todayStart)
            ->orderBy('ts', 'asc')
            ->get(['ts as time', 'price']);

        $oneDayData = collect();
        if ($currentTimeUTC->gte($marketOpenUTC)) {
            $timeSlot = $marketOpenUTC->copy();
            $endTime = min($currentTimeUTC, $marketCloseUTC);
            $dataMap = $actualOneDayData->keyBy(function ($item) {
                return Carbon::parse($item->time)->format('Y-m-d H:i:00');
            });

            while ($timeSlot->lte($endTime)) {
                $timeKey = $timeSlot->format('Y-m-d H:i:00');
                $price = $dataMap->has($timeKey) ? $dataMap[$timeKey]->price : null;
                $oneDayData->push((object) [
                    'time' => $timeSlot->toDateTimeString(),
                    'price' => $price,
                ]);
                $timeSlot->addMinutes(5);
            }
        } else {
            $oneDayData = $actualOneDayData;
        }

        $chartData['1D'] = $oneDayData->map(fn ($item) => [
            'time' => $item->time,
            'price' => $item->price,
        ])->values()->all();

        // Last Open Day
        // Find the most recent trading day using five-minute data (fallback to daily if needed)
        $lastTradingDay = $asset->fiveMinutePrices()
            ->where('ts', '<', $todayStart)
            ->selectRaw('DATE(ts) as trading_date')
            ->distinct()
            ->orderByDesc('trading_date')
            ->first();

        if (! $lastTradingDay) {
            // Fallback to daily prices if no five-minute data available
            $lastTradingDayRecord = $asset->dailyPrices()
                ->where('date', '<', $todayStart->toDateString())
                ->orderBy('date', 'desc')
                ->first();

            if ($lastTradingDayRecord) {
                $lastTradingDay = (object) ['trading_date' => $lastTradingDayRecord->date];
            }
        }

        if ($lastTradingDay) {
            $lastTradingDayStart = Carbon::parse($lastTradingDay->trading_date)->startOfDay();
            $lastOpenDayData = $asset->fiveMinutePrices()
                ->whereBetween('ts', [$lastTradingDayStart, $lastTradingDayStart->copy()->endOfDay()])
                ->orderBy('ts', 'asc')
                ->get(['ts as time', 'price']);

            $chartData['Last Open Day'] = $lastOpenDayData->map(fn ($item) => [
                'time' => $item->time,
                'price' => $item->price,
            ])->values()->all();
        }

        // 5D - 5 minute data
        $fiveDayData = $asset->fiveMinutePrices()
            ->whereBetween('ts', [$fiveDaysStart, $todayStart])
            ->orderBy('ts', 'asc')
            ->get(['ts as time', 'price']);

        $chartData['5D'] = $fiveDayData->map(fn ($item) => [
            'time' => $item->time,
            'price' => $item->price,
        ])->values()->all();

        // 1M, 3M, 6M - Use hourly data if available, otherwise daily
        $oneMonthData = $asset->hourlyPrices()
            ->where('ts', '>=', $oneMonthStart)
            ->orderBy('ts', 'asc')
            ->get(['ts as time', 'price']);

        if ($oneMonthData->isEmpty()) {
            $oneMonthData = $asset->dailyPrices()
                ->mondayOnly()
                ->where('date', '>=', $oneMonthStart->toDateString())
                ->orderBy('date', 'asc')
                ->get(['date as time', 'price']);
        }

        $chartData['1M'] = $oneMonthData->map(fn ($item) => [
            'time' => $item->time,
            'price' => $item->price,
        ])->values()->all();

        $threeMonthData = $asset->hourlyPrices()
            ->where('ts', '>=', $threeMonthStart)
            ->orderBy('ts', 'asc')
            ->get(['ts as time', 'price']);

        if ($threeMonthData->isEmpty()) {
            $threeMonthData = $asset->dailyPrices()
                ->mondayOnly()
                ->where('date', '>=', $threeMonthStart->toDateString())
                ->orderBy('date', 'asc')
                ->get(['date as time', 'price']);
        }

        $chartData['3M'] = $threeMonthData->map(fn ($item) => [
            'time' => $item->time,
            'price' => $item->price,
        ])->values()->all();

        $sixMonthData = $asset->hourlyPrices()
            ->where('ts', '>=', $sixMonthStart)
            ->orderBy('ts', 'asc')
            ->get(['ts as time', 'price']);

        if ($sixMonthData->isEmpty()) {
            $sixMonthData = $asset->dailyPrices()
                ->mondayOnly()
                ->where('date', '>=', $sixMonthStart->toDateString())
                ->orderBy('date', 'asc')
                ->get(['date as time', 'price']);
        }

        $chartData['6M'] = $sixMonthData->map(fn ($item) => [
            'time' => $item->time,
            'price' => $item->price,
        ])->values()->all();

        // 1Y and MAX - Daily data
        $oneYearData = $asset->dailyPrices()
            ->mondayOnly()
            ->where('date', '>=', $oneYearStart->toDateString())
            ->orderBy('date', 'asc')
            ->get(['date as time', 'price']);

        $chartData['1Y'] = $oneYearData->map(fn ($item) => [
            'time' => $item->time,
            'price' => $item->price,
        ])->values()->all();

        return $chartData;
    }

    private function getPriceStats(AssetInfo $asset): array
    {
        $cacheKey = sprintf('watch-price-stats:%s:%s', $asset->symbol, $asset->asset_type);

        return Cache::remember($cacheKey, 900, function () use ($asset) {
            return $this->computePriceStats($asset);
        });
    }

    private function computePriceStats(AssetInfo $asset): array
    {
        $priceStats = [];
        $todayStart = now('UTC')->startOfDay();

        // Calculate stats for each time period
        $ranges = [
            '1D' => [now('UTC')->startOfDay(), now('UTC'), 'fiveMin'],
            '5D' => [now('UTC')->subDays(5)->startOfDay(), now('UTC'), 'fiveMin'],
            '1M' => [now('UTC')->subMonth(), now('UTC'), 'hourly'],
            '3M' => [now('UTC')->subMonths(3), now('UTC'), 'daily'],
            '6M' => [now('UTC')->subMonths(6), now('UTC'), 'daily'],
            '1Y' => [now('UTC')->subYear(), now('UTC'), 'daily'],
        ];

        // Add Last Open Day range - find the most recent trading day using five-minute data
        $lastTradingDay = $asset->fiveMinutePrices()
            ->where('ts', '<', $todayStart)
            ->selectRaw('DATE(ts) as trading_date')
            ->distinct()
            ->orderByDesc('trading_date')
            ->first();

        if (! $lastTradingDay) {
            // Fallback to daily prices if no five-minute data available
            $lastTradingDayRecord = $asset->dailyPrices()
                ->where('date', '<', $todayStart->toDateString())
                ->orderBy('date', 'desc')
                ->first();

            if ($lastTradingDayRecord) {
                $lastTradingDay = (object) ['trading_date' => $lastTradingDayRecord->date];
            }
        }

        if ($lastTradingDay) {
            $lastTradingDayStart = Carbon::parse($lastTradingDay->trading_date)->startOfDay();
            $lastTradingDayEnd = $lastTradingDayStart->copy()->endOfDay();
            $ranges['Last Open Day'] = [$lastTradingDayStart, $lastTradingDayEnd, 'fiveMin'];
        }

        foreach ($ranges as $period => [$start, $end, $preferredSource]) {
            $prices = collect();

            // Try preferred source first
            if ($preferredSource === 'fiveMin') {
                $prices = $asset->fiveMinutePrices()
                    ->whereBetween('ts', [$start, $end])
                    ->orderBy('ts', 'asc')
                    ->pluck('price');
            } elseif ($preferredSource === 'hourly') {
                $prices = $asset->hourlyPrices()
                    ->whereBetween('ts', [$start, $end])
                    ->orderBy('ts', 'asc')
                    ->pluck('price');
            } else {
                $prices = $asset->dailyPrices()
                    ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                    ->orderBy('date', 'asc')
                    ->pluck('price');
            }

            // Fall back to next best source if needed
            if ($prices->isEmpty() && $preferredSource !== 'hourly') {
                $prices = $asset->hourlyPrices()
                    ->whereBetween('ts', [$start, $end])
                    ->orderBy('ts', 'asc')
                    ->pluck('price');
            }

            if ($prices->isEmpty()) {
                $prices = $asset->dailyPrices()
                    ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                    ->orderBy('date', 'asc')
                    ->pluck('price');
            }

            if ($prices->count() >= 2) {
                $startPrice = $prices->first();
                $endPrice = $prices->last();
                $change = $endPrice - $startPrice;
                $changePercent = ($change / $startPrice) * 100;

                $priceStats[$period] = [
                    'change' => $change,
                    'changePercent' => $changePercent,
                ];
            }
        }

        return $priceStats;
    }

    private function getStats(AssetInfo $asset): array
    {
        $cacheKey = sprintf('watch-stats:%s:%s', $asset->symbol, $asset->asset_type);

        return Cache::remember($cacheKey, 3600, function () use ($asset) {
            return $this->computeStats($asset);
        });
    }

    private function computeStats(AssetInfo $asset): array
    {
        $stats = [
            '52WeekHigh' => 0,
            '52WeekLow' => 0,
            'avgVolume' => 0,
            'open' => 0,
            'previousClose' => 0,
            'dayHigh' => 0,
            'dayLow' => 0,
            'volume' => 0,
        ];

        $oneYearAgo = now()->subYear()->toDateString();
        $pricesLastYear = $asset->dailyPrices()
            ->mondayOnly()
            ->where('date', '>=', $oneYearAgo)
            ->get();

        if ($pricesLastYear->isNotEmpty()) {
            $stats['52WeekHigh'] = $pricesLastYear->max('high');
            $stats['52WeekLow'] = $pricesLastYear->min('low');
        }

        $stats['avgVolume'] = $asset->dailyPrices()
            ->mondayOnly()
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->avg('volume');

        $latestDailyPrice = $asset->dailyPrices()
            ->mondayOnly()
            ->orderBy('date', 'desc')
            ->first();

        if ($latestDailyPrice) {
            $todayDailyPrice = $asset->dailyPrices()
                ->mondayOnly()
                ->whereDate('date', now()->toDateString())
                ->first();

            $statsSource = $todayDailyPrice ?: $latestDailyPrice;

            $stats['open'] = $statsSource->open;
            $stats['volume'] = $statsSource->volume;
            $stats['previousClose'] = $asset->dailyPrices()
                ->mondayOnly()
                ->where('date', '<', $statsSource->date)
                ->orderBy('date', 'desc')
                ->first()?->price;

            // Calculate day's high and low from today's 5-minute prices
            $today = now()->toDateString();
            $todayFiveMinutePrices = $asset->fiveMinutePrices()
                ->whereDate('ts', $today)
                ->get(['price']);

            if ($todayFiveMinutePrices->isNotEmpty()) {
                $stats['dayHigh'] = $todayFiveMinutePrices->max('price');
                $stats['dayLow'] = $todayFiveMinutePrices->min('price');
            } else {
                // Fallback to daily price high/low if no 5-minute data available
                $stats['dayHigh'] = $statsSource->high;
                $stats['dayLow'] = $statsSource->low;
            }
        }

        return $stats;
    }
}
