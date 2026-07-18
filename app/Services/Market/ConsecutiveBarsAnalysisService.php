<?php

namespace App\Services\Market;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ConsecutiveBarsAnalysisService
{
    /**
     * Calculate 8-hour projections based on consecutive bars analysis
     *
     * @param  string|null  $estDateTime  EST datetime string (Y-m-d H:i:s)
     * @param  string  $assetType  'stock' or 'crypto'
     * @param  int  $numBars  Number of bars to analyze (default 4)
     */
    public function calculateEightHourProjections(
        ?string $estDateTime = null,
        string $assetType = 'stock',
        int $numBars = 4
    ): array {

        if ($numBars < 2) {
            throw new \InvalidArgumentException('numBars must be >= 2');
        }

        // Determine end time in EST
        $endEst = $estDateTime
            ? Carbon::parse($estDateTime, 'America/New_York')
            : Carbon::now('America/New_York');

        // Start = 2 hours earlier (still fixed 2h scan window)
        $startEst = $endEst->copy()->subHours(2);

        // Pull only the 2h range
        $priceData = DB::table('five_minute_prices')
            ->select(['symbol', 'asset_type', 'ts_est', 'price'])
            ->where('asset_type', $assetType)
            ->whereBetween('ts_est', [$startEst->format('Y-m-d H:i:s'), $endEst->format('Y-m-d H:i:s')])
            ->orderBy('symbol')
            ->orderBy('ts_est')
            ->get();

        $results = [];
        $bars = [];
        $currentSymbol = null;

        $flushSymbol = function () use (&$results, &$bars, &$currentSymbol, $numBars, $estDateTime) {
            if ($currentSymbol === null || count($bars) < $numBars) {
                $bars = [];

                return;
            }

            $count = count($bars);
            $lastBar = $bars[$count - 1];
            $lastPrice = (float) $lastBar['price'];

            // Dynamic "N bars ago"
            $priceNbarsAgo = (float) $bars[$count - $numBars]['price'];

            if ($priceNbarsAgo <= 0) {
                $bars = [];

                return;
            }

            // Check that each bar is strictly increasing
            if (! $this->hasStrictlyIncreasingBars($bars, $numBars)) {
                $bars = [];

                return;
            }

            // Apply pre-analysis filtering if enabled
            if (config('market.enable_pre_analysis_filtering', false)) {
                if (! $this->passesPreAnalysisFiltering($currentSymbol, $estDateTime)) {
                    $bars = [];

                    return;
                }
            }

            // Compute percent gain over N bars
            $pctLookback = ($lastPrice - $priceNbarsAgo) / $priceNbarsAgo;

            // Skip non-upward symbols
            if ($pctLookback <= 0) {
                $bars = [];

                return;
            }

            // 2h window gain (first -> last)
            $firstPrice = (float) $bars[0]['price'];
            $pctLast2h = $firstPrice > 0
                ? ($lastPrice - $firstPrice) / $firstPrice
                : 0;

            // Project N-bar gain forward for 8 hours.
            // Each bar = 5 minutes → N bars = N*5 minutes
            // 8 hours = 480 minutes
            // periods = 480 / (N*5)
            $periodLenMinutes = $numBars * 5;
            $periods = intval(480 / $periodLenMinutes);

            if ($periods < 1) {
                $periods = 1;
            }

            $projectedPct8h = pow(1 + $pctLookback, $periods) - 1;

            $results[] = [
                'symbol' => $currentSymbol,
                'asset_type' => $lastBar['asset_type'],
                'last_price' => $lastPrice,
                'pct_last_lookup' => $pctLookback,
                'pct_last_2h' => $pctLast2h,
                'projected_pct_8h' => $projectedPct8h,
                'num_bars_used' => $numBars,
                'bars_data' => $bars, // Include the raw bars data
            ];

            $bars = [];
        };

        foreach ($priceData as $row) {
            $symbol = $row->symbol;

            if ($currentSymbol !== null && $symbol !== $currentSymbol) {
                $flushSymbol();
            }

            if ($currentSymbol !== $symbol) {
                $currentSymbol = $symbol;
                $bars = [];
            }

            $bars[] = [
                'ts_est' => $row->ts_est,
                'price' => $row->price,
                'asset_type' => $row->asset_type,
            ];

            // Keep 24 bars max (2 hours)
            if (count($bars) > 24) {
                array_shift($bars);
            }
        }

        // Final symbol flush
        $flushSymbol();

        // Sort best → worst
        usort($results, fn ($a, $b) => $b['projected_pct_8h'] <=> $a['projected_pct_8h']);

        // Filter out results with 8H projection below 5%
        $results = array_filter($results, fn ($result) => $result['projected_pct_8h'] >= 0.05);

        return $results;
    }

    /**
     * Get formatted analysis data for the frontend
     */
    public function getAnalysisData(
        ?string $estDateTime = null,
        string $assetType = 'stock',
        int $numBars = 4
    ): array {
        $results = $this->calculateEightHourProjections($estDateTime, $assetType, $numBars);

        $timestampUsed = $estDateTime ?? Carbon::now('America/New_York')->format('Y-m-d H:i:s');
        $isHistorical = $estDateTime !== null;

        return [
            'stocks' => $this->formatForFrontend($results, $timestampUsed, $isHistorical),
            'timestamp' => Carbon::parse($timestampUsed)->format('Y-m-d H:i:s'),
            'timestampEst' => Carbon::parse($timestampUsed, 'America/New_York')->format('Y-m-d H:i:s T'),
            'totalAnalyzed' => $this->getTotalSymbolsAnalyzed($assetType),
            'totalFound' => count($results),
            'dataFreshness' => $isHistorical ? 'Historical' : 'Live',
            'numBarsUsed' => $numBars,
            'description' => "Analysis of {$numBars} consecutive increasing bars with 8-hour projections".
                           ($isHistorical ? ' (Historical analysis for '.Carbon::parse($timestampUsed, 'America/New_York')->format('M j, Y g:i A T').')' : ''),
        ];
    }

    /**
     * Format results for frontend display
     */
    private function formatForFrontend(array $results, string $timestampUsed, bool $isHistorical): array
    {
        // Get all symbols to batch fetch volume data
        $symbols = array_column($results, 'symbol');
        $volumeData = $this->getBatchVolumeData($symbols);

        // Get actual close data if this is a historical analysis
        $actualCloseData = $isHistorical ? $this->getBatchActualCloseData($symbols, $timestampUsed) : [];

        // Get daily high data if this is a historical analysis
        $dailyHighData = $isHistorical ? $this->getBatchDailyHighData($symbols, $timestampUsed) : [];

        return array_map(function ($result) use ($volumeData, $actualCloseData, $dailyHighData, $isHistorical) {
            $formattedResult = [
                'symbol' => $result['symbol'],
                'asset_id' => $this->getAssetId($result['symbol']), // Get asset_id if needed
                'name' => $this->getAssetName($result['symbol']), // Get company name
                'type' => $result['asset_type'],
                'lastPrice' => $result['last_price'],
                'pctLookback' => $result['pct_last_lookup'] * 100, // Convert to percentage
                'pctLast2h' => $result['pct_last_2h'] * 100,
                'projectedPct8h' => $result['projected_pct_8h'] * 100,
                'numBarsUsed' => $result['num_bars_used'],
                'avgDailyVolume' => $volumeData[$result['symbol']] ?? null,
            ];

            // Add actual close data for historical analysis
            if ($isHistorical && isset($actualCloseData[$result['symbol']])) {
                $actualClosePrice = $actualCloseData[$result['symbol']];
                $formattedResult['actualClosePrice'] = $actualClosePrice;
                $formattedResult['actualClosePct'] = (($actualClosePrice - $result['last_price']) / $result['last_price']) * 100;
            }

            // Add daily high data for historical analysis
            if ($isHistorical && isset($dailyHighData[$result['symbol']])) {
                $dailyHighPrice = $dailyHighData[$result['symbol']];
                $formattedResult['dailyHighPrice'] = $dailyHighPrice;
                $formattedResult['dailyHighPct'] = (($dailyHighPrice - $result['last_price']) / $result['last_price']) * 100;
            }

            return $formattedResult;
        }, $results);
    }

    /**
     * Get volume data for multiple symbols in a batch query
     */
    private function getBatchVolumeData(array $symbols): array
    {
        if (empty($symbols)) {
            return [];
        }

        $volumeData = DB::table('five_minute_prices')
            ->select('symbol', DB::raw('AVG(volume) as avg_volume'))
            ->whereIn('symbol', $symbols)
            ->where('ts_est', '>=', Carbon::now('America/New_York')->subDays(7)->format('Y-m-d'))
            ->whereNotNull('volume')
            ->where('volume', '>', 0)
            ->groupBy('symbol')
            ->get()
            ->pluck('avg_volume', 'symbol')
            ->map(fn ($vol) => (int) round($vol))
            ->toArray();

        return $volumeData;
    }

    /**
     * Get actual close prices at 3:55 PM EST for historical analysis
     */
    private function getBatchActualCloseData(array $symbols, string $timestampUsed): array
    {
        if (empty($symbols)) {
            return [];
        }

        // Parse the analysis date and create the 3:55 PM EST timestamp for that day
        $analysisDate = Carbon::parse($timestampUsed, 'America/New_York');
        $closeTime = $analysisDate->copy()->setTime(15, 55, 0); // 3:55 PM EST

        // Get prices closest to 3:55 PM EST on the analysis date
        // Look for prices between 3:50 PM and 4:00 PM to handle slight variations
        $startTime = $closeTime->copy()->subMinutes(5)->format('Y-m-d H:i:s');
        $endTime = $closeTime->copy()->addMinutes(5)->format('Y-m-d H:i:s');

        $actualCloses = DB::table('five_minute_prices')
            ->select('symbol', 'price', 'ts_est')
            ->whereIn('symbol', $symbols)
            ->where('ts_est', '>=', $startTime)
            ->where('ts_est', '<=', $endTime)
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->orderBy('ts_est', 'desc') // Get the latest price in the window
            ->get()
            ->groupBy('symbol')
            ->map(function ($prices) {
                // Return the latest price for each symbol
                return (float) $prices->first()->price;
            })
            ->toArray();

        return $actualCloses;
    }

    /**
     * Get daily high prices after analysis time for historical analysis
     */
    private function getBatchDailyHighData(array $symbols, string $timestampUsed): array
    {
        if (empty($symbols)) {
            return [];
        }

        // Parse the analysis time and create the end of trading day timestamp
        $analysisTime = Carbon::parse($timestampUsed, 'America/New_York');
        $endOfDay = $analysisTime->copy()->setTime(16, 0, 0); // 4:00 PM EST (market close)

        // Get the highest price after the analysis time until market close
        $dailyHighs = DB::table('five_minute_prices')
            ->select('symbol', DB::raw('MAX(price) as high_price'))
            ->whereIn('symbol', $symbols)
            ->where('ts_est', '>', $analysisTime->format('Y-m-d H:i:s')) // After analysis time
            ->where('ts_est', '<=', $endOfDay->format('Y-m-d H:i:s'))     // Until market close
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->groupBy('symbol')
            ->get()
            ->pluck('high_price', 'symbol')
            ->map(fn ($price) => (float) $price)
            ->toArray();

        return $dailyHighs;
    }

    /**
     * Get asset ID for a symbol
     */
    private function getAssetId(string $symbol): ?int
    {
        $asset = DB::table('asset_info')
            ->select('id')
            ->where('symbol', $symbol)
            ->whereNull('deleted_at')
            ->first();

        return $asset ? $asset->id : null;
    }

    /**
     * Get asset name for a symbol
     */
    private function getAssetName(string $symbol): string
    {
        $asset = DB::table('asset_info')
            ->select('common_name')
            ->where('symbol', $symbol)
            ->whereNull('deleted_at')
            ->first();

        return $asset ? $asset->common_name : $symbol;
    }

    /**
     * Get total number of symbols analyzed (for stats)
     */
    private function getTotalSymbolsAnalyzed(string $assetType): int
    {
        return DB::table('five_minute_prices')
            ->where('asset_type', $assetType)
            ->distinct('symbol')
            ->count('symbol');
    }

    /**
     * Check if the last N bars are strictly increasing (each bar higher than previous)
     * Allows for configurable downward tolerance to account for market noise
     *
     * @param  array  $bars  All bars data
     * @param  int  $numBars  Number of bars to check from the end
     */
    private function hasStrictlyIncreasingBars(array $bars, int $numBars): bool
    {
        $count = count($bars);

        // Need at least numBars to check
        if ($count < $numBars) {
            return false;
        }

        // Get the downward tolerance percentage from config
        $downwardTolerancePct = config('market.consecutive_bars_downward_tolerance_pct', 0.0) / 100;

        // Check the last numBars bars for increasing sequence with tolerance
        for ($i = $count - $numBars; $i < $count - 1; $i++) {
            $currentPrice = (float) $bars[$i]['price'];
            $nextPrice = (float) $bars[$i + 1]['price'];

            // Calculate the percentage change
            $pctChange = ($nextPrice - $currentPrice) / $currentPrice;

            // If the downward movement exceeds the tolerance, reject
            if ($pctChange < -$downwardTolerancePct) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a symbol passes pre-analysis filtering based on volume patterns
     * Uses patterns identified from historical analysis to filter likely negative performers
     */
    private function passesPreAnalysisFiltering(string $symbol, ?string $estDateTime): bool
    {
        // Only apply to historical analysis for now (when estDateTime is provided)
        if ($estDateTime === null) {
            return true;
        }

        $analysisTime = Carbon::parse($estDateTime, 'America/New_York');
        $marketOpen = $analysisTime->copy()->setTime(9, 30, 0);

        // Get price data from market open to analysis time
        $priceData = DB::table('five_minute_prices')
            ->where('symbol', $symbol)
            ->where('ts_est', '>=', $marketOpen->format('Y-m-d H:i:s'))
            ->where('ts_est', '<=', $analysisTime->format('Y-m-d H:i:s'))
            ->orderBy('ts_est')
            ->get(['ts_est', 'price', 'volume']);

        if ($priceData->count() < 6) {
            return false; // Not enough data for pattern analysis
        }

        // Calculate volume ratio (early vs later volume)
        $firstHalf = $priceData->take(ceil($priceData->count() / 2));
        $secondHalf = $priceData->skip(ceil($priceData->count() / 2));

        $earlyVolume = $firstHalf->sum('volume');
        $laterVolume = $secondHalf->sum('volume');

        $volumeRatio = $laterVolume > 0 ? $earlyVolume / $laterVolume : 0;

        // Get filtering threshold from config
        $minVolumeRatio = config('market.pre_analysis_min_volume_ratio', 1.3);

        // Apply volume ratio filter only
        return $volumeRatio >= $minVolumeRatio;
    }
}
