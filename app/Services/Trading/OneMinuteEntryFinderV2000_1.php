<?php

declare(strict_types=1);

namespace App\Services\Trading;

use App\Contracts\Trading\OneMinuteEntryFinderContract;

/**
 * Version 2000.1 - Market Movers Universe Entry Finder with real 1m trigger gating
 *
 * Same public findBestLong()/findBestShort() arguments as v2000.0.
 *
 * Main difference from v2000.0:
 * - v2000.0 created an entry for almost every scanner signal.
 * - v2000.1 only creates an entry when a real 1m trigger exists.
 */
class OneMinuteEntryFinderV2000_1 implements OneMinuteEntryFinderContract
{
    use HasPriceTables;

    private string $version = 'v2000.1';

    public function getVersion(): string
    {
        return $this->version;
    }

    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        ...$rest
    ): array {
        $signalBar = $this->dbSelect('
            SELECT
                ts_est,
                price,
                open,
                high,
                low,
                volume,
                atr,
                atr_pct,
                vwap,
                vwap_dist_pct,
                above_vwap,
                ema9,
                ema21,
                ema9_ema21_spread,
                ema9_above_ema21,
                rsi_14
            FROM five_minute_prices
            WHERE symbol = ?
              AND asset_type = ?
              AND ts_est = ?
            LIMIT 1
        ', [$symbol, $assetType, $signalTsEst]);

        if (empty($signalBar)) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'signal_not_found',
            ];
        }

        $signalBar = $signalBar[0];
        $signalAgeSeconds = $this->secondsBetween($signalTsEst, $asOfTsEst);

        if ($signalAgeSeconds !== null && $signalAgeSeconds > 600) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'signal_too_old',
                'meta' => [
                    'version' => $this->version,
                    'signal_age_seconds' => $signalAgeSeconds,
                    'as_of_ts_est' => $asOfTsEst,
                ],
            ];
        }

        $recentOneMinuteBars = $this->dbSelect('
            SELECT
                ts_est,
                price,
                open,
                high,
                low,
                volume,
                atr,
                atr_pct,
                vwap,
                vwap_dist_pct,
                above_vwap,
                ema9,
                ema21,
                ema9_ema21_spread,
                ema9_above_ema21,
                rsi_14
            FROM one_minute_prices
            WHERE symbol = ?
              AND asset_type = ?
              AND trading_date_est = DATE(?)
              AND ts_est <= ?
            ORDER BY ts_est DESC
            LIMIT 30
        ', [$symbol, $assetType, $asOfTsEst, $asOfTsEst]);

        if (empty($recentOneMinuteBars)) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'no_recent_1m_bars',
            ];
        }

        if (count($recentOneMinuteBars) < 4) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'not_enough_1m_bars',
            ];
        }

        $latestBar = $recentOneMinuteBars[0];
        $previousBars = array_slice($recentOneMinuteBars, 1);

        $entryTsEst = (string) $latestBar->ts_est;
        $entryPrice = $this->floatOrNull($latestBar->price);
        $entryOpen = $this->floatOrNull($latestBar->open);
        $entryHigh = $this->floatOrNull($latestBar->high);
        $entryLow = $this->floatOrNull($latestBar->low);
        $entryVolume = $this->floatOrNull($latestBar->volume);

        if ($entryPrice === null || $entryOpen === null || $entryHigh === null || $entryLow === null || $entryVolume === null) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'missing_entry_bar_fields',
            ];
        }

        if ($entryPrice <= 0) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'invalid_entry_price',
            ];
        }

        $barAgeSeconds = $this->secondsBetween($entryTsEst, $asOfTsEst);

        if ($barAgeSeconds !== null && $barAgeSeconds > 180) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'entry_bar_too_old',
                'meta' => [
                    'version' => $this->version,
                    'entry_bar_age_seconds' => $barAgeSeconds,
                    'as_of_ts_est' => $asOfTsEst,
                ],
            ];
        }

        $atr = $this->floatOrNull($latestBar->atr) ?? $this->floatOrNull($signalBar->atr);
        $atrPct = $this->floatOrNull($latestBar->atr_pct) ?? $this->floatOrNull($signalBar->atr_pct);

        $vwap = $this->floatOrNull($latestBar->vwap);
        $vwapDistPct = $this->floatOrNull($latestBar->vwap_dist_pct);
        $ema9 = $this->floatOrNull($latestBar->ema9);
        $ema21 = $this->floatOrNull($latestBar->ema21);
        $emaSpreadPct = $this->floatOrNull($latestBar->ema9_ema21_spread);
        $rsi14 = $this->floatOrNull($latestBar->rsi_14);

        if ($vwapDistPct === null && $vwap !== null && $vwap > 0) {
            $vwapDistPct = (($entryPrice - $vwap) / $vwap) * 100;
        }

        $aboveVwap = null;
        if ($this->floatOrNull($latestBar->above_vwap) !== null) {
            $aboveVwap = ((float) $latestBar->above_vwap) > 0;
        } elseif ($vwap !== null && $vwap > 0) {
            $aboveVwap = $entryPrice >= $vwap;
        }

        $emaTrendOk = null;
        if ($this->floatOrNull($latestBar->ema9_above_ema21) !== null) {
            $emaTrendOk = ((float) $latestBar->ema9_above_ema21) > 0;
        } elseif ($ema9 !== null && $ema21 !== null) {
            $emaTrendOk = $ema9 >= $ema21;
        }

        $priorVolumes = array_values(array_filter(array_map(
            fn ($bar) => $this->floatOrNull($bar->volume),
            array_slice($previousBars, 0, 15)
        ), static fn ($value) => $value !== null));

        $avgVolume = ! empty($priorVolumes)
            ? array_sum($priorVolumes) / count($priorVolumes)
            : null;

        $volRatio = ($avgVolume !== null && $avgVolume > 0)
            ? round($entryVolume / $avgVolume, 3)
            : null;

        if ($atrPct !== null && ($atrPct < 0.25 || $atrPct > 3.00)) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'atr_pct_out_of_range',
                'meta' => [
                    'version' => $this->version,
                    'atr_pct' => $atrPct,
                ],
            ];
        }

        if ($aboveVwap === false) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'entry_below_vwap',
                'meta' => [
                    'version' => $this->version,
                    'vwap' => $vwap,
                    'vwap_dist_pct' => $vwapDistPct,
                ],
            ];
        }

        if ($ema9 !== null && $entryPrice < $ema9) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'entry_below_ema9',
                'meta' => [
                    'version' => $this->version,
                    'ema9' => $ema9,
                    'entry' => $entryPrice,
                ],
            ];
        }

        if ($vwapDistPct !== null && $vwapDistPct > 2.25) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'entry_too_extended_from_vwap',
                'meta' => [
                    'version' => $this->version,
                    'vwap_dist_pct' => round($vwapDistPct, 4),
                ],
            ];
        }

        if ($volRatio !== null && $volRatio < 1.15) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'entry_volume_ratio_too_low',
                'meta' => [
                    'version' => $this->version,
                    'vol_ratio' => $volRatio,
                ],
            ];
        }

        $trigger = $this->detectLongTrigger(
            latestBar: $latestBar,
            previousBars: $previousBars,
            entryPrice: $entryPrice,
            entryOpen: $entryOpen,
            entryHigh: $entryHigh,
            entryLow: $entryLow,
            volRatio: $volRatio,
            vwap: $vwap,
            aboveVwap: $aboveVwap
        );

        if ($trigger === null) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'no_1m_trigger',
                'meta' => [
                    'version' => $this->version,
                    'entry_ts_est' => $entryTsEst,
                    'entry' => round($entryPrice, 4),
                    'vol_ratio' => $volRatio,
                    'vwap_dist_pct' => $vwapDistPct !== null ? round($vwapDistPct, 4) : null,
                    'above_vwap' => $aboveVwap,
                    'ema_trend_ok' => $emaTrendOk,
                ],
            ];
        }

        $stopData = $this->calculateStop(
            trigger: $trigger,
            entryPrice: $entryPrice,
            entryLow: $entryLow,
            previousBars: $previousBars,
            vwap: $vwap,
            atr: $atr
        );

        $stopPrice = $stopData['stop'];
        $stopType = $stopData['stop_type'];

        $riskPerShare = round($entryPrice - $stopPrice, 4);
        $riskPct = round(($riskPerShare / $entryPrice) * 100, 2);

        if ($riskPerShare <= 0 || $riskPct <= 0) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'invalid_stop',
            ];
        }

        if ($riskPct > 1.90) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'risk_too_wide',
                'meta' => [
                    'version' => $this->version,
                    'risk_pct' => $riskPct,
                    'entry' => round($entryPrice, 4),
                    'stop' => $stopPrice,
                    'stop_type' => $stopType,
                ],
            ];
        }

        if ($riskPct < 0.35) {
            $stopPrice = max(0.01, round($entryPrice * 0.996, 4));
            $riskPerShare = round($entryPrice - $stopPrice, 4);
            $riskPct = round(($riskPerShare / $entryPrice) * 100, 2);
            $stopType .= '_normalized_min_risk';
        }

        $suggestedTrailingStop = $atr !== null
            ? round($atr * 2.0, 4)
            : round($entryPrice * 0.015, 4);

        $suggestedTrailingStopPct = round(($suggestedTrailingStop / $entryPrice) * 100, 2);
        $targets = $this->buildTargets($entryPrice, $riskPerShare);
        $universeStats = $this->getUniverseStats($symbol);

        $score = $this->scoreEntry(
            trigger: $trigger,
            daysAppeared: $universeStats['days_appeared'] ?? 1,
            maxGainPct: $universeStats['max_gain_pct'] ?? 0.0,
            volRatio: $volRatio,
            riskPct: $riskPct,
            vwapDistPct: $vwapDistPct,
            emaTrendOk: $emaTrendOk
        );

        $bestEntry = [
            'type' => $trigger,
            'entry_ts_est' => $entryTsEst,
            'entry' => round($entryPrice, 4),
            'stop' => $stopPrice,
            'risk_pct' => $riskPct,
            'risk_per_share' => $riskPerShare,
            'score' => $score,
            'vol_ratio' => $volRatio,
            'atr' => $atr,
            'atr_pct' => $atrPct,
            'suggested_trailing_stop' => $suggestedTrailingStop,
            'suggested_trailing_stop_pct' => $suggestedTrailingStopPct,
            'targets' => $targets,
            'meta' => [
                'version' => $this->version,
                'signal_ts_est' => $signalTsEst,
                'as_of_ts_est' => $asOfTsEst,
                'days_appeared' => $universeStats['days_appeared'] ?? null,
                'max_gain_pct' => $universeStats['max_gain_pct'] ?? null,
                'trigger' => $trigger,
                'stop_type' => $stopType,
                'signal_age_seconds' => $signalAgeSeconds,
                'entry_bar_age_seconds' => $barAgeSeconds,
                'entry_open' => round($entryOpen, 4),
                'entry_high' => round($entryHigh, 4),
                'entry_low' => round($entryLow, 4),
                'entry_volume' => $entryVolume,
                'entry_volume_ratio' => $volRatio,
                'vwap' => $vwap,
                'vwap_dist_pct' => $vwapDistPct !== null ? round($vwapDistPct, 4) : null,
                'above_vwap' => $aboveVwap,
                'ema9' => $ema9,
                'ema21' => $ema21,
                'ema_spread_pct' => $emaSpreadPct,
                'ema_trend_ok' => $emaTrendOk,
                'rsi_14' => $rsi14,
                'bar_body_pct' => $this->barBodyPct($entryOpen, $entryPrice, $entryHigh, $entryLow),
                'upper_wick_pct' => $this->upperWickPct($entryOpen, $entryPrice, $entryHigh, $entryLow),
                'lower_wick_pct' => $this->lowerWickPct($entryOpen, $entryPrice, $entryHigh, $entryLow),
            ],
        ];

        return [
            'ok' => 1,
            'best_entry' => $bestEntry,
            'meta' => [
                'version' => $this->version,
                'as_of_ts_est' => $asOfTsEst,
            ],
        ];
    }

    public function findBestShort(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        ...$rest
    ): array {
        return [
            'ok' => 0,
            'best_entry' => null,
            'reason' => 'short_not_implemented',
        ];
    }

    private function detectLongTrigger(
        object $latestBar,
        array $previousBars,
        float $entryPrice,
        float $entryOpen,
        float $entryHigh,
        float $entryLow,
        ?float $volRatio,
        ?float $vwap,
        ?bool $aboveVwap
    ): ?string {
        $greenBar = $entryPrice > $entryOpen;
        $prev = $previousBars[0] ?? null;

        if (! $prev) {
            return null;
        }

        $prevHigh = $this->floatOrNull($prev->high);
        $prevLow = $this->floatOrNull($prev->low);
        $prevClose = $this->floatOrNull($prev->price);

        if ($prevHigh === null || $prevLow === null || $prevClose === null) {
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

        if (empty($priorHighs) || empty($priorLows)) {
            return null;
        }

        $recentHigh = max($priorHighs);
        $recentLow = min($priorLows);
        $recentRangePct = $recentLow > 0 ? (($recentHigh - $recentLow) / $entryPrice) * 100 : null;

        $breaksPriorHigh = $entryPrice > ($prevHigh * 1.0003);
        $breaksRecentHigh = $entryPrice > ($recentHigh * 1.0003);

        $wasBelowVwap = false;
        if ($vwap !== null && $vwap > 0) {
            foreach (array_slice($previousBars, 0, 4) as $bar) {
                $close = $this->floatOrNull($bar->price);
                if ($close !== null && $close < $vwap) {
                    $wasBelowVwap = true;
                    break;
                }
            }
        }

        if (
            $aboveVwap === true
            && $wasBelowVwap
            && $greenBar
            && $breaksPriorHigh
            && ($volRatio === null || $volRatio >= 1.20)
        ) {
            return 'VWAP_RECLAIM_1M';
        }

        if (
            $vwap !== null
            && $vwap > 0
            && $entryLow <= ($vwap * 1.003)
            && $entryPrice > $vwap
            && $greenBar
            && ($volRatio === null || $volRatio >= 1.15)
        ) {
            return 'VWAP_PULLBACK_HOLD_1M';
        }

        if (
            $recentRangePct !== null
            && $recentRangePct <= 1.20
            && $breaksRecentHigh
            && $greenBar
            && ($volRatio === null || $volRatio >= 1.25)
        ) {
            return 'BULL_FLAG_BREAKOUT_1M';
        }

        if (
            $entryLow > $recentLow
            && $breaksPriorHigh
            && $greenBar
            && ($volRatio === null || $volRatio >= 1.20)
        ) {
            return 'HIGHER_LOW_BREAK_1M';
        }

        if (
            $breaksPriorHigh
            && $greenBar
            && ($volRatio === null || $volRatio >= 1.35)
        ) {
            return 'MOMENTUM_CONFIRM_1M';
        }

        return null;
    }

    private function calculateStop(
        string $trigger,
        float $entryPrice,
        float $entryLow,
        array $previousBars,
        ?float $vwap,
        ?float $atr
    ): array {
        $lastFive = array_slice($previousBars, 0, 5);

        $priorLows = array_values(array_filter(array_map(
            fn ($bar) => $this->floatOrNull($bar->low),
            $lastFive
        ), static fn ($value) => $value !== null));

        $recentLow = ! empty($priorLows) ? min($priorLows) : $entryLow;
        $buffer = max($entryPrice * 0.0025, $atr !== null ? $atr * 0.20 : 0.0);

        $stopType = 'technical';

        $stop = match ($trigger) {
            'VWAP_RECLAIM_1M' => $this->stopBelowLevel(
                level: $vwap !== null && $vwap > 0 ? min($vwap, $entryLow) : $entryLow,
                buffer: $buffer,
                fallbackEntry: $entryPrice
            ),
            'VWAP_PULLBACK_HOLD_1M' => $this->stopBelowLevel(
                level: $vwap !== null && $vwap > 0 ? min($vwap, $entryLow) : $entryLow,
                buffer: $buffer,
                fallbackEntry: $entryPrice
            ),
            'BULL_FLAG_BREAKOUT_1M' => $this->stopBelowLevel(
                level: $recentLow,
                buffer: $buffer,
                fallbackEntry: $entryPrice
            ),
            'HIGHER_LOW_BREAK_1M' => $this->stopBelowLevel(
                level: min($entryLow, $recentLow),
                buffer: $buffer,
                fallbackEntry: $entryPrice
            ),
            'MOMENTUM_CONFIRM_1M' => $this->stopBelowLevel(
                level: min($entryLow, $recentLow),
                buffer: $buffer,
                fallbackEntry: $entryPrice
            ),
            default => max(0.01, round($entryPrice - max($entryPrice * 0.008, $atr !== null ? $atr * 1.0 : 0.0), 4)),
        };

        if (in_array($trigger, ['VWAP_RECLAIM_1M', 'VWAP_PULLBACK_HOLD_1M'], true)) {
            $stopType = 'below_vwap_or_entry_low';
        } elseif ($trigger === 'BULL_FLAG_BREAKOUT_1M') {
            $stopType = 'below_flag_low';
        } elseif ($trigger === 'HIGHER_LOW_BREAK_1M') {
            $stopType = 'below_higher_low';
        } elseif ($trigger === 'MOMENTUM_CONFIRM_1M') {
            $stopType = 'below_recent_low';
        }

        if ($stop >= $entryPrice) {
            $fallbackRisk = max($entryPrice * 0.006, $atr !== null ? $atr * 0.90 : 0.0);
            $stop = max(0.01, round($entryPrice - $fallbackRisk, 4));
            $stopType .= '_fallback';
        }

        return [
            'stop' => $stop,
            'stop_type' => $stopType,
        ];
    }

    private function stopBelowLevel(float $level, float $buffer, float $fallbackEntry): float
    {
        if ($level <= 0 || $level >= $fallbackEntry) {
            return max(0.01, round($fallbackEntry * 0.992, 4));
        }

        return max(0.01, round($level - $buffer, 4));
    }

    private function scoreEntry(
        string $trigger,
        int $daysAppeared,
        float $maxGainPct,
        ?float $volRatio,
        float $riskPct,
        ?float $vwapDistPct,
        ?bool $emaTrendOk
    ): float {
        $triggerBonus = match ($trigger) {
            'VWAP_PULLBACK_HOLD_1M' => 22.0,
            'VWAP_RECLAIM_1M' => 20.0,
            'BULL_FLAG_BREAKOUT_1M' => 18.0,
            'HIGHER_LOW_BREAK_1M' => 16.0,
            'MOMENTUM_CONFIRM_1M' => 14.0,
            default => 0.0,
        };

        $score = 40.0;
        $score += $triggerBonus;
        $score += min(15.0, $daysAppeared * 3.0);
        $score += min(15.0, $maxGainPct * 0.25);
        $score += $volRatio !== null ? min(15.0, $volRatio * 4.0) : 0.0;

        if ($riskPct >= 0.40 && $riskPct <= 1.30) {
            $score += 12.0;
        } elseif ($riskPct > 1.60) {
            $score -= 10.0;
        }

        if ($vwapDistPct !== null) {
            if ($vwapDistPct >= 0.0 && $vwapDistPct <= 1.25) {
                $score += 8.0;
            } elseif ($vwapDistPct > 1.75) {
                $score -= 10.0;
            }
        }

        if ($emaTrendOk === true) {
            $score += 6.0;
        }

        return round(max(0.0, min(100.0, $score)), 3);
    }

    private function buildTargets(float $entryPrice, float $riskPerShare): array
    {
        $riskPerShare = max(0.01, $riskPerShare);

        return [
            '1R' => round($entryPrice + $riskPerShare, 4),
            '2R' => round($entryPrice + ($riskPerShare * 2), 4),
            '3R' => round($entryPrice + ($riskPerShare * 3), 4),
            '3pct' => round($entryPrice * 1.03, 4),
            '4pct' => round($entryPrice * 1.04, 4),
            '5pct' => round($entryPrice * 1.05, 4),
        ];
    }

    private function getUniverseStats(string $symbol): array
    {
        $rows = $this->dbSelect('
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
              AND p.symbol = ?
            GROUP BY p.symbol
            LIMIT 1
        ', [$symbol]);

        if (empty($rows)) {
            return [];
        }

        return [
            'days_appeared' => (int) $rows[0]->days_appeared,
            'max_gain_pct' => (float) $rows[0]->max_gain_pct,
        ];
    }

    private function secondsBetween(string $fromTsEst, string $toTsEst): ?int
    {
        $from = strtotime($fromTsEst);
        $to = strtotime($toTsEst);

        if ($from === false || $to === false) {
            return null;
        }

        return max(0, $to - $from);
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
