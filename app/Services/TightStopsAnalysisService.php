<?php

declare(strict_types=1);

namespace App\Services;

use DateTime;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * TightStopsAnalysisService
 *
 * Service for finding symbols whose recent intraday behavior is friendly to tight
 * 0.5–1.0% initial and trailing stop losses.
 *
 * Strategy:
 * - Look at a recent intraday window of 5m bars (default: last 120 minutes).
 * - For each symbol:
 *   - Compute % trend over the window (first -> last close).
 *   - Compute max peak-to-trough drawdown inside that window using highs/lows.
 *   - Filter to symbols with:
 *       trend >= minTrendPct (default 0.5% = 0.005)
 *       |max_drawdown| <= maxDrawdownPct (default 1.0% = 0.01)
 *   - Score by trend / |max_drawdown| (risk-adjusted uptrend).
 */
class TightStopsAnalysisService
{
    /**
     * Find best picks for tight stops strategy.
     *
     * @param  string|null  $endEstDatetime  EST end datetime "Y-m-d H:i:s" or null for now
     * @param  int  $lookbackMinutes  minutes to look back from end time
     * @param  float  $maxDrawdownPct  max allowed peak-to-trough drop (e.g. 0.01 = 1%)
     * @param  float  $minTrendPct  min required trend from first->last (e.g. 0.005 = 0.5%)
     * @param  string  $assetType  'stock' or 'crypto'
     * @return array[] Each row: [
     *                 'symbol',
     *                 'asset_type',
     *                 'last_price',
     *                 'trend_pct',
     *                 'max_drawdown_pct',
     *                 'bars',
     *                 'up_bars',
     *                 'down_bars',
     *                 'risk_score'
     *                 ]
     */
    public function findBestPicksForTightStops(
        ?string $endEstDatetime = null,
        int $lookbackMinutes = 120,
        float $maxDrawdownPct = 0.01,
        float $minTrendPct = 0.005,
        string $assetType = 'stock',
        bool $onlyOver1Mil = false
    ): array {
        if ($lookbackMinutes <= 0) {
            throw new InvalidArgumentException('lookbackMinutes must be > 0');
        }

        $tzEst = new DateTimeZone('America/New_York');

        // End of analysis window in EST
        $endEst = $endEstDatetime
            ? new DateTime($endEstDatetime, $tzEst)
            : new DateTime('now', $tzEst);

        $startEst = clone $endEst;
        $startEst->modify("-{$lookbackMinutes} minutes");

        $endStr = $endEst->format('Y-m-d H:i:s');
        $startStr = $startEst->format('Y-m-d H:i:s');

        // Pull 5-minute bars in the window, ordered by symbol + time
        $marketCapFilter = $onlyOver1Mil ? 'AND ai.over_1mil = 1' : '';
        $rows = DB::select("
            SELECT fmp.symbol, fmp.asset_type, fmp.ts_est, fmp.price, fmp.high, fmp.low, ai.id as asset_id
            FROM five_minute_prices fmp
            LEFT JOIN asset_info ai ON fmp.symbol = ai.symbol AND fmp.asset_type = ai.asset_type AND ai.deleted_at IS NULL
            WHERE fmp.asset_type = ?
              AND fmp.ts_est BETWEEN ? AND ?
              {$marketCapFilter}
            ORDER BY fmp.symbol, fmp.ts_est
        ", [$assetType, $startStr, $endStr]);

        $results = [];
        $currentSymbol = null;

        // Per-symbol state
        $firstPrice = null;
        $lastPrice = null;
        $maxPriceSoFar = null; // for tracking drawdown from peaks
        $maxDrawdownPctOnSymbol = 0.0;
        $barsCount = 0;
        $upBars = 0;
        $downBars = 0;
        $prevPrice = null;
        $lastAssetType = null;
        $lastAssetId = null;

        $flush = function () use (
            &$results,
            &$currentSymbol,
            &$firstPrice,
            &$lastPrice,
            &$maxPriceSoFar,
            &$maxDrawdownPctOnSymbol,
            &$barsCount,
            &$upBars,
            &$downBars,
            &$prevPrice,
            &$lastAssetType,
            &$lastAssetId,
            $maxDrawdownPct,
            $minTrendPct
        ) {
            if ($currentSymbol === null || $barsCount < 3) {
                // Not enough bars to say anything useful
                $this->resetSymbolState(
                    $currentSymbol,
                    $firstPrice,
                    $lastPrice,
                    $maxPriceSoFar,
                    $maxDrawdownPctOnSymbol,
                    $barsCount,
                    $upBars,
                    $downBars,
                    $prevPrice,
                    $lastAssetType,
                    $lastAssetId
                );

                return;
            }

            if ($firstPrice <= 0.0 || $lastPrice === null) {
                $this->resetSymbolState(
                    $currentSymbol,
                    $firstPrice,
                    $lastPrice,
                    $maxPriceSoFar,
                    $maxDrawdownPctOnSymbol,
                    $barsCount,
                    $upBars,
                    $downBars,
                    $prevPrice,
                    $lastAssetType,
                    $lastAssetId
                );

                return;
            }

            $trendPct = ($lastPrice - $firstPrice) / $firstPrice; // e.g. 0.01 = +1%

            // If trend is not positive enough, skip
            if ($trendPct < $minTrendPct) {
                $this->resetSymbolState(
                    $currentSymbol,
                    $firstPrice,
                    $lastPrice,
                    $maxPriceSoFar,
                    $maxDrawdownPctOnSymbol,
                    $barsCount,
                    $upBars,
                    $downBars,
                    $prevPrice,
                    $lastAssetType,
                    $lastAssetId
                );

                return;
            }

            // maxDrawdownPctOnSymbol is negative or zero (e.g. -0.007 = -0.7%)
            $drawdownAbs = abs($maxDrawdownPctOnSymbol);

            // If drawdown is too large for tight stops, skip
            if ($drawdownAbs > $maxDrawdownPct) {
                $this->resetSymbolState(
                    $currentSymbol,
                    $firstPrice,
                    $lastPrice,
                    $maxPriceSoFar,
                    $maxDrawdownPctOnSymbol,
                    $barsCount,
                    $upBars,
                    $downBars,
                    $prevPrice,
                    $lastAssetType,
                    $lastAssetId
                );

                return;
            }

            // Risk-adjusted score: trend vs. worst dip
            $denom = $drawdownAbs > 0 ? $drawdownAbs : 0.0001;
            $riskScore = $trendPct / $denom;

            $results[] = [
                'symbol' => $currentSymbol,
                'asset_type' => $lastAssetType,
                'asset_id' => $lastAssetId,
                'last_price' => $lastPrice,
                'trend_pct' => $trendPct,
                'max_drawdown_pct' => $maxDrawdownPctOnSymbol,
                'bars' => $barsCount,
                'up_bars' => $upBars,
                'down_bars' => $downBars,
                'risk_score' => $riskScore,
            ];

            $this->resetSymbolState(
                $currentSymbol,
                $firstPrice,
                $lastPrice,
                $maxPriceSoFar,
                $maxDrawdownPctOnSymbol,
                $barsCount,
                $upBars,
                $downBars,
                $prevPrice,
                $lastAssetType,
                $lastAssetId
            );
        };

        foreach ($rows as $row) {
            $symbol = $row->symbol;

            if ($currentSymbol !== null && $symbol !== $currentSymbol) {
                // process previous symbol before switching
                $flush();
            }

            if ($currentSymbol !== $symbol) {
                // starting a new symbol
                $currentSymbol = $symbol;
                $firstPrice = null;
                $lastPrice = null;
                $maxPriceSoFar = null;
                $maxDrawdownPctOnSymbol = 0.0;
                $barsCount = 0;
                $upBars = 0;
                $downBars = 0;
                $prevPrice = null;
                $lastAssetType = $row->asset_type;
                $lastAssetId = $row->asset_id;
            }

            $price = (float) $row->price;
            $high = $row->high !== null ? (float) $row->high : $price;
            $low = $row->low !== null ? (float) $row->low : $price;

            if ($firstPrice === null) {
                $firstPrice = $price;
            }

            $lastPrice = $price;
            $barsCount++;

            // Up / down bar counting for feel of choppiness vs trend
            if ($prevPrice !== null) {
                if ($price > $prevPrice) {
                    $upBars++;
                } elseif ($price < $prevPrice) {
                    $downBars++;
                }
            }
            $prevPrice = $price;

            // Peak-to-trough drawdown tracking
            if ($maxPriceSoFar === null || $high > $maxPriceSoFar) {
                $maxPriceSoFar = $high;
            }

            if ($maxPriceSoFar > 0.0) {
                $drawdown = ($low - $maxPriceSoFar) / $maxPriceSoFar; // negative or zero
                if ($drawdown < $maxDrawdownPctOnSymbol) {
                    $maxDrawdownPctOnSymbol = $drawdown;
                }
            }
        }

        // Flush last symbol
        $flush();

        // Determine if this is historical analysis
        $isHistorical = $endEstDatetime !== null;

        // Get daily high data for historical analysis
        $dailyHighData = [];
        if ($isHistorical && ! empty($results)) {
            $symbols = array_column($results, 'symbol');
            $dailyHighData = $this->getBatchDailyHighData($symbols, $endStr);
        }

        // Add daily high data to results if available
        if ($isHistorical && ! empty($dailyHighData)) {
            foreach ($results as &$result) {
                if (isset($dailyHighData[$result['symbol']])) {
                    $dailyHighPrice = $dailyHighData[$result['symbol']];
                    $result['daily_high_price'] = $dailyHighPrice;
                    $result['daily_high_pct'] = (($dailyHighPrice - $result['last_price']) / $result['last_price']) * 100;
                }
            }
            unset($result); // Break reference
        }

        // Sort best -> worst by risk score (higher better)
        usort($results, static function (array $a, array $b) {
            return $b['risk_score'] <=> $a['risk_score'];
        });

        return $results;
    }

    /**
     * Helper to reset per-symbol state.
     */
    private function resetSymbolState(
        ?string &$currentSymbol,
        ?float &$firstPrice,
        ?float &$lastPrice,
        ?float &$maxPriceSoFar,
        float &$maxDrawdownPctOnSymbol,
        int &$barsCount,
        int &$upBars,
        int &$downBars,
        ?float &$prevPrice,
        ?string &$lastAssetType,
        ?int &$lastAssetId
    ): void {
        $firstPrice = null;
        $lastPrice = null;
        $maxPriceSoFar = null;
        $maxDrawdownPctOnSymbol = 0.0;
        $barsCount = 0;
        $upBars = 0;
        $downBars = 0;
        $prevPrice = null;
        $lastAssetType = null;
        $lastAssetId = null;
        // Note: $currentSymbol is left as-is; caller typically sets it when switching
    }

    /**
     * Get analysis parameters summary for display.
     */
    public function getAnalysisSummary(
        ?string $endEstDatetime = null,
        int $lookbackMinutes = 120,
        float $maxDrawdownPct = 0.01,
        float $minTrendPct = 0.005,
        string $assetType = 'stock',
        bool $onlyOver1Mil = false
    ): array {
        $tzEst = new DateTimeZone('America/New_York');
        $endEst = $endEstDatetime
            ? new DateTime($endEstDatetime, $tzEst)
            : new DateTime('now', $tzEst);

        return [
            'end_est' => $endEst->format('Y-m-d H:i:s'),
            'lookback_minutes' => $lookbackMinutes,
            'max_drawdown_pct' => $maxDrawdownPct,
            'min_trend_pct' => $minTrendPct,
            'asset_type' => $assetType,
            'only_over_1mil' => $onlyOver1Mil,
            'max_drawdown_display' => sprintf('%.2f%%', $maxDrawdownPct * 100),
            'min_trend_display' => sprintf('%.2f%%', $minTrendPct * 100),
            'market_cap_filter' => $onlyOver1Mil ? 'Over $1M only' : 'All market caps',
        ];
    }

    /**
     * Get daily high prices for multiple symbols for historical analysis.
     * Returns the highest price after the analysis time until market close.
     *
     * @param  array  $symbols  Array of symbol names
     * @param  string  $timestampUsed  Analysis timestamp in EST
     * @return array Symbol => highest price mapping
     */
    private function getBatchDailyHighData(array $symbols, string $timestampUsed): array
    {
        if (empty($symbols)) {
            return [];
        }

        // Parse the analysis time and create the end of trading day timestamp
        $analysisTime = new DateTime($timestampUsed, new DateTimeZone('America/New_York'));
        $endOfDay = clone $analysisTime;
        $endOfDay->setTime(16, 0, 0); // 4:00 PM EST (market close)

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
}
