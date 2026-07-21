<?php

declare(strict_types=1);

namespace App\Services\Trading;

use App\Contracts\Trading\FiveMinuteSignalScannerContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Version 2000.1 - Market Movers Universe Scanner with real 5m setup gating
 *
 * Same public scan() arguments as v2000.0.
 *
 * Main difference from v2000.0:
 * - v2000.0 surfaced almost the whole market-movers universe.
 * - v2000.1 only returns symbols that are actively setting up right now.
 *
 * Goal:
 * - Send fewer, cleaner candidates to the 1m entry finder and ML ranker.
 */
class FiveMinuteSignalScannerV2000_1 implements FiveMinuteSignalScannerContract
{
    use HasPriceTables;

    private string $version = 'v2000.1';

    private string $name = 'Market Movers Universe';

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
        float $minMovePct = 0.4,
        float $volMult = 1.5,
        int $limit = 10000
    ): array {
        $tradeDate = substr($asOfTsEst, 0, 10);
        $limit = max(1, $limit);

        // ---------- 0) Universe: intraday_universe + market_movers ----------
        $universeSymbols = [];
        $addIntradayUniverse = (int) config('trading.market_movers.pipeline_j_add_intraday_universe', 0);

        if ($addIntradayUniverse > 0) {
            $universeCacheKey = "scan_v2000_1:universe_symbols:{$assetType}";
            $universeSymbols = Cache::get($universeCacheKey);

            if ($universeSymbols === null) {
                $universeSymbols = DB::table('intraday_universe')
                    ->select('symbol')
                    ->where('asset_type', $assetType)
                    ->orderBy('symbol')
                    ->pluck('symbol')
                    ->all();

                Cache::put($universeCacheKey, $universeSymbols, 28800);
            }
        }

        $universeRows = $this->dbSelect('
            SELECT
                p.symbol,
                COUNT(DISTINCT p.trading_date_est) AS days_appeared,
                ROUND(MAX(((p.price - p.open) / p.open) * 100), 2) AS max_gain_pct
            FROM five_minute_prices p
            JOIN (
                SELECT trading_date
                FROM market_movers
                ORDER BY trading_date DESC
                LIMIT 5
            ) d ON d.trading_date = p.trading_date_est
            WHERE p.open > 0
              AND ((p.price - p.open) / p.open) * 100 >= 4
            GROUP BY p.symbol
            ORDER BY days_appeared DESC, max_gain_pct DESC, p.symbol
        ');

        if (empty($universeRows) && empty($universeSymbols)) {
            return [];
        }

        $moverSymbols = array_values(array_unique(array_filter(array_map(
            static fn ($row) => (string) ($row->symbol ?? ''),
            $universeRows
        ))));

        // Merge intraday_universe symbols with market_movers symbols
        $symbols = array_values(array_unique(array_merge($moverSymbols, $universeSymbols)));

        if (empty($symbols)) {
            return [];
        }

        $symbols = array_slice($symbols, 0, max(300, min(1500, $limit * 5)));
        $placeholders = implode(',', array_fill(0, count($symbols), '?'));

        $recentRows = $this->dbSelect("
            SELECT
                ranked.symbol,
                ranked.asset_type,
                ranked.ts_est,
                ranked.price AS close_price,
                ranked.open,
                ranked.high,
                ranked.low,
                ranked.volume,
                ranked.atr,
                ranked.atr_pct,
                ranked.vwap,
                ranked.vwap_dist_pct,
                ranked.above_vwap,
                ranked.ema9,
                ranked.ema21,
                ranked.ema9_ema21_spread,
                ranked.ema9_above_ema21,
                ranked.rsi_14,
                ranked.trading_date_est,
                ranked.rn
            FROM (
                SELECT
                    f.symbol,
                    f.asset_type,
                    f.ts_est,
                    f.price,
                    f.open,
                    f.high,
                    f.low,
                    f.volume,
                    f.atr,
                    f.atr_pct,
                    f.vwap,
                    f.vwap_dist_pct,
                    f.above_vwap,
                    f.ema9,
                    f.ema21,
                    f.ema9_ema21_spread,
                    f.ema9_above_ema21,
                    f.rsi_14,
                    f.trading_date_est,
                    ROW_NUMBER() OVER (PARTITION BY f.symbol ORDER BY f.ts_est DESC) AS rn
                FROM five_minute_prices f
                WHERE f.asset_type = ?
                  AND f.trading_date_est = ?
                  AND f.ts_est <= ?
                  AND f.symbol IN ($placeholders)
            ) ranked
            WHERE ranked.rn <= 20
            ORDER BY ranked.symbol ASC, ranked.rn ASC
        ", array_merge([$assetType, $tradeDate, $asOfTsEst], $symbols));

        if (empty($recentRows)) {
            return [];
        }

        $barsBySymbol = [];
        foreach ($recentRows as $row) {
            $symbol = (string) $row->symbol;
            $barsBySymbol[$symbol][] = $row;
        }

        $dayStatsRows = $this->dbSelect("
            SELECT
                f.symbol,
                CAST(SUBSTRING_INDEX(GROUP_CONCAT(f.open ORDER BY f.ts_est ASC), ',', 1) AS DECIMAL(20, 8)) AS day_open,
                MAX(f.high) AS intraday_high,
                AVG(f.volume) AS avg_5m_volume,
                AVG(f.price * f.volume) / 5 AS avg_dollar_volume_per_minute
            FROM five_minute_prices f
            WHERE f.asset_type = ?
              AND f.trading_date_est = ?
              AND f.ts_est <= ?
              AND f.symbol IN ($placeholders)
            GROUP BY f.symbol
        ", array_merge([$assetType, $tradeDate, $asOfTsEst], $symbols));

        $dayStatsBySymbol = [];
        foreach ($dayStatsRows as $row) {
            $dayStatsBySymbol[(string) $row->symbol] = $row;
        }

        $out = [];

        foreach ($universeRows as $rank => $universeRow) {
            $symbol = (string) $universeRow->symbol;

            if (! isset($barsBySymbol[$symbol])) {
                continue;
            }

            $bars = $barsBySymbol[$symbol];

            if (count($bars) < 3) {
                continue;
            }

            $latest = $bars[0];
            $previousBars = array_slice($bars, 1);
            $dayStats = $dayStatsBySymbol[$symbol] ?? null;

            $price = $this->floatOrNull($latest->close_price);
            $open = $this->floatOrNull($latest->open);
            $high = $this->floatOrNull($latest->high);
            $low = $this->floatOrNull($latest->low);
            $volume = $this->floatOrNull($latest->volume);
            $atr = $this->floatOrNull($latest->atr);
            $atrPct = $this->floatOrNull($latest->atr_pct);
            $vwap = $this->floatOrNull($latest->vwap);
            $vwapDistPct = $this->floatOrNull($latest->vwap_dist_pct);
            $ema9 = $this->floatOrNull($latest->ema9);
            $ema21 = $this->floatOrNull($latest->ema21);
            $emaSpreadPct = $this->floatOrNull($latest->ema9_ema21_spread);
            $rsi14 = $this->floatOrNull($latest->rsi_14);

            if ($price === null || $open === null || $high === null || $low === null || $volume === null) {
                continue;
            }

            if ($price <= 0 || $open <= 0) {
                continue;
            }

            if ($price < 1.00 || $price > 100.00) {
                continue;
            }

            $dayOpen = $dayStats ? $this->floatOrNull($dayStats->day_open) : null;
            $intradayHigh = $dayStats ? $this->floatOrNull($dayStats->intraday_high) : null;
            $avgDollarVolumePerMinute = $dayStats ? $this->floatOrNull($dayStats->avg_dollar_volume_per_minute) : null;

            $dayMovePct = ($dayOpen !== null && $dayOpen > 0)
                ? (($price - $dayOpen) / $dayOpen) * 100
                : null;

            $fiveMinuteMovePct = (($price - $open) / $open) * 100;

            $distanceFromHighPct = ($intradayHigh !== null && $intradayHigh > 0)
                ? (($intradayHigh - $price) / $intradayHigh) * 100
                : null;

            $priorVolumes = array_values(array_filter(array_map(
                fn ($bar) => $this->floatOrNull($bar->volume),
                $previousBars
            ), static fn ($value) => $value !== null));

            $avgPriorVolume = ! empty($priorVolumes)
                ? array_sum($priorVolumes) / count($priorVolumes)
                : null;

            $volRatio = ($avgPriorVolume !== null && $avgPriorVolume > 0)
                ? $volume / $avgPriorVolume
                : null;

            $aboveVwap = null;
            if ($this->floatOrNull($latest->above_vwap) !== null) {
                $aboveVwap = ((float) $latest->above_vwap) > 0;
            } elseif ($vwap !== null && $vwap > 0) {
                $aboveVwap = $price >= $vwap;
            }

            if ($vwapDistPct === null && $vwap !== null && $vwap > 0) {
                $vwapDistPct = (($price - $vwap) / $vwap) * 100;
            }

            $emaTrendOk = null;
            if ($this->floatOrNull($latest->ema9_above_ema21) !== null) {
                $emaTrendOk = ((float) $latest->ema9_above_ema21) > 0;
            } elseif ($ema9 !== null && $ema21 !== null) {
                $emaTrendOk = $ema9 >= $ema21;
            }

            $minDayMovePct = max(0.80, $minMovePct);
            $minFiveMinuteMovePct = max(0.12, $minMovePct * 0.30);
            $minVolRatio = max(1.20, $volMult);
            $minAvgDollarVolumePerMinute = 10000.0;

            if ($dayMovePct !== null && $dayMovePct < $minDayMovePct) {
                continue;
            }

            if ($fiveMinuteMovePct < $minFiveMinuteMovePct) {
                continue;
            }

            if ($volRatio !== null && $volRatio < $minVolRatio) {
                continue;
            }

            if ($avgDollarVolumePerMinute !== null && $avgDollarVolumePerMinute < $minAvgDollarVolumePerMinute) {
                continue;
            }

            if ($atrPct !== null && ($atrPct < 0.35 || $atrPct > 2.75)) {
                continue;
            }

            if ($aboveVwap === false) {
                continue;
            }

            if ($emaTrendOk === false) {
                continue;
            }

            if ($vwapDistPct !== null && ($vwapDistPct < -0.35 || $vwapDistPct > 2.25)) {
                continue;
            }

            if ($distanceFromHighPct !== null && $distanceFromHighPct > 4.00) {
                continue;
            }

            $setupType = $this->classifyFiveMinuteSetup(
                latest: $latest,
                previousBars: $previousBars,
                price: $price,
                fiveMinuteMovePct: $fiveMinuteMovePct,
                volRatio: $volRatio,
                vwap: $vwap,
                vwapDistPct: $vwapDistPct,
                aboveVwap: $aboveVwap,
                distanceFromHighPct: $distanceFromHighPct
            );

            if ($setupType === null) {
                continue;
            }

            $daysAppeared = (int) $universeRow->days_appeared;
            $maxGainPct = (float) $universeRow->max_gain_pct;

            $score = $this->scoreSignal(
                setupType: $setupType,
                daysAppeared: $daysAppeared,
                maxGainPct: $maxGainPct,
                dayMovePct: $dayMovePct,
                fiveMinuteMovePct: $fiveMinuteMovePct,
                volRatio: $volRatio,
                vwapDistPct: $vwapDistPct,
                atrPct: $atrPct,
                distanceFromHighPct: $distanceFromHighPct,
                emaTrendOk: $emaTrendOk
            );

            $out[] = [
                'symbol' => $symbol,
                'asset_type' => (string) $latest->asset_type,
                'signal_type' => $setupType,
                'signal_ts_est' => (string) $latest->ts_est,
                'score' => $score,
                'atr' => $atr,
                'atr_pct' => $atrPct,
                'meta' => [
                    'version' => $this->version,
                    'universe_rank' => $rank + 1,
                    'universe_size' => count($universeRows),
                    'days_appeared' => $daysAppeared,
                    'max_gain_pct' => $maxGainPct,
                    'current_price' => $price,
                    'current_volume' => $volume,
                    'trading_date' => (string) $latest->trading_date_est,
                    'universe_days' => 5,
                    'lookback_minutes' => $lookbackMinutes,
                    'min_move_pct' => $minMovePct,
                    'vol_mult' => $volMult,
                    'setup_type' => $setupType,
                    'day_move_pct' => $dayMovePct !== null ? round($dayMovePct, 4) : null,
                    'five_minute_move_pct' => round($fiveMinuteMovePct, 4),
                    'five_minute_volume_ratio' => $volRatio !== null ? round($volRatio, 4) : null,
                    'avg_dollar_volume_per_minute' => $avgDollarVolumePerMinute !== null ? round($avgDollarVolumePerMinute, 2) : null,
                    'vwap' => $vwap,
                    'vwap_dist_pct' => $vwapDistPct !== null ? round($vwapDistPct, 4) : null,
                    'above_vwap' => $aboveVwap,
                    'ema9' => $ema9,
                    'ema21' => $ema21,
                    'ema_spread_pct' => $emaSpreadPct,
                    'ema_trend_ok' => $emaTrendOk,
                    'rsi_14' => $rsi14,
                    'distance_from_high_pct' => $distanceFromHighPct !== null ? round($distanceFromHighPct, 4) : null,
                    'bar_body_pct' => $this->barBodyPct($open, $price, $high, $low),
                    'upper_wick_pct' => $this->upperWickPct($open, $price, $high, $low),
                    'lower_wick_pct' => $this->lowerWickPct($open, $price, $high, $low),
                ],
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        usort($out, static fn (array $a, array $b) => ($b['score'] <=> $a['score']));

        return array_slice($out, 0, $limit);
    }

    private function classifyFiveMinuteSetup(
        object $latest,
        array $previousBars,
        float $price,
        float $fiveMinuteMovePct,
        ?float $volRatio,
        ?float $vwap,
        ?float $vwapDistPct,
        ?bool $aboveVwap,
        ?float $distanceFromHighPct
    ): ?string {
        $open = $this->floatOrNull($latest->open);
        $high = $this->floatOrNull($latest->high);
        $low = $this->floatOrNull($latest->low);

        if ($open === null || $high === null || $low === null || $price <= 0) {
            return null;
        }

        $lastFive = array_slice($previousBars, 0, 5);

        $priorHighs = array_values(array_filter(array_map(
            fn ($bar) => $this->floatOrNull($bar->high),
            $lastFive
        ), static fn ($value) => $value !== null));

        $priorLows = array_values(array_filter(array_map(
            fn ($bar) => $this->floatOrNull($bar->low),
            $lastFive
        ), static fn ($value) => $value !== null));

        $priorCloses = array_values(array_filter(array_map(
            fn ($bar) => $this->floatOrNull($bar->close_price),
            $lastFive
        ), static fn ($value) => $value !== null));

        if (empty($priorHighs) || empty($priorLows)) {
            return null;
        }

        $recentHigh = max($priorHighs);
        $recentLow = min($priorLows);

        $breaksRecentHigh = $recentHigh > 0 && $price > ($recentHigh * 1.0005);
        $recentRangePct = $recentLow > 0 ? (($recentHigh - $recentLow) / $price) * 100 : null;
        $greenBar = $price > $open;

        $wasBelowVwap = false;
        if ($vwap !== null && $vwap > 0 && ! empty($priorCloses)) {
            foreach ($priorCloses as $close) {
                if ($close < $vwap) {
                    $wasBelowVwap = true;
                    break;
                }
            }
        }

        if (
            $aboveVwap === true
            && $wasBelowVwap
            && $greenBar
            && ($volRatio === null || $volRatio >= 1.30)
            && $fiveMinuteMovePct >= 0.12
        ) {
            return 'VWAP_RECLAIM_5M';
        }

        if (
            $vwap !== null
            && $vwap > 0
            && $low <= ($vwap * 1.004)
            && $price > $vwap
            && $greenBar
            && ($volRatio === null || $volRatio >= 1.20)
        ) {
            return 'VWAP_PULLBACK_HOLD_5M';
        }

        if (
            $recentRangePct !== null
            && $recentRangePct <= 1.50
            && $breaksRecentHigh
            && $greenBar
            && ($volRatio === null || $volRatio >= 1.35)
        ) {
            return 'BULL_FLAG_BREAKOUT_5M';
        }

        if (
            $distanceFromHighPct !== null
            && $distanceFromHighPct <= 0.75
            && $breaksRecentHigh
            && $greenBar
            && ($volRatio === null || $volRatio >= 1.40)
        ) {
            return 'HIGH_OF_DAY_RECLAIM_5M';
        }

        if (
            $breaksRecentHigh
            && $greenBar
            && $fiveMinuteMovePct >= 0.15
            && ($volRatio === null || $volRatio >= 1.50)
        ) {
            return 'MOMENTUM_CONTINUATION_5M';
        }

        return null;
    }

    private function scoreSignal(
        string $setupType,
        int $daysAppeared,
        float $maxGainPct,
        ?float $dayMovePct,
        float $fiveMinuteMovePct,
        ?float $volRatio,
        ?float $vwapDistPct,
        ?float $atrPct,
        ?float $distanceFromHighPct,
        ?bool $emaTrendOk
    ): float {
        $setupBonus = match ($setupType) {
            'VWAP_PULLBACK_HOLD_5M' => 18.0,
            'VWAP_RECLAIM_5M' => 16.0,
            'BULL_FLAG_BREAKOUT_5M' => 15.0,
            'HIGH_OF_DAY_RECLAIM_5M' => 14.0,
            'MOMENTUM_CONTINUATION_5M' => 12.0,
            default => 0.0,
        };

        $score = 40.0;
        $score += min(20.0, $daysAppeared * 4.0);
        $score += min(20.0, $maxGainPct * 0.35);
        $score += $dayMovePct !== null ? min(18.0, max(0.0, $dayMovePct * 2.5)) : 0.0;
        $score += min(12.0, max(0.0, $fiveMinuteMovePct * 10.0));
        $score += $volRatio !== null ? min(16.0, max(0.0, $volRatio * 4.0)) : 0.0;
        $score += $setupBonus;

        if ($emaTrendOk === true) {
            $score += 6.0;
        }

        if ($vwapDistPct !== null) {
            if ($vwapDistPct > 1.75) {
                $score -= 10.0;
            } elseif ($vwapDistPct >= 0.0 && $vwapDistPct <= 1.25) {
                $score += 8.0;
            }
        }

        if ($atrPct !== null) {
            if ($atrPct >= 0.50 && $atrPct <= 1.75) {
                $score += 8.0;
            } elseif ($atrPct > 2.25) {
                $score -= 8.0;
            }
        }

        if ($distanceFromHighPct !== null) {
            if ($distanceFromHighPct <= 1.00) {
                $score += 6.0;
            } elseif ($distanceFromHighPct > 3.00) {
                $score -= 8.0;
            }
        }

        return round(max(0.0, min(100.0, $score)), 3);
    }

    private function floatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function barBodyPct(float $open, float $close, float $high, float $low): ?float
    {
        $range = $high - $low;

        if ($range <= 0) {
            return null;
        }

        return round((abs($close - $open) / $range) * 100, 2);
    }

    private function upperWickPct(float $open, float $close, float $high, float $low): ?float
    {
        $range = $high - $low;

        if ($range <= 0) {
            return null;
        }

        $upper = $high - max($open, $close);

        return round((max(0.0, $upper) / $range) * 100, 2);
    }

    private function lowerWickPct(float $open, float $close, float $high, float $low): ?float
    {
        $range = $high - $low;

        if ($range <= 0) {
            return null;
        }

        $lower = min($open, $close) - $low;

        return round((max(0.0, $lower) / $range) * 100, 2);
    }
}
