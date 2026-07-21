<?php

namespace App\Services\Market;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class BuyZoneFromTopPerformersService
{
    /**
     * Input: symbols (top performers)
     * Output: only symbols currently in a "buy zone" + risk/stop/position sizing.
     *
     * @param  array<int,string>  $symbols
     * @return array<int,array<string,mixed>>
     */
    public function filterBuyZone(array $symbols, array $opts = []): array
    {
        $assetType = (string) ($opts['assetType'] ?? 'stock');
        $tz = (string) ($opts['tz'] ?? 'America/New_York');

        // Allow override of "now" for testing with historical data (e.g., "2025-12-15 10:15:00")
        $testDateTime = $opts['testDateTime'] ?? null;

        // 7d window for "extended/pullback" metrics
        $days = (int) ($opts['days'] ?? 7);

        // Not-extended gate (distance below 7d high)
        $minDistFromHigh = (float) ($opts['minDistFromHigh'] ?? 0.03); // 3%
        $maxDistFromHigh = (float) ($opts['maxDistFromHigh'] ?? 0.08); // 8%

        // Pullback gate (retracement of the 7d move)
        $minRetrace = (float) ($opts['minRetrace'] ?? 0.20); // 20%
        $maxRetrace = (float) ($opts['maxRetrace'] ?? 0.50); // 50%

        // VWAP reclaim lookback window (minutes) to detect "was below, now above"
        $vwapLookbackMin = (int) ($opts['vwapLookbackMin'] ?? 60);

        // EMA config (5m)
        $emaFast = (int) ($opts['emaFast'] ?? 9);
        $emaSlow = (int) ($opts['emaSlow'] ?? 21);
        $emaBars = (int) ($opts['emaBars'] ?? 250);

        // RVOL proxy (today volume so far / avg daily volume)
        $rvolDays = (int) ($opts['rvolDays'] ?? 20);
        $minRvol = (float) ($opts['minRvol'] ?? 1.50);

        // Data quality
        $min5mBarsIn7d = (int) ($opts['min5mBarsIn7d'] ?? 200);
        $min1mBarsToday = (int) ($opts['min1mBarsToday'] ?? 120);

        // ---- NEW: Risk sizing options ----
        $accountSize = (float) ($opts['accountSize'] ?? 18000); // default based on your prior runs
        $riskPerTradePct = (float) ($opts['riskPerTradePct'] ?? 0.005); // 0.5% default
        $maxStopPct = (float) ($opts['maxStopPct'] ?? 0.01); // "1% stop viable" threshold

        // Buffers for stop placement
        $stopBelowLowPct = (float) ($opts['stopBelowLowPct'] ?? 0.001); // 0.10% below pullback low
        $stopBelowEmaPct = (float) ($opts['stopBelowEmaPct'] ?? 0.001); // 0.10% below EMA slow
        $stopBelowVwapPct = (float) ($opts['stopBelowVwapPct'] ?? 0.002); // 0.20% below VWAP

        $symbols = array_values(array_unique(array_filter($symbols)));
        if (count($symbols) === 0) {
            return [];
        }

        $now = $testDateTime
            ? CarbonImmutable::parse($testDateTime, $tz)
            : CarbonImmutable::now($tz);
        $start = $now->subDays($days);

        $startTs = $start->format('Y-m-d H:i:s');
        $endTs = $now->format('Y-m-d H:i:s');

        $todayStart = $now->startOfDay()->format('Y-m-d H:i:s');

        // VWAP lookback - ensure it doesn't go before market open (9:30 AM EST)
        $marketOpen = CarbonImmutable::parse($now->format('Y-m-d').' 09:30:00', $tz);
        $rawLookbackStart = $now->subMinutes($vwapLookbackMin);
        $lookbackStart = $rawLookbackStart->lt($marketOpen)
            ? $marketOpen->format('Y-m-d H:i:s')
            : $rawLookbackStart->format('Y-m-d H:i:s');

        // 1) 7d stats
        $sevenDay = $this->fetchSevenDayStats($symbols, $assetType, $startTs, $endTs, $min5mBarsIn7d);

        // 2) 1m today stats (VWAP, reclaim, plus pullback_low)
        $oneMinToday = $this->fetchOneMinuteTodayStats($symbols, $assetType, $todayStart, $lookbackStart, $endTs, $min1mBarsToday);

        // 3) Avg daily vol (RVOL)
        $avgDailyVol = $this->fetchAvgDailyVolume(
            $symbols,
            $assetType,
            $now->subDays($rvolDays)->format('Y-m-d'),
            $now->subDay()->format('Y-m-d')
        );

        // 4) EMA state
        $emaState = $this->computeEmaStateForSymbols($symbols, $assetType, $endTs, $emaBars, $emaFast, $emaSlow);

        // 5) Get asset_ids for symbols
        $assetIds = DB::table('asset_info')
            ->whereIn('symbol', $symbols)
            ->where('asset_type', $assetType)
            ->pluck('id', 'symbol')
            ->toArray();

        // Risk dollars per trade
        $riskDollars = max(0.0, $accountSize * $riskPerTradePct);

        $out = [];

        foreach ($symbols as $sym) {
            if (! isset($sevenDay[$sym], $oneMinToday[$sym])) {
                continue;
            }

            $sd = $sevenDay[$sym];
            $om = $oneMinToday[$sym];

            $high7 = (float) $sd['high7'];
            $low7 = (float) $sd['low7'];

            // Entry price: latest 1m (preferred) else 5m last
            $entry = $om['last_price'] !== null ? (float) $om['last_price'] : (float) $sd['last_price'];

            if ($high7 <= 0 || $low7 <= 0 || $entry <= 0) {
                continue;
            }

            // Buy-zone metrics
            $move = max($high7 - $low7, 1e-9);
            $distFromHigh = ($high7 - $entry) / $high7;  // fraction below high
            $retracement = ($high7 - $entry) / $move;   // retracement fraction

            $vwapNow = $om['vwap_now'] !== null ? (float) $om['vwap_now'] : null;
            $vwapReclaimed = (bool) $om['vwap_reclaimed'];

            $todayVol = (float) $om['today_vol_sum'];
            $avgVol = isset($avgDailyVol[$sym]) ? (float) $avgDailyVol[$sym] : null;
            $rvol = ($avgVol && $avgVol > 0) ? ($todayVol / $avgVol) : null;

            $ema = $emaState[$sym] ?? null;
            $emaFastVal = $ema['ema_fast'] ?? null;
            $emaSlowVal = $ema['ema_slow'] ?? null;
            $emaStateStr = $ema['state'] ?? 'UNKNOWN';

            // Gates
            $notExtended = ($distFromHigh >= $minDistFromHigh && $distFromHigh <= $maxDistFromHigh);
            $isPullback = ($retracement >= $minRetrace && $retracement <= $maxRetrace);
            $emaOk = ($emaStateStr === 'EMA_FAST_ABOVE_SLOW');
            $rvolOk = ($rvol !== null && $rvol >= $minRvol);

            if (! ($notExtended && $isPullback && $vwapReclaimed && $emaOk && $rvolOk)) {
                continue;
            }

            // ---- NEW: Stop computation (structure-based) ----
            $pullbackLow = $om['pullback_low'] !== null ? (float) $om['pullback_low'] : null;

            $stopCandidates = [];

            if ($pullbackLow !== null && $pullbackLow > 0) {
                $stopCandidates[] = $pullbackLow * (1.0 - $stopBelowLowPct);
            }

            if ($emaSlowVal !== null && $emaSlowVal > 0) {
                $stopCandidates[] = ((float) $emaSlowVal) * (1.0 - $stopBelowEmaPct);
            }

            if ($vwapNow !== null && $vwapNow > 0) {
                $stopCandidates[] = $vwapNow * (1.0 - $stopBelowVwapPct);
            }

            if (count($stopCandidates) === 0) {
                // Can't compute a stop -> skip
                continue;
            }

            $stopPrice = min(...$stopCandidates);

            // Risk calc
            $riskPerShare = $entry - $stopPrice;
            if ($riskPerShare <= 0) {
                continue;
            }

            $riskPct = $riskPerShare / $entry;
            $stopViable1pct = ($riskPct <= $maxStopPct);

            // Position sizing
            $shares = (int) floor($riskDollars / $riskPerShare);
            if ($shares < 1) {
                // Can't size at least 1 share within risk budget
                // Still return it? We'll keep it (some may be expensive stocks)
                $shares = 0;
            }

            $positionNotional = $shares > 0 ? $shares * $entry : 0.0;

            $out[] = [
                'symbol' => $sym,
                'asset_id' => $assetIds[$sym] ?? null,

                // 7d context
                'high_7d' => $high7,
                'low_7d' => $low7,

                // Entry/Now
                'entry_price' => $entry,

                // Requested buy-zone fields
                'dist_from_7d_high' => $distFromHigh,
                'dist_from_7d_high_pct' => $distFromHigh * 100.0,
                'retracement_pct' => $retracement * 100.0,
                'vwap_now' => $vwapNow,
                'vwap_reclaimed' => $vwapReclaimed,
                'ema_state' => $emaStateStr,
                'ema_fast' => $emaFastVal,
                'ema_slow' => $emaSlowVal,
                'rvol' => $rvol,

                // NEW: Stop + risk fields
                'pullback_low' => $pullbackLow,
                'stop_price' => $stopPrice,
                'risk_per_share' => $riskPerShare,
                'risk_pct' => $riskPct,
                'risk_pct_pct' => $riskPct * 100.0,
                'stop_viable_1pct' => $stopViable1pct,

                // NEW: Sizing fields
                'account_size' => $accountSize,
                'risk_per_trade_pct' => $riskPerTradePct,
                'risk_dollars' => $riskDollars,
                'recommended_shares' => $shares,
                'position_notional' => $positionNotional,

                // extra useful debugging fields
                'today_1m_bars' => (int) $om['bars_today'],
                'today_vol_sum' => (int) $todayVol,
                'avg_daily_vol' => $avgVol ? (int) $avgVol : null,
                'was_below_vwap_in_lookback' => (bool) $om['was_below_vwap'],
                'is_above_vwap_now' => (bool) $om['is_above_vwap_now'],
            ];
        }

        // Sort: prefer stop-viable, higher rvol, lower risk_pct
        usort($out, function ($a, $b) {
            if ($a['stop_viable_1pct'] !== $b['stop_viable_1pct']) {
                return $a['stop_viable_1pct'] ? -1 : 1;
            }
            $ar = $a['rvol'] ?? 0;
            $br = $b['rvol'] ?? 0;
            if ($ar !== $br) {
                return $br <=> $ar;
            }

            return $a['risk_pct'] <=> $b['risk_pct'];
        });

        return $out;
    }

    private function fetchSevenDayStats(array $symbols, string $assetType, string $startTs, string $endTs, int $minBars): array
    {
        $out = [];

        foreach (array_chunk($symbols, 800) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));

            $sql = "
                SELECT
                    symbol,
                    COUNT(*) AS bars7,
                    MIN(low) AS low7,
                    MAX(high) AS high7,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(price ORDER BY ts_est DESC SEPARATOR ','),
                        ',', 1
                    ) AS last_price
                FROM five_minute_prices
                WHERE asset_type = ?
                  AND ts_est >= ?
                  AND ts_est <= ?
                  AND symbol IN ($placeholders)
                GROUP BY symbol
                HAVING bars7 >= ?
            ";

            $rows = DB::select($sql, array_merge([$assetType, $startTs, $endTs], $chunk, [$minBars]));

            foreach ($rows as $r) {
                $out[(string) $r->symbol] = [
                    'bars7' => (int) $r->bars7,
                    'low7' => (float) $r->low7,
                    'high7' => (float) $r->high7,
                    'last_price' => $r->last_price !== null ? (float) $r->last_price : null,
                ];
            }
        }

        return $out;
    }

    private function fetchOneMinuteTodayStats(array $symbols, string $assetType, string $todayStart, string $lookbackStart, string $endTs, int $minBarsToday): array
    {
        $out = [];

        foreach ($symbols as $sym) {
            $lastRow = DB::selectOne('
                SELECT ts_est, price, volume
                FROM one_minute_prices
                WHERE asset_type = ?
                  AND symbol = ?
                  AND ts_est >= ?
                  AND ts_est <= ?
                ORDER BY ts_est DESC
                LIMIT 1
            ', [$assetType, $sym, $todayStart, $endTs]);

            if (! $lastRow) {
                continue;
            }

            $aggToday = DB::selectOne('
                SELECT
                    COUNT(*) AS bars_today,
                    COALESCE(SUM(volume),0) AS today_vol_sum,
                    COALESCE(SUM(price * volume),0) AS pv_sum
                FROM one_minute_prices
                WHERE asset_type = ?
                  AND symbol = ?
                  AND ts_est >= ?
                  AND ts_est <= ?
            ', [$assetType, $sym, $todayStart, $endTs]);

            if (! $aggToday || (int) $aggToday->bars_today < $minBarsToday) {
                continue;
            }

            $volSum = (float) $aggToday->today_vol_sum;
            $pvSum = (float) $aggToday->pv_sum;
            $vwapNow = ($volSum > 0) ? ($pvSum / $volSum) : null;

            // Lookback min price = pullback low proxy (for stop placement)
            $minPriceLookback = DB::selectOne('
                SELECT MIN(price) AS min_price
                FROM one_minute_prices
                WHERE asset_type = ?
                  AND symbol = ?
                  AND ts_est >= ?
                  AND ts_est <= ?
            ', [$assetType, $sym, $lookbackStart, $endTs]);

            $pullbackLow = $minPriceLookback && $minPriceLookback->min_price !== null
                ? (float) $minPriceLookback->min_price
                : null;

            $wasBelow = false;
            $isAboveNow = false;

            if ($vwapNow !== null) {
                $wasBelow = ($pullbackLow !== null && $pullbackLow < $vwapNow);
                $isAboveNow = ((float) $lastRow->price > $vwapNow);
            }

            $out[$sym] = [
                'bars_today' => (int) $aggToday->bars_today,
                'today_vol_sum' => (int) $volSum,
                'vwap_now' => $vwapNow,
                'last_price' => (float) $lastRow->price,

                'pullback_low' => $pullbackLow,

                'was_below_vwap' => $wasBelow,
                'is_above_vwap_now' => $isAboveNow,
                'vwap_reclaimed' => ($wasBelow && $isAboveNow),
            ];
        }

        return $out;
    }

    private function fetchAvgDailyVolume(array $symbols, string $assetType, string $startDate, string $endDate): array
    {
        $out = [];

        foreach (array_chunk($symbols, 800) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));

            $sql = "
                SELECT symbol, AVG(volume) AS avg_vol
                FROM daily_prices
                WHERE asset_type = ?
                  AND date >= ?
                  AND date <= ?
                  AND symbol IN ($placeholders)
                GROUP BY symbol
            ";

            $rows = DB::select($sql, array_merge([$assetType, $startDate, $endDate], $chunk));

            foreach ($rows as $r) {
                $out[(string) $r->symbol] = (float) $r->avg_vol;
            }
        }

        return $out;
    }

    private function computeEmaStateForSymbols(array $symbols, string $assetType, string $endTs, int $bars, int $fast, int $slow): array
    {
        $out = [];

        foreach ($symbols as $sym) {
            $rows = DB::select("
                SELECT ts_est, price
                FROM five_minute_prices
                WHERE asset_type = ?
                  AND symbol = ?
                  AND ts_est <= ?
                ORDER BY ts_est DESC
                LIMIT {$bars}
            ", [$assetType, $sym, $endTs]);

            if (count($rows) < max($slow * 3, 60)) {
                $out[$sym] = ['state' => 'NOT_ENOUGH_BARS'];

                continue;
            }

            $prices = array_reverse(array_map(fn ($r) => (float) $r->price, $rows));

            $emaFast = $this->ema($prices, $fast);
            $emaSlow = $this->ema($prices, $slow);

            if ($emaFast === null || $emaSlow === null) {
                $out[$sym] = ['state' => 'EMA_FAILED'];

                continue;
            }

            $state = ($emaFast > $emaSlow) ? 'EMA_FAST_ABOVE_SLOW' : 'EMA_FAST_BELOW_SLOW';

            $out[$sym] = [
                'state' => $state,
                'ema_fast' => $emaFast,
                'ema_slow' => $emaSlow,
            ];
        }

        return $out;
    }

    private function ema(array $values, int $period): ?float
    {
        $n = count($values);
        if ($n < $period) {
            return null;
        }

        $alpha = 2.0 / ($period + 1.0);

        $sma = array_sum(array_slice($values, 0, $period)) / $period;
        $ema = $sma;

        for ($i = $period; $i < $n; $i++) {
            $ema = ($values[$i] - $ema) * $alpha + $ema;
        }

        return $ema;
    }
}
