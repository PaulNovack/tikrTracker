<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * FiveMinuteSignalScannerV200_0 - "TPB" (Trend + Pullback + Breakout) Scanner
 *
 * Output signals are compatible with TradeAlertWriterV1:
 *  - symbol, asset_type, signal_type, signal_ts_est, price, score, vol_ratio, meta[]
 *
 * Uses ONLY DB tables:
 *  - five_minute_prices (ts_est, open, high, low, price, volume, vwap, ema9, ema21, atr, atr_pct, ema9_above_ema21)
 *
 * Config (config/trading.php or env -> config):
 *  trading.v200.*
 *   - universe_limit (default 350)
 *   - limit (default 80)
 *   - lookback_minutes (default 60)
 *   - min_price (default 2.50)
 *   - max_price (default 80.00)
 *   - min_sum_vol_lookback (default 200000)          // total 5m vol across lookback window
 *   - min_last_bar_notional (default 250000)         // last 5m bar: price*volume
 *   - min_atr_pct (default 0.15)
 *   - max_atr_pct (default 2.50)
 *   - max_vwap_extension_pct (default 1.20)          // avoid too extended above VWAP
 *   - require_pullback_to_vwap_or_ema (default true)
 *   - max_consolidation_range_pct (default 0.55)     // last few bars tightness (percent)
 *   - min_vol_ratio_last_bar (default 1.20)          // last 5m bar vs avg prior bars
 */
class FiveMinuteSignalScannerV200_0
{
    use HasPriceTables;

    private string $version = 'v200.0';

    private string $name = 'TPB Trend-Pullback-Breakout';

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        int $limit = 80
    ): array {
        $cfg = fn (string $k, $d) => config("trading.v200.$k", $d);

        $lookbackMinutes = (int) $cfg('lookback_minutes', $lookbackMinutes);
        $limit = (int) $cfg('limit', $limit);

        $universeLimit = (int) $cfg('universe_limit', 350);
        $minPrice = (float) $cfg('min_price', 2.50);
        $maxPrice = (float) $cfg('max_price', 80.00);
        $minSumVol = (float) $cfg('min_sum_vol_lookback', 200000);
        $minLastBarNotional = (float) $cfg('min_last_bar_notional', 250000);
        $minAtrPct = (float) $cfg('min_atr_pct', 0.15);
        $maxAtrPct = (float) $cfg('max_atr_pct', 2.50);

        $start = date('Y-m-d H:i:s', strtotime($asOfTsEst." -{$lookbackMinutes} minutes"));

        // 1) Build a universe of liquid symbols in the lookback window.
        //    (Fast filter before doing per-symbol bar fetch.)
        $symbols = $this->dbSelect('
            SELECT
                symbol,
                SUM(volume) AS vol_sum,
                MAX(price) AS max_price_in_window
            FROM five_minute_prices
            WHERE asset_type = ?
              AND ts_est >= ?
              AND ts_est <= ?
            GROUP BY symbol
            HAVING vol_sum >= ?
               AND max_price_in_window BETWEEN ? AND ?
            ORDER BY vol_sum DESC
            LIMIT ?
        ', [$assetType, $start, $asOfTsEst, $minSumVol, $minPrice, $maxPrice, $universeLimit]);

        if (empty($symbols)) {
            return [];
        }

        // SECTOR BLACKLIST: TPB pattern fails on mining/metals (42.6% WR)
        // Data shows these consistently lose: AG (22% WR), CDE (30%), HL (53%), TQQQ (40%)
        // Round 2 losers: ONDS (0% WR), NOK (0%), UBER (0%), PONY (0%), RKLB (0%), IONQ (25%), NIO (18%), RIVN (25%), TSLL (22%)
        // Round 3 losers: IBIT (0%), RGTI (0%), FRMI (0%), BULL (0%), SMCI (0%), ITUB (30%), BBAI (25%), LI (33%), CLSK (33%), RIOT (40%), ETHA (46%)
        $blacklist = [
            'AG', 'CDE', 'HL', 'TQQQ', 'SQQQ', 'SPXU', 'GLD', 'SLV', 'GOLD', 'AUY', 'NEM',
            'ONDS', 'NOK', 'UBER', 'PONY', 'RKLB', 'IONQ', 'NIO', 'RIVN', 'TSLL', 'PTEN', 'QS', 'RKT', 'SOLT', 'PATH',
            'IBIT', 'RGTI', 'FRMI', 'BULL', 'SMCI', 'ITUB', 'BBAI', 'LI', 'CLSK', 'RIOT', 'ETHA', 'GRAB',
        ];

        $candidates = [];

        // We fetch up to last 12 bars (~60m) for scoring and structure.
        $barsFrom = date('Y-m-d H:i:s', strtotime($asOfTsEst.' -60 minutes'));

        foreach ($symbols as $row) {
            $symbol = (string) $row->symbol;

            // Skip blacklisted symbols
            if (in_array($symbol, $blacklist)) {
                continue;
            }
            $bars = $this->get5MinBars($symbol, $assetType, $barsFrom, $asOfTsEst);
            if (count($bars) < 10) {
                continue;
            }

            // Resistance = max high of last 60 minutes (or last N bars)
            $resistance = 0.0;
            foreach ($bars as $b) {
                $resistance = max($resistance, (float) ($b->high ?? 0));
            }

            $last = $bars[count($bars) - 1];
            $lastPrice = (float) ($last->price ?? 0);
            $lastVol = (float) ($last->volume ?? 0);
            $vwap = (float) ($last->vwap ?? 0);
            $ema9 = (float) ($last->ema9 ?? 0);
            $ema21 = (float) ($last->ema21 ?? 0);
            $atr = (float) ($last->atr ?? 0);
            $atrPct = (float) ($last->atr_pct ?? 0);

            if ($lastPrice <= 0 || $lastVol <= 0 || $vwap <= 0 || $ema9 <= 0 || $ema21 <= 0) {
                continue;
            }

            // TIME-OF-DAY FILTER: Only scan during profitable hours (10am, 1-2pm)
            // Avoid 11am-12pm (worst performers: 36-37% WR) and 3pm (44.2% WR, -0.13% avg)
            $signalHour = (int) date('G', strtotime($asOfTsEst));
            if ($signalHour == 11 || $signalHour == 12 || $signalHour >= 15) {
                continue;  // Skip lunch and late afternoon
            }

            // ATR% filter (avoid dead + avoid insane).
            // DATA-DRIVEN: <0.3% ATR = 52.7% WR, >0.8% ATR = 32.7% WR
            // Focus on low volatility stocks with tight consolidation
            if ($atrPct > 0 && ($atrPct < $minAtrPct || $atrPct > $maxAtrPct)) {
                continue;
            }

            // Require last 3 bars EMA9 > EMA21 (strong 5m trend).
            $trendBars = 0;
            for ($i = count($bars) - 3; $i < count($bars); $i++) {
                if ($i >= 0) {
                    $b = $bars[$i];
                    if (isset($b->ema9, $b->ema21) && (float) $b->ema9 > (float) $b->ema21) {
                        $trendBars++;
                    }
                }
            }
            if ($trendBars < 3) {
                continue;
            }

            // EMA spread strength filter: (ema9 - ema21) / ema21
            $minEmaSpreadPct = (float) $cfg('min_ema_spread_pct', 0.12);
            $emaSpreadPct = (($ema9 - $ema21) / $ema21) * 100.0;
            if ($emaSpreadPct < $minEmaSpreadPct) {
                continue;
            }

            // Last-bar liquidity via notional
            $lastNotional = $lastPrice * $lastVol;
            if ($lastNotional < $minLastBarNotional) {
                continue;
            }

            // Avoid very extended above VWAP
            $maxVwapExt = (float) $cfg('max_vwap_extension_pct', 1.20);
            $vwapExtPct = (($lastPrice - $vwap) / $vwap) * 100.0;
            if ($vwapExtPct > $maxVwapExt) {
                continue;
            }

            // Compute last 6 bars stats
            $sliceN = 6;
            $slice = array_slice($bars, max(0, count($bars) - $sliceN), $sliceN);
            $avgVol = $this->avg(array_map(fn ($b) => (float) ($b->volume ?? 0), array_slice($slice, 0, max(0, count($slice) - 1))));
            $volRatio = $avgVol > 0 ? ($lastVol / $avgVol) : 0.0;

            $minVolRatio = (float) $cfg('min_vol_ratio_last_bar', 1.20);
            if ($volRatio < $minVolRatio) {
                continue;
            }

            // VOLUME RATIO FILTER: Avoid extreme spikes (10x+) which have 35-41% WR
            // DATA-DRIVEN: <1.5x vol = 73.2% WR!, 2-3x = 58.5%, 3-5x = 50.7% (worst)
            // Sweet spot is LOWER volume (consolidation not explosion)
            $maxVolRatio = (float) $cfg('max_vol_ratio_last_bar', 3.0);
            if ($volRatio > $maxVolRatio) {
                continue;  // Skip high volume - prefer quiet consolidation
            }

            // Green bar pct, directional changes, net progress for diagnostics (writer can store these)
            $greens = 0;
            $dirChanges = 0;
            $prevDir = null;
            $firstPrice = (float) ($slice[0]->price ?? $lastPrice);

            for ($i = 0; $i < count($slice); $i++) {
                $b = $slice[$i];
                $o = (float) ($b->open ?? 0);
                $c = (float) ($b->price ?? 0);
                if ($c > $o) {
                    $greens++;
                }

                if ($i > 0) {
                    $prevC = (float) ($slice[$i - 1]->price ?? 0);
                    $dir = ($c > $prevC) ? 1 : (($c < $prevC) ? -1 : 0);
                    if ($prevDir !== null && $dir !== 0 && $prevDir !== 0 && $dir !== $prevDir) {
                        $dirChanges++;
                    }
                    if ($dir !== 0) {
                        $prevDir = $dir;
                    }
                }
            }

            $greenPct = count($slice) > 0 ? ($greens / count($slice)) * 100.0 : 0.0;
            $netProgressPct = $firstPrice > 0 ? (($lastPrice - $firstPrice) / $firstPrice) * 100.0 : 0.0;

            // Pullback / consolidation near VWAP or EMA9
            $requirePullback = (bool) $cfg('require_pullback_to_vwap_or_ema', true);
            $maxConsRangePct = (float) $cfg('max_consolidation_range_pct', 0.55);

            [$pullbackOk, $pullbackLow, $consolidationBars, $breakoutLevel] =
                $this->analyzeStructure($bars, $vwap, $ema9, $maxConsRangePct, $requirePullback);

            if (! $pullbackOk || $breakoutLevel <= 0) {
                continue;
            }

            // Score (0-100): trend + tightness + volume + "not too extended" + low chop
            $score = 50.0;

            // Trend strength
            $emaSpreadPct = (($ema9 - $ema21) / $ema21) * 100.0;
            $score += min(18, max(0, $emaSpreadPct * 20)); // e.g. 0.4% spread => +8

            // Tight structure
            $score += min(12, $consolidationBars * 3);

            // Volume
            $score += min(15, max(0, ($volRatio - 1.0) * 7));

            // Not extended above VWAP is good
            $score += max(0, 10 - max(0, $vwapExtPct) * 6);

            // Penalize chop
            $score -= min(10, $dirChanges * 2);

            // Reward net progress
            $score += min(10, max(0, $netProgressPct * 2));

            $score = max(0, min(100, $score));

            $candidates[] = [
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_type' => 'TPB_5M',
                'signal_ts_est' => (string) ($last->ts_est ?? $asOfTsEst),
                'price' => round($lastPrice, 4),
                'score' => round($score, 2),
                'vol_ratio' => round($volRatio, 2),
                'meta' => [
                    // EntryFinder routing
                    'pattern' => 'TPB',

                    // Levels + structure for the 1m entry finder
                    'vwap' => $vwap,
                    'ema9' => $ema9,
                    'ema21' => $ema21,
                    'pullback_low' => $pullbackLow,
                    'breakout_level' => $breakoutLevel,
                    'consolidation_bars_5m' => $consolidationBars,
                    'resistance_5m' => $resistance,

                    // Diagnostics (can be copied into entry fields)
                    'five_min_green_bar_pct' => round($greenPct, 2),
                    'five_min_directional_changes' => $dirChanges,
                    'five_min_net_progress' => round($netProgressPct, 2),

                    'atr' => $atr,
                    'atr_pct' => $atrPct,
                    'vwap_ext_pct' => round($vwapExtPct, 3),
                ],
            ];
        }

        usort($candidates, fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        return array_slice($candidates, 0, $limit);
    }

    private function get5MinBars(string $symbol, string $assetType, string $from, string $to): array
    {
        return $this->dbSelect('
            SELECT
                ts_est,
                `open`,
                high,
                low,
                price,
                volume,
                vwap,
                ema9,
                ema21,
                ema9_above_ema21,
                atr,
                atr_pct
            FROM five_minute_prices
            WHERE symbol = ?
              AND asset_type = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ', [$symbol, $assetType, $from, $to]);
    }

    private function analyzeStructure(
        array $bars,
        float $vwap,
        float $ema9,
        float $maxConsRangePct,
        bool $requirePullback
    ): array {
        $n = count($bars);

        // Consider last 4 bars as "setup window"
        $window = array_slice($bars, max(0, $n - 6), 6);
        if (count($window) < 5) {
            return [false, 0.0, 0, 0.0];
        }

        // Pullback definition: in last 3 bars, low touches VWAP or EMA9 (within ~0.25%)
        $touchTol = 0.0025;
        $pullbackLow = 999999.0;
        $touched = false;

        for ($i = max(0, count($window) - 3); $i < count($window); $i++) {
            $b = $window[$i];
            $low = (float) ($b->low ?? 0);
            if ($low > 0) {
                $pullbackLow = min($pullbackLow, $low);
            }

            $touchVwap = ($vwap > 0) ? ($low <= $vwap * (1 + $touchTol)) : false;
            $touchEma9 = ($ema9 > 0) ? ($low <= $ema9 * (1 + $touchTol)) : false;

            if ($touchVwap || $touchEma9) {
                $touched = true;
            }
        }

        if ($requirePullback && ! $touched) {
            return [false, 0.0, 0, 0.0];
        }

        // Consolidation: count last consecutive bars with small range %
        $consBars = 0;
        for ($i = count($window) - 1; $i >= 0; $i--) {
            $b = $window[$i];
            $hi = (float) ($b->high ?? 0);
            $lo = (float) ($b->low ?? 0);
            if ($lo <= 0) {
                break;
            }

            $rangePct = (($hi - $lo) / $lo) * 100.0;
            if ($rangePct <= $maxConsRangePct) {
                $consBars++;
            } else {
                break;
            }
        }

        // Breakout level = max high of the consolidation segment (excluding last bar if you want)
        $seg = array_slice($window, max(0, count($window) - max(2, $consBars)), max(2, $consBars));
        $breakoutLevel = 0.0;
        foreach ($seg as $b) {
            $breakoutLevel = max($breakoutLevel, (float) ($b->high ?? 0));
        }

        if ($pullbackLow >= 999999.0) {
            $pullbackLow = (float) ($window[count($window) - 1]->low ?? 0);
        }

        return [true, $pullbackLow, $consBars, $breakoutLevel];
    }

    private function avg(array $nums): float
    {
        $nums = array_values(array_filter($nums, fn ($v) => is_numeric($v) && $v > 0));
        if (empty($nums)) {
            return 0.0;
        }

        return array_sum($nums) / count($nums);
    }
}
