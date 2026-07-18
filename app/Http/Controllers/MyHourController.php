<?php

namespace App\Http\Controllers;

use App\Models\AssetInfo;
use App\Models\FiveMinutePrice;
use App\Models\Watch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class MyHourController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();

        // Get filter from request
        $assetTypeFilter = $request->get('filter', 'all'); // Default to all for watchlist

        // Cache key for this user's request
        $cacheKey = "my_hour_user_{$user->id}_{$assetTypeFilter}";

        // Try to get from cache first
        $data = Cache::get($cacheKey);

        if ($data === null) {
            // Cache miss - generate fresh data
            $data = $this->getWatchlistHourlyData($user, $assetTypeFilter);

            // Cache the fresh data for this user (2 minutes)
            Cache::put($cacheKey, $data, 120);

            // Optional: Log cache miss for monitoring
            \Log::info("Cache miss for my-hour user {$user->id}: {$assetTypeFilter}");
        }

        return Inertia::render('MyHour', [
            'stocks' => $data['stocks'],
            'timeIntervals' => $data['timeIntervals'],
            'timestamp' => $data['timestamp'],
            'timestampEst' => $data['timestampEst'],
            'assetTypeFilter' => $assetTypeFilter,
            'totalAnalyzed' => $data['totalAnalyzed'],
            'dataFreshness' => $data['dataFreshness'],
        ]);
    }

    private function getWatchlistHourlyData($user, string $assetTypeFilter): array
    {
        // Get user's watchlist with asset info
        $watchlistQuery = Watch::where('user_id', $user->id)
            ->with('asset');

        // Apply asset type filter if not 'all'
        if ($assetTypeFilter !== 'all') {
            $watchlistQuery->whereHas('asset', function ($query) use ($assetTypeFilter) {
                $query->where('asset_type', $assetTypeFilter);
            });
        }

        $watchlist = $watchlistQuery->get();

        if ($watchlist->isEmpty()) {
            return [
                'stocks' => [],
                'timeIntervals' => [],
                'timestamp' => now()->toDateTimeString(),
                'timestampEst' => now()->setTimezone('America/New_York')->format('M j, Y g:i A T'),
                'totalAnalyzed' => 0,
                'dataFreshness' => 'No watchlist items found',
            ];
        }

        // Get the symbols from watchlist
        $symbols = $watchlist->pluck('asset.symbol')->toArray();

        // Get the latest timestamp for the symbols in the watchlist
        $latestTimestamp = FiveMinutePrice::whereIn('symbol', $symbols)
            ->when($assetTypeFilter !== 'all', function ($query) use ($assetTypeFilter) {
                $query->where('asset_type', $assetTypeFilter);
            })
            ->orderBy('ts', 'desc')
            ->value('ts');

        if (! $latestTimestamp) {
            return [
                'stocks' => [],
                'timeIntervals' => [],
                'timestamp' => null,
                'timestampEst' => null,
                'totalAnalyzed' => 0,
                'dataFreshness' => 'No price data found for watchlist',
            ];
        }

        // Calculate one hour back from the latest timestamp (not from now)
        $oneHourAgo = date('Y-m-d H:i:s', strtotime('-1 hour', strtotime($latestTimestamp)));

        // Generate 5-minute intervals for the last hour (excluding -50M, -40M, -35M)
        $timeIntervals = [];
        $excludeMinutes = [50, 40, 35];
        for ($i = 0; $i < 60; $i += 5) {
            if (in_array($i, $excludeMinutes)) {
                continue; // Skip -50M, -40M, -35M intervals
            }
            $intervalTime = date('Y-m-d H:i:s', strtotime("-{$i} minutes", strtotime($latestTimestamp)));
            $timeIntervals[] = [
                'timestamp' => $intervalTime,
                'label' => "-{$i}m",
                'minutesAgo' => $i,
            ];
        }

        // Reverse to show oldest to newest
        $timeIntervals = array_reverse($timeIntervals);

        // Get all price data for the time window in one query (limited to watchlist symbols)
        $allPriceData = FiveMinutePrice::query()
            ->whereIn('symbol', $symbols)
            ->where('ts', '>=', $oneHourAgo)
            ->where('ts', '<=', $latestTimestamp)
            ->when($assetTypeFilter !== 'all', fn ($q) => $q->where('asset_type', $assetTypeFilter))
            ->orderBy('symbol')
            ->orderBy('ts')
            ->get(['symbol', 'ts', 'price', 'open', 'high', 'low', 'volume', 'asset_type']);

        // Get all asset info in one query for symbols that have data
        $symbolsWithData = $allPriceData->pluck('symbol')->unique();
        $assetInfoMap = AssetInfo::whereIn('symbol', $symbolsWithData)
            ->get(['symbol', 'id', 'asset_type', 'common_name'])
            ->keyBy('symbol');

        // Group price data by symbol
        $priceDataBySymbol = $allPriceData->groupBy('symbol');

        $watchlistStocks = [];

        foreach ($priceDataBySymbol as $symbol => $symbolPriceData) {
            if ($symbolPriceData->count() < 2) {
                continue; // Need at least 2 data points to calculate change
            }

            $firstPrice = $symbolPriceData->first();
            $lastPrice = $symbolPriceData->last();

            // Calculate total percentage change over the hour
            $baselinePrice = (float) ($firstPrice->open ?? $firstPrice->price);
            $currentPrice = (float) $lastPrice->price;
            $totalPercentChange = (($currentPrice - $baselinePrice) / $baselinePrice) * 100;

            // Get asset info
            $assetInfo = $assetInfoMap->get($symbol);

            // Calculate price changes for each 5-minute interval
            $intervalData = [];
            $previousPrice = $baselinePrice;

            foreach ($timeIntervals as $interval) {
                // Find the closest price data to this interval time
                $intervalPrice = $symbolPriceData
                    ->where('ts', '<=', $interval['timestamp'])
                    ->last();

                if ($intervalPrice) {
                    $currentIntervalPrice = (float) $intervalPrice->price;
                    $percentChange = (($currentIntervalPrice - $previousPrice) / $previousPrice) * 100;
                    $intervalData[] = [
                        'timestamp' => $interval['timestamp'],
                        'price' => round($currentIntervalPrice, 2),
                        'percentChange' => round($percentChange, 2),
                        'volume' => (int) $intervalPrice->volume,
                    ];
                    $previousPrice = $currentIntervalPrice;
                } else {
                    // Ensure numeric fallbacks instead of null to prevent frontend errors
                    $intervalData[] = [
                        'timestamp' => $interval['timestamp'],
                        'price' => 0.0,          // Use 0.0 instead of null
                        'percentChange' => 0.0,  // Use 0.0 instead of null
                        'volume' => 0,           // Use 0 instead of null
                    ];
                }
            }

            $watchlistStocks[] = [
                'symbol' => $symbol,
                'asset_id' => $assetInfo->id ?? null,
                'asset_type' => $assetInfo->asset_type ?? 'stock',
                'company_name' => $assetInfo->common_name ?? $symbol,
                'baselinePrice' => round($baselinePrice, 2),
                'currentPrice' => round($currentPrice, 2),
                'totalPercentChange' => round($totalPercentChange, 2),
                'intervalData' => $intervalData,
                'totalVolume' => (int) $symbolPriceData->sum('volume'),
                'highPrice' => round((float) $symbolPriceData->max('high'), 2),
                'lowPrice' => round((float) $symbolPriceData->min('low'), 2),
            ];
        }

        // Sort by total percentage change (highest first)
        usort($watchlistStocks, function ($a, $b) {
            return $b['totalPercentChange'] <=> $a['totalPercentChange'];
        });

        return [
            'stocks' => $watchlistStocks,
            'timeIntervals' => $timeIntervals,
            'timestamp' => $latestTimestamp,
            'timestampEst' => date('M j, Y g:i A T', strtotime($latestTimestamp)),
            'totalAnalyzed' => count($symbolsWithData),
            'dataFreshness' => $this->calculateDataFreshness($latestTimestamp),
        ];
    }

    private function calculateDataFreshness(string $timestamp): string
    {
        $minutesAgo = now()->diffInMinutes($timestamp);

        if ($minutesAgo < 5) {
            return 'Live';
        } elseif ($minutesAgo < 30) {
            return "Updated {$minutesAgo} minutes ago";
        } elseif ($minutesAgo < 60) {
            return 'Updated within the hour';
        } else {
            $hoursAgo = now()->diffInHours($timestamp);

            return "Updated {$hoursAgo} hours ago";
        }
    }
}
