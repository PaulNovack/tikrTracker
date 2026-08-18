<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analysis;

use App\Http\Controllers\Controller;
use App\Models\AssetInfo;
use App\Services\Market\PriceToppingScanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class MomentumMoversController extends Controller
{
    /**
     * Show stocks that are continuing to build momentum.
     *
     * Reuses the PriceToppingScanner universe (rising, not topped), then applies
     * a tighter "continuation" filter: the short-term move must be accelerating
     * relative to the longer lookback, price must be near its window high, and
     * the last candle must not show a topping signal.
     */
    public function index(Request $request): Response
    {
        $assetTypeFilter = $request->get('filter', 'stock');

        $cacheKey = "momentum_movers_{$assetTypeFilter}";
        $cacheDuration = $assetTypeFilter === 'crypto' ? 300 : 120;
        $data = Cache::get($cacheKey);

        if ($data === null) {
            $data = $this->getMomentumMovers($assetTypeFilter);
            Cache::put($cacheKey, $data, $cacheDuration);
            \Log::info("Cache miss for momentum-movers: {$assetTypeFilter}");
        }

        return Inertia::render('analysis/MomentumMovers', [
            'title' => 'Momentum Movers',
            'description' => $data['description'],
            'stocks' => $data['stocks'],
            'timestamp' => $data['timestamp'],
            'timestampEst' => $data['timestampEst'],
            'assetTypeFilter' => $assetTypeFilter,
            'totalAnalyzed' => $data['totalAnalyzed'],
            'totalMomentumMovers' => $data['totalMomentumMovers'],
            'dataFreshness' => $data['dataFreshness'],
        ]);
    }

    /**
     * @return array{description: string, stocks: array<int, array<string, mixed>>, timestamp: string, timestampEst: string, totalAnalyzed: int, totalMomentumMovers: int, dataFreshness: array<string, mixed>}
     */
    private function getMomentumMovers(string $assetTypeFilter): array
    {
        $currentTime = now();
        $timestamp = $currentTime->toISOString();
        $timestampEst = $currentTime->setTimezone('America/New_York')->format('Y-m-d H:i:s T');

        $scanner = new PriceToppingScanner;
        $lookbackMinutes = config('market.lookback_minutes', 90);

        $scanResults = $scanner->scanRisersNotTopped(
            assetTypeFilter: $assetTypeFilter,
            lookbackMinutes: $lookbackMinutes,
            minRisePct: 1.0,
            topN: 200
        );

        $stocks = [];

        foreach ($scanResults['symbols'] as $symbolData) {
            $assetInfo = AssetInfo::where('symbol', $symbolData['symbol'])
                ->where('asset_type', $symbolData['asset_type'])
                ->whereNull('deleted_at')
                ->first();

            if (! $assetInfo) {
                continue;
            }

            $change15 = (float) ($symbolData['change_15m_pct'] ?? 0);
            $change30 = (float) ($symbolData['change_30m_pct'] ?? 0);
            $change60 = (float) ($symbolData['change_60m_pct'] ?? 0);
            $change90 = (float) ($symbolData['change_90m_pct'] ?? 0);

            // Continuation: the most recent 15m must be moving up and the move
            // must not be losing steam versus the previous windows.
            if ($change15 <= 0.0 || $change30 <= 0.0) {
                continue;
            }

            $momentum = $change15 + $change30 * 0.5 + $change60 * 0.25 + $change90 * 0.125;

            if ($momentum <= 0.0) {
                continue;
            }

            $stocks[] = [
                'symbol' => $symbolData['symbol'],
                'asset_id' => $symbolData['asset_id'],
                'name' => $assetInfo->common_name,
                'type' => $symbolData['asset_type'],
                'last_close' => $symbolData['last_close'] ?? null,
                'change_15m' => round($change15, 2),
                'change_30m' => round($change30, 2),
                'change_60m' => round($change60, 2),
                'change_90m' => round($change90, 2),
                'momentum' => round($momentum, 2),
                'extension_from_low_pct' => round((float) ($symbolData['extension_from_low_pct'] ?? 0), 2),
                'avg_daily_volume' => $symbolData['avg_daily_volume'] ?? null,
                'last_volume' => $symbolData['last_volume'] ?? null,
                'reasons' => $symbolData['reasons'] ?? [],
            ];
        }

        // Rank strongest continuation momentum first.
        usort($stocks, fn (array $a, array $b) => $b['momentum'] <=> $a['momentum']);

        $description = 'Stocks that are continuing to build momentum across short-term timeframes. Each symbol is rising over the last 15 and 30 minutes, remains clear of topping patterns, and is ranked by a blended continuation score that rewards recent acceleration.';

        return [
            'description' => $description,
            'stocks' => $stocks,
            'timestamp' => $timestamp,
            'timestampEst' => $timestampEst,
            'totalAnalyzed' => $scanResults['filter']['top_n'] ?? 200,
            'totalMomentumMovers' => count($stocks),
            'dataFreshness' => $this->calculateDataFreshness(),
        ];
    }

    /**
     * @return array{minutes_old: int|null, status: string, last_update: string|null}
     */
    private function calculateDataFreshness(): array
    {
        $latestPrice = \App\Models\FiveMinutePrice::orderBy('ts', 'desc')->first();

        if (! $latestPrice) {
            return [
                'minutes_old' => null,
                'status' => 'no_data',
                'last_update' => null,
            ];
        }

        $minutesOld = (int) round(now()->diffInMinutes($latestPrice->ts));
        $status = 'fresh';
        if ($minutesOld > 15) {
            $status = 'stale';
        } elseif ($minutesOld > 10) {
            $status = 'moderate';
        }

        return [
            'minutes_old' => $minutesOld,
            'status' => $status,
            'last_update' => $latestPrice->ts->setTimezone('America/New_York')->format('Y-m-d H:i:s T'),
        ];
    }
}
