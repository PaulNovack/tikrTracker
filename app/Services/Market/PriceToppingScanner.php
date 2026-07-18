<?php

namespace App\Services\Market;

use Illuminate\Support\Facades\DB;

class PriceToppingScanner
{
    /**
     * Scan a symbol for extension & topping signals using 5-minute candles.
     *
     * @param  string  $assetType  'stock' or 'crypto'
     * @param  int  $lookbackMinutes  default = 60
     * @return array JSON-like array matching your PHP script structure
     */
    public function scan(string $symbol, string $assetType = 'stock', int $lookbackMinutes = 120): array
    {
        $symbol = strtoupper($symbol);
        $assetType = strtolower($assetType);

        $barsNeeded = (int) ceil($lookbackMinutes / 5);

        // Fetch the most recent bars
        $rows = DB::table('five_minute_prices')
            ->select('ts', 'price AS close_px', 'open', 'high', 'low', 'volume')
            ->where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->orderBy('ts', 'desc')
            ->limit($barsNeeded)
            ->get()
            ->toArray();

        if (count($rows) < 3) {
            return [
                'error' => 'Not enough data — need at least 3 bars, got '.count($rows),
            ];
        }

        // Chronological (oldest → newest)
        $bars = array_reverse(array_map(fn ($r) => (array) $r, $rows));
        $lastIdx = count($bars) - 1;
        $lastBar = $bars[$lastIdx];

        //
        // --------------- Helpers ---------------------
        //

        $percentChange = function (array $slice) {
            if (count($slice) < 2) {
                return null;
            }
            $start = (float) $slice[0]['close_px'];
            $end = (float) $slice[count($slice) - 1]['close_px'];
            if ($start <= 0) {
                return null;
            }

            return (($end - $start) / $start) * 100.0;
        };

        $avgVolume = function (array $slice) {
            $sum = 0;
            $cnt = 0;
            foreach ($slice as $b) {
                if ($b['volume'] !== null) {
                    $sum += (float) $b['volume'];
                    $cnt++;
                }
            }

            return $cnt > 0 ? $sum / $cnt : null;
        };

        $isShootingStar = function (array $b): bool {
            $open = (float) $b['open'];
            $close = (float) $b['close_px'];
            $high = (float) $b['high'];
            $low = (float) $b['low'];

            $body = abs($close - $open);
            $range = max(0.0000001, $high - $low);

            $upperWick = $high - max($open, $close);

            // Criteria
            if ($upperWick < 2 * $body) {
                return false;
            }
            if ($body / $range > 0.5) {
                return false;
            }
            if ($close > $low + 0.5 * $range) {
                return false;
            }

            return true;
        };

        //
        // ------------- Window slices --------------
        //

        $bars15 = array_slice($bars, max(0, $lastIdx - 2));  // ~15m
        $bars30 = array_slice($bars, max(0, $lastIdx - 5));  // ~30m
        $bars60 = $bars;                                     // full window

        //
        // -------- Calculations ----------
        //

        $change15 = $percentChange($bars15);
        $change30 = $percentChange($bars30);
        $change60 = $percentChange($bars60);

        $low60 = min(array_map(fn ($b) => (float) $b['low'], $bars60));
        $lastClose = (float) $lastBar['close_px'];

        $extensionPct = $low60 > 0
            ? (($lastClose - $low60) / $low60) * 100.0
            : null;

        $avgVol60 = $avgVolume($bars60);
        $lastVol = $lastBar['volume'] !== null ? (float) $lastBar['volume'] : null;

        //
        // ----------- Topping patterns --------------
        //

        // Lower high + lower close pattern
        $lowerHighLowerClose = false;
        if ($lastIdx >= 2) {
            $b1 = $bars[$lastIdx - 2];
            $b2 = $bars[$lastIdx - 1];
            $b3 = $bars[$lastIdx];

            if (
                (float) $b2['high'] > (float) $b1['high'] &&
                (float) $b3['high'] < (float) $b2['high'] &&
                (float) $b3['close_px'] < (float) $b2['close_px']
            ) {
                $lowerHighLowerClose = true;
            }
        }

        $shootingStar = $isShootingStar($lastBar);

        $volumeClimax = false;
        if ($avgVol60 && $lastVol !== null) {
            if ($lastVol >= 2.0 * $avgVol60) {
                $volumeClimax = true;
            }
        }

        //
        // ----------- Flags & reasons --------------
        //

        $flags = [
            'is_extended' => false,
            'possible_top' => false,
        ];

        $reasons = [];

        if ($extensionPct !== null && $extensionPct >= 5.0 && $change60 > 0) {
            $flags['is_extended'] = true;
            $reasons[] = sprintf(
                'Extended: last close is %.2f%% above %d-minute low.',
                $extensionPct,
                $lookbackMinutes
            );
        }

        if ($lowerHighLowerClose) {
            $flags['possible_top'] = true;
            $reasons[] = 'Lower high + lower close detected after recent upward push.';
        }

        if ($shootingStar) {
            $flags['possible_top'] = true;
            $reasons[] = 'Last candle resembles a shooting star (long upper wick, small body).';
        }

        if ($volumeClimax) {
            $flags['possible_top'] = true;
            $reasons[] = "Volume climax: last candle volume >= 2× avg of last {$lookbackMinutes} minutes.";
        }

        if ($flags['is_extended'] && $flags['possible_top']) {
            $reasons[] = 'Extended move + topping signals → high risk of reversal.';
        }

        //
        // -------- Return structured array ----------
        //

        return [
            'symbol' => $symbol,
            'asset_type' => $assetType,
            'lookback_minutes' => $lookbackMinutes,
            'last_ts' => $lastBar['ts'],
            'last_close' => $lastClose,
            'change_15m_pct' => $change15,
            'change_30m_pct' => $change30,
            'change_60m_pct' => $change60,
            'extension_from_low_pct' => $extensionPct,
            'avg_volume_lookback' => $avgVol60,
            'last_volume' => $lastVol,
            'flags' => $flags,
            'reasons' => $reasons,
        ];
    }

    /**
     * Scan for rising stocks that have NOT topped out
     *
     * @param  string  $assetTypeFilter  'stock', 'crypto', or 'all'
     * @param  int  $lookbackMinutes  Window size in minutes (default 60)
     * @param  float  $minRisePct  Minimum rise percentage (default 1.0)
     * @param  int  $topN  Maximum number of results (default 20)
     * @return array Array with filter info, count, and symbol analyses
     */
    public function scanRisersNotTopped(
        string $assetTypeFilter = 'stock',
        int $lookbackMinutes = 60,
        float $minRisePct = 1.0,
        int $topN = 20
    ): array {
        $lookbackMinutes = max(15, $lookbackMinutes);
        $topN = max(1, $topN);
        $barsNeeded = (int) ceil($lookbackMinutes / 5);

        // Build query for symbols (exclude soft deleted stocks)
        $symbolQuery = DB::table('five_minute_prices', 'fmp')
            ->join('asset_info', function ($join) {
                $join->on('fmp.symbol', '=', 'asset_info.symbol')
                    ->on('fmp.asset_type', '=', 'asset_info.asset_type');
            })
            ->selectRaw('DISTINCT fmp.symbol, fmp.asset_type, asset_info.id as asset_id')
            ->whereNull('asset_info.deleted_at');

        if ($assetTypeFilter !== 'all') {
            $symbolQuery->where('fmp.asset_type', $assetTypeFilter);
        }

        $symbols = $symbolQuery->get();

        if ($symbols->isEmpty()) {
            return [
                'filter' => [
                    'asset_type_filter' => $assetTypeFilter,
                    'lookback_minutes' => $lookbackMinutes,
                    'min_rise_pct' => $minRisePct,
                    'min_daily_volume' => config('market.min_daily_volume', 1000000),
                    'top_n' => $topN,
                ],
                'count' => 0,
                'symbols' => [],
            ];
        }

        $results = [];

        foreach ($symbols as $symbolRow) {
            $symbol = $symbolRow->symbol;
            $assetType = $symbolRow->asset_type;
            $assetId = $symbolRow->asset_id;

            // Fetch bars for this symbol
            $barsRaw = DB::table('five_minute_prices')
                ->select('ts', 'price AS close_px', 'open', 'high', 'low', 'volume')
                ->where('symbol', $symbol)
                ->where('asset_type', $assetType)
                ->orderBy('ts', 'desc')
                ->limit($barsNeeded)
                ->get()
                ->toArray();

            if (count($barsRaw) < 3) {
                continue;
            }

            // Convert to chronological order (oldest → newest)
            $bars = array_reverse(array_map(fn ($r) => (array) $r, $barsRaw));

            $analysis = $this->analyzeSymbolForRisers($bars, $symbol, $assetType, $lookbackMinutes, $assetId);
            if ($analysis === null) {
                continue;
            }

            // Filter by minimum daily volume
            $minVolume = config('market.min_daily_volume', 1000000);
            if (isset($analysis['avg_daily_volume']) && $analysis['avg_daily_volume'] < $minVolume) {
                continue;
            }

            $flags = $analysis['flags'];
            $change90 = $analysis['change_90m_pct'];

            // Filter: must be rising by at least minRisePct AND not topped
            if ($change90 === null || $change90 < $minRisePct) {
                continue;
            }

            if ($flags['possible_top'] === true) {
                continue;
            }

            $results[] = $analysis;
        }

        // Sort by 90-minute change (descending)
        usort($results, function (array $a, array $b) {
            $ca = $a['change_90m_pct'] ?? 0;
            $cb = $b['change_90m_pct'] ?? 0;

            return $cb <=> $ca; // Higher gains first
        });

        // Keep only top N
        if (count($results) > $topN) {
            $results = array_slice($results, 0, $topN);
        }

        return [
            'filter' => [
                'asset_type_filter' => $assetTypeFilter,
                'lookback_minutes' => $lookbackMinutes,
                'min_rise_pct' => $minRisePct,
                'min_daily_volume' => config('market.min_daily_volume', 1000000),
                'top_n' => $topN,
            ],
            'count' => count($results),
            'symbols' => $results,
        ];
    }

    /**
     * Analyze a symbol's bars specifically for the risers-not-topped scanner
     */
    private function analyzeSymbolForRisers(array $bars, string $symbol, string $assetType, int $lookbackMinutes, ?int $assetId = null): ?array
    {
        if (count($bars) < 3) {
            return null;
        }

        $lastIdx = count($bars) - 1;
        $lastBar = $bars[$lastIdx];

        $flags = [
            'is_extended' => false,
            'possible_top' => false,
        ];
        $reasons = [];

        // Window slices
        $bars15 = array_slice($bars, max(0, $lastIdx - 2));  // last 3 bars ~15m
        $bars30 = array_slice($bars, max(0, $lastIdx - 5));  // last 6 bars ~30m
        $bars60 = array_slice($bars, max(0, $lastIdx - 11)); // last 12 bars ~60m
        $bars90 = $bars;                                     // full window for 90m

        // Percent changes
        $change15 = $this->calculatePercentChange($bars15);
        $change30 = $this->calculatePercentChange($bars30);
        $change60 = $this->calculatePercentChange($bars60);
        $change90 = $this->calculatePercentChange($bars90);

        // Start and end prices for each interval
        $prices15 = $this->getStartEndPrices($bars15);
        $prices30 = $this->getStartEndPrices($bars30);
        $prices60 = $this->getStartEndPrices($bars60);
        $prices90 = $this->getStartEndPrices($bars90);

        // Extension vs 90-min low
        $low90 = min(array_map(fn ($b) => (float) $b['low'], $bars90));
        $lastClose = (float) $lastBar['close_px'];

        $extensionPct = $low90 > 0 ? (($lastClose - $low90) / $low90) * 100.0 : null;

        // Volume
        $avgVol90 = $this->calculateAverageVolume($bars90);
        $lastVol = $lastBar['volume'] !== null ? (float) $lastBar['volume'] : null;

        // Lower high / lower close pattern
        $lowerHighLowerClose = $this->detectLowerHighLowerClose($bars);

        // Shooting star pattern
        $shootingStar = $this->isShootingStar($lastBar);

        // Volume climax
        $volumeClimax = false;
        if ($avgVol90 !== null && $avgVol90 > 0 && $lastVol !== null) {
            if ($lastVol >= 2.0 * $avgVol90) {
                $volumeClimax = true;
            }
        }

        // Extended flag
        if ($extensionPct !== null && $extensionPct >= 5.0 && $change90 !== null && $change90 > 0) {
            $flags['is_extended'] = true;
            $reasons[] = sprintf(
                'Extended: last close is %.2f%% above last %d-minute low.',
                $extensionPct,
                $lookbackMinutes
            );
        }

        // Strong move notation
        if ($change90 !== null && $change90 >= 4.0) {
            $reasons[] = sprintf(
                'Strong move: last %d minutes up %.2f%%.',
                $lookbackMinutes,
                $change90
            );
        }

        // Topping conditions
        if ($lowerHighLowerClose) {
            $flags['possible_top'] = true;
            $reasons[] = 'Price action: lower high + lower close pattern after a push.';
        }

        if ($shootingStar) {
            $flags['possible_top'] = true;
            $reasons[] = 'Last candle resembles a shooting star (long upper wick, small body).';
        }

        if ($volumeClimax) {
            $flags['possible_top'] = true;
            $reasons[] = sprintf(
                'Volume climax: last candle volume is >= 2x avg of last %d minutes.',
                $lookbackMinutes
            );
        }

        if ($flags['is_extended'] && $flags['possible_top']) {
            $reasons[] = 'Caution: extended move *and* topping-like signals; chasing here is high risk.';
        }

        // Calculate average daily volume
        $avgDailyVolume = $this->calculateAverageDailyVolume($symbol, $assetType);

        return [
            'symbol' => $symbol,
            'asset_id' => $assetId,
            'asset_type' => $assetType,
            'lookback_minutes' => $lookbackMinutes,
            'last_ts' => $lastBar['ts'],
            'last_close' => $lastClose,
            'change_15m_pct' => $change15,
            'change_30m_pct' => $change30,
            'change_60m_pct' => $change60,
            'change_90m_pct' => $change90,
            'prices_15m' => $prices15,
            'prices_30m' => $prices30,
            'prices_60m' => $prices60,
            'prices_90m' => $prices90,
            'extension_from_low_pct' => $extensionPct,
            'avg_volume_lookback' => $avgVol90,
            'last_volume' => $lastVol,
            'avg_daily_volume' => $avgDailyVolume,
            'flags' => $flags,
            'reasons' => $reasons,
        ];
    }

    /**
     * Calculate percentage change from first to last price in slice
     */
    private function calculatePercentChange(array $barsSlice): ?float
    {
        if (count($barsSlice) < 2) {
            return null;
        }

        $start = (float) $barsSlice[0]['close_px'];
        $end = (float) $barsSlice[count($barsSlice) - 1]['close_px'];

        if ($start <= 0.0) {
            return null;
        }

        return (($end - $start) / $start) * 100.0;
    }

    /**
     * Calculate average volume over slice
     */
    private function calculateAverageVolume(array $barsSlice): ?float
    {
        if (empty($barsSlice)) {
            return null;
        }

        $sum = 0.0;
        $count = 0;

        foreach ($barsSlice as $bar) {
            if ($bar['volume'] === null) {
                continue;
            }
            $sum += (float) $bar['volume'];
            $count++;
        }

        return $count > 0 ? $sum / $count : null;
    }

    /**
     * Detect lower high + lower close pattern
     */
    private function detectLowerHighLowerClose(array $bars): bool
    {
        if (count($bars) < 3) {
            return false;
        }

        $lastIdx = count($bars) - 1;
        $b1 = $bars[$lastIdx - 2];
        $b2 = $bars[$lastIdx - 1];
        $b3 = $bars[$lastIdx];

        $b1High = (float) $b1['high'];
        $b2High = (float) $b2['high'];
        $b3High = (float) $b3['high'];
        $b2Close = (float) $b2['close_px'];
        $b3Close = (float) $b3['close_px'];

        return $b2High > $b1High && $b3High < $b2High && $b3Close < $b2Close;
    }

    /**
     * Check if bar resembles a shooting star pattern
     */
    private function isShootingStar(array $bar): bool
    {
        $open = (float) $bar['open'];
        $close = (float) $bar['close_px'];
        $high = (float) $bar['high'];
        $low = (float) $bar['low'];

        $body = abs($close - $open);
        $range = max(0.00000001, $high - $low); // avoid div-zero

        $upperWick = $high - max($open, $close);

        // Heuristics: big upper wick, small body
        if ($upperWick < (2.0 * $body)) {
            return false;
        }

        // Body less than ~50% of total range
        if ($body / $range > 0.5) {
            return false;
        }

        // Close near the low half of the range (rejection)
        if ($close > $low + 0.5 * $range) {
            return false;
        }

        return true;
    }

    /**
     * Get start and end prices for a price slice
     */
    private function getStartEndPrices(array $barsSlice): array
    {
        if (count($barsSlice) < 2) {
            return ['start' => null, 'end' => null];
        }

        $startPrice = (float) $barsSlice[0]['close_px'];
        $endPrice = (float) $barsSlice[count($barsSlice) - 1]['close_px'];

        return [
            'start' => $startPrice,
            'end' => $endPrice,
        ];
    }

    /**
     * Calculate average daily volume over the last 5 trading days
     */
    private function calculateAverageDailyVolume(string $symbol, string $assetType): ?float
    {
        // Get 5 trading days worth of data (approximately 7-10 calendar days to account for weekends)
        $startDate = now()->subDays(10);

        $volumeData = DB::table('five_minute_prices')
            ->where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->where('ts', '>=', $startDate)
            ->whereNotNull('volume')
            ->select(DB::raw('DATE(ts) as trade_date, SUM(volume) as daily_volume'))
            ->groupBy('trade_date')
            ->orderBy('trade_date', 'desc')
            ->limit(5)
            ->get();

        if ($volumeData->isEmpty() || $volumeData->count() < 3) {
            return null;
        }

        // Calculate average of the daily totals
        $totalVolume = $volumeData->sum('daily_volume');
        $avgDailyVolume = $totalVolume / $volumeData->count();

        return $avgDailyVolume > 0 ? round($avgDailyVolume) : null;
    }
}
