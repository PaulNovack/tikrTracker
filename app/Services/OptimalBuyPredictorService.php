<?php

namespace App\Services;

use App\Models\BuyWindowSignal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OptimalBuyPredictorService
{
    /**
     * Buy Predictor service implementing the 11.533% breakthrough algorithm
     * NOW USING 1-MINUTE DATA for faster, more precise entry signals
     * Based on the CLI buy_predictor.php logic optimized for 10:15 AM timing
     */
    public function scan(
        ?string $asOfEst = null,
        string $assetType = 'stock',
        int $minScore = 5,
        int $lookbackMinutes = 90,
        int $limit = 50,
        bool $storeSignals = false
    ): array {
        // Convert to EST timezone - optimal timing is 10:15 AM EST
        $asOf = $asOfEst
            ? EstTimezoneHelper::parseEstTimestamp($asOfEst)
            : Carbon::now('America/New_York');

        $asOfStr = $asOf->format('Y-m-d H:i:s');

        // Calculate lookback window
        $startEst = (clone $asOf)->modify("-{$lookbackMinutes} minutes");
        $startStr = $startEst->format('Y-m-d H:i:s');

        // Check if we're at optimal timing (10:15 AM EST)
        $timeStr = $asOf->format('H:i:s');
        $isOptimalTime = ($timeStr === '10:15:00');

        try {
            // Fetch 1-minute data for PRIMARY analysis (faster entry signals)
            $oneMinData = DB::select('
                SELECT symbol, asset_type, ts_est, price, open, high, low, volume
                FROM one_minute_prices
                WHERE asset_type = ?
                  AND ts_est BETWEEN ? AND ?
                ORDER BY symbol, ts_est
            ', [$assetType, $startStr, $asOfStr]);

            if (empty($oneMinData)) {
                return [
                    'ok' => true,
                    'isOptimalTime' => $isOptimalTime,
                    'optimalTime' => '10:15:00',
                    'asOfEst' => $asOfStr,
                    'message' => "No 1-minute data found for {$assetType}",
                    'signals' => [],
                ];
            }

            // Group by symbol
            $symbolData = [];
            foreach ($oneMinData as $row) {
                $sym = $row->symbol;
                if (! isset($symbolData[$sym])) {
                    $symbolData[$sym] = [];
                }
                $symbolData[$sym][] = (array) $row;
            }

            // Analyze each symbol using 1-minute data
            $results = [];
            foreach ($symbolData as $symbol => $bars1m) {
                if (count($bars1m) < 20) {
                    continue;
                } // Need sufficient 1-minute bars

                $analysis = $this->analyzeSymbol($symbol, $bars1m, []);

                if ($analysis['score'] >= $minScore) {
                    $results[] = $analysis;
                }
            }

            // Sort by score descending
            usort($results, function ($a, $b) {
                return $b['score'] <=> $a['score'];
            });

            // Limit results
            $results = array_slice($results, 0, $limit);

            // Store signals in database if requested
            if ($storeSignals && ! empty($results)) {
                $this->storeSignals($results, $asOfStr, $assetType, $lookbackMinutes, $isOptimalTime);
            }

            return [
                'ok' => true,
                'isOptimalTime' => $isOptimalTime,
                'optimalTime' => '10:15:00',
                'asOfEst' => $asOfStr,
                'lookbackMinutes' => $lookbackMinutes,
                'totalSymbols' => count($symbolData),
                'qualifiedSignals' => count($results),
                'signals' => $results,
            ];

        } catch (\Exception $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'signals' => [],
            ];
        }
    }

    private function analyzeSymbol(string $symbol, array $bars1m, array $unused): array
    {
        $lastBar = end($bars1m);
        $score = 0;
        $reasons = [];

        // Basic metrics from 1-minute bar
        $last = (float) $lastBar['price'];
        $high = (float) $lastBar['high'];
        $low = (float) $lastBar['low'];
        $open = (float) $lastBar['open'];
        $volume = (float) $lastBar['volume'];

        // Calculate range percentage
        $rangePct = $low > 0 ? (($high - $low) / $low) * 100 : 0;

        // Calculate pullback percentage
        $pullbackPct = $high > 0 ? (($high - $last) / $high) * 100 : 0;

        // Volume analysis - key for 11.533% strategy (adapted for 1-minute bars)
        $volumeSurge = $this->calculateVolumeSurge($bars1m);

        // VWAP calculation
        $vwap = $this->calculateVWAP($bars1m);

        // Moving averages (using appropriate periods for 1-minute data)
        $ma10 = $this->calculateMA($bars1m, 10);  // ~10 minutes
        $ma30 = $this->calculateMA($bars1m, 30);  // ~30 minutes

        // Scoring logic based on breakthrough algorithm

        // Range scoring - explosive moves get higher scores
        if ($rangePct >= 6.0) {
            $score += 6;
            $reasons[] = 'explosive range '.round($rangePct, 2).'%';
        } elseif ($rangePct >= 4.0) {
            $score += 5;
            $reasons[] = 'strong range '.round($rangePct, 2).'%';
        } elseif ($rangePct >= 2.0) {
            $score += 3;
            $reasons[] = 'decent range '.round($rangePct, 2).'%';
        }

        // Volume surge scoring - critical for 11.533% performance
        if ($volumeSurge >= 20.0) {
            $score += 6;
            $reasons[] = 'massive volume surge '.round($volumeSurge, 2).'x';
        } elseif ($volumeSurge >= 5.0) {
            $score += 5;
            $reasons[] = 'strong volume surge '.round($volumeSurge, 2).'x';
        } elseif ($volumeSurge >= 2.0) {
            $score += 3;
            $reasons[] = 'volume surge '.round($volumeSurge, 2).'x';
        }

        // Pullback scoring - optimal entry on small pullbacks
        if ($pullbackPct <= 1.0 && $pullbackPct >= 0.0) {
            $score += 2;
            $reasons[] = 'optimal pullback '.round($pullbackPct, 2).'%';
        }

        // VWAP positioning
        if ($last > $vwap * 1.01) {
            $score += 1;
            $reasons[] = 'above VWAP';
        }

        // Trend confirmation (10-min over 30-min MA)
        if ($ma10 > $ma30) {
            $score += 1;
            $reasons[] = 'bullish trend';
        }

        // Lookup asset_id for symbol links
        $assetInfo = DB::selectOne('SELECT id FROM asset_info WHERE symbol = ?', [$symbol]);
        $assetId = $assetInfo ? (int) $assetInfo->id : null;

        return [
            'symbol' => $symbol,
            'score' => $score,
            'last' => $last,
            'rangePct' => round($rangePct, 2),
            'pullbackPct' => round($pullbackPct, 2),
            'volumeSurge' => round($volumeSurge, 1),
            'vwap' => round($vwap, 4),
            'ma10' => round($ma10, 4),
            'ma30' => round($ma30, 4),
            'reasons' => $reasons,
            'timestamp' => $lastBar['ts_est'],
            'asset_id' => $assetId,
        ];
    }

    private function calculateVolumeSurge(array $bars): float
    {
        if (count($bars) < 6) {
            return 1.0;
        }

        $recent = array_slice($bars, -1, 1)[0];
        $previous = array_slice($bars, -6, 5);

        $recentVolume = (float) $recent['volume'];
        $avgVolume = array_sum(array_column($previous, 'volume')) / count($previous);

        return $avgVolume > 0 ? $recentVolume / $avgVolume : 1.0;
    }

    private function calculateVWAP(array $bars): float
    {
        $totalVolume = 0;
        $totalPriceVolume = 0;

        foreach ($bars as $bar) {
            $price = ((float) $bar['high'] + (float) $bar['low'] + (float) $bar['price']) / 3;
            $volume = (float) $bar['volume'];

            $totalPriceVolume += $price * $volume;
            $totalVolume += $volume;
        }

        return $totalVolume > 0 ? $totalPriceVolume / $totalVolume : 0;
    }

    private function calculateMA(array $bars, int $period): float
    {
        if (count($bars) < $period) {
            return 0;
        }

        $prices = array_slice(array_column($bars, 'price'), -$period);

        return array_sum($prices) / count($prices);
    }

    /**
     * Store signals in database with automatic deduplication
     */
    private function storeSignals(
        array $signals,
        string $signalTime,
        string $assetType,
        int $lookbackMinutes,
        bool $isOptimalTime
    ): void {
        foreach ($signals as $signal) {
            try {
                BuyWindowSignal::updateOrCreate(
                    [
                        'symbol' => $signal['symbol'],
                        'signal_time' => $signalTime,
                        'asset_type' => $assetType,
                    ],
                    [
                        'asset_id' => $signal['asset_id'],
                        'score' => $signal['score'],
                        'last_price' => $signal['last'],
                        'range_pct' => $signal['rangePct'],
                        'pullback_pct' => $signal['pullbackPct'],
                        'volume_surge' => $signal['volumeSurge'],
                        'vwap' => $signal['vwap'],
                        'ma10' => $signal['ma10'],
                        'ma30' => $signal['ma30'],
                        'reasons' => $signal['reasons'],
                        'lookback_minutes' => $lookbackMinutes,
                        'is_optimal_time' => $isOptimalTime,
                    ]
                );
            } catch (\Exception $e) {
                // Log but don't fail - duplicate key violations are expected
                Log::warning("Failed to store buy window signal for {$signal['symbol']}: {$e->getMessage()}");
            }
        }
    }
}
