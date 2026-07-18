<?php

namespace App\Http\Controllers;

use App\Models\AssetInfo;
use App\Models\FiveMinutePrice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class RisingHourController extends Controller
{
    public function index(Request $request): Response
    {
        // Get filter from request
        $assetTypeFilter = $request->get('filter', 'stock'); // Default to stocks

        // Cache key for this request
        $cacheKey = "rising_hour_{$assetTypeFilter}";

        // Try to get from cache first
        $data = Cache::get($cacheKey);

        if ($data === null) {
            // Cache miss - generate fresh data
            $data = $this->getHourlyRisingStocks($assetTypeFilter);

            // Cache the fresh data for other users (2 minutes)
            Cache::put($cacheKey, $data, 120);

            // Optional: Log cache miss for monitoring
            \Log::info("Cache miss for rising-hour: {$assetTypeFilter}");
        }

        return Inertia::render('RisingHour', [
            'stocks' => $data['stocks'],
            'timeIntervals' => $data['timeIntervals'],
            'timestamp' => $data['timestamp'],
            'timestampEst' => $data['timestampEst'],
            'assetTypeFilter' => $assetTypeFilter,
            'totalAnalyzed' => $data['totalAnalyzed'],
            'dataFreshness' => $data['dataFreshness'],
        ]);
    }

    private function getHourlyRisingStocks(string $assetTypeFilter): array
    {
        if ($assetTypeFilter === 'all') {
            // For 'all' assets, get rising stocks from each asset type's latest data
            return $this->getAllAssetTypesRisingStocks();
        }

        // Find the most recent timestamp with data for specific asset type
        $latestTimestamp = FiveMinutePrice::query()
            ->where('asset_type', $assetTypeFilter)
            ->orderByDesc('ts')
            ->value('ts');

        if (! $latestTimestamp) {
            return [
                'stocks' => [],
                'timeIntervals' => [],
                'timestamp' => null,
                'timestampEst' => null,
                'totalAnalyzed' => 0,
                'dataFreshness' => 'No data available',
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

        // Get all price data for the time window in one query
        $allPriceData = FiveMinutePrice::query()
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

        $risingStocks = [];

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

            // Skip if not rising enough
            if ($totalPercentChange <= 0.01) {
                continue;
            }

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

            $risingStocks[] = [
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
        usort($risingStocks, function ($a, $b) {
            return $b['totalPercentChange'] <=> $a['totalPercentChange'];
        });

        // Take top 100
        $risingStocks = array_slice($risingStocks, 0, 100);

        return [
            'stocks' => $risingStocks,
            'timeIntervals' => $timeIntervals,
            'timestamp' => $latestTimestamp,
            'timestampEst' => date('M j, Y g:i A T', strtotime($latestTimestamp)),
            'totalAnalyzed' => count($symbolsWithData),
            'dataFreshness' => $this->calculateDataFreshness($latestTimestamp),
        ];
    }

    private function getAllAssetTypesRisingStocks(): array
    {
        // Get rising stocks from each asset type using their respective latest timestamps
        $allRisingStocks = [];
        $allTimeIntervals = [];
        $latestTimestampOverall = null;
        $totalAnalyzed = 0;

        // Get distinct asset types
        $assetTypes = FiveMinutePrice::query()
            ->distinct()
            ->pluck('asset_type')
            ->toArray();

        foreach ($assetTypes as $assetType) {
            $assetData = $this->getHourlyRisingStocks($assetType);

            if (! empty($assetData['stocks'])) {
                $allRisingStocks = array_merge($allRisingStocks, $assetData['stocks']);
            }

            if (! empty($assetData['timeIntervals']) && empty($allTimeIntervals)) {
                // Use time intervals from the first asset type that has data
                $allTimeIntervals = $assetData['timeIntervals'];
            }

            $totalAnalyzed += $assetData['totalAnalyzed'] ?? 0;

            // Use the most recent timestamp across all asset types
            if ($assetData['timestamp'] &&
                (! $latestTimestampOverall || $assetData['timestamp'] > $latestTimestampOverall)) {
                $latestTimestampOverall = $assetData['timestamp'];
            }
        }

        // Sort all rising stocks by total percentage change (highest first)
        usort($allRisingStocks, function ($a, $b) {
            return $b['totalPercentChange'] <=> $a['totalPercentChange'];
        });

        // Take top 100 across all asset types
        $allRisingStocks = array_slice($allRisingStocks, 0, 100);

        return [
            'stocks' => $allRisingStocks,
            'timeIntervals' => $allTimeIntervals,
            'timestamp' => $latestTimestampOverall,
            'timestampEst' => $latestTimestampOverall ? date('M j, Y g:i A T', strtotime($latestTimestampOverall)) : null,
            'totalAnalyzed' => $totalAnalyzed,
            'dataFreshness' => $latestTimestampOverall ? $this->calculateDataFreshness($latestTimestampOverall) : 'No data available',
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
