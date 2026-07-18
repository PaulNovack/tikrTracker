<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Log;

/**
 * Version 103.0 - ORB retest entry finder.
 *
 * Rebuilt from scratch to keep the opening-range breakout structure,
 * accept more realistic pullbacks, and add a breakout-continuation
 * fallback when the day never produces a true retest.
 */
class OneMinuteEntryFinderV103_0
{
    use HasPriceTables;

    private string $version = 'v103.0';

    public bool $debug = false;

    public int $marketOpenMinute = 570;

    public int $openingRangeEndMinute = 585;

    public int $entryStartMinute = 586;

    public int $entryEndMinute = 705;

    public int $minimumOpeningRangeBars = 8;

    public int $atrPeriod1m = 14;

    public int $volLookback1m = 20;

    public int $maxSignalAgeMinutes = 30;

    public int $maxEntryAgeMinutes = 20;

    public int $maxBarsAfterBreakout = 24;

    public float $minNotional1m = 30000.0;

    public float $minAtrPct = 0.08;

    public float $maxAtrPct = 5.00;

    public float $breakoutCloseBufferPct = 0.03;

    public float $retestZoneAboveOrbPct = 0.75;

    public float $maxRetestBelowOrbPct = 1.15;

    public float $reclaimCloseBufferPct = 0.08;

    public float $minAboveVwapPct = -0.20;

    public float $maxAboveVwapPct = 3.00;

    public float $minimumEmaSpreadPct = -0.05;

    public float $maxEntryExtensionAboveOrbPct = 2.50;

    public float $maxEntryExtensionAboveOrbAtr = 2.50;

    public float $minRetestVolumeRatio = 0.90;

    public float $minConfirmationVolumeRatio = 0.95;

    public float $minHigherLowVolumeRatio = 0.90;

    public float $minContinuationVolumeRatio = 0.95;

    public float $minRetestClosePosition = 0.60;

    public float $minConfirmationClosePosition = 0.65;

    public float $minHigherLowClosePosition = 0.60;

    public float $minContinuationClosePosition = 0.66;

    public float $minBodyRangeFraction = 0.26;

    public float $maxUpperWickFraction = 0.45;

    public float $continuationMinExtensionPct = 0.25;

    public float $continuationMaxExtensionPct = 3.00;

    public float $continuationMaxExtensionAboveOrbAtr = 100.00;

    public float $continuationMinBodyRangeFraction = 0.28;

    public float $continuationMaxUpperWickFraction = 0.40;

    public float $stopAtrBuffer = 0.20;

    public float $stopPriceBufferPct = 0.05;

    public float $minStopPct = 0.60;

    public float $maxStopPct = 2.60;

    public float $trailingStopAtrMultiplier = 2.5;

    public float $minTrailingStopPct = 0.80;

    public float $maxTrailingStopPct = 2.25;

    public float $minRetestScore = 62.0;

    public float $minContinuationScore = 58.0;

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * @return array<string, int|float>
     */
    public function entryConfig(): array
    {
        return [
            'market_open_minute' => $this->marketOpenMinute,
            'opening_range_end_minute' => $this->openingRangeEndMinute,
            'entry_start_minute' => $this->entryStartMinute,
            'entry_end_minute' => $this->entryEndMinute,
            'minimum_opening_range_bars' => $this->minimumOpeningRangeBars,
            'atr_period_1m' => $this->atrPeriod1m,
            'vol_lookback_1m' => $this->volLookback1m,
            'max_signal_age_minutes' => $this->maxSignalAgeMinutes,
            'max_entry_age_minutes' => $this->maxEntryAgeMinutes,
            'max_bars_after_breakout' => $this->maxBarsAfterBreakout,
            'breakout_close_buffer_pct' => $this->breakoutCloseBufferPct,
            'retest_zone_above_orb_pct' => $this->retestZoneAboveOrbPct,
            'max_retest_below_orb_pct' => $this->maxRetestBelowOrbPct,
            'reclaim_close_buffer_pct' => $this->reclaimCloseBufferPct,
            'min_above_vwap_pct' => $this->minAboveVwapPct,
            'max_above_vwap_pct' => $this->maxAboveVwapPct,
            'minimum_ema_spread_pct' => $this->minimumEmaSpreadPct,
            'max_entry_extension_above_orb_pct' => $this->maxEntryExtensionAboveOrbPct,
            'max_entry_extension_above_orb_atr' => $this->maxEntryExtensionAboveOrbAtr,
            'min_retest_volume_ratio' => $this->minRetestVolumeRatio,
            'min_confirmation_volume_ratio' => $this->minConfirmationVolumeRatio,
            'min_higher_low_volume_ratio' => $this->minHigherLowVolumeRatio,
            'min_continuation_volume_ratio' => $this->minContinuationVolumeRatio,
            'min_retest_score' => $this->minRetestScore,
            'min_continuation_score' => $this->minContinuationScore,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        ...$rest
    ): array {
        $fillMethod = isset($rest[4]) ? (string) $rest[4] : 'next_open';

        [$entry, $reason] = $this->findEntry($symbol, $assetType, $signalTsEst, $asOfTsEst, $fillMethod);

        if ($entry === null) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => $reason,
                'meta' => [
                    'version' => $this->version,
                    'as_of_ts_est' => $asOfTsEst,
                ],
            ];
        }

        return [
            'ok' => 1,
            'best_entry' => $entry,
            'meta' => [
                'version' => $this->version,
                'pattern' => $entry['pattern'] ?? 'OPENING_RANGE_BREAKOUT_RETEST',
                'as_of_ts_est' => $asOfTsEst,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function findEntry(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        string $fillMethod
    ): array {
        $signalEpoch = strtotime($signalTsEst);
        $asOfEpoch = strtotime($asOfTsEst);

        if ($signalEpoch === false || $asOfEpoch === false) {
            return [null, 'invalid_timestamp'];
        }

        if ($asOfEpoch < $signalEpoch) {
            return [null, 'signal_stale'];
        }

        if (($asOfEpoch - $signalEpoch) > ($this->maxSignalAgeMinutes * 60)) {
            return [null, 'signal_stale'];
        }

        $tradeDate = substr($asOfTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';

        $rows = $this->dbSelect(
            "SELECT ts_est, `open`, high, low, price AS close, volume
             FROM {$this->oneMinuteTable}
             WHERE asset_type = ?
               AND symbol = ?
               AND trading_date_est = ?
               AND ts_est >= ?
               AND ts_est <= ?
             ORDER BY ts_est ASC",
            [$assetType, $symbol, $tradeDate, $marketOpen, $asOfTsEst]
        );

        $minimumBars = max($this->minimumOpeningRangeBars + 2, 12);
        if (count($rows) < $minimumBars) {
            return [null, 'not_enough_bars'];
        }

        $bars = $this->normalizeBars($rows, max(5, $this->atrPeriod1m), max(5, $this->volLookback1m));
        if ($bars === null || count($bars) < $minimumBars) {
            return [null, 'bad_data'];
        }

        $openingRange = $this->findOpeningRange($bars);
        if ($openingRange === null) {
            return [null, 'opening_range_failed'];
        }

        $orbHigh = $openingRange['orb_high'];
        $orbLow = $openingRange['orb_low'];
        $openingEndIndex = $openingRange['opening_end_index'];

        $searchStartIndex = $this->findFirstIndexAtOrAfter($bars, $signalTsEst);
        if ($searchStartIndex === null) {
            return [null, 'no_entry_window'];
        }

        $searchStartIndex = max($searchStartIndex, $openingEndIndex + 1);
        if ($searchStartIndex >= count($bars)) {
            return [null, 'no_entry_window'];
        }

        $retestCandidates = [];
        $continuationCandidates = [];
        $lastIndex = count($bars) - 1;

        for ($i = $lastIndex; $i >= $searchStartIndex; $i--) {
            $entryMinute = (int) $bars[$i]['minute'];
            if (! $this->isEntryMinute($entryMinute)) {
                continue;
            }

            $candidate = $this->evaluateEntryBar(
                $bars,
                $i,
                $openingEndIndex,
                $orbHigh,
                $orbLow,
                $asOfEpoch,
                $signalEpoch,
                $fillMethod
            );

            if ($candidate === null) {
                continue;
            }

            if ($candidate['family'] === 'retest') {
                $retestCandidates[] = $candidate;

                continue;
            }

            $continuationCandidates[] = $candidate;
        }

        if ($retestCandidates !== []) {
            usort($retestCandidates, static function (array $a, array $b): int {
                $ageCompare = ($a['entry_age_seconds'] ?? PHP_INT_MAX) <=> ($b['entry_age_seconds'] ?? PHP_INT_MAX);
                if ($ageCompare !== 0) {
                    return $ageCompare;
                }

                return ($b['score'] ?? 0.0) <=> ($a['score'] ?? 0.0);
            });

            $best = $retestCandidates[0];

            if ($this->debug) {
                Log::info('[EntryFinderV103_0] accepted retest entry', [
                    'symbol' => $symbol,
                    'signal_ts_est' => $signalTsEst,
                    'as_of_ts_est' => $asOfTsEst,
                    'entry_ts_est' => $best['entry_ts_est'] ?? null,
                    'type' => $best['type'] ?? null,
                    'entry' => $best['entry'] ?? null,
                    'stop' => $best['stop'] ?? null,
                    'risk_pct' => $best['risk_pct'] ?? null,
                    'score' => $best['score'] ?? null,
                    'pid' => getmypid(),
                ]);
            }

            return [$best, 'ok'];
        }

        if ($continuationCandidates !== []) {
            usort($continuationCandidates, static function (array $a, array $b): int {
                $ageCompare = ($a['entry_age_seconds'] ?? PHP_INT_MAX) <=> ($b['entry_age_seconds'] ?? PHP_INT_MAX);
                if ($ageCompare !== 0) {
                    return $ageCompare;
                }

                return ($b['score'] ?? 0.0) <=> ($a['score'] ?? 0.0);
            });

            $best = $continuationCandidates[0];

            if ($this->debug) {
                Log::info('[EntryFinderV103_0] accepted continuation entry', [
                    'symbol' => $symbol,
                    'signal_ts_est' => $signalTsEst,
                    'as_of_ts_est' => $asOfTsEst,
                    'entry_ts_est' => $best['entry_ts_est'] ?? null,
                    'type' => $best['type'] ?? null,
                    'entry' => $best['entry'] ?? null,
                    'stop' => $best['stop'] ?? null,
                    'risk_pct' => $best['risk_pct'] ?? null,
                    'score' => $best['score'] ?? null,
                    'pid' => getmypid(),
                ]);
            }

            return [$best, 'ok'];
        }

        return [null, 'no_valid_v103_orb_retest_or_continuation_entry'];
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<int, array<string, int|float|string>>|null
     */
    private function normalizeBars(array $rows, int $atrPeriod, int $volLookback): ?array
    {
        $bars = [];
        $ema9 = null;
        $ema21 = null;
        $k9 = 2.0 / 10.0;
        $k21 = 2.0 / 22.0;
        $cumPv = 0.0;
        $cumVolume = 0.0;
        $trueRanges = [];
        $volumes = [];
        $previousClose = null;

        foreach ($rows as $row) {
            $open = (float) $row->open;
            $high = (float) $row->high;
            $low = (float) $row->low;
            $close = (float) $row->close;
            $volume = max(0.0, (float) $row->volume);

            if (
                $open <= 0 || $high <= 0 || $low <= 0 || $close <= 0
                || $high < $low || $high < max($open, $close) || $low > min($open, $close)
            ) {
                return null;
            }

            $typical = ($high + $low + $close) / 3.0;
            $cumPv += $typical * $volume;
            $cumVolume += $volume;
            $vwap = $cumVolume > 0 ? $cumPv / $cumVolume : $close;

            $ema9 = $ema9 === null ? $close : (($close * $k9) + ($ema9 * (1.0 - $k9)));
            $ema21 = $ema21 === null ? $close : (($close * $k21) + ($ema21 * (1.0 - $k21)));

            $tr = $previousClose === null
                ? $high - $low
                : max($high - $low, abs($high - $previousClose), abs($low - $previousClose));
            $trueRanges[] = max(0.0, $tr);
            $previousClose = $close;

            $atrSlice = array_slice($trueRanges, -min($atrPeriod, count($trueRanges)));
            $atr = $atrSlice === [] ? 0.0 : array_sum($atrSlice) / count($atrSlice);

            $priorVolumes = array_slice($volumes, -min($volLookback, count($volumes)));
            $avgPriorVolume = $priorVolumes === [] ? 0.0 : array_sum($priorVolumes) / count($priorVolumes);
            $volumeRatio = $avgPriorVolume > 0 ? $volume / $avgPriorVolume : 1.0;
            $volumes[] = $volume;

            $range = max(1e-9, $high - $low);

            $bars[] = [
                'ts' => (string) $row->ts_est,
                'epoch' => strtotime((string) $row->ts_est) ?: 0,
                'minute' => $this->minuteOfDay((string) $row->ts_est),
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => $volume,
                'vwap' => $vwap,
                'ema9' => $ema9,
                'ema21' => $ema21,
                'atr' => $atr,
                'atr_pct' => ($close > 0 && $atr > 0) ? (($atr / $close) * 100.0) : 0.0,
                'volume_ratio' => $volumeRatio,
                'notional' => $close * $volume,
                'body_range_fraction' => abs($close - $open) / $range,
                'close_position' => ($close - $low) / $range,
                'upper_wick_fraction' => ($high - max($open, $close)) / $range,
            ];
        }

        return $bars;
    }

    /**
     * @param  array<int, array<string, int|float|string>>  $bars
     * @return array<string, float|int>|null
     */
    private function findOpeningRange(array $bars): ?array
    {
        $openingIndexes = [];

        foreach ($bars as $index => $bar) {
            $minute = (int) $bar['minute'];
            if ($minute >= $this->marketOpenMinute && $minute < $this->openingRangeEndMinute) {
                $openingIndexes[] = $index;
            }
        }

        if (count($openingIndexes) < $this->minimumOpeningRangeBars) {
            return null;
        }

        $openingStart = min($openingIndexes);
        $openingEnd = max($openingIndexes);
        $orbHigh = $this->maxField($bars, $openingStart, $openingEnd, 'high');
        $orbLow = $this->minField($bars, $openingStart, $openingEnd, 'low');

        if ($orbHigh <= 0 || $orbLow <= 0 || $orbHigh <= $orbLow) {
            return null;
        }

        return [
            'opening_start_index' => $openingStart,
            'opening_end_index' => $openingEnd,
            'orb_high' => $orbHigh,
            'orb_low' => $orbLow,
        ];
    }

    /**
     * @param  array<int, array<string, int|float|string>>  $bars
     */
    private function findFirstIndexAtOrAfter(array $bars, string $tsEst): ?int
    {
        foreach ($bars as $index => $bar) {
            if ((string) $bar['ts'] >= $tsEst) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, int|float|string>>  $bars
     */
    private function findLatestBreakoutIndex(array $bars, int $start, int $end, float $orbHigh): ?int
    {
        if ($end < $start) {
            return null;
        }

        for ($i = min($end, count($bars) - 1); $i >= max(0, $start); $i--) {
            $bar = $bars[$i];
            $close = (float) $bar['close'];
            $high = (float) $bar['high'];
            $open = (float) $bar['open'];
            $vwap = (float) $bar['vwap'];
            $ema9 = (float) $bar['ema9'];
            $ema21 = (float) $bar['ema21'];
            $volumeRatio = (float) $bar['volume_ratio'];
            $closePosition = (float) $bar['close_position'];
            $bodyFraction = (float) $bar['body_range_fraction'];

            if (
                $close >= ($orbHigh * (1.0 + ($this->breakoutCloseBufferPct / 100.0)))
                && $high >= $orbHigh
                && $close > $open
                && $vwap > 0
                && $close >= $vwap
                && $ema9 > 0
                && $ema21 > 0
                && $ema9 >= ($ema21 * 0.995)
                && $volumeRatio >= 0.50
                && $closePosition >= 0.45
                && $bodyFraction >= 0.18
            ) {
                return $i;
            }
        }

        return null;
    }

    private function isEntryMinute(int $minute): bool
    {
        return $minute >= $this->entryStartMinute && $minute <= $this->entryEndMinute;
    }

    /**
     * @param  array<int, array<string, int|float|string>>  $bars
     */
    private function findLatestRetestTouchIndex(array $bars, int $start, int $end, float $orbHigh): ?int
    {
        if ($end < $start) {
            return null;
        }

        for ($i = min($end, count($bars) - 1); $i >= max(0, $start); $i--) {
            $low = (float) $bars[$i]['low'];
            if ($this->touchesRetestZone($low, $orbHigh)) {
                return $i;
            }
        }

        return null;
    }

    private function touchesRetestZone(float $low, float $orbHigh): bool
    {
        return $low <= ($orbHigh * (1.0 + ($this->retestZoneAboveOrbPct / 100.0)))
            && $low >= ($orbHigh * (1.0 - ($this->maxRetestBelowOrbPct / 100.0)));
    }

    /**
     * @param  array<int, array<string, int|float|string>>  $bars
     * @return array<string, mixed>|null
     */
    private function evaluateEntryBar(
        array $bars,
        int $entryIndex,
        int $openingEndIndex,
        float $orbHigh,
        float $orbLow,
        int $asOfEpoch,
        int $signalEpoch,
        string $fillMethod
    ): ?array {
        $entryBar = $bars[$entryIndex];
        $entryClose = (float) $entryBar['close'];
        $entryOpen = (float) $entryBar['open'];
        $entryHigh = (float) $entryBar['high'];
        $entryLow = (float) $entryBar['low'];
        $entryVolume = (float) $entryBar['volume'];
        $entryNotional = (float) $entryBar['notional'];
        $atr = (float) $entryBar['atr'];
        $atrPct = (float) $entryBar['atr_pct'];
        $vwap = (float) $entryBar['vwap'];
        $ema9 = (float) $entryBar['ema9'];
        $ema21 = (float) $entryBar['ema21'];
        $volumeRatio = (float) $entryBar['volume_ratio'];
        $bodyFraction = (float) $entryBar['body_range_fraction'];
        $closePosition = (float) $entryBar['close_position'];
        $upperWickFraction = (float) $entryBar['upper_wick_fraction'];

        if ($entryClose <= 0 || $atr <= 0 || $vwap <= 0 || $ema9 <= 0 || $ema21 <= 0) {
            return null;
        }

        if ($entryNotional < $this->minNotional1m) {
            return null;
        }

        if ($atrPct < $this->minAtrPct || $atrPct > $this->maxAtrPct) {
            return null;
        }

        $aboveVwapPct = (($entryClose - $vwap) / $vwap) * 100.0;
        $emaSpreadPct = (($ema9 - $ema21) / $ema21) * 100.0;
        $extensionAboveOrbPct = (($entryClose - $orbHigh) / $orbHigh) * 100.0;
        $extensionAboveOrbAtr = max(0.0, ($entryClose - $orbHigh) / $atr);
        $move1mPct = $entryIndex > 0 && (float) $bars[$entryIndex - 1]['close'] > 0
            ? (($entryClose - (float) $bars[$entryIndex - 1]['close']) / (float) $bars[$entryIndex - 1]['close']) * 100.0
            : 0.0;
        $move3mPct = $this->movePct($bars, max(0, $entryIndex - 3), $entryIndex);

        if ($aboveVwapPct < $this->minAboveVwapPct || $aboveVwapPct > $this->maxAboveVwapPct) {
            return null;
        }

        $retestExtensionOk = $extensionAboveOrbPct <= $this->maxEntryExtensionAboveOrbPct
            && $extensionAboveOrbAtr <= $this->maxEntryExtensionAboveOrbAtr;
        $continuationExtensionOk = $extensionAboveOrbPct <= $this->continuationMaxExtensionPct
            && $extensionAboveOrbAtr <= $this->continuationMaxExtensionAboveOrbAtr;

        if ($emaSpreadPct < $this->minimumEmaSpreadPct && $entryClose < $ema21) {
            return null;
        }

        $breakoutIndex = $this->findLatestBreakoutIndex($bars, $openingEndIndex + 1, $entryIndex - 1, $orbHigh);
        if ($breakoutIndex === null) {
            return null;
        }

        $retestTouchIndex = $this->findLatestRetestTouchIndex($bars, $breakoutIndex + 1, $entryIndex, $orbHigh);

        if (($entryIndex - $breakoutIndex) > $this->maxBarsAfterBreakout) {
            return null;
        }

        $breakoutBar = $bars[$breakoutIndex];
        $breakoutHigh = $this->maxField($bars, $breakoutIndex, $entryIndex, 'high');
        $retestLow = $retestTouchIndex !== null
            ? $this->minField($bars, $retestTouchIndex, $entryIndex, 'low')
            : min($entryLow, (float) $breakoutBar['low']);
        $retestDepthPct = max(0.0, (($orbHigh - $retestLow) / $orbHigh) * 100.0);
        $barsBreakoutToEntry = $entryIndex - $breakoutIndex;
        $barsRetestToEntry = $retestTouchIndex !== null ? $entryIndex - $retestTouchIndex : null;

        $entryTsEst = $this->resolveEntryTs($bars, $entryIndex, $fillMethod);
        $entryEpoch = strtotime($entryTsEst);
        if ($entryEpoch === false) {
            return null;
        }

        $entryAgeSeconds = $asOfEpoch - $entryEpoch;
        if ($entryAgeSeconds < 0 || $entryAgeSeconds > ($this->maxEntryAgeMinutes * 60)) {
            return null;
        }

        $previousBar = $entryIndex > 0 ? $bars[$entryIndex - 1] : null;
        $family = null;
        $type = null;
        $requiredScore = 0.0;

        $touchesRetestZone = $retestTouchIndex !== null;
        $currentTouchesRetestZone = $this->touchesRetestZone($entryLow, $orbHigh);

        if (
            $touchesRetestZone
            && $currentTouchesRetestZone
            && $entryClose > $entryOpen
            && $closePosition >= $this->minRetestClosePosition
            && $volumeRatio >= $this->minRetestVolumeRatio
            && $retestExtensionOk
        ) {
            $family = 'retest';
            $type = 'ORB_RETEST_RECLAIM_1M_V103_0';
            $requiredScore = $this->minRetestScore;
        } elseif (
            $touchesRetestZone
            && $retestTouchIndex < $entryIndex
            && $entryClose > $entryOpen
            && $closePosition >= $this->minConfirmationClosePosition
            && $volumeRatio >= $this->minConfirmationVolumeRatio
            && $retestExtensionOk
            && $entryClose >= $this->maxField($bars, $retestTouchIndex, $entryIndex - 1, 'high') * 1.0003
        ) {
            $family = 'retest';
            $type = 'ORB_RETEST_CONFIRMATION_BREAK_1M_V103_0';
            $requiredScore = $this->minRetestScore;
        } elseif (
            $touchesRetestZone
            && $previousBar !== null
            && $entryLow > (float) $previousBar['low']
            && $entryClose > (float) $previousBar['close']
            && $closePosition >= $this->minHigherLowClosePosition
            && $volumeRatio >= $this->minHigherLowVolumeRatio
            && $retestExtensionOk
        ) {
            $family = 'retest';
            $type = 'ORB_RETEST_HIGHER_LOW_HOLD_1M_V103_0';
            $requiredScore = $this->minRetestScore;
        } elseif (
            $retestTouchIndex === null
            && $entryClose >= ($orbHigh * (1.0 + ($this->continuationMinExtensionPct / 100.0)))
            && $entryClose > $entryOpen
            && $entryHigh > (float) ($previousBar['high'] ?? $entryHigh)
            && $closePosition >= $this->minContinuationClosePosition
            && $bodyFraction >= $this->continuationMinBodyRangeFraction
            && $upperWickFraction <= $this->continuationMaxUpperWickFraction
            && $volumeRatio >= $this->minContinuationVolumeRatio
            && $continuationExtensionOk
        ) {
            $family = 'continuation';
            $type = 'ORB_BREAKOUT_CONTINUATION_1M_V103_0';
            $requiredScore = $this->minContinuationScore;
        }

        if ($family === null || $type === null) {
            return null;
        }

        $score = $family === 'retest'
            ? $this->scoreRetestCandidate($volumeRatio, $retestDepthPct, $closePosition, $bodyFraction, $upperWickFraction, $emaSpreadPct, $aboveVwapPct, $move1mPct, $move3mPct, $type)
            : $this->scoreContinuationCandidate($volumeRatio, $extensionAboveOrbPct, $closePosition, $bodyFraction, $upperWickFraction, $emaSpreadPct, $aboveVwapPct, $move1mPct, $move3mPct);

        if ($score < $requiredScore) {
            return null;
        }

        $buffer = max(
            $atr * $this->stopAtrBuffer,
            $entryClose * ($this->stopPriceBufferPct / 100.0)
        );

        $structureLow = $family === 'retest'
            ? min($retestLow, $entryLow, (float) $breakoutBar['low'], $orbLow)
            : min($entryLow, (float) $breakoutBar['low'], (float) ($previousBar['low'] ?? $entryLow));

        $stopPrice = max(0.01, $structureLow - $buffer);
        $riskPerShare = $entryClose - $stopPrice;
        $riskPct = $riskPerShare > 0 ? ($riskPerShare / $entryClose) * 100.0 : 0.0;

        if ($riskPct > 0 && $riskPct < $this->minStopPct) {
            $stopPrice = max(0.01, $entryClose * (1.0 - ($this->minStopPct / 100.0)));
            $riskPerShare = $entryClose - $stopPrice;
            $riskPct = $riskPerShare > 0 ? ($riskPerShare / $entryClose) * 100.0 : 0.0;
        }

        if ($riskPerShare <= 0 || $riskPct < $this->minStopPct || $riskPct > $this->maxStopPct) {
            return null;
        }

        $suggestedTrailingStop = $atr * $this->trailingStopAtrMultiplier;
        $minTrailingStop = $entryClose * ($this->minTrailingStopPct / 100.0);
        $maxTrailingStop = $entryClose * ($this->maxTrailingStopPct / 100.0);
        $suggestedTrailingStop = max($minTrailingStop, min($suggestedTrailingStop, $maxTrailingStop));

        $entryBodyPct = $entryOpen > 0 ? abs($entryClose - $entryOpen) / $entryOpen * 100.0 : 0.0;
        $entryNotional1m = $entryClose * $entryVolume;
        $entrySpreadStrength = $entryClose > 0
            ? $this->clamp(((($ema9 - $ema21) / $entryClose) - 0.0005) / (0.0030 - 0.0005))
            : 0.0;
        $entryVwapDistScore = max(0.0, 1.0 - (abs($aboveVwapPct - 0.15) / 0.30));
        $entryAtrScore = $this->clamp((($atrPct - 0.08) / (0.20 - 0.08)))
            * (1.0 - $this->clamp((($atrPct - 0.50) / (1.50 - 0.50))));
        $entryVolScore = $this->clamp(($volumeRatio - 0.8) / (2.5 - 0.8));
        $entryCandleScore = $this->clamp(($closePosition - 0.45) / (0.80 - 0.45));
        $entryTimeBonus = 0.0;
        $entryTimeOfDay = substr($entryTsEst, 11, 8);
        if ($entryTimeOfDay <= '10:30:00') {
            $entryTimeBonus = 1.0;
        } elseif ($entryTimeOfDay <= '11:00:00') {
            $entryTimeBonus = 0.5;
        }

        $vwapReclaimStrengthPct = max(0.0, $aboveVwapPct);
        $vwapReclaimWickBelowPct = $vwap > 0 ? max(0.0, (($vwap - min($entryLow, $entryOpen)) / $vwap) * 100.0) : 0.0;
        $ema9PullbackDepthPct = $ema9 > 0 ? max(0.0, (($ema9 - $entryLow) / $ema9) * 100.0) : 0.0;
        $ema9ReclaimPct = $ema9 > 0 ? max(0.0, (($entryClose - $ema9) / $ema9) * 100.0) : 0.0;

        return [
            'family' => $family,
            'type' => $type,
            'pattern' => 'OPENING_RANGE_BREAKOUT_RETEST',
            'entry_ts_est' => $entryTsEst,
            'entry' => round($entryClose, 6),
            'entry_price' => round($entryClose, 6),
            'stop' => round($stopPrice, 6),
            'stop_price' => round($stopPrice, 6),
            'risk_pct' => round($riskPct, 4),
            'risk_per_share' => round($riskPerShare, 6),
            'score' => round($score, 2),
            'vol_ratio' => round($volumeRatio, 4),
            'atr' => round($atr, 6),
            'atr_pct' => round($atrPct, 4),
            'suggested_trailing_stop' => round($suggestedTrailingStop, 6),
            'suggested_trailing_stop_pct' => round(($suggestedTrailingStop / $entryClose) * 100.0, 4),
            'entry_body_pct' => round($entryBodyPct, 4),
            'entry_close_position' => round($closePosition, 6),
            'entry_volume_ratio' => round($volumeRatio, 4),
            'entry_notional_1m' => round($entryNotional1m, 2),
            'entry_spread_strength' => round($entrySpreadStrength, 6),
            'entry_vwap_dist_score' => round($entryVwapDistScore, 6),
            'entry_atr_score' => round($entryAtrScore, 6),
            'entry_vol_score' => round($entryVolScore, 6),
            'entry_candle_score' => round($entryCandleScore, 6),
            'entry_time_bonus' => round($entryTimeBonus, 6),
            'vwap_reclaim_strength_pct' => round($vwapReclaimStrengthPct, 4),
            'vwap_reclaim_wick_below_pct' => round($vwapReclaimWickBelowPct, 4),
            'or_high_v252' => round($orbHigh, 8),
            'or_break_distance_pct' => round($extensionAboveOrbPct, 4),
            'or_retest_depth_pct' => round($retestDepthPct, 4),
            'or_hold_close_pct' => round(max(0.0, $extensionAboveOrbPct), 4),
            'bars_since_or_break' => $barsBreakoutToEntry,
            'ema9_pullback_depth_pct' => round($ema9PullbackDepthPct, 4),
            'ema9_reclaim_pct' => round($ema9ReclaimPct, 4),
            'targets' => [
                'breakout_high' => round($orbHigh, 6),
                'or_low' => round($orbLow, 6),
                'or_range' => round(max(0.0, $orbHigh - $orbLow), 6),
                '1R' => round($entryClose + $riskPerShare, 6),
                '2R' => round($entryClose + ($riskPerShare * 2.0), 6),
                '3R' => round($entryClose + ($riskPerShare * 3.0), 6),
            ],
            'entry_age_seconds' => $entryAgeSeconds,
            'signal_age_seconds' => $asOfEpoch - $signalEpoch,
            'meta' => [
                'version' => $this->version,
                'trigger' => $type,
                'family' => $family,
                'orb_high' => round($orbHigh, 6),
                'orb_low' => round($orbLow, 6),
                'breakout_ts_est' => (string) $breakoutBar['ts'],
                'breakout_close' => round((float) $breakoutBar['close'], 6),
                'breakout_volume_ratio' => round((float) $breakoutBar['volume_ratio'], 4),
                'bars_breakout_to_entry' => $barsBreakoutToEntry,
                'bars_retest_to_entry' => $barsRetestToEntry,
                'retest_ts_est' => $retestTouchIndex !== null ? (string) $bars[$retestTouchIndex]['ts'] : null,
                'retest_depth_pct' => round($retestDepthPct, 4),
                'current_close' => round($entryClose, 6),
                'current_open' => round($entryOpen, 6),
                'current_high' => round($entryHigh, 6),
                'current_low' => round($entryLow, 6),
                'current_volume' => $entryVolume,
                'current_notional' => round($entryNotional, 2),
                'vwap' => round($vwap, 6),
                'above_vwap_pct' => round($aboveVwapPct, 4),
                'ema9' => round($ema9, 6),
                'ema21' => round($ema21, 6),
                'ema_spread_pct' => round($emaSpreadPct, 4),
                'extension_above_orb_pct' => round($extensionAboveOrbPct, 4),
                'extension_above_orb_atr' => round($extensionAboveOrbAtr, 4),
                'move_1m_pct' => round($move1mPct, 4),
                'move_3m_pct' => round($move3mPct, 4),
                'body_range_fraction' => round($bodyFraction, 4),
                'close_position' => round($closePosition, 4),
                'upper_wick_fraction' => round($upperWickFraction, 4),
                'required_entry_score' => $requiredScore,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, int|float|string>>  $bars
     */
    private function resolveEntryTs(array $bars, int $entryIndex, string $fillMethod): string
    {
        if ($fillMethod === 'close') {
            return (string) $bars[$entryIndex]['ts'];
        }

        $nextIndex = $entryIndex + 1;
        if (! isset($bars[$nextIndex])) {
            return (string) $bars[$entryIndex]['ts'];
        }

        $nextBar = $bars[$nextIndex];
        $nextOpen = (float) $nextBar['open'];

        return $nextOpen > 0 ? (string) $nextBar['ts'] : (string) $bars[$entryIndex]['ts'];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function buildBestEntry(array $candidate, int $signalEpoch, int $asOfEpoch): array
    {
        $entryEpoch = strtotime((string) $candidate['entry_ts_est']);

        $entry = [
            'type' => $candidate['type'],
            'pattern' => $candidate['pattern'],
            'entry_ts_est' => $candidate['entry_ts_est'],
            'entry' => $candidate['entry'],
            'entry_price' => $candidate['entry_price'],
            'stop' => $candidate['stop'],
            'stop_price' => $candidate['stop_price'],
            'risk_pct' => $candidate['risk_pct'],
            'risk_per_share' => $candidate['risk_per_share'],
            'score' => $candidate['score'],
            'vol_ratio' => $candidate['vol_ratio'],
            'atr' => $candidate['atr'],
            'atr_pct' => $candidate['atr_pct'],
            'suggested_trailing_stop' => $candidate['suggested_trailing_stop'],
            'suggested_trailing_stop_pct' => $candidate['suggested_trailing_stop_pct'],
            'targets' => $candidate['targets'],
            'entry_age_seconds' => $candidate['entry_age_seconds'],
            'signal_age_seconds' => $candidate['signal_age_seconds'],
            'meta' => $candidate['meta'],
        ];

        if ($this->debug) {
            Log::info('[EntryFinderV103_0] entry candidate accepted', [
                'type' => $entry['type'],
                'entry_ts_est' => $entry['entry_ts_est'],
                'entry' => $entry['entry'],
                'stop' => $entry['stop'],
                'score' => $entry['score'],
                'signal_age_seconds' => $asOfEpoch - $signalEpoch,
                'entry_age_seconds' => $entryEpoch !== false ? $asOfEpoch - $entryEpoch : null,
                'pid' => getmypid(),
            ]);
        }

        return $entry;
    }

    /**
     * @param  array<int, array<string, int|float|string>>  $bars
     */
    private function maxField(array $bars, int $start, int $end, string $field): float
    {
        $max = null;

        for ($i = max(0, $start); $i <= min($end, count($bars) - 1); $i++) {
            $value = (float) $bars[$i][$field];
            $max = $max === null ? $value : max($max, $value);
        }

        return $max ?? 0.0;
    }

    /**
     * @param  array<int, array<string, int|float|string>>  $bars
     */
    private function minField(array $bars, int $start, int $end, string $field): float
    {
        $min = null;

        for ($i = max(0, $start); $i <= min($end, count($bars) - 1); $i++) {
            $value = (float) $bars[$i][$field];
            $min = $min === null ? $value : min($min, $value);
        }

        return $min ?? 0.0;
    }

    /**
     * @param  array<int, array<string, int|float|string>>  $bars
     */
    private function movePct(array $bars, int $start, int $end): float
    {
        if (! isset($bars[$start], $bars[$end])) {
            return 0.0;
        }

        $startClose = (float) $bars[$start]['close'];
        $endClose = (float) $bars[$end]['close'];

        if ($startClose <= 0) {
            return 0.0;
        }

        return (($endClose - $startClose) / $startClose) * 100.0;
    }

    private function scoreRetestCandidate(
        float $volumeRatio,
        float $retestDepthPct,
        float $closePosition,
        float $bodyRangeFraction,
        float $upperWickFraction,
        float $emaSpreadPct,
        float $aboveVwapPct,
        float $move1mPct,
        float $move3mPct,
        string $type
    ): float {
        $typeBoost = match ($type) {
            'ORB_RETEST_RECLAIM_1M_V103_0' => 1.00,
            'ORB_RETEST_CONFIRMATION_BREAK_1M_V103_0' => 0.97,
            'ORB_RETEST_HIGHER_LOW_HOLD_1M_V103_0' => 0.95,
            default => 0.92,
        };

        $score = 54.0
            + 14.0 * $this->clamp(($volumeRatio - 0.70) / 1.50)
            + 12.0 * $this->clamp(($closePosition - 0.48) / 0.52)
            + 10.0 * $this->clamp((1.25 - $retestDepthPct) / 1.25)
            + 8.0 * $this->clamp(($bodyRangeFraction - 0.20) / 0.45)
            + 6.0 * $this->clamp((0.55 - $upperWickFraction) / 0.55)
            + 6.0 * $this->clamp((($emaSpreadPct - $this->minimumEmaSpreadPct) + 0.10) / 0.70)
            + 4.0 * $this->clamp((0.50 + $move1mPct) / 1.00)
            + 4.0 * $this->clamp((0.75 + $move3mPct) / 1.50)
            + 2.0 * $this->clamp((2.50 - abs($aboveVwapPct)) / 2.50);

        return round(min(100.0, $score * $typeBoost), 2);
    }

    private function scoreContinuationCandidate(
        float $volumeRatio,
        float $extensionAboveOrbPct,
        float $closePosition,
        float $bodyRangeFraction,
        float $upperWickFraction,
        float $emaSpreadPct,
        float $aboveVwapPct,
        float $move1mPct,
        float $move3mPct
    ): float {
        $score = 49.0
            + 14.0 * $this->clamp(($volumeRatio - 0.85) / 1.25)
            + 12.0 * $this->clamp((2.00 - $extensionAboveOrbPct) / 2.00)
            + 10.0 * $this->clamp(($closePosition - 0.55) / 0.45)
            + 10.0 * $this->clamp(($bodyRangeFraction - 0.20) / 0.45)
            + 8.0 * $this->clamp((0.45 - $upperWickFraction) / 0.45)
            + 8.0 * $this->clamp((($emaSpreadPct - $this->minimumEmaSpreadPct) + 0.10) / 0.70)
            + 4.0 * $this->clamp((2.25 - abs($aboveVwapPct)) / 2.25)
            + 2.0 * $this->clamp((0.60 + $move1mPct) / 1.20)
            + 2.0 * $this->clamp((1.00 + $move3mPct) / 2.00);

        return round(min(100.0, $score), 2);
    }

    private function clamp(float $value, float $min = 0.0, float $max = 1.0): float
    {
        return max($min, min($max, $value));
    }

    private function minuteOfDay(string $tsEst): int
    {
        $hour = (int) substr($tsEst, 11, 2);
        $minute = (int) substr($tsEst, 14, 2);

        return ($hour * 60) + $minute;
    }
}
