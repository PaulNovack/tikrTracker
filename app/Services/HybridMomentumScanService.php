<?php

namespace App\Services;

use App\Support\EstTimezoneHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class HybridMomentumScanService
{
    // Configuration constants
    private const MIN_AVG_DOLLAR_VOLUME = 2_000_000; // 2M USD

    private const MAX_SYMBOLS = 500;

    private const LOOKBACK_60M = 60;

    private const LOOKBACK_30M = 30;

    private const LOOKBACK_15M = 15;

    private const LOOKBACK_3H = 180;

    /**
     * Run hybrid momentum scan
     */
    public function scan(
        ?string $asOfEst = null,
        string $assetType = 'stock',
        int $minScore = 5
    ): array {
        $endEst = $this->determineAsOfTime($asOfEst);
        $startEst60 = $endEst->copy()->subMinutes(self::LOOKBACK_60M);
        $startEst3h = $endEst->copy()->subMinutes(self::LOOKBACK_3H);

        // Get candidate symbols
        $candidates = $this->fetchCandidateSymbols($assetType, $startEst60, $endEst);

        if (empty($candidates)) {
            return [
                'results' => [],
                'meta' => [
                    'as_of' => $endEst->format('Y-m-d H:i:s'),
                    'asset_type' => $assetType,
                    'min_score' => $minScore,
                    'message' => 'No candidate symbols found in the last 60 minutes',
                    'candidates_found' => 0,
                ],
            ];
        }

        $results = [];

        foreach ($candidates as $candidate) {
            $symbol = $candidate->symbol;
            $assetId = $candidate->asset_id;

            try {
                $oneMinBars = $this->fetchOneMinuteBars($symbol, $assetType, $startEst60, $endEst);
                $fiveMinBars = $this->fetchFiveMinuteBars($symbol, $assetType, $startEst3h, $endEst);
                $avgDollarVol = $this->fetchAvgDailyDollarVolume($symbol, $assetType);

                $scoreData = $this->buildScoreForSymbol(
                    $symbol,
                    $oneMinBars,
                    $fiveMinBars,
                    $endEst,
                    $avgDollarVol
                );

                if (! $scoreData['valid'] || $scoreData['score'] < $minScore) {
                    continue;
                }

                $results[] = [
                    'id' => $assetId,
                    'symbol' => $symbol,
                    'score' => $scoreData['score'],
                    'last_price' => $scoreData['lastPrice'],
                    'pct_60m' => $scoreData['pct60'],
                    'pct_30m' => $scoreData['pct30'],
                    'pct_15m' => $scoreData['pct15'],
                    'volume_boost' => $scoreData['volBoost'],
                    'dist_from_vwap_pct' => $scoreData['distFromVwapPct'],
                    'atr_like' => $scoreData['atrLike'],
                    'avg_dollar_volume' => $scoreData['avgDollarVol'],
                    'topping_pattern' => $scoreData['topping'],
                    'reasons' => $scoreData['reasons'],
                    'vwap' => $scoreData['vwap'],
                ];
            } catch (\Exception $e) {
                \Log::error("Error processing symbol {$symbol}: ".$e->getMessage());

                continue;
            }
        }

        // Sort by score desc, then by 60m % change
        usort($results, function ($a, $b) {
            if ($a['score'] == $b['score']) {
                return $b['pct_60m'] <=> $a['pct_60m'];
            }

            return $b['score'] <=> $a['score'];
        });

        return [
            'results' => $results,
            'meta' => [
                'as_of' => $endEst->format('Y-m-d H:i:s'),
                'asset_type' => $assetType,
                'min_score' => $minScore,
                'candidates_found' => count($candidates),
                'results_count' => count($results),
                'data_window' => [
                    'start_60m' => $startEst60->format('Y-m-d H:i:s'),
                    'start_3h' => $startEst3h->format('Y-m-d H:i:s'),
                    'end' => $endEst->format('Y-m-d H:i:s'),
                ],
            ],
        ];
    }

    /**
     * Determine as of time from input or current
     */
    private function determineAsOfTime(?string $asOfEstInput): Carbon
    {
        if ($asOfEstInput) {
            return EstTimezoneHelper::parseEstTimestamp($asOfEstInput);
        }

        return Carbon::now('America/New_York');
    }

    /**
     * Fetch candidate symbols with their asset IDs that have 1-minute data
     */
    private function fetchCandidateSymbols(string $assetType, Carbon $startEst60, Carbon $endEst): array
    {
        $symbols = DB::select('
            SELECT DISTINCT omp.symbol, ai.id as asset_id
            FROM one_minute_prices omp
            INNER JOIN asset_info ai
                ON ai.symbol = omp.symbol
               AND ai.asset_type = omp.asset_type
            WHERE omp.asset_type = ?
              AND omp.ts_est BETWEEN ? AND ?
              AND omp.volume IS NOT NULL
              AND omp.volume > 0
              AND ai.deleted_at IS NULL
            LIMIT ?
        ', [
            $assetType,
            $startEst60->format('Y-m-d H:i:s'),
            $endEst->format('Y-m-d H:i:s'),
            self::MAX_SYMBOLS,
        ]);

        return $symbols;
    }

    /**
     * Fetch 1-minute bars for the last 60 minutes
     */
    private function fetchOneMinuteBars(string $symbol, string $assetType, Carbon $startEst60, Carbon $endEst): array
    {
        $data = DB::select('
            SELECT ts_est, price, open, high, low, volume
            FROM one_minute_prices
            WHERE symbol = ?
              AND asset_type = ?
              AND ts_est BETWEEN ? AND ?
            ORDER BY ts_est ASC
        ', [$symbol, $assetType, $startEst60->format('Y-m-d H:i:s'), $endEst->format('Y-m-d H:i:s')]);

        return array_map(function ($row) {
            return [
                'ts_est' => $row->ts_est,
                'price' => (float) $row->price,
                'open' => $row->open !== null ? (float) $row->open : (float) $row->price,
                'high' => $row->high !== null ? (float) $row->high : (float) $row->price,
                'low' => $row->low !== null ? (float) $row->low : (float) $row->price,
                'volume' => $row->volume !== null ? (float) $row->volume : 0.0,
            ];
        }, $data);
    }

    /**
     * Fetch 5-minute bars for the last 3 hours
     */
    private function fetchFiveMinuteBars(string $symbol, string $assetType, Carbon $startEst3h, Carbon $endEst): array
    {
        $data = DB::select('
            SELECT ts_est, price, open, high, low, volume
            FROM five_minute_prices
            WHERE symbol = ?
              AND asset_type = ?
              AND ts_est BETWEEN ? AND ?
            ORDER BY ts_est ASC
        ', [$symbol, $assetType, $startEst3h->format('Y-m-d H:i:s'), $endEst->format('Y-m-d H:i:s')]);

        return array_map(function ($row) {
            return [
                'ts_est' => $row->ts_est,
                'price' => (float) $row->price,
                'open' => $row->open !== null ? (float) $row->open : (float) $row->price,
                'high' => $row->high !== null ? (float) $row->high : (float) $row->price,
                'low' => $row->low !== null ? (float) $row->low : (float) $row->price,
                'volume' => $row->volume !== null ? (float) $row->volume : 0.0,
            ];
        }, $data);
    }

    /**
     * Fetch average daily dollar volume
     */
    private function fetchAvgDailyDollarVolume(string $symbol, string $assetType, int $days = 10): float
    {
        $result = DB::selectOne('
            SELECT AVG(price * volume) AS avg_dollar_vol
            FROM (
                SELECT price, volume
                FROM daily_prices
                WHERE symbol = ?
                  AND asset_type = ?
                ORDER BY date DESC
                LIMIT ?
            ) t
        ', [$symbol, $assetType, $days]);

        return $result && $result->avg_dollar_vol !== null ? (float) $result->avg_dollar_vol : 0.0;
    }

    /**
     * Build momentum score for a symbol
     */
    private function buildScoreForSymbol(
        string $symbol,
        array $oneMinBars,
        array $fiveMinBars,
        Carbon $endEst,
        float $avgDollarVol
    ): array {
        if (empty($oneMinBars) || empty($fiveMinBars)) {
            return [
                'valid' => false,
                'reason' => 'insufficient intraday data',
            ];
        }

        $lastBar = end($oneMinBars);
        $lastPrice = (float) $lastBar['price'];

        $price60 = $this->getPriceAtLookback($oneMinBars, $endEst, self::LOOKBACK_60M);
        $price30 = $this->getPriceAtLookback($oneMinBars, $endEst, self::LOOKBACK_30M);
        $price15 = $this->getPriceAtLookback($oneMinBars, $endEst, self::LOOKBACK_15M);

        $pct60 = $this->pctChange($price60, $lastPrice);
        $pct30 = $this->pctChange($price30, $lastPrice);
        $pct15 = $this->pctChange($price15, $lastPrice);

        $volBoost = $this->computeVolumeBoost($oneMinBars, $endEst);
        $vwap = $this->computeVWAP($fiveMinBars);
        $atrLike = $this->computeAtrLike($fiveMinBars);
        $topping = $this->detectTopping($fiveMinBars);

        $distFromVwapPct = 0.0;
        if ($vwap > 0.0) {
            $distFromVwapPct = ($lastPrice - $vwap) / $vwap * 100.0;
        }

        // Liquidity filter
        if ($avgDollarVol < self::MIN_AVG_DOLLAR_VOLUME) {
            return [
                'valid' => false,
                'reason' => 'illiquid (avg dollar volume < '.self::MIN_AVG_DOLLAR_VOLUME.')',
            ];
        }

        // Scoring logic
        $score = 0.0;
        $reasons = [];

        // 60m momentum
        if ($pct60 > 1.0 && $pct30 > 0.7) {
            $score += 3.0;
            $reasons[] = 'Strong 60m/30m momentum';
        } elseif ($pct60 > 0.5) {
            $score += 2.0;
            $reasons[] = 'Moderate 60m momentum';
        } elseif ($pct60 < -0.5) {
            $score -= 1.0;
            $reasons[] = 'Negative 60m momentum';
        }

        // 15m near-term acceleration
        if ($pct15 > 0.3 && $pct15 < 3.0) {
            $score += 2.0;
            $reasons[] = 'Healthy 15m acceleration';
        } elseif ($pct15 >= 3.0) {
            $score -= 1.0;
            $reasons[] = 'Possible blow-off 15m move';
        }

        // Volume boost
        if ($volBoost > 1.0) {
            $score += 2.0;
            $reasons[] = 'Volume > 2x baseline';
        } elseif ($volBoost > 0.5) {
            $score += 1.0;
            $reasons[] = 'Volume moderately elevated';
        } elseif ($volBoost < -0.3) {
            $score -= 1.0;
            $reasons[] = 'Volume weak vs baseline';
        }

        // VWAP relationship
        if ($vwap > 0.0) {
            if ($distFromVwapPct > 0.0 && $distFromVwapPct <= 1.0) {
                $score += 2.0;
                $reasons[] = 'Above VWAP but not extended';
            } elseif ($distFromVwapPct > 1.0 && $distFromVwapPct <= 2.5) {
                $score += 1.0;
                $reasons[] = 'Moderately above VWAP';
            } elseif ($distFromVwapPct > 3.0) {
                $score -= 1.0;
                $reasons[] = 'Too extended above VWAP';
            } elseif ($distFromVwapPct < -0.5) {
                $score -= 1.0;
                $reasons[] = 'Below VWAP';
            }
        }

        // ATR sanity check
        if ($atrLike > 0.0) {
            $atrToPrice = ($atrLike / $lastPrice) * 100.0;
            if ($atrToPrice > 5.0) {
                $score -= 1.0;
                $reasons[] = 'Very high short-term volatility';
            }
        }

        // Topping pattern penalty
        if ($topping) {
            $score -= 2.0;
            $reasons[] = 'Topping pattern in last 3 five-minute bars';
        }

        // Clip to 0-10 range
        $score = max(0.0, min(10.0, $score));

        return [
            'valid' => true,
            'score' => $score,
            'reasons' => $reasons,
            'lastPrice' => $lastPrice,
            'pct60' => $pct60,
            'pct30' => $pct30,
            'pct15' => $pct15,
            'volBoost' => $volBoost,
            'vwap' => $vwap,
            'distFromVwapPct' => $distFromVwapPct,
            'atrLike' => $atrLike,
            'topping' => $topping,
            'avgDollarVol' => $avgDollarVol,
        ];
    }

    /**
     * Safe percent change calculation
     */
    private function pctChange(float $from, float $to): float
    {
        if ($from == 0.0) {
            return 0.0;
        }

        return ($to - $from) / $from * 100.0;
    }

    /**
     * Get price at specific lookback time
     */
    private function getPriceAtLookback(array $oneMinBars, Carbon $endEst, int $lookbackMinutes): float
    {
        if (empty($oneMinBars)) {
            return 0.0;
        }

        $target = $endEst->copy()->subMinutes($lookbackMinutes);
        $best = $oneMinBars[0]['price'];
        $bestDiff = PHP_FLOAT_MAX;

        foreach ($oneMinBars as $bar) {
            $ts = EstTimezoneHelper::parseEstTimestamp($bar['ts_est']);
            $diff = abs($ts->timestamp - $target->timestamp);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $bar['price'];
            }
        }

        return $best;
    }

    /**
     * Compute volume boost (current 15m vs avg 60m volume)
     */
    private function computeVolumeBoost(array $oneMinBars, Carbon $endEst): float
    {
        $count = count($oneMinBars);
        if ($count === 0) {
            return 0.0;
        }

        // Total volume 60m
        $sumVol60 = array_sum(array_column($oneMinBars, 'volume'));
        $avgVolPerMin = $sumVol60 / max(1, $count);

        // Volume last 15m
        $cutoff15 = $endEst->copy()->subMinutes(self::LOOKBACK_15M);
        $sumVol15 = 0.0;
        foreach ($oneMinBars as $bar) {
            $ts = EstTimezoneHelper::parseEstTimestamp($bar['ts_est']);
            if ($ts->gte($cutoff15)) {
                $sumVol15 += $bar['volume'];
            }
        }

        $avg15IfFlat = $avgVolPerMin * self::LOOKBACK_15M;
        if ($avg15IfFlat <= 0.0) {
            return 0.0;
        }

        return ($sumVol15 - $avg15IfFlat) / $avg15IfFlat;
    }

    /**
     * Compute VWAP from 5-minute bars
     */
    private function computeVWAP(array $bars): float
    {
        $num = 0.0;
        $den = 0.0;

        foreach ($bars as $bar) {
            $typical = ($bar['high'] + $bar['low'] + $bar['price']) / 3.0;
            $vol = $bar['volume'] ?? 0.0;
            $num += $typical * $vol;
            $den += $vol;
        }

        return $den > 0.0 ? $num / $den : 0.0;
    }

    /**
     * Compute ATR-like volatility
     */
    private function computeAtrLike(array $bars, int $periods = 12): float
    {
        $count = count($bars);
        if ($count === 0) {
            return 0.0;
        }

        $startIdx = max(0, $count - $periods);
        $sumRange = 0.0;
        $n = 0;

        for ($i = $startIdx; $i < $count; $i++) {
            $high = $bars[$i]['high'];
            $low = $bars[$i]['low'];
            $sumRange += max(0.0, $high - $low);
            $n++;
        }

        return $n > 0 ? $sumRange / $n : 0.0;
    }

    /**
     * Detect topping pattern from last 3 five-minute candles
     */
    private function detectTopping(array $fiveMinBars): bool
    {
        $count = count($fiveMinBars);
        if ($count < 3) {
            return false;
        }

        $last = $fiveMinBars[$count - 1];
        $prev1 = $fiveMinBars[$count - 2];
        $prev2 = $fiveMinBars[$count - 3];

        // Long upper wick on last bar
        $body = abs($last['price'] - $last['open']);
        $upperWick = $last['high'] - max($last['price'], $last['open']);
        $lowerWick = min($last['price'], $last['open']) - $last['low'];

        $longUpper = ($body > 0.0) && ($upperWick > 2.0 * $body) && ($upperWick > $lowerWick);

        // Deceleration: each bar making smaller new highs
        $deceleratingHighs = ($prev2['high'] < $prev1['high']) && ($prev1['high'] < $last['high']);

        return $longUpper && $deceleratingHighs;
    }
}
