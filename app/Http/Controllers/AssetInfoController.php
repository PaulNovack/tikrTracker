<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSymbolRequest;
use App\Jobs\Market\UpdateStockDataJob;
use App\Models\AssetInfo;
use App\Models\MarketSchedule;
use App\Services\Market\WikimediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class AssetInfoController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = $request->input('filter', 'stock');

        // Cache the asset list for 24 hours since asset info doesn't change frequently
        $cacheKey = "asset-info-index:{$filter}";
        $assets = Cache::remember($cacheKey, now()->addDay(), function () use ($filter) {
            $query = AssetInfo::query()
                ->orderBy('asset_type')
                ->orderBy('symbol');

            // Apply filter if specified
            if ($filter !== 'all') {
                $query->where('asset_type', $filter);
            }

            // Fetch ALL required data in one query - eliminate the need for separate description requests
            // This is more efficient than 213+ separate requests for descriptions
            return $query->select([
                'id',
                'symbol',
                'asset_type',
                'common_name',
                'description',  // Include description in initial load
                'sector',
            ])->get();
        });

        return Inertia::render('market-data/asset-info/index', [
            'assets' => $assets,
            'filter' => $filter,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $search = $request->input('search', '');
        $assetType = $request->input('asset_type');

        $query = AssetInfo::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('symbol', 'like', $search.'%')
                    ->orWhere('common_name', 'like', '%'.$search.'%');
            });
        }

        if ($assetType && $assetType !== 'all') {
            $query->where('asset_type', $assetType);
        }

        $results = $query
            ->orderByRaw('
                CASE 
                    WHEN symbol = ? THEN 1 
                    WHEN symbol LIKE ? THEN 2 
                    WHEN common_name LIKE ? THEN 3 
                    ELSE 4 
                END
            ', [$search, $search.'%', $search.'%'])
            ->orderBy('symbol')
            ->limit(50)
            ->get(['id', 'symbol', 'common_name', 'asset_type']);

        return response()->json($results);
    }

    public function getDescriptions(Request $request): JsonResponse
    {
        $idsParam = $request->input('ids', '');

        if (empty($idsParam)) {
            return response()->json([]);
        }

        // Parse comma-separated string into array of IDs
        $ids = array_filter(array_map('intval', explode(',', $idsParam)));

        if (empty($ids)) {
            return response()->json([]);
        }

        $descriptions = [];

        // Fetch each description with 30-day cache per asset
        foreach ($ids as $id) {
            $cacheKey = "asset-description:{$id}";
            $description = Cache::remember($cacheKey, now()->addDays(30), function () use ($id) {
                return AssetInfo::query()
                    ->where('id', $id)
                    ->select(['id', 'symbol', 'description', 'sector'])
                    ->first();
            });

            if ($description) {
                $descriptions[$id] = $description;
            }
        }

        return response()->json($descriptions);
    }

    public function show(AssetInfo $assetInfo, Request $request): Response
    {
        // Get custom date from request or default to today
        $customDate = $request->query('date');

        // Get today's market open time (9:30 AM ET) in UTC for filtering today's data
        $todayMarketOpenET = now('America/New_York')->setTime(9, 30, 0);
        $todayStart = $todayMarketOpenET->clone()->utc();

        // Get the latest 5-minute price (more current during trading hours)
        $latestFiveMinutePrice = $assetInfo->fiveMinutePrices()
            ->where('ts', '>=', $todayStart)
            ->orderBy('ts', 'desc')
            ->first();

        // Fallback: if market is closed (no today data), use most recent 5-min price from any day
        if (! $latestFiveMinutePrice) {
            $latestFiveMinutePrice = $assetInfo->fiveMinutePrices()
                ->orderBy('ts', 'desc')
                ->first();
        }

        // Cache ALL daily prices for this symbol (24 hours) - used for all stats calculations
        // This eliminates separate queries and instead does all calculations from cached data
        $dailyPricesCacheKey = "asset-daily-prices:{$assetInfo->id}:{$assetInfo->symbol}:{$assetInfo->asset_type}";
        $allDailyPrices = Cache::remember($dailyPricesCacheKey, now()->addDay(), function () use ($assetInfo) {
            return $assetInfo->dailyPrices()
                ->orderBy('date', 'asc')
                ->get();
        });

        $latestDailyPrice = $allDailyPrices->last();

        // Cache latest price for 1 hour
        $latestPriceCacheKey = "asset-latest-price:{$assetInfo->id}:{$assetInfo->symbol}:{$assetInfo->asset_type}";
        $latestPrice = Cache::remember($latestPriceCacheKey, now()->addHour(), function () use ($latestFiveMinutePrice, $latestDailyPrice) {
            // Use 5-minute price if available (more current), otherwise use daily price
            return $latestFiveMinutePrice ?: $latestDailyPrice;
        });

        // Check hourly data availability OUTSIDE cache to determine frontend flags
        // Use raw SQL for counts to avoid memory issues with Eloquent on large datasets
        // (hourly_prices table has 200K+ records)
        $hourlyDataCount1M = \DB::table('hourly_prices')
            ->where('symbol', $assetInfo->symbol)
            ->where('asset_type', $assetInfo->asset_type)
            ->where('ts', '>=', now('UTC')->subMonth())
            ->count();
        $hourlyDataCount3M = \DB::table('hourly_prices')
            ->where('symbol', $assetInfo->symbol)
            ->where('asset_type', $assetInfo->asset_type)
            ->where('ts', '>=', now('UTC')->subMonths(3))
            ->count();
        $hourlyDataCount6M = \DB::table('hourly_prices')
            ->where('symbol', $assetInfo->symbol)
            ->where('asset_type', $assetInfo->asset_type)
            ->where('ts', '>=', now('UTC')->subMonths(6))
            ->count();

        // Need at least 7 hours/day worth of data (e.g., 210 for 30 days)
        $hasEnoughHourlyData1M = $hourlyDataCount1M > 150; // ~1 month
        $hasEnoughHourlyData3M = $hourlyDataCount3M > 450; // ~3 months
        $hasEnoughHourlyData6M = $hourlyDataCount6M > 900; // ~6 months

        $hasEnoughHourlyData = $hasEnoughHourlyData1M; // For frontend flag

        // Determine the last open/trading day OUTSIDE the cache
        // This is needed for the frontend to display the correct date
        $lastOpenDay = null;
        if ($assetInfo->asset_type === 'stock') {
            $lastOpenDayModel = \App\Models\MarketSchedule::byMarketType('stock')
                ->where('date', '<', $todayStart->toDateString())
                ->whereIn('status', ['open', 'half_day'])
                ->orderBy('date', 'desc')
                ->first();
            if ($lastOpenDayModel) {
                $lastOpenDay = \Carbon\Carbon::parse($lastOpenDayModel->date)->toDateString();
            }
        } else {
            $lastTradingDayRecord = $assetInfo->dailyPrices()
                ->where('date', '<', $todayStart->toDateString())
                ->orderBy('date', 'desc')
                ->first();
            if ($lastTradingDayRecord) {
                $lastOpenDay = \Carbon\Carbon::parse($lastTradingDayRecord->date)->toDateString();
            }
        }

        // Cache chart data with smart TTL:
        // - 5 minutes during trading hours (9:30 AM - 4:30 PM ET) for real-time updates
        // - 24 hours after hours for stability
        // Invalidated when prices are updated via UpdateStockDataJob
        // CRITICAL: Move all data building into the cache closure to avoid re-executing queries
        // when the cache is hit. Only build data if not in cache.
        $cacheKey = "asset-chart-data:{$assetInfo->id}:{$assetInfo->symbol}:{$assetInfo->asset_type}";

        // Determine cache TTL based on market hours
        $currentTimeUTC = now('UTC');
        // Convert market hours from ET to UTC dynamically (handles EST/EDT)
        $marketOpenET = now('America/New_York')->setTime(9, 30, 0);
        $marketCloseET = now('America/New_York')->setTime(16, 30, 0);
        $marketOpenUTC = $marketOpenET->clone()->utc();
        $marketCloseUTC = $marketCloseET->clone()->utc();
        $cacheTTL = ($currentTimeUTC->gte($marketOpenUTC) && $currentTimeUTC->lt($marketCloseUTC))
            ? now()->addMinutes(5) // 5-minute cache during trading hours
            : now()->addDay(); // 24-hour cache after hours

        $chartData = Cache::remember($cacheKey, $cacheTTL, function () use ($assetInfo, $todayStart, $hasEnoughHourlyData3M, $hasEnoughHourlyData6M, $lastOpenDay) {
            // For intraday charts (1D, 5D), use current/recent trading days, not rolling 24h/120h
            // Markets are open 9:30 AM - 4:00 PM ET, Mon-Fri
            // Database timestamps are in UTC, so we work in UTC
            $fiveDaysStart = now('UTC')->subDays(7)->startOfDay(); // Go back 7 calendar days to catch 5 trading days

            // Get 1D data (today's trading)
            // Check today's market schedule for actual closing time
            // Convert market hours from ET to UTC dynamically (handles EST/EDT)
            $marketOpenET = now('America/New_York')->setTime(9, 30, 0);
            $marketCloseET = now('America/New_York')->setTime(16, 0, 0);
            $marketOpenUTC = $marketOpenET->clone()->utc();
            $marketCloseUTC = $marketCloseET->clone()->utc();

            // Override close time if today has a different schedule (e.g., half day)
            if ($assetInfo->asset_type === 'stock') {
                $todaySchedule = \App\Models\MarketSchedule::byMarketType('stock')
                    ->where('date', now('UTC')->toDateString())
                    ->first();

                if ($todaySchedule && $todaySchedule->closes_at) {
                    // Convert EST closing time to UTC
                    // Handle both Carbon objects and string formats
                    $closeTimeString = $todaySchedule->closes_at;

                    // If it's a Carbon instance, extract just the time part
                    if ($closeTimeString instanceof \Carbon\Carbon) {
                        $closeTimeString = $closeTimeString->format('H:i:s');
                    }

                    // If it's still a datetime string, extract just the time part
                    if (is_string($closeTimeString) && str_contains($closeTimeString, ' ')) {
                        $closeTimeString = explode(' ', $closeTimeString)[1];
                    }

                    // Parse as time only and set to today in EST timezone
                    $closeTimeEST = \Carbon\Carbon::createFromFormat('H:i:s', $closeTimeString, 'America/New_York');
                    $closeTimeEST->setDate(now()->year, now()->month, now()->day);
                    $marketCloseUTC = $closeTimeEST->utc();
                }
            }

            $currentTimeUTC = now('UTC');

            // Get actual data for today
            $actualOneDayData = $assetInfo->oneMinutePrices()
                ->where('ts', '>=', $todayStart)
                ->orderBy('ts', 'asc')
                ->get(['ts as time', 'price']);

            // Create full day timeline with 1-minute intervals
            $oneDayData = collect();
            if ($currentTimeUTC->gte($marketOpenUTC)) {
                // Market is open or closed for today - show the full day timeline
                $timeSlot = $marketOpenUTC->copy();
                $endTime = min($currentTimeUTC, $marketCloseUTC);

                // Create a map of actual data keyed by timestamp
                $dataMap = $actualOneDayData->keyBy(function ($item) {
                    return \Carbon\Carbon::parse($item->time)->format('Y-m-d H:i:00');
                });

                // Build timeline from market open to current time (or market close)
                // Forward-fill missing minutes with the last known price to avoid chart gaps
                $lastKnownPrice = null;
                while ($timeSlot->lte($endTime)) {
                    $timeKey = $timeSlot->format('Y-m-d H:i:00');
                    if ($dataMap->has($timeKey)) {
                        $lastKnownPrice = $dataMap[$timeKey]->price;
                    }

                    $oneDayData->push((object) [
                        'time' => $timeSlot->toDateTimeString(),
                        'price' => $lastKnownPrice,
                    ]);

                    $timeSlot->addMinutes(1);
                }
            } else {
                // Market hasn't opened yet today
                $oneDayData = $actualOneDayData;
            }

            // Get last trading day data
            $lastOpenDayData = collect();

            if ($lastOpenDay) {
                $lastTradingDayStart = \Carbon\Carbon::parse($lastOpenDay)->startOfDay();
                $lastOpenDayData = $assetInfo->fiveMinutePrices()
                    ->whereDate('ts', $lastTradingDayStart)
                    ->orderBy('ts', 'asc')
                    ->get(['ts as time', 'price']);
            }

            return [
                '1D' => $oneDayData,
                'Last Open Day' => $lastOpenDayData,
                '5D' => $assetInfo->fiveMinutePrices()
                    ->where('ts', '>=', $fiveDaysStart)
                    ->orderBy('ts', 'asc')
                    ->get(['ts as time', 'price']),
                '1M' => $assetInfo->hourlyPrices()
                    ->where('ts', '>=', now('UTC')->subMonth())
                    ->orderBy('ts', 'asc')
                    ->limit(1000)
                    ->get(['ts as time', 'price']),
                '3M' => $hasEnoughHourlyData3M
                    ? $assetInfo->hourlyPrices()
                        ->where('ts', '>=', now('UTC')->subMonths(3))
                        ->orderBy('ts', 'asc')
                        ->limit(2500)
                        ->get(['ts as time', 'price'])
                    : $assetInfo->dailyPrices()
                        ->where('date', '>=', now('UTC')->subMonths(3))
                        ->orderBy('date', 'asc')
                        ->get(['date as time', 'price']),
                '6M' => $hasEnoughHourlyData6M
                    ? $assetInfo->hourlyPrices()
                        ->where('ts', '>=', now('UTC')->subMonths(6))
                        ->orderBy('ts', 'asc')
                        ->limit(5000)
                        ->get(['ts as time', 'price'])
                    : $assetInfo->dailyPrices()
                        ->where('date', '>=', now('UTC')->subMonths(6))
                        ->orderBy('date', 'asc')
                        ->get(['date as time', 'price']),
                '1Y' => $assetInfo->dailyPrices()
                    ->where('date', '>=', now('UTC')->subYear())
                    ->orderBy('date', 'asc')
                    ->get(['date as time', 'price']),
            ];
        });

        // Calculate price changes for different time periods using CACHED daily prices
        $priceStats = [];
        if ($latestPrice && $allDailyPrices->count() > 0) {
            // For 1D, use today's 5-minute data to calculate intraday changes
            // Filter out null prices (from unfilled timeline slots)
            if ($chartData['1D']->count() > 0) {
                $nonNullPrices = $chartData['1D']->filter(fn ($item) => $item->price !== null);
                if ($nonNullPrices->count() > 0) {
                    $firstPrice = $nonNullPrices->first()->price;
                    $lastPrice = $nonNullPrices->last()->price;
                    $change = $lastPrice - $firstPrice;
                    $changePercent = $firstPrice > 0 ? ($change / $firstPrice) * 100 : 0;
                    $priceStats['1D'] = [
                        'change' => $change,
                        'changePercent' => $changePercent,
                    ];
                }
            }

            // For Last Open Day, calculate from that trading day
            if ($chartData['Last Open Day']->count() > 0) {
                $nonNullPrices = $chartData['Last Open Day']->filter(fn ($item) => $item->price !== null);
                if ($nonNullPrices->count() > 0) {
                    $firstPrice = $nonNullPrices->first()->price;
                    $lastPrice = $nonNullPrices->last()->price;
                    $change = $lastPrice - $firstPrice;
                    $changePercent = $firstPrice > 0 ? ($change / $firstPrice) * 100 : 0;
                    $priceStats['Last Open Day'] = [
                        'change' => $change,
                        'changePercent' => $changePercent,
                    ];
                }
            }

            // For 5D, use 5-minute data
            if ($chartData['5D']->count() > 0) {
                $nonNullPrices = $chartData['5D']->filter(fn ($item) => $item->price !== null);
                if ($nonNullPrices->count() > 0) {
                    $firstPrice = $nonNullPrices->first()->price;
                    $lastPrice = $nonNullPrices->last()->price;
                    $change = $lastPrice - $firstPrice;
                    $changePercent = $firstPrice > 0 ? ($change / $firstPrice) * 100 : 0;
                    $priceStats['5D'] = [
                        'change' => $change,
                        'changePercent' => $changePercent,
                    ];
                }
            }

            // For longer periods, calculate from CACHED daily prices collection (NO QUERIES!)
            $periods = [
                '1M' => now('UTC')->subMonth(),
                '3M' => now('UTC')->subMonths(3),
                '6M' => now('UTC')->subMonths(6),
                '1Y' => now('UTC')->subYear(),
            ];

            foreach ($periods as $label => $date) {
                // Try to find oldest price from chartData for this period
                $periodChartData = $chartData[$label] ?? [];

                if (! empty($periodChartData)) {
                    // Get first and last price from the chart data for this period
                    $firstPrice = $periodChartData[0]['price'] ?? null;
                    $lastPrice = $periodChartData[count($periodChartData) - 1]['price'] ?? null;

                    if ($firstPrice && $lastPrice && $firstPrice > 0) {
                        $change = $lastPrice - $firstPrice;
                        $changePercent = ($change / $firstPrice) * 100;
                        $priceStats[$label] = [
                            'change' => $change,
                            'changePercent' => $changePercent,
                        ];
                    }
                } else {
                    // Fallback to cached daily prices if chart data is not available
                    $oldPrice = $allDailyPrices
                        ->filter(fn ($p) => \Carbon\Carbon::parse($p->date)->lte($date))
                        ->last();

                    if ($oldPrice && $oldPrice->price > 0) {
                        $change = $latestPrice->price - $oldPrice->price;
                        $changePercent = ($change / $oldPrice->price) * 100;
                        $priceStats[$label] = [
                            'change' => $change,
                            'changePercent' => $changePercent,
                        ];
                    }
                }
                // If no data found that far back, skip this period (don't show 0%)
            }
        }

        // Get key statistics - all calculated from CACHED daily prices collection
        $stats = [];
        if ($latestPrice && $allDailyPrices->count() > 0) {
            // Get 52-week high/low from cached collection (NO QUERY!)
            $yearStart = now()->subYear();
            $yearData = $allDailyPrices->filter(fn ($p) => \Carbon\Carbon::parse($p->date)->gte($yearStart));

            $stats['52WeekHigh'] = $yearData->count() > 0 ? $yearData->max('high') : null;
            $stats['52WeekLow'] = $yearData->count() > 0 ? $yearData->min('low') : null;

            // Get average volume (30 days) from cached collection (NO QUERY!)
            $thirtyDaysAgo = now()->subDays(30);
            $thirtyDayData = $allDailyPrices->filter(fn ($p) => \Carbon\Carbon::parse($p->date)->gte($thirtyDaysAgo));
            $stats['avgVolume'] = $thirtyDayData->count() > 0 ? $thirtyDayData->avg('volume') : null;

            // If latest price is from 5-minute data, get today's stats from latest daily price
            // 5-minute data only has price, not open/high/low/volume
            if ($latestFiveMinutePrice && $latestDailyPrice) {
                // Use today's intraday stats if available, otherwise use yesterday's
                $todayStr = now()->format('Y-m-d');
                $todayDailyPrice = $allDailyPrices->first(fn ($p) => \Carbon\Carbon::parse($p->date)->format('Y-m-d') === $todayStr);

                $statsSource = $todayDailyPrice ?: $latestDailyPrice;

                $stats['open'] = $statsSource->open;
                $stats['volume'] = $statsSource->volume;

                // Calculate day's high and low from today's 5-minute prices
                $today = now()->toDateString();
                $todayFiveMinutePrices = $assetInfo->fiveMinutePrices()
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

                // Find previous close from cached collection
                $statSourceDate = \Carbon\Carbon::parse($statsSource->date);
                $previousClose = $allDailyPrices
                    ->filter(fn ($p) => \Carbon\Carbon::parse($p->date)->lt($statSourceDate))
                    ->last();
                $stats['previousClose'] = $previousClose?->price;
            } elseif ($latestDailyPrice) {
                // Only have daily data
                $stats['open'] = $latestDailyPrice->open;
                $stats['volume'] = $latestDailyPrice->volume;

                // Calculate day's high and low from today's 5-minute prices if available
                $today = now()->toDateString();
                $todayFiveMinutePrices = $assetInfo->fiveMinutePrices()
                    ->whereDate('ts', $today)
                    ->get(['price']);

                if ($todayFiveMinutePrices->isNotEmpty()) {
                    $stats['dayHigh'] = $todayFiveMinutePrices->max('price');
                    $stats['dayLow'] = $todayFiveMinutePrices->min('price');
                } else {
                    // Fallback to daily price high/low
                    $stats['dayHigh'] = $latestDailyPrice->high;
                    $stats['dayLow'] = $latestDailyPrice->low;
                }

                // Find previous close from cached collection
                $latestDailyDate = \Carbon\Carbon::parse($latestDailyPrice->date);
                $previousClose = $allDailyPrices
                    ->filter(fn ($p) => \Carbon\Carbon::parse($p->date)->lt($latestDailyDate))
                    ->last();
                $stats['previousClose'] = $previousClose?->price;
            }
        }

        // Check if today is a market holiday or weekend for stocks
        $todayMarketStatus = null;
        if ($assetInfo->asset_type === 'stock') {
            $todayMarketStatus = MarketSchedule::byMarketType('stock')
                ->where('date', $todayStart->toDateString())
                ->first();

            // Add a formatted date string for frontend to avoid parsing issues
            if ($todayMarketStatus) {
                $todayMarketStatus->formatted_date = $todayMarketStatus->date->format('Y-m-d');
            }
        }

        // Check if the current user is watching this asset
        $isWatched = auth()->user()->watches()
            ->where('asset_info_id', $assetInfo->id)
            ->exists();

        return Inertia::render('market-data/asset-info/show', [
            'asset' => $assetInfo,
            'latestPrice' => $latestPrice,
            'chartData' => $chartData,
            'priceStats' => $priceStats,
            'stats' => $stats,
            'hasEnoughHourlyData' => $hasEnoughHourlyData,
            'todayMarketStatus' => $todayMarketStatus,
            'lastOpenDay' => $lastOpenDay,
            'isWatched' => $isWatched,
            'customDate' => $customDate,
        ]);
    }

    public function getLiveQuote(AssetInfo $assetInfo): JsonResponse
    {
        if ($assetInfo->asset_type !== 'stock') {
            return response()->json([
                'quote' => null,
            ]);
        }

        $quote = DB::table('latest_stock_quotes')
            ->where('symbol', $assetInfo->symbol)
            ->first([
                'symbol',
                'bid_price',
                'ask_price',
                'bid_size',
                'ask_size',
                'quote_ts_utc',
                'received_at_utc',
                'updated_at',
            ]);

        if (! $quote) {
            return response()->json([
                'quote' => null,
            ]);
        }

        $bid = is_numeric($quote->bid_price) ? (float) $quote->bid_price : null;
        $ask = is_numeric($quote->ask_price) ? (float) $quote->ask_price : null;
        $spread = ($bid !== null && $ask !== null) ? round($ask - $bid, 4) : null;
        $spreadPct = ($bid !== null && $ask !== null && ($bid + $ask) > 0)
            ? round((($ask - $bid) / (($bid + $ask) / 2)) * 100, 4)
            : null;

        return response()->json([
            'quote' => [
                'symbol' => $quote->symbol,
                'bid_price' => $quote->bid_price,
                'ask_price' => $quote->ask_price,
                'bid_size' => $quote->bid_size,
                'ask_size' => $quote->ask_size,
                'quote_ts_utc' => $quote->quote_ts_utc,
                'received_at_utc' => $quote->received_at_utc,
                'updated_at' => $quote->updated_at,
                'spread' => $spread,
                'spread_pct' => $spreadPct,
            ],
        ]);
    }

    public function getMaxChartData(AssetInfo $assetInfo): \Illuminate\Http\JsonResponse
    {
        $cacheKey = "asset-max-chart-data:{$assetInfo->id}:{$assetInfo->symbol}:{$assetInfo->asset_type}";

        $maxData = Cache::remember($cacheKey, now()->addDay(), function () use ($assetInfo) {
            return $assetInfo->dailyPrices()
                ->orderBy('date', 'asc')
                ->get(['date as time', 'price'])
                ->map(fn ($item) => [
                    'time' => $item->time,
                    'price' => $item->price,
                ])
                ->values()
                ->all();
        });

        return response()->json([
            'MAX' => $maxData,
        ]);
    }

    public function getCustomDateChartData(AssetInfo $assetInfo, Request $request): \Illuminate\Http\JsonResponse
    {
        $date = $request->query('date');

        if (! $date) {
            return response()->json(['error' => 'Date parameter is required'], 400);
        }

        try {
            $targetDate = \Carbon\Carbon::parse($date);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid date format'], 400);
        }

        $cacheKey = "asset-custom-date-chart:{$assetInfo->id}:{$date}";

        $chartData = Cache::remember($cacheKey, now()->addDay(), function () use ($assetInfo, $targetDate) {
            // Get 5-minute prices for the specified date
            $fiveMinutePrices = $assetInfo->fiveMinutePrices()
                ->whereDate('ts', $targetDate->toDateString())
                ->orderBy('ts', 'asc')
                ->get(['ts as time', 'price'])
                ->map(fn ($item) => [
                    'time' => \Carbon\Carbon::parse($item->time)->format('Y-m-d H:i:s'),
                    'price' => (string) $item->price,
                ]);

            return $fiveMinutePrices;
        });

        // Calculate price stats for the day
        $priceStats = null;
        if ($chartData->isNotEmpty()) {
            $firstPrice = (float) $chartData->first()['price'];
            $lastPrice = (float) $chartData->last()['price'];

            if ($firstPrice > 0) {
                $change = $lastPrice - $firstPrice;
                $changePercent = ($change / $firstPrice) * 100;
                $priceStats = [
                    'change' => $change,
                    'changePercent' => $changePercent,
                ];
            }
        }

        return response()->json([
            'chartData' => $chartData,
            'priceStats' => $priceStats,
            'date' => $targetDate->format('Y-m-d'),
        ]);
    }

    public function getCandlestickChartData(AssetInfo $assetInfo, Request $request): JsonResponse
    {
        $range = (string) $request->query('range', '1D');
        $date = $request->query('date');

        if ($date) {
            try {
                $targetDate = \Carbon\Carbon::parse($date, 'America/New_York');
            } catch (\Exception) {
                return response()->json(['error' => 'Invalid date format'], 400);
            }

            $startTime = $targetDate->copy()->startOfDay()->utc();
            $endTime = $targetDate->copy()->endOfDay()->utc();
        } else {
            $now = now('UTC');
            $oldestFiveMinutePrice = $assetInfo->fiveMinutePrices()->orderBy('ts', 'asc')->first();

            $startTime = match ($range) {
                '1D' => $now->copy()->startOfDay(),
                'Last Open Day' => $now->copy()->subDay()->startOfDay(),
                '5D' => $now->copy()->subDays(5)->startOfDay(),
                '1M' => $now->copy()->subMonth(),
                '3M' => $now->copy()->subMonths(3),
                '6M' => $now->copy()->subMonths(6),
                '1Y' => $now->copy()->subYear(),
                'MAX' => $oldestFiveMinutePrice?->ts
                    ? \Carbon\Carbon::parse($oldestFiveMinutePrice->ts)
                    : $now->copy()->subYear(),
                default => $now->copy()->startOfDay(),
            };
            $endTime = $now;
        }

        $cacheKey = sprintf(
            'asset-candlestick-data:%s:%s:%s',
            $assetInfo->id,
            $startTime->timestamp,
            $endTime->timestamp,
        );

        $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($assetInfo, $startTime, $endTime) {
            return $assetInfo->oneMinutePrices()
                ->whereBetween('ts', [$startTime, $endTime])
                ->orderBy('ts', 'asc')
                ->get(['ts', 'price', 'open', 'high', 'low', 'volume'])
                ->map(function ($item) {
                    $timestamp = $item->ts instanceof \DateTimeInterface
                        ? $item->ts
                        : \Carbon\Carbon::parse($item->ts, 'UTC');

                    return [
                        'time' => $timestamp->utc()->format('Y-m-d H:i:s'),
                        'open' => (float) ($item->open ?? $item->price),
                        'high' => (float) ($item->high ?? $item->price),
                        'low' => (float) ($item->low ?? $item->price),
                        'close' => (float) $item->price,
                        'volume' => (int) ($item->volume ?? 0),
                    ];
                })
                ->values()
                ->all();
        });

        return response()->json([
            'data' => $data,
            'interval' => '1m',
            'range' => $range,
            'date' => $date,
        ]);
    }

    public function store(StoreSymbolRequest $request)
    {
        // Only admins can add new symbols
        if (! auth()->user()?->isAdmin()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'Only administrators can add new symbols.',
                ], 403);
            }

            abort(403, 'Only administrators can add new symbols.');
        }

        $validated = $request->validated();

        // Check if asset already exists
        $existing = AssetInfo::where('symbol', $validated['symbol'])
            ->where('asset_type', $validated['asset_type'])
            ->first();

        if ($existing) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'An asset with this symbol and type already exists in the system.',
                ], 409);
            }

            return back()->withErrors([
                'symbol' => 'An asset with this symbol and type already exists in the system.',
            ]);
        }

        // Fetch description from Wikimedia if not provided
        $description = $validated['description'] ?? null;
        if (! $description) {
            $description = WikimediaService::fetchDescription(
                $validated['symbol'],
                $validated['common_name']
            );
        }
            $chartData = Cache::remember($cacheKey, $cacheTTL, $buildChartData);

            $hasTodayOneMinuteData = $assetInfo->isStock()
                && $assetInfo->oneMinutePrices()
                    ->where('ts', '>=', $todayStart)
                    ->exists();

            if ($hasTodayOneMinuteData && isset($chartData['1D']) && $chartData['1D']->isEmpty()) {
                Cache::forget($cacheKey);
                $chartData = Cache::remember($cacheKey, now()->addMinutes(5), $buildChartData);
            }

        // Create the new asset
        $asset = AssetInfo::create([
            'symbol' => $validated['symbol'],
            'asset_type' => $validated['asset_type'],
            'common_name' => $validated['common_name'],
            'description' => $description,
            'sector' => $validated['sector'] ?? null,
        ]);

        // Queue job to fetch market data for this specific symbol only
        UpdateStockDataJob::dispatch($validated['symbol'], $validated['asset_type']);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Asset created successfully',
                'asset' => $asset,
                'note' => 'Market data fetching has been queued for this symbol.',
            ], 201);
        }

        return redirect()->route('asset-info.show', ['assetInfo' => $asset])
            ->with('success', "{$asset->common_name} ({$asset->symbol}) has been added successfully!");
    }
}
