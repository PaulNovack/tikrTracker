<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OptimizedBuySignalsService
{
    /**
     * Batch fetch all price data for all symbols at once
     * This eliminates the N+1 query problem
     */
    private function batchGetPriceData(array $symbols, Carbon $simTime): array
    {
        $symbolList = array_column($symbols, 'symbol');

        // Batch fetch 5-minute data for all symbols
        $fiveMinData = DB::table('five_minute_prices')
            ->whereIn('symbol', $symbolList)
            ->where('asset_type', 'stock')
            ->where('ts_est', '<=', $simTime->format('Y-m-d H:i:s'))
            ->where('ts_est', '>=', $simTime->copy()->subHours(48)->format('Y-m-d H:i:s')) // Limit to reasonable time window
            ->select('symbol', 'ts_est', 'price', 'open', 'high', 'low', 'volume')
            ->orderBy('symbol')
            ->orderBy('ts_est', 'desc')
            ->get();

        // Batch fetch 1-minute data for all symbols
        $oneMinData = DB::table('one_minute_prices')
            ->whereIn('symbol', $symbolList)
            ->where('asset_type', 'stock')
            ->where('ts_est', '<=', $simTime->format('Y-m-d H:i:s'))
            ->where('ts_est', '>=', $simTime->copy()->subHours(2)->format('Y-m-d H:i:s')) // Limit to reasonable time window
            ->select('symbol', 'ts_est', 'price', 'open', 'high', 'low', 'volume')
            ->orderBy('symbol')
            ->orderBy('ts_est', 'desc')
            ->get();

        // Group by symbol and limit to required number of records per symbol
        $groupedFiveMin = [];
        $groupedOneMin = [];

        foreach ($fiveMinData as $row) {
            $symbol = $row->symbol;
            if (! isset($groupedFiveMin[$symbol])) {
                $groupedFiveMin[$symbol] = [];
            }
            if (count($groupedFiveMin[$symbol]) < 300) { // Limit to 300 records per symbol
                $groupedFiveMin[$symbol][] = (array) $row;
            }
        }

        foreach ($oneMinData as $row) {
            $symbol = $row->symbol;
            if (! isset($groupedOneMin[$symbol])) {
                $groupedOneMin[$symbol] = [];
            }
            if (count($groupedOneMin[$symbol]) < 60) { // Limit to 60 records per symbol
                $groupedOneMin[$symbol][] = (array) $row;
            }
        }

        // Reverse arrays to get oldest first (matching original logic)
        foreach ($groupedFiveMin as $symbol => &$data) {
            $data = array_reverse($data);
        }
        foreach ($groupedOneMin as $symbol => &$data) {
            $data = array_reverse($data);
        }

        return [
            'five_min' => $groupedFiveMin,
            'one_min' => $groupedOneMin,
        ];
    }

    /**
     * Get active symbols with caching for better performance
     */
    private function getActiveSymbolsWithCache(): array
    {
        return Cache::remember('active_symbols_buy_signals', 300, function () { // Cache for 5 minutes
            return DB::table('asset_info')
                ->where('asset_type', 'stock')
                ->where('over_1mil', true)
                ->whereNull('deleted_at')
                ->orderBy('symbol')
                ->select('id', 'symbol')
                ->get()
                ->map(fn ($asset) => [
                    'id' => $asset->id,
                    'symbol' => strtoupper(trim($asset->symbol)),
                ])
                ->toArray();
        });
    }

    /**
     * Check if the US stock market is currently closed
     */
    private function isMarketClosed(Carbon $time): bool
    {
        // Convert to EST
        $estTime = $time->setTimezone('America/New_York');

        // Check if it's weekend
        if ($estTime->isWeekend()) {
            return true;
        }

        // Market hours: 9:30 AM - 4:00 PM EST on weekdays
        $marketOpen = $estTime->copy()->setTime(9, 30);
        $marketClose = $estTime->copy()->setTime(16, 0);

        return $estTime->lt($marketOpen) || $estTime->gt($marketClose);
    }

    /**
     * Get the next market open time
     */
    private function getNextMarketOpenTime(Carbon $time): Carbon
    {
        $estTime = $time->setTimezone('America/New_York');

        // Start with today
        $nextOpen = $estTime->copy();

        // If it's already past market close (4:00 PM) or weekend, move to next day
        if ($estTime->hour >= 16 || $estTime->isWeekend()) {
            $nextOpen->addDay();
        }

        // If it's before market open today (9:30 AM) and not weekend, use today
        if ($estTime->hour < 9 || ($estTime->hour == 9 && $estTime->minute < 30)) {
            if (! $estTime->isWeekend()) {
                $nextOpen = $estTime->copy();
            }
        }

        // Skip to next weekday if it's a weekend
        while ($nextOpen->isWeekend()) {
            $nextOpen->addDay();
        }

        // Set to market open time (9:30 AM EST)
        return $nextOpen->setTime(9, 30);
    }

    /**
     * Get appropriate entry time - current time if market is open, next market open if closed
     */
    private function getAppropriateEntryTime(Carbon $simTime): Carbon
    {
        if ($this->isMarketClosed($simTime)) {
            return $this->getNextMarketOpenTime($simTime);
        }

        return $simTime;
    }

    /**
     * Process buy signals in smaller batches to reduce memory usage
     */
    public function getBuySignalsBatched(?Carbon $simTime = null, int $batchSize = 500): array
    {
        if ($simTime === null) {
            $simTime = Carbon::now('America/New_York');
        }

        $symbols = $this->getActiveSymbolsWithCache();
        $allSignals = [];

        // Process symbols in batches to manage memory and query load
        $batches = array_chunk($symbols, $batchSize);

        foreach ($batches as $batchSymbols) {
            // Batch fetch price data for this batch of symbols
            $priceData = $this->batchGetPriceData($batchSymbols, $simTime);

            // Process each symbol in the batch
            foreach ($batchSymbols as $symbolData) {
                $symbol = $symbolData['symbol'];
                $assetId = $symbolData['id'];

                if (empty($symbol)) {
                    continue;
                }

                $fiveMinData = $priceData['five_min'][$symbol] ?? [];
                $oneMinData = $priceData['one_min'][$symbol] ?? [];

                if (count($fiveMinData) < 60) {
                    continue;
                } // Need sufficient data

                $signal = $this->computeBuySignalWithData($symbol, $assetId, $fiveMinData, $oneMinData, $simTime);
                if ($signal !== null) {
                    $allSignals[] = $signal;
                }
            }
        }

        // Sort signals by score
        if (! empty($allSignals)) {
            usort($allSignals, function (array $a, array $b): int {
                $scoreCmp = $b['score'] <=> $a['score'];
                if ($scoreCmp !== 0) {
                    return $scoreCmp;
                }

                $trendA = (float) $a['ema9'] - (float) $a['ema21'];
                $trendB = (float) $b['ema9'] - (float) $b['ema21'];

                return $trendB <=> $trendA;
            });
        }

        return $allSignals;
    }

    /**
     * Optimized compute buy signal using pre-fetched data
     */
    private function computeBuySignalWithData(
        string $symbol,
        int $assetId,
        array $fiveMinData,
        array $oneMinData,
        Carbon $simTime
    ): ?array {
        if (count($fiveMinData) < 60) {
            return null;
        }

        // --- EMAs & VWAP on 5m ---
        $closes = array_column($fiveMinData, 'price');

        $ema9 = $this->computeEma($closes, 9);
        $ema21 = $this->computeEma($closes, 21);
        $ema50 = $this->computeEma($closes, 50);
        $vwap = $this->computeVwap($fiveMinData);

        if ($ema9 === null || $ema21 === null || $ema50 === null || $vwap === null) {
            return null;
        }

        $last = $fiveMinData[count($fiveMinData) - 1];
        $prev = $fiveMinData[count($fiveMinData) - 2];

        $lastClose = (float) $last['price'];

        // --- Trend (forgiving) ---
        $trendUp = $lastClose > (float) $ema50 && (float) $ema9 > (float) $ema21;
        if (! $trendUp) {
            return null;
        }

        // --- Pullback on 5m (forgiving) ---
        $currLow = (float) $last['low'];
        $prevLow = (float) $prev['low'];

        $pulledNearEma9 = $currLow <= ((float) $ema9 * 1.003);
        $pulledFromAboveEma9 = $prevLow > (float) $ema9;
        $pulledNearVwap = $currLow <= ((float) $vwap * 1.003);
        $pulledFromAboveVwap = $prevLow > (float) $vwap;

        $isPullback = ($pulledNearEma9 && $pulledFromAboveEma9) ||
                      ($pulledNearVwap && $pulledFromAboveVwap);

        if (! $isPullback) {
            return null;
        }

        // --- 1m confirmation (optional) ---
        $useOneMinute = count($oneMinData) >= 10;

        // Defaults: 5m-based entry
        $entryPrice = (float) $last['price'];
        $stopLossBuffer = config('market.buy_signals.stop_loss_buffer_pct', 0.5) / 100;
        $stopLoss = (float) $last['low'] * (1 - $stopLossBuffer);
        $appropriateEntryTime = $this->getAppropriateEntryTime($simTime);
        $entryTime = $appropriateEntryTime->toISOString();
        $reason = [];

        if ($useOneMinute) {
            $n = count($oneMinData) - 1;

            $lowNm1 = (float) $oneMinData[$n - 1]['low'];
            $lowNm2 = (float) $oneMinData[$n - 2]['low'];
            $closeN = (float) $oneMinData[$n]['price'];
            $highNm1 = (float) $oneMinData[$n - 1]['high'];

            $hl = $lowNm1 > $lowNm2;
            $break = $closeN > $highNm1;

            $volSlice = array_slice($oneMinData, max(0, $n - 5), 5);
            $vols = array_map(
                fn ($r) => isset($r['volume']) ? (float) $r['volume'] : 0.0,
                $volSlice
            );
            $avgVol = count($vols) > 0 ? array_sum($vols) / count($vols) : 0.0;
            $volN = isset($oneMinData[$n]['volume']) ? (float) $oneMinData[$n]['volume'] : 0.0;
            $volSpike = $avgVol > 0.0 && $volN > ($avgVol * 1.2);

            if ($hl && $break && $volSpike) {
                $entryPrice = $closeN;
                $rawStopLoss = min($lowNm1, $currLow);
                $stopLoss = $rawStopLoss * (1 - $stopLossBuffer);
                $entryTime = $appropriateEntryTime->toISOString();
                $reason[] = '1m HL+break+volume';
            } else {
                $lastOpen = (float) $last['open'];
                $lastClose = (float) $last['price'];

                if (! ($lastClose > $lastOpen)) {
                    return null;
                }
                $reason[] = 'fallback bullish 5m';
            }
        } else {
            $lastOpen = (float) $last['open'];
            $lastClose = (float) $last['price'];

            if (! ($lastClose > $lastOpen)) {
                return null;
            }
            $reason[] = '5m-only entry (no 1m)';
        }

        $risk = $entryPrice - $stopLoss;
        if ($risk <= 0.0) {
            return null;
        }

        // --- SCORING SYSTEM ---
        $score = 0;

        // Flags for scoring
        $hl = false;
        $break = false;
        $volSpike = false;

        if ($useOneMinute && count($oneMinData) >= 10) {
            $n = count($oneMinData) - 1;
            $lowNm1 = (float) $oneMinData[$n - 1]['low'];
            $lowNm2 = (float) $oneMinData[$n - 2]['low'];
            $closeN = (float) $oneMinData[$n]['price'];
            $highNm1 = (float) $oneMinData[$n - 1]['high'];

            $hl = $lowNm1 > $lowNm2;
            $break = $closeN > $highNm1;

            $volSlice = array_slice($oneMinData, max(0, $n - 5), 5);
            $vols = array_map(
                fn ($r) => isset($r['volume']) ? (float) $r['volume'] : 0.0,
                $volSlice
            );
            $avgVol = count($vols) > 0 ? array_sum($vols) / count($vols) : 0.0;
            $volN = isset($oneMinData[$n]['volume']) ? (float) $oneMinData[$n]['volume'] : 0.0;
            $volSpike = $avgVol > 0.0 && $volN > ($avgVol * 1.2);
        }

        // Trend strength
        if ($trendUp) {
            $score += 2;
        }
        // EMA alignment (stronger short-term trend)
        if ((float) $ema9 > (float) $ema21) {
            $score += 1;
        }
        if ($lastClose > (float) $ema50) {
            $score += 1;
        }

        // Pullback quality
        if ($pulledNearEma9) {
            $score += 1;
        }
        if ($pulledNearVwap) {
            $score += 1;
        }

        // 1m confirmation bonuses
        if ($useOneMinute) {
            if ($hl) {
                $score += 1;
            }
            if ($break) {
                $score += 1;
            }
            if ($volSpike) {
                $score += 1;
            }
        }

        // Reward / risk potential bonus
        if ($risk > 0.0) {
            if ($risk <= $entryPrice * 0.01) {
                $score += 1;
            }
        }

        return [
            'asset_id' => $assetId,
            'symbol' => $symbol,
            'entry_time_est' => $entryTime,
            'entry_price' => round((float) $entryPrice, 4),
            'stop_loss' => round((float) $stopLoss, 4),
            'risk_per_share' => round((float) $risk, 4),
            'ema9' => round((float) $ema9, 4),
            'ema21' => round((float) $ema21, 4),
            'ema50' => round((float) $ema50, 4),
            'vwap' => round((float) $vwap, 4),
            'score' => $score,
            'reason' => implode(', ', $reason),
        ];
    }

    /**
     * Copy original helper methods
     */
    private function computeEma(array $closes, int $length): ?float
    {
        $count = count($closes);
        if ($count < $length) {
            return null;
        }

        $k = 2 / ($length + 1);
        $closesFloat = array_map(fn ($v) => (float) $v, $closes);
        $ema = array_sum(array_slice($closesFloat, 0, $length)) / $length;

        for ($i = $length; $i < $count; $i++) {
            $ema = ($closesFloat[$i] - $ema) * $k + $ema;
        }

        return $ema;
    }

    private function computeVwap(array $rows): ?float
    {
        $sumPv = 0.0;
        $sumV = 0.0;

        foreach ($rows as $r) {
            $price = isset($r['price']) ? (float) $r['price'] : 0.0;
            $vol = isset($r['volume']) ? (float) $r['volume'] : 0.0;

            if ($vol <= 0) {
                continue;
            }

            $sumPv += $price * $vol;
            $sumV += $vol;
        }

        if ($sumV <= 0.0) {
            return null;
        }

        return $sumPv / $sumV;
    }
}
