<?php

namespace App\Services;

use App\Models\MarketSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BuySignalsService
{
    /**
     * Check if the US stock market is currently closed using database market schedule
     */
    private function isMarketClosed(Carbon $time): bool
    {
        // Convert to EST/EDT
        $estTime = $time->setTimezone('America/New_York');
        $dateString = $estTime->toDateString();

        // Get market schedule for this date
        $schedule = MarketSchedule::byMarketType('stock')
            ->where('date', $dateString)
            ->first();

        // If no schedule found, assume closed (safety default)
        if (! $schedule) {
            return true;
        }

        // If market is explicitly closed or holiday
        if (! $schedule->isOpen()) {
            return true;
        }

        // Check if current time is within market hours
        $currentTimeString = $estTime->format('H:i:s');
        $opensAt = $schedule->opens_at instanceof Carbon
            ? $schedule->opens_at->format('H:i:s')
            : $schedule->opens_at;
        $closesAt = $schedule->closes_at instanceof Carbon
            ? $schedule->closes_at->format('H:i:s')
            : $schedule->closes_at;

        return $currentTimeString < $opensAt || $currentTimeString >= $closesAt;
    }

    /**
     * Get the most recent market close time using database market schedule
     */
    private function getLastMarketCloseTime(Carbon $time): Carbon
    {
        $estTime = $time->setTimezone('America/New_York');
        $currentDate = $estTime->toDateString();

        // Try to find market schedule for today first
        $schedule = MarketSchedule::byMarketType('stock')
            ->where('date', $currentDate)
            ->first();

        // If market is open today and we're past the opening time, use today's close
        if ($schedule && $schedule->isOpen()) {
            $opensAt = $schedule->opens_at instanceof Carbon
                ? $schedule->opens_at->format('H:i:s')
                : $schedule->opens_at;

            if ($estTime->format('H:i:s') >= $opensAt) {
                $closesAt = $schedule->closes_at instanceof Carbon
                    ? $schedule->closes_at->format('H:i:s')
                    : $schedule->closes_at;

                return $estTime->copy()->setTimeFromTimeString($closesAt);
            }
        }

        // Look for the previous market day
        $searchDate = $estTime->copy()->subDay();
        while (true) {
            $prevSchedule = MarketSchedule::byMarketType('stock')
                ->where('date', $searchDate->toDateString())
                ->first();

            if ($prevSchedule && $prevSchedule->isOpen()) {
                $closesAt = $prevSchedule->closes_at instanceof Carbon
                    ? $prevSchedule->closes_at->format('H:i:s')
                    : $prevSchedule->closes_at;

                return $searchDate->setTimeFromTimeString($closesAt);
            }

            $searchDate->subDay();

            // Safety check: don't go back more than 10 days
            if ($searchDate->diffInDays($estTime) > 10) {
                // Fallback to default 4:00 PM on previous weekday
                $fallback = $estTime->copy()->subDay();
                while ($fallback->isWeekend()) {
                    $fallback->subDay();
                }

                return $fallback->setTime(16, 0);
            }
        }
    }

    /**
     * Get the next market open time using database market schedule
     */
    private function getNextMarketOpenTime(Carbon $time): Carbon
    {
        $estTime = $time->setTimezone('America/New_York');
        $searchDate = $estTime->copy();

        // Check if market is open today and we haven't passed opening time yet
        $todaySchedule = MarketSchedule::byMarketType('stock')
            ->where('date', $searchDate->toDateString())
            ->first();

        if ($todaySchedule && $todaySchedule->isOpen()) {
            $opensAt = $todaySchedule->opens_at instanceof Carbon
                ? $todaySchedule->opens_at->format('H:i:s')
                : $todaySchedule->opens_at;

            // If we're before market open today, return today's open time
            if ($estTime->format('H:i:s') < $opensAt) {
                return $searchDate->setTimeFromTimeString($opensAt);
            }
        }

        // Look for next market open day
        $searchDate->addDay();
        while (true) {
            $nextSchedule = MarketSchedule::byMarketType('stock')
                ->where('date', $searchDate->toDateString())
                ->first();

            if ($nextSchedule && $nextSchedule->isOpen()) {
                $opensAt = $nextSchedule->opens_at instanceof Carbon
                    ? $nextSchedule->opens_at->format('H:i:s')
                    : $nextSchedule->opens_at;

                return $searchDate->setTimeFromTimeString($opensAt);
            }

            $searchDate->addDay();

            // Safety check: don't look more than 10 days ahead
            if ($searchDate->diffInDays($estTime) > 10) {
                // Fallback to next weekday at 9:30 AM
                $fallback = $estTime->copy()->addDay();
                while ($fallback->isWeekend()) {
                    $fallback->addDay();
                }

                return $fallback->setTime(9, 30);
            }
        }
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
     * Get active stock symbols from the database with over $1M daily trading volume
     */
    public function getActiveSymbols(): array
    {
        return DB::table('asset_info')
            ->where('asset_type', 'stock')
            ->where('over_1mil', true)
            ->whereNull('deleted_at')
            ->orderBy('symbol')
            ->pluck('symbol')
            ->map(fn ($symbol) => strtoupper(trim($symbol)))
            ->toArray();
    }

    /**
     * Get active stock symbols with their asset_info IDs
     */
    public function getActiveSymbolsWithIds(): array
    {
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
    }

    /**
     * Compute EMA (Exponential Moving Average)
     */
    private function computeEma(array $closes, int $length): ?float
    {
        $count = count($closes);
        if ($count < $length) {
            return null;
        }

        $k = 2 / ($length + 1);

        // Cast all to float
        $closesFloat = array_map(fn ($v) => (float) $v, $closes);

        $ema = array_sum(array_slice($closesFloat, 0, $length)) / $length;

        for ($i = $length; $i < $count; $i++) {
            $ema = ($closesFloat[$i] - $ema) * $k + $ema;
        }

        return $ema;
    }

    /**
     * Compute VWAP (Volume Weighted Average Price)
     */
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

    /**
     * Get 5-minute price data for a symbol
     */
    private function get5mData(string $symbol, Carbon $simTime): array
    {
        $rows = DB::table('five_minute_prices')
            ->where('symbol', $symbol)
            ->where('asset_type', 'stock')
            ->where('ts_est', '<=', $simTime->format('Y-m-d H:i:s'))
            ->orderBy('ts_est', 'desc')
            ->limit(300)
            ->get()
            ->toArray();

        return array_reverse(array_map(fn ($row) => (array) $row, $rows)); // oldest first
    }

    /**
     * Get 1-minute price data for a symbol
     */
    private function get1mData(string $symbol, Carbon $simTime): array
    {
        $rows = DB::table('one_minute_prices')
            ->where('symbol', $symbol)
            ->where('asset_type', 'stock')
            ->where('ts_est', '<=', $simTime->format('Y-m-d H:i:s'))
            ->orderBy('ts_est', 'desc')
            ->limit(60)
            ->get()
            ->toArray();

        return array_reverse(array_map(fn ($row) => (array) $row, $rows)); // oldest first
    }

    /**
     * Compute buy signal for a specific symbol
     */
    public function computeBuySignalForSymbol(string $symbol, Carbon $simTime, ?int $assetId = null): ?array
    {
        $five = $this->get5mData($symbol, $simTime);
        if (count($five) < 60) {
            return null;
        }

        $one = $this->get1mData($symbol, $simTime);

        // --- EMAs & VWAP on 5m ---
        $closes = array_column($five, 'price');

        $ema9 = $this->computeEma($closes, 9);
        $ema21 = $this->computeEma($closes, 21);
        $ema50 = $this->computeEma($closes, 50);
        $vwap = $this->computeVwap($five);

        if ($ema9 === null || $ema21 === null || $ema50 === null || $vwap === null) {
            return null;
        }

        $last = $five[count($five) - 1];
        $prev = $five[count($five) - 2];

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
        $useOneMinute = count($one) >= 10;

        // Defaults: 5m-based entry
        $entryPrice = (float) $last['price'];
        $stopLossBuffer = config('market.buy_signals.stop_loss_buffer_pct', 0.5) / 100; // Convert percentage to decimal
        $stopLoss = (float) $last['low'] * (1 - $stopLossBuffer); // Apply buffer below the low
        $appropriateEntryTime = $this->getAppropriateEntryTime($simTime);
        $entryTime = $appropriateEntryTime->toISOString();  // Use appropriate market time
        $reason = [];

        if ($useOneMinute) {
            $n = count($one) - 1;

            $lowNm1 = (float) $one[$n - 1]['low'];
            $lowNm2 = (float) $one[$n - 2]['low'];
            $closeN = (float) $one[$n]['price'];
            $highNm1 = (float) $one[$n - 1]['high'];

            $hl = $lowNm1 > $lowNm2;
            $break = $closeN > $highNm1;

            $volSlice = array_slice($one, max(0, $n - 5), 5);
            $vols = array_map(
                fn ($r) => isset($r['volume']) ? (float) $r['volume'] : 0.0,
                $volSlice
            );
            $avgVol = count($vols) > 0 ? array_sum($vols) / count($vols) : 0.0;
            $volN = isset($one[$n]['volume']) ? (float) $one[$n]['volume'] : 0.0;
            $volSpike = $avgVol > 0.0 && $volN > ($avgVol * 1.2);

            if ($hl && $break && $volSpike) {
                $entryPrice = $closeN;
                $rawStopLoss = min($lowNm1, $currLow);
                $stopLoss = $rawStopLoss * (1 - $stopLossBuffer); // Apply buffer below the low
                $entryTime = $appropriateEntryTime->toISOString();  // Use appropriate market time
                $reason[] = '1m HL+break+volume';
            } else {
                // fallback to bullish 5m candle only
                $lastOpen = (float) $last['open'];
                $lastClose = (float) $last['price'];

                if (! ($lastClose > $lastOpen)) {
                    return null;
                }
                $reason[] = 'fallback bullish 5m';
            }
        } else {
            // No usable 1m → 5m-only logic
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

        // Flags for scoring (need to re-capture them)
        $hl = false;
        $break = false;
        $volSpike = false;

        if ($useOneMinute && count($one) >= 10) {
            $n = count($one) - 1;
            $lowNm1 = (float) $one[$n - 1]['low'];
            $lowNm2 = (float) $one[$n - 2]['low'];
            $closeN = (float) $one[$n]['price'];
            $highNm1 = (float) $one[$n - 1]['high'];

            $hl = $lowNm1 > $lowNm2;
            $break = $closeN > $highNm1;

            $volSlice = array_slice($one, max(0, $n - 5), 5);
            $vols = array_map(
                fn ($r) => isset($r['volume']) ? (float) $r['volume'] : 0.0,
                $volSlice
            );
            $avgVol = count($vols) > 0 ? array_sum($vols) / count($vols) : 0.0;
            $volN = isset($one[$n]['volume']) ? (float) $one[$n]['volume'] : 0.0;
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

        // Reward / risk potential bonus (lower risk per share preferred)
        if ($risk > 0.0) {
            if ($risk <= $entryPrice * 0.01) { // risk < 1% of price
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
     * Scan all symbols for buy signals
     */
    public function getBuySignals(?Carbon $simTime = null): array
    {
        if ($simTime === null) {
            $simTime = Carbon::now('America/New_York');
            // Always use current real time in EST - no simulation
        }

        $symbols = $this->getActiveSymbolsWithIds();
        $signals = [];

        foreach ($symbols as $symbolData) {
            $symbol = strtoupper(trim($symbolData['symbol']));
            $assetId = $symbolData['id'];

            if ($symbol === '') {
                continue;
            }

            $signal = $this->computeBuySignalForSymbol($symbol, $simTime, $assetId);
            if ($signal !== null) {
                $signals[] = $signal;
            }
        }

        // Sort best → worst by score, then by ema9-ema21 distance (stronger short-term trend)
        if (! empty($signals)) {
            usort($signals, function (array $a, array $b): int {
                $scoreCmp = $b['score'] <=> $a['score'];
                if ($scoreCmp !== 0) {
                    return $scoreCmp;
                }

                $trendA = (float) $a['ema9'] - (float) $a['ema21'];
                $trendB = (float) $b['ema9'] - (float) $b['ema21'];

                return $trendB <=> $trendA;
            });
        }

        return $signals;
    }

    /**
     * Get buy signals with metadata - optimized version with caching
     */
    public function getBuySignalsWithMeta(?Carbon $simTime = null): array
    {
        // Determine the actual simulation time that will be used
        $actualSimTime = $simTime;
        if ($actualSimTime === null) {
            $actualSimTime = Carbon::now('America/New_York');
        }

        // Create cache key based on simulation time (rounded to nearest 5 minutes for reasonable cache hits)
        $cacheTime = $actualSimTime->copy()->second(0)->minute(floor($actualSimTime->minute / 5) * 5);
        $cacheKey = 'buy_signals_'.$cacheTime->format('Y_m_d_H_i');

        // Cache results for 2 minutes to balance freshness with performance
        $signals = Cache::remember($cacheKey, 120, function () use ($actualSimTime) {
            // Use optimized batch processing to eliminate N+1 queries
            $optimizedService = new \App\Services\OptimizedBuySignalsService;

            return $optimizedService->getBuySignalsBatched($actualSimTime, 500);
        });

        if (empty($signals)) {
            return [
                'current_time' => $actualSimTime->toISOString(),
                'is_historical' => $simTime !== null,
                'columns' => ['asset_id', 'symbol', 'entry_time_est', 'entry_price', 'stop_loss', 'risk_per_share', 'ema9', 'ema21', 'ema50', 'vwap', 'score', 'reason'],
                'signals' => [],
                'count' => 0,
            ];
        }

        $columns = array_keys($signals[0]);

        return [
            'current_time' => $actualSimTime->toISOString(),
            'is_historical' => $simTime !== null,
            'columns' => $columns,
            'signals' => $signals,
            'count' => count($signals),
        ];
    }
}
