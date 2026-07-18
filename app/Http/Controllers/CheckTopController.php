<?php

namespace App\Http\Controllers;

use App\Models\AssetInfo;
use App\Models\FiveMinutePrice;
use App\Services\Market\PriceToppingScanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CheckTopController extends Controller
{
    /**
     * Display the check top analysis page.
     */
    public function index(Request $request): Response
    {
        // Get filter from request
        $assetTypeFilter = $request->get('filter', 'stock'); // Default to stocks
        $backtestTime = $request->get('backtest_time'); // Optional backtest time

        // Check data availability and freshness for requested asset type
        $requestedTypeData = $this->checkDataFreshness($assetTypeFilter);
        $actualAssetType = $assetTypeFilter;

        // If requested data is too old or missing, fallback to stock data if available
        if ($assetTypeFilter === 'crypto' && (! $requestedTypeData || $requestedTypeData['isStale'])) {
            $stockData = $this->checkDataFreshness('stock');
            if ($stockData && ! $stockData['isStale']) {
                $actualAssetType = 'stock';
                \Log::info('Crypto data is stale/missing, falling back to stock data', [
                    'requested' => $assetTypeFilter,
                    'using' => $actualAssetType,
                    'crypto_timestamp' => $requestedTypeData['timestamp'] ?? 'none',
                    'stock_timestamp' => $stockData['timestamp'] ?? 'none',
                ]);
            }
        }

        // Cache key for the actual asset type we're using (include backtest time if specified)
        $cacheKey = "check_top_{$actualAssetType}";
        if ($backtestTime) {
            $cacheKey .= '_backtest_'.str_replace([':', ' ', '-'], '_', $backtestTime);
        }

        // Try to get from cache first (5 minutes for crypto, 2 minutes for stocks)
        $cacheDuration = $actualAssetType === 'crypto' ? 300 : 120;
        $data = Cache::get($cacheKey);

        if ($data === null) {
            // Cache miss - generate fresh data
            $data = $this->getHourlyRisingStocks($actualAssetType, $backtestTime);

            // Cache the fresh data
            Cache::put($cacheKey, $data, $cacheDuration);

            // Optional: Log cache miss for monitoring
            \Log::info("Cache miss for check-top: {$assetTypeFilter}");
        }

        return Inertia::render('check-top/index', [
            'title' => $backtestTime ? 'Historical Analysis - Rising Stock Analysis' : 'Last 15 Minutes Rising Stock Analysis',
            'description' => $backtestTime
                ? "Analyze stocks as they appeared at {$backtestTime} to see what the system would have identified then."
                : 'Analyze stocks rising in the LAST 15 MINUTES to quickly identify emerging momentum and potential trading opportunities. Note: This shows 15-minute performance for faster detection.',
            'stocks' => $data['stocks'],
            'timeIntervals' => $data['timeIntervals'],
            'timestamp' => $data['timestamp'],
            'timestampEst' => $data['timestampEst'],
            'assetTypeFilter' => $assetTypeFilter, // What user requested
            'actualAssetType' => $actualAssetType, // What we're actually showing
            'totalAnalyzed' => $data['totalAnalyzed'],
            'dataFreshness' => $data['dataFreshness'],
            'isStaleData' => $data['isStaleData'] ?? false,
            'dataAge' => $data['dataAge'] ?? null,
            'backtestTime' => $backtestTime,
            'isBacktesting' => ! empty($backtestTime),
        ]);
    }

    private function getHourlyRisingStocks(string $assetTypeFilter, ?string $backtestTime = null): array
    {
        // Get the latest timestamp for the requested asset type
        if ($backtestTime) {
            // For backtesting, use the specified time but find the closest available data
            $targetTimestamp = \Carbon\Carbon::parse($backtestTime)->format('Y-m-d H:i:s');
            $latestTimestamp = FiveMinutePrice::query()
                ->where('asset_type', $assetTypeFilter)
                ->where('ts', '<=', $targetTimestamp)
                ->max('ts');
        } else {
            // Normal operation - get the actual latest timestamp
            $latestTimestamp = FiveMinutePrice::query()
                ->where('asset_type', $assetTypeFilter)
                ->max('ts');
        }

        if (! $latestTimestamp) {
            return [
                'stocks' => [],
                'timeIntervals' => [],
                'timestamp' => null,
                'timestampEst' => null,
                'totalAnalyzed' => 0,
                'dataFreshness' => $backtestTime ? 'No data available for specified time' : 'No data available',
                'isStaleData' => false,
                'dataAge' => null,
            ];
        }

        // Check if data is from today
        $latestDate = \Carbon\Carbon::parse($latestTimestamp)->format('Y-m-d');
        $today = \Carbon\Carbon::now()->format('Y-m-d');
        $isStaleData = $latestDate !== $today;
        $dataAge = \Carbon\Carbon::parse($latestTimestamp)->diffForHumans();

        // Calculate fifteen minutes back from the latest timestamp for faster trend detection
        $fifteenMinutesAgo = date('Y-m-d H:i:s', strtotime('-15 minutes', strtotime($latestTimestamp)));

        // Generate 5-minute intervals for the last 15 minutes (3 intervals)
        $timeIntervals = [];
        for ($i = 0; $i <= 15; $i += 5) {
            $intervalTime = date('Y-m-d H:i:s', strtotime("-{$i} minutes", strtotime($latestTimestamp)));
            $timeIntervals[] = [
                'timestamp' => $intervalTime,
                'label' => "-{$i}m",
                'minutesAgo' => $i,
            ];
        }

        // Reverse to show oldest to newest
        $timeIntervals = array_reverse($timeIntervals);

        // Step 1: Efficiently get baseline and latest prices for quick filtering (exclude soft-deleted symbols)
        $baselinePrices = FiveMinutePrice::query()
            ->select(['symbol', 'price', 'open', 'asset_type'])
            ->where('ts', '>=', $fifteenMinutesAgo)
            ->where('ts', '<=', $latestTimestamp)
            ->where('asset_type', $assetTypeFilter)
            ->whereExists(function ($query) use ($assetTypeFilter) {
                $query->select(DB::raw(1))
                    ->from('asset_info')
                    ->whereColumn('asset_info.symbol', 'five_minute_prices.symbol')
                    ->where('asset_info.asset_type', $assetTypeFilter)
                    ->whereNull('asset_info.deleted_at');
            })
            ->whereRaw('ts = (SELECT MIN(fp2.ts) FROM five_minute_prices fp2 WHERE fp2.symbol = five_minute_prices.symbol AND fp2.asset_type = five_minute_prices.asset_type AND fp2.ts >= ? AND fp2.ts <= ?)', [$fifteenMinutesAgo, $latestTimestamp])
            ->get()
            ->keyBy('symbol');

        $latestPrices = FiveMinutePrice::query()
            ->select(['symbol', 'price', 'asset_type'])
            ->where('ts', '>=', $fifteenMinutesAgo)
            ->where('ts', '<=', $latestTimestamp)
            ->where('asset_type', $assetTypeFilter)
            ->whereExists(function ($query) use ($assetTypeFilter) {
                $query->select(DB::raw(1))
                    ->from('asset_info')
                    ->whereColumn('asset_info.symbol', 'five_minute_prices.symbol')
                    ->where('asset_info.asset_type', $assetTypeFilter)
                    ->whereNull('asset_info.deleted_at');
            })
            ->whereRaw('ts = (SELECT MAX(fp2.ts) FROM five_minute_prices fp2 WHERE fp2.symbol = five_minute_prices.symbol AND fp2.asset_type = five_minute_prices.asset_type AND fp2.ts >= ? AND fp2.ts <= ?)', [$fifteenMinutesAgo, $latestTimestamp])
            ->get()
            ->keyBy('symbol');

        // Step 2: Filter to only rising symbols
        $risingSymbols = [];
        foreach ($baselinePrices as $symbol => $baseline) {
            $latest = $latestPrices->get($symbol);
            if (! $latest) {
                continue;
            }

            $baselinePrice = (float) ($baseline->open ?? $baseline->price);
            $currentPrice = (float) $latest->price;
            $totalPercentChange = (($currentPrice - $baselinePrice) / $baselinePrice) * 100;

            if ($totalPercentChange > 0.01) {
                $risingSymbols[] = $symbol;
            }
        }

        if (empty($risingSymbols)) {
            return [
                'stocks' => [],
                'timeIntervals' => $timeIntervals,
                'timestamp' => $latestTimestamp,
                'timestampEst' => \Carbon\Carbon::parse($latestTimestamp)->setTimezone('America/New_York')->format('M j, Y g:i A T'),
                'totalAnalyzed' => $baselinePrices->count(),
                'dataFreshness' => $dataAge,
                'isStaleData' => $isStaleData,
                'dataAge' => $dataAge,
            ];
        }

        // Step 3: Get detailed data only for rising symbols (exclude soft-deleted)
        $allPriceData = FiveMinutePrice::query()
            ->whereIn('symbol', $risingSymbols)
            ->where('ts', '>=', $fifteenMinutesAgo)
            ->where('ts', '<=', $latestTimestamp)
            ->where('asset_type', $assetTypeFilter)
            ->whereExists(function ($query) use ($assetTypeFilter) {
                $query->select(DB::raw(1))
                    ->from('asset_info')
                    ->whereColumn('asset_info.symbol', 'five_minute_prices.symbol')
                    ->where('asset_info.asset_type', $assetTypeFilter)
                    ->whereNull('asset_info.deleted_at');
            })
            ->orderBy('symbol')
            ->orderBy('ts')
            ->get(['symbol', 'ts', 'price', 'open', 'high', 'low', 'volume', 'asset_type']);

        // Step 4: Get asset info for rising symbols only (exclude soft-deleted)
        $assetInfoMap = AssetInfo::whereIn('symbol', $risingSymbols)
            ->where('asset_type', $assetTypeFilter)
            ->whereNull('deleted_at')
            ->get(['symbol', 'id', 'asset_type', 'common_name'])
            ->keyBy('symbol');

        // Step 5: Group price data by symbol
        $priceDataBySymbol = $allPriceData->groupBy('symbol');

        $risingStocks = [];

        foreach ($priceDataBySymbol as $symbol => $symbolPriceData) {
            $firstPrice = $symbolPriceData->first();
            $lastPrice = $symbolPriceData->last();

            // Calculate total percentage change over the hour
            $baselinePrice = (float) ($firstPrice->open ?? $firstPrice->price);
            $currentPrice = (float) $lastPrice->price;
            $totalPercentChange = (($currentPrice - $baselinePrice) / $baselinePrice) * 100;

            // Get asset info
            $assetInfo = $assetInfoMap->get($symbol);

            // Pre-sort symbol data by timestamp for efficient interval lookup
            $sortedPriceData = $symbolPriceData->sortBy('ts')->values();

            // Run topping analysis using PriceToppingScanner
            $scanner = new PriceToppingScanner;
            $toppingAnalysis = $scanner->scan($symbol, $firstPrice->asset_type, 60);

            // Calculate price changes for each 5-minute interval using efficient lookup
            $intervalData = [];
            $lastFoundIndex = 0; // Optimize by remembering last position

            foreach ($timeIntervals as $interval) {
                // Efficient forward search from last position
                $intervalPrice = null;
                for ($i = $lastFoundIndex; $i < $sortedPriceData->count(); $i++) {
                    if ($sortedPriceData[$i]->ts <= $interval['timestamp']) {
                        $intervalPrice = $sortedPriceData[$i];
                        $lastFoundIndex = $i; // Remember this position for next iteration
                    } else {
                        break; // No point checking further timestamps
                    }
                }

                if ($intervalPrice) {
                    $currentIntervalPrice = (float) $intervalPrice->price;
                    $percentChange = (($currentIntervalPrice - $baselinePrice) / $baselinePrice) * 100;
                    $intervalData[] = [
                        'timestamp' => $interval['timestamp'],
                        'price' => round($currentIntervalPrice, 2),
                        'percentChange' => round($percentChange, 2),
                        'volume' => (int) $intervalPrice->volume,
                    ];
                } else {
                    // No data for this interval
                    $intervalData[] = [
                        'timestamp' => $interval['timestamp'],
                        'price' => null,
                        'percentChange' => null,
                        'volume' => null,
                    ];
                }
            }

            $risingStocks[] = [
                'symbol' => $symbol,
                'asset_id' => $assetInfo?->id,
                'asset_type' => $firstPrice->asset_type,
                'company_name' => $assetInfo?->common_name ?? $symbol,
                'baselinePrice' => round($baselinePrice, 2),
                'currentPrice' => round($currentPrice, 2),
                'totalPercentChange' => round($totalPercentChange, 2),
                'intervalData' => $intervalData,
                'totalVolume' => (int) $symbolPriceData->sum('volume'),
                'highPrice' => round($symbolPriceData->max('high'), 2),
                'lowPrice' => round($symbolPriceData->min('low'), 2),
                'timestamp' => $latestTimestamp,
                'toppingAnalysis' => $toppingAnalysis,
            ];
        }

        // Sort by total percentage change (descending)
        usort($risingStocks, function ($a, $b) {
            return $b['totalPercentChange'] <=> $a['totalPercentChange'];
        });

        // Get top 100
        $risingStocks = array_slice($risingStocks, 0, 100);

        // Calculate data freshness
        $latestTime = \Carbon\Carbon::parse($latestTimestamp);
        $dataFreshness = $latestTime->diffForHumans();
        $timestampEst = $latestTime->setTimezone('America/New_York')->format('M j, Y g:i A T');

        // Total analyzed symbols
        $totalAnalyzed = $baselinePrices->count();

        return [
            'stocks' => $risingStocks,
            'timeIntervals' => $timeIntervals,
            'timestamp' => $latestTimestamp,
            'timestampEst' => $timestampEst,
            'totalAnalyzed' => $totalAnalyzed,
            'dataFreshness' => $dataFreshness,
            'isStaleData' => $isStaleData,
            'dataAge' => $dataAge,
        ];
    }

    /**
     * Check data freshness for a given asset type
     */
    private function checkDataFreshness(string $assetType): ?array
    {
        $latestTimestamp = FiveMinutePrice::query()
            ->where('asset_type', $assetType)
            ->max('ts');

        if (! $latestTimestamp) {
            return null;
        }

        $latestDate = \Carbon\Carbon::parse($latestTimestamp)->format('Y-m-d');
        $today = \Carbon\Carbon::now()->format('Y-m-d');
        $isStale = $latestDate !== $today;

        return [
            'timestamp' => $latestTimestamp,
            'isStale' => $isStale,
            'date' => $latestDate,
        ];
    }
}
