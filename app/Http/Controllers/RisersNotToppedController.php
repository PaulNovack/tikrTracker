<?php

namespace App\Http\Controllers;

use App\Models\AssetInfo;
use App\Models\FiveMinutePrice;
use App\Services\Market\PriceToppingScanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class RisersNotToppedController extends Controller
{
    /**
     * Display the risers not topped analysis page.
     */
    public function index(Request $request): Response
    {
        // Get filter from request
        $assetTypeFilter = $request->get('filter', 'stock'); // Default to stocks

        // Cache key for this request
        $cacheKey = "risers_not_topped_{$assetTypeFilter}";

        // Try to get from cache first (5 minutes for crypto, 2 minutes for stocks)
        $cacheDuration = $assetTypeFilter === 'crypto' ? 300 : 120;
        $data = Cache::get($cacheKey);

        if ($data === null) {
            // Cache miss - generate fresh data
            $data = $this->getRisersNotTopped($assetTypeFilter);

            // Cache the fresh data
            Cache::put($cacheKey, $data, $cacheDuration);

            // Optional: Log cache miss for monitoring
            \Log::info("Cache miss for risers-not-topped: {$assetTypeFilter}");
        }

        return Inertia::render('risers-not-topped/index', [
            'title' => 'Risers Not Topped',
            'description' => $data['description'] ?? 'Advanced stock screening tool for rising stocks with strong momentum.',
            'stocks' => $data['stocks'],
            'timeIntervals' => $data['timeIntervals'],
            'timestamp' => $data['timestamp'],
            'timestampEst' => $data['timestampEst'],
            'assetTypeFilter' => $assetTypeFilter,
            'totalAnalyzed' => $data['totalAnalyzed'],
            'dataFreshness' => $data['dataFreshness'],
            'totalRisersNotTopped' => $data['totalRisersNotTopped'],
            'minDailyVolume' => $data['minDailyVolume'],
        ]);
    }

    /**
     * Get rising stocks that have NOT topped out
     */
    private function getRisersNotTopped(string $assetTypeFilter): array
    {
        $currentTime = now();
        $timestamp = $currentTime->toISOString();
        $timestampEst = $currentTime->setTimezone('America/New_York')->format('Y-m-d H:i:s T');

        // Use the PriceToppingScanner service to get risers not topped
        $scanner = new PriceToppingScanner;
        $lookbackMinutes = config('market.lookback_minutes', 90);

        $scanResults = $scanner->scanRisersNotTopped(
            assetTypeFilter: $assetTypeFilter,
            lookbackMinutes: $lookbackMinutes,
            minRisePct: 1.0,
            topN: 100
        );

        // Transform the results to match our frontend structure
        $stocks = [];

        // Generate time intervals based on the lookback period
        $timeIntervals = ['15m', '30m', '60m'];
        if ($lookbackMinutes >= 90) {
            $timeIntervals[] = '90m';
        }

        foreach ($scanResults['symbols'] as $symbolData) {
            // Get company name from asset_info if available (exclude soft deleted)
            $assetInfo = AssetInfo::where('symbol', $symbolData['symbol'])
                ->where('asset_type', $symbolData['asset_type'])
                ->whereNull('deleted_at')
                ->first();

            // Skip soft deleted stocks
            if (! $assetInfo) {
                continue;
            }

            $stockData = [
                'symbol' => $symbolData['symbol'],
                'asset_id' => $symbolData['asset_id'],
                'name' => $assetInfo->common_name,
                'type' => $symbolData['asset_type'],
                'avgDailyVolume' => $symbolData['avg_daily_volume'],
                'intervals' => [],
                'isRising' => true, // Already filtered to rising stocks
                'hasTopped' => false, // Already filtered to non-topped
                'qualifies' => true,
            ];

            // Map the scanner results to our interval structure
            $stockData['intervals']['15m'] = [
                'percentChange' => round($symbolData['change_15m_pct'] ?? 0, 2),
                'isRising' => ($symbolData['change_15m_pct'] ?? 0) > 0.5,
                'hasTopped' => $symbolData['flags']['possible_top'] ?? false,
                'startPrice' => $symbolData['prices_15m']['start'] ?? 0,
                'endPrice' => $symbolData['prices_15m']['end'] ?? 0,
                'dataPoints' => 3, // Approximate
            ];

            $stockData['intervals']['30m'] = [
                'percentChange' => round($symbolData['change_30m_pct'] ?? 0, 2),
                'isRising' => ($symbolData['change_30m_pct'] ?? 0) > 0.5,
                'hasTopped' => $symbolData['flags']['possible_top'] ?? false,
                'startPrice' => $symbolData['prices_30m']['start'] ?? 0,
                'endPrice' => $symbolData['prices_30m']['end'] ?? 0,
                'dataPoints' => 6, // Approximate
            ];

            $stockData['intervals']['60m'] = [
                'percentChange' => round($symbolData['change_60m_pct'] ?? 0, 2),
                'isRising' => ($symbolData['change_60m_pct'] ?? 0) > 1.0,
                'hasTopped' => $symbolData['flags']['possible_top'] ?? false,
                'startPrice' => $symbolData['prices_60m']['start'] ?? 0,
                'endPrice' => $symbolData['prices_60m']['end'] ?? 0,
                'dataPoints' => 12, // Approximate
            ];

            $stockData['intervals']['90m'] = [
                'percentChange' => round($symbolData['change_90m_pct'] ?? 0, 2),
                'isRising' => ($symbolData['change_90m_pct'] ?? 0) > 1.0,
                'hasTopped' => $symbolData['flags']['possible_top'] ?? false,
                'startPrice' => $symbolData['prices_90m']['start'] ?? 0,
                'endPrice' => $symbolData['prices_90m']['end'] ?? 0,
                'dataPoints' => 18, // Approximate (90 minutes / 5-minute bars)
            ];

            $stocks[] = $stockData;
        }

        $dataFreshness = $this->calculateDataFreshness();

        // Generate dynamic description based on configuration
        $minVolume = $scanResults['filter']['min_daily_volume'] ?? 1000000;
        $timeframesList = implode(', ', $timeIntervals);
        $description = "This advanced stock screening tool identifies rising stocks with strong upward momentum that have not yet shown signs of topping out. Our sophisticated technical analysis engine scans through thousands of stocks to find those that meet strict criteria: (1) Rising at least 1.0% over the last {$lookbackMinutes} minutes with consistent upward price action, (2) Maintaining an average daily trading volume of at least ".number_format($minVolume)." shares to ensure sufficient liquidity for active trading, (3) Showing no technical topping signals such as shooting star candlestick patterns, lower highs with lower closes, or volume climax conditions that typically indicate exhaustion. The scanner analyzes 5-minute price data across multiple timeframes ({$timeframesList}) to identify the best momentum opportunities while filtering out extended moves that may be overdue for pullbacks. Each stock is evaluated for extension levels above recent lows, volume patterns, and candlestick formations to provide you with high-probability continuation candidates. This real-time analysis helps traders identify stocks with the best risk/reward profiles for momentum-based strategies, focusing on liquid names that can handle larger position sizes without significant slippage.";

        return [
            'description' => $description,
            'stocks' => $stocks,
            'timeIntervals' => $timeIntervals,
            'timestamp' => $timestamp,
            'timestampEst' => $timestampEst,
            'totalAnalyzed' => $scanResults['filter']['top_n'] ?? 100, // From scanner config
            'totalRisersNotTopped' => $scanResults['count'],
            'minDailyVolume' => $scanResults['filter']['min_daily_volume'] ?? 1000000,
            'dataFreshness' => $dataFreshness,
        ];
    }

    /**
     * Calculate how fresh our data is
     */
    private function calculateDataFreshness(): array
    {
        $latestPrice = FiveMinutePrice::orderBy('ts', 'desc')->first();

        if (! $latestPrice) {
            return [
                'minutes_old' => null,
                'status' => 'no_data',
                'last_update' => null,
            ];
        }

        $latestTimestamp = $latestPrice->ts;
        $minutesOld = round(now()->diffInMinutes($latestTimestamp));
        $secondsOld = now()->diffInSeconds($latestTimestamp);

        $status = 'fresh';
        if ($minutesOld > 15) {
            $status = 'stale';
        } elseif ($minutesOld > 10) {
            $status = 'moderate';
        }

        return [
            'minutes_old' => $minutesOld,
            'seconds_old' => $secondsOld,
            'status' => $status,
            'last_update' => $latestTimestamp->setTimezone('America/New_York')->format('Y-m-d H:i:s T'),
        ];
    }
}
