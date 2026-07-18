<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Log;

/**
 * Version 57.0 - One-minute ORB retest entry finder.
 *
 * A valid entry requires:
 * 1. a completed 15-minute opening range;
 * 2. a prior one-minute close above the opening-range high;
 * 3. a later pullback into the OR-high retest zone; and
 * 4. a reclaim, confirmation break, or higher-low hold above that level.
 *
 * This deliberately avoids buying the first breakout candle.
 */
class OneMinuteEntryFinderV57_0
{
    use HasPriceTables;

    private string $version = 'v57.0';

    // All v57.0 entry settings are intentionally kept in this class.
    public bool $debug = false;

    public int $debugEvery = 100;

    public int $marketOpenMinute = 570;       // 09:30 ET

    public int $openingRangeEndMinute = 585;  // 09:45 ET, exclusive

    public int $entryStartMinute = 586;       // 09:46 ET

    public int $entryEndMinute = 705;         // 11:45 ET

    public int $minimumOpeningRangeBars = 10;

    public int $minBars = 18;

    public int $atrPeriod1m = 14;

    public int $volLookback1m = 20;

    public int $maxEntryAgeMinutes = 5;

    public int $maxSignalAgeMinutes = 35;

    public int $maxBarsAfterBreakout = 20;

    public int $retestLookbackBars = 8;

    public float $breakoutCloseBufferPct = 0.03;

    public float $retestZoneAboveOrbPct = 0.30;

    public float $maxRetestBelowOrbPct = 0.55;

    public float $retestCloseToleranceBelowPct = 0.12;

    public float $reclaimCloseBufferPct = 0.015;

    public float $confirmationBreakBufferPct = 0.005;

    public float $minNotional1m = 25000.0;

    public float $minAtrPct = 0.10;

    public float $maxAtrPct = 4.00;

    public float $maxEntryExtensionAboveOrbPct = 1.50;

    public float $maxEntryExtensionAboveOrbAtr = 1.75;

    public float $maxAboveVwapPct = 4.00;

    public float $minimumEmaSpreadPct = -0.10;

    public float $minReclaimVolumeRatio = 0.70;

    public float $minConfirmationVolumeRatio = 0.80;

    public float $minHigherLowVolumeRatio = 0.70;

    public float $minReclaimClosePosition = 0.55;

    public float $minConfirmationClosePosition = 0.58;

    public float $minHigherLowClosePosition = 0.52;

    public float $minBodyRangeFraction = 0.22;

    public float $maxUpperWickFraction = 0.48;

    public float $stopAtrBuffer = 0.18;

    public float $stopPriceBufferPct = 0.04;

    public float $minStopPct = 0.80;

    public float $maxStopPct = 2.20;

    public float $minRoomToBreakoutHighR = 0.30;

    public float $breakoutHighTolerancePct = 0.03;

    public float $trailingStopAtrMultiplier = 4.0;

    public float $minTrailingStopPct = 1.00;

    public float $maxTrailingStopPct = 2.40;

    public float $minReclaimScore = 43.0;

    public float $minConfirmationScore = 45.0;

    public float $minHigherLowScore = 47.0;

    /** @var array<string, int> */
    private static array $dbg = [
        'called' => 0,
        'time_blocked' => 0,
        'signal_stale' => 0,
        'not_enough_bars' => 0,
        'bad_data' => 0,
        'opening_range_failed' => 0,
        'before_signal' => 0,
        'no_prior_breakout' => 0,
        'breakout_too_old' => 0,
        'no_retest' => 0,
        'trend_failed' => 0,
        'liquidity_failed' => 0,
        'extension_failed' => 0,
        'quality_failed' => 0,
        'reclaim_detected' => 0,
        'confirmation_detected' => 0,
        'higher_low_detected' => 0,
        'risk_failed' => 0,
        'resistance_failed' => 0,
        'score_failed' => 0,
        'returned' => 0,
    ];

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setFullTable(bool $full): void
    {
        $this->fiveMinuteTable = $full ? 'five_minute_prices_full' : 'five_minute_prices';
        $this->oneMinuteTable = $full ? 'one_minute_prices_full' : 'one_minute_prices';
    }

    /** @return array<string, int|float|bool> */
    public function entryConfig(): array
    {
        return [
            'debug' => $this->debug,
            'debug_every' => $this->debugEvery,
            'market_open_minute' => $this->marketOpenMinute,
            'opening_range_end_minute' => $this->openingRangeEndMinute,
            'entry_start_minute' => $this->entryStartMinute,
            'entry_end_minute' => $this->entryEndMinute,
            'minimum_opening_range_bars' => $this->minimumOpeningRangeBars,
            'min_bars' => $this->minBars,
            'atr_period_1m' => $this->atrPeriod1m,
            'vol_lookback_1m' => $this->volLookback1m,
            'max_entry_age_minutes' => $this->maxEntryAgeMinutes,
            'max_signal_age_minutes' => $this->maxSignalAgeMinutes,
            'max_bars_after_breakout' => $this->maxBarsAfterBreakout,
            'retest_lookback_bars' => $this->retestLookbackBars,
            'breakout_close_buffer_pct' => $this->breakoutCloseBufferPct,
            'retest_zone_above_orb_pct' => $this->retestZoneAboveOrbPct,
            'max_retest_below_orb_pct' => $this->maxRetestBelowOrbPct,
            'retest_close_tolerance_below_pct' => $this->retestCloseToleranceBelowPct,
            'reclaim_close_buffer_pct' => $this->reclaimCloseBufferPct,
            'min_notional_1m' => $this->minNotional1m,
            'min_atr_pct' => $this->minAtrPct,
            'max_atr_pct' => $this->maxAtrPct,
            'max_entry_extension_above_orb_pct' => $this->maxEntryExtensionAboveOrbPct,
            'max_entry_extension_above_orb_atr' => $this->maxEntryExtensionAboveOrbAtr,
            'max_above_vwap_pct' => $this->maxAboveVwapPct,
            'minimum_ema_spread_pct' => $this->minimumEmaSpreadPct,
            'min_reclaim_volume_ratio' => $this->minReclaimVolumeRatio,
            'min_confirmation_volume_ratio' => $this->minConfirmationVolumeRatio,
            'min_higher_low_volume_ratio' => $this->minHigherLowVolumeRatio,
            'min_stop_pct' => $this->minStopPct,
            'max_stop_pct' => $this->maxStopPct,
            'trailing_stop_atr_multiplier' => $this->trailingStopAtrMultiplier,
            'min_trailing_stop_pct' => $this->minTrailingStopPct,
            'max_trailing_stop_pct' => $this->maxTrailingStopPct,
            'min_reclaim_score' => $this->minReclaimScore,
            'min_confirmation_score' => $this->minConfirmationScore,
            'min_higher_low_score' => $this->minHigherLowScore,
        ];
    }

    /** @return array<string, mixed> */
    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        ...$rest
    ): array {
        self::$dbg['called']++;

        [$entry, $reason] = $this->findEntry($symbol, $assetType, $signalTsEst, $asOfTsEst);
        if ($entry === null) {
            $this->maybeLogDebug();

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

        self::$dbg['returned']++;
        $this->maybeLogDebug();

        $entry['symbol'] = $symbol;
        $entry['asset_type'] = $assetType;
        $entry['signal_ts_est'] = $signalTsEst;

        return [
            'ok' => 1,
            'best_entry' => $entry,
            'meta' => [
                'version' => $this->version,
                'pattern' => 'OPENING_RANGE_BREAKOUT_RETEST',
                'as_of_ts_est' => $asOfTsEst,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function findBestShort(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        ...$rest
    ): array {
        return ['ok' => 0, 'best_entry' => null, 'reason' => 'short_not_implemented'];
    }

    /** @return array{0: array<string, mixed>|null, 1: string} */
    private function findEntry(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst
    ): array {
        if (! $this->isAllowedTime($asOfTsEst)) {
            self::$dbg['time_blocked']++;

            return [null, 'time_blocked'];
        }

        $asOfEpoch = strtotime($asOfTsEst);
        $signalEpoch = strtotime($signalTsEst);
        if ($asOfEpoch === false || $signalEpoch === false) {
            return [null, 'invalid_timestamp'];
        }

        $signalAgeSeconds = $asOfEpoch - $signalEpoch;
        if ($signalAgeSeconds < 0 || $signalAgeSeconds > ($this->maxSignalAgeMinutes * 60)) {
            self::$dbg['signal_stale']++;

            return [null, 'signal_stale'];
        }

        $tradeDate = substr($signalTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';
        $table = $this->oneMinuteTable;

        $rows = $this->dbSelect("
            SELECT ts_est, `open`, high, low, price AS close, volume
            FROM {$table}
            WHERE asset_type = ?
              AND symbol = ?
              AND trading_date_est = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ", [$assetType, $symbol, $tradeDate, $marketOpen, $asOfTsEst]);

        $minimumBars = max($this->minBars, $this->minimumOpeningRangeBars + 3);
        if (count($rows) < $minimumBars) {
            self::$dbg['not_enough_bars']++;

            return [null, 'not_enough_bars'];
        }

        $bars = $this->normalizeBars(
            $rows,
            max(5, $this->atrPeriod1m),
            max(5, $this->volLookback1m)
        );
        if ($bars === null || count($bars) < $minimumBars) {
            self::$dbg['bad_data']++;

            return [null, 'bad_data'];
        }

        $openingIndexes = [];
        foreach ($bars as $index => $bar) {
            $minute = (int) $bar['minute'];
            if ($minute >= $this->marketOpenMinute && $minute < $this->openingRangeEndMinute) {
                $openingIndexes[] = $index;
            }
        }
        if (count($openingIndexes) < $this->minimumOpeningRangeBars) {
            self::$dbg['opening_range_failed']++;

            return [null, 'opening_range_failed'];
        }

        $openingStart = min($openingIndexes);
        $openingEnd = max($openingIndexes);
        $orbHigh = $this->maxField($bars, $openingStart, $openingEnd, 'high');
        $orbLow = $this->minField($bars, $openingStart, $openingEnd, 'low');
        if ($orbHigh <= 0 || $orbLow <= 0 || $orbHigh <= $orbLow) {
            self::$dbg['opening_range_failed']++;

            return [null, 'opening_range_failed'];
        }

        $lastIndex = count($bars) - 1;
        $earliestCandidate = max(
            $openingEnd + 2,
            $lastIndex - (max(1, $this->maxEntryAgeMinutes) + 1)
        );
        $candidates = [];

        for ($i = $lastIndex; $i >= $earliestCandidate; $i--) {
            $entryTs = (string) $bars[$i]['ts'];
            if (! $this->isAllowedTime($entryTs)) {
                continue;
            }

            $entryEpoch = strtotime($entryTs);
            if ($entryEpoch === false) {
                continue;
            }

            $entryAgeSeconds = $asOfEpoch - $entryEpoch;
            if ($entryAgeSeconds < 0 || $entryAgeSeconds > ($this->maxEntryAgeMinutes * 60)) {
                continue;
            }
            if ($entryEpoch < $signalEpoch) {
                self::$dbg['before_signal']++;

                continue;
            }

            $breakoutIndex = $this->findLatestBreakoutIndex($bars, $openingEnd + 1, $i - 1, $orbHigh);
            if ($breakoutIndex === null) {
                self::$dbg['no_prior_breakout']++;

                continue;
            }
            if (($i - $breakoutIndex) > $this->maxBarsAfterBreakout) {
                self::$dbg['breakout_too_old']++;

                continue;
            }

            $retest = $this->findRetest($bars, $breakoutIndex + 1, $i, $orbHigh);
            if ($retest === null) {
                self::$dbg['no_retest']++;

                continue;
            }

            $bar = $bars[$i];
            $open = (float) $bar['open'];
            $high = (float) $bar['high'];
            $low = (float) $bar['low'];
            $close = (float) $bar['close'];
            $volume = (float) $bar['volume'];
            $atr = (float) $bar['atr'];
            $vwap = (float) $bar['vwap'];
            $ema9 = (float) $bar['ema9'];
            $ema21 = (float) $bar['ema21'];
            $volumeRatio = (float) $bar['volume_ratio'];

            if ($close <= 0 || $atr <= 0 || $vwap <= 0 || $ema9 <= 0 || $ema21 <= 0) {
                continue;
            }

            $range = max(1e-9, $high - $low);
            $bodyRangeFraction = abs($close - $open) / $range;
            $closePosition = ($close - $low) / $range;
            $upperWickFraction = ($high - max($open, $close)) / $range;
            $notional = $close * $volume;
            $atrPct = ($atr / $close) * 100.0;
            $aboveVwapPct = (($close - $vwap) / $vwap) * 100.0;
            $emaSpreadPct = (($ema9 - $ema21) / $ema21) * 100.0;
            $extensionAboveOrbPct = (($close - $orbHigh) / $orbHigh) * 100.0;
            $extensionAboveOrbAtr = max(0.0, ($close - $orbHigh) / $atr);
            $move1mPct = $i > 0 && (float) $bars[$i - 1]['close'] > 0
                ? (($close - (float) $bars[$i - 1]['close']) / (float) $bars[$i - 1]['close']) * 100.0
                : 0.0;
            $move3mPct = $this->movePct($bars, max(0, $i - 3), $i);

            $trendOk =
                $close >= ($orbHigh * (1.0 + ($this->reclaimCloseBufferPct / 100.0)))
                && $close > $vwap
                && $close >= ($ema9 * 0.996)
                && (
                    $emaSpreadPct >= $this->minimumEmaSpreadPct
                    || $move3mPct >= 0.25
                );
            if (! $trendOk) {
                self::$dbg['trend_failed']++;

                continue;
            }

            if ($notional < $this->minNotional1m) {
                self::$dbg['liquidity_failed']++;

                continue;
            }

            if (
                $atrPct < $this->minAtrPct
                || $atrPct > $this->maxAtrPct
                || $extensionAboveOrbPct > $this->maxEntryExtensionAboveOrbPct
                || $extensionAboveOrbAtr > $this->maxEntryExtensionAboveOrbAtr
                || $aboveVwapPct > $this->maxAboveVwapPct
            ) {
                self::$dbg['extension_failed']++;

                continue;
            }

            $trigger = null;
            $requiredScore = 0.0;
            $minimumClosePosition = 0.0;
            $minimumVolumeRatio = 0.0;
            $retestIndex = (int) $retest['index'];
            $previous = $i > 0 ? $bars[$i - 1] : null;

            $currentTouchedLevel = $retestIndex === $i;
            if (
                $currentTouchedLevel
                && $close > $open
                && $closePosition >= $this->minReclaimClosePosition
                && $volumeRatio >= $this->minReclaimVolumeRatio
            ) {
                $trigger = 'ORB_RETEST_RECLAIM_1M';
                $requiredScore = $this->minReclaimScore;
                $minimumClosePosition = $this->minReclaimClosePosition;
                $minimumVolumeRatio = $this->minReclaimVolumeRatio;
                self::$dbg['reclaim_detected']++;
            }

            if ($trigger === null && $retestIndex < $i) {
                $confirmationLevel = $this->maxField($bars, $retestIndex, $i - 1, 'high');
                $confirmationThreshold = $confirmationLevel
                    * (1.0 + ($this->confirmationBreakBufferPct / 100.0));
                if (
                    $close >= $confirmationThreshold
                    && $close > $open
                    && $closePosition >= $this->minConfirmationClosePosition
                    && $volumeRatio >= $this->minConfirmationVolumeRatio
                ) {
                    $trigger = 'ORB_RETEST_CONFIRMATION_BREAK_1M';
                    $requiredScore = $this->minConfirmationScore;
                    $minimumClosePosition = $this->minConfirmationClosePosition;
                    $minimumVolumeRatio = $this->minConfirmationVolumeRatio;
                    self::$dbg['confirmation_detected']++;
                }
            }

            if ($trigger === null && $retestIndex < $i && $previous !== null) {
                $higherLow = $low > (float) $previous['low'];
                $progress = $close > (float) $previous['close'];
                $nearLevel = $low <= ($orbHigh * (1.0 + (($this->retestZoneAboveOrbPct + 0.15) / 100.0)));
                if (
                    $higherLow
                    && $progress
                    && $nearLevel
                    && $closePosition >= $this->minHigherLowClosePosition
                    && $volumeRatio >= $this->minHigherLowVolumeRatio
                ) {
                    $trigger = 'ORB_RETEST_HIGHER_LOW_HOLD_1M';
                    $requiredScore = $this->minHigherLowScore;
                    $minimumClosePosition = $this->minHigherLowClosePosition;
                    $minimumVolumeRatio = $this->minHigherLowVolumeRatio;
                    self::$dbg['higher_low_detected']++;
                }
            }

            if ($trigger === null) {
                self::$dbg['quality_failed']++;

                continue;
            }

            if (
                $bodyRangeFraction < $this->minBodyRangeFraction
                || $closePosition < $minimumClosePosition
                || $upperWickFraction > $this->maxUpperWickFraction
                || $volumeRatio < $minimumVolumeRatio
            ) {
                self::$dbg['quality_failed']++;

                continue;
            }

            $retestLow = (float) $retest['low'];
            $structureLow = $this->minField($bars, $retestIndex, $i, 'low');
            $buffer = max(
                $atr * $this->stopAtrBuffer,
                $close * ($this->stopPriceBufferPct / 100.0)
            );
            $stop = max(0.01, min($structureLow, $orbHigh) - $buffer);
            $riskPerShare = $close - $stop;
            $riskPct = $riskPerShare > 0 ? ($riskPerShare / $close) * 100.0 : 0.0;

            if ($riskPct > 0 && $riskPct < $this->minStopPct) {
                $stop = max(0.01, $close * (1.0 - ($this->minStopPct / 100.0)));
                $riskPerShare = $close - $stop;
                $riskPct = ($riskPerShare / $close) * 100.0;
            }

            if ($riskPerShare <= 0 || $riskPct < $this->minStopPct || $riskPct > $this->maxStopPct) {
                self::$dbg['risk_failed']++;

                continue;
            }

            $breakoutHigh = $this->maxField($bars, $breakoutIndex, max($breakoutIndex, $i - 1), 'high');
            $breaksBreakoutHigh = $close >= ($breakoutHigh * (1.0 - ($this->breakoutHighTolerancePct / 100.0)));
            $roomToBreakoutHighR = $breakoutHigh > $close
                ? ($breakoutHigh - $close) / $riskPerShare
                : 999.0;
            if (! $breaksBreakoutHigh && $roomToBreakoutHighR < $this->minRoomToBreakoutHighR) {
                self::$dbg['resistance_failed']++;

                continue;
            }

            $breakoutBar = $bars[$breakoutIndex];
            $retestDepthPct = (($orbHigh - $retestLow) / $orbHigh) * 100.0;
            $scoreParts = $this->scoreEntry(
                $trigger,
                (float) $breakoutBar['volume_ratio'],
                $volumeRatio,
                $retestDepthPct,
                $closePosition,
                $bodyRangeFraction,
                $upperWickFraction,
                $emaSpreadPct,
                $aboveVwapPct,
                $riskPct,
                $move1mPct,
                $move3mPct
            );

            if ((float) $scoreParts['score'] < $requiredScore) {
                self::$dbg['score_failed']++;

                continue;
            }

            $rawTrail = $atr * $this->trailingStopAtrMultiplier;
            $minTrail = $close * ($this->minTrailingStopPct / 100.0);
            $maxTrail = $close * ($this->maxTrailingStopPct / 100.0);
            $suggestedTrail = max($minTrail, min($rawTrail, $maxTrail));

            $candidates[] = [
                'type' => $trigger,
                'pattern' => 'OPENING_RANGE_BREAKOUT_RETEST',
                'entry_ts_est' => $entryTs,
                'entry' => round($close, 6),
                'stop' => round($stop, 6),
                'risk_per_share' => round($riskPerShare, 6),
                'risk_pct' => round($riskPct, 4),
                'score' => $scoreParts['score'],
                'vol_ratio' => round($volumeRatio, 4),
                'entry_volume_ratio' => round($volumeRatio, 4),
                'atr' => round($atr, 6),
                'atr_pct' => round($atrPct, 4),
                'vwap' => round($vwap, 6),
                'above_vwap_pct' => round($aboveVwapPct, 4),
                'ema9' => round($ema9, 6),
                'ema21' => round($ema21, 6),
                'ema9_ema21_spread_pct' => round($emaSpreadPct, 4),
                'suggested_trailing_stop' => round($suggestedTrail, 6),
                'suggested_trailing_stop_pct' => round(($suggestedTrail / $close) * 100.0, 4),
                'targets' => [
                    'breakout_high' => round($breakoutHigh, 6),
                    '1R' => round($close + $riskPerShare, 6),
                    '2R' => round($close + (2.0 * $riskPerShare), 6),
                    '3R' => round($close + (3.0 * $riskPerShare), 6),
                    '3pct' => round($close * 1.03, 6),
                    '4pct' => round($close * 1.04, 6),
                    '5pct' => round($close * 1.05, 6),
                ],
                'entry_age_seconds' => $entryAgeSeconds,
                'signal_age_seconds' => $signalAgeSeconds,
                'meta' => [
                    'version' => $this->version,
                    'trigger' => $trigger,
                    'opening_range_minutes' => 15,
                    'orb_high' => round($orbHigh, 6),
                    'orb_low' => round($orbLow, 6),
                    'opening_range_pct' => round((($orbHigh - $orbLow) / (($orbHigh + $orbLow) / 2.0)) * 100.0, 4),
                    'breakout_ts_est' => (string) $breakoutBar['ts'],
                    'breakout_close' => round((float) $breakoutBar['close'], 6),
                    'breakout_volume_ratio' => round((float) $breakoutBar['volume_ratio'], 4),
                    'breakout_high' => round($breakoutHigh, 6),
                    'retest_ts_est' => (string) $bars[$retestIndex]['ts'],
                    'retest_low' => round($retestLow, 6),
                    'retest_depth_pct' => round($retestDepthPct, 4),
                    'bars_breakout_to_entry' => $i - $breakoutIndex,
                    'bars_retest_to_entry' => $i - $retestIndex,
                    'room_to_breakout_high_r' => round($roomToBreakoutHighR, 4),
                    'breaks_breakout_high' => $breaksBreakoutHigh,
                    'entry_open' => round($open, 6),
                    'entry_high' => round($high, 6),
                    'entry_low' => round($low, 6),
                    'entry_volume' => $volume,
                    'entry_notional' => round($notional, 2),
                    'extension_above_orb_pct' => round($extensionAboveOrbPct, 4),
                    'extension_above_orb_atr' => round($extensionAboveOrbAtr, 4),
                    'move_1m_pct' => round($move1mPct, 4),
                    'move_3m_pct' => round($move3mPct, 4),
                    'body_range_fraction' => round($bodyRangeFraction, 4),
                    'close_position' => round($closePosition, 4),
                    'upper_wick_fraction' => round($upperWickFraction, 4),
                    'retest_score' => $scoreParts['retest_score'],
                    'confirmation_score' => $scoreParts['confirmation_score'],
                    'trend_score' => $scoreParts['trend_score'],
                    'risk_score' => $scoreParts['risk_score'],
                    'required_entry_score' => $requiredScore,
                ],
            ];
        }

        if ($candidates === []) {
            return [null, 'no_valid_v57_orb_retest_entry'];
        }

        usort($candidates, static function (array $a, array $b): int {
            $ageCompare = ($a['entry_age_seconds'] ?? PHP_INT_MAX) <=> ($b['entry_age_seconds'] ?? PHP_INT_MAX);
            if ($ageCompare !== 0) {
                return $ageCompare;
            }

            return ($b['score'] ?? 0.0) <=> ($a['score'] ?? 0.0);
        });

        $best = $candidates[0];
        if ($this->debug) {
            Log::info('[EntryFinderV57_0] accepted entry', [
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

    /**
     * @param  array<int, object>  $rows
     * @return array<int, array<string, int|float|string>>|null
     */
    private function normalizeBars(array $rows, int $atrPeriod, int $volLookback): ?array
    {
        $out = [];
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
            $volumeRatio = $avgPriorVolume > 0 ? $volume / $avgPriorVolume : 0.0;
            $volumes[] = $volume;

            $out[] = [
                'ts' => (string) $row->ts_est,
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
                'volume_ratio' => $volumeRatio,
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, int|float|string>>  $bars
     */
    private function findLatestBreakoutIndex(array $bars, int $start, int $end, float $orbHigh): ?int
    {
        if ($end < $start) {
            return null;
        }

        $threshold = $orbHigh * (1.0 + ($this->breakoutCloseBufferPct / 100.0));
        $latest = null;
        for ($i = max(1, $start); $i <= $end; $i++) {
            if ((int) $bars[$i]['minute'] < $this->openingRangeEndMinute) {
                continue;
            }

            $previousClose = (float) $bars[$i - 1]['close'];
            $close = (float) $bars[$i]['close'];
            if ($previousClose < $threshold && $close >= $threshold) {
                $latest = $i;
            }
        }

        return $latest;
    }

    /**
     * @param  array<int, array<string, int|float|string>>  $bars
     * @return array{index:int,low:float}|null
     */
    private function findRetest(array $bars, int $start, int $end, float $orbHigh): ?array
    {
        if ($end < $start) {
            return null;
        }

        $start = max($start, $end - max(1, $this->retestLookbackBars) + 1);
        $zoneHigh = $orbHigh * (1.0 + ($this->retestZoneAboveOrbPct / 100.0));
        $zoneLow = $orbHigh * (1.0 - ($this->maxRetestBelowOrbPct / 100.0));
        $minimumClose = $orbHigh * (1.0 - ($this->retestCloseToleranceBelowPct / 100.0));
        $latest = null;

        for ($i = $start; $i <= $end; $i++) {
            $low = (float) $bars[$i]['low'];
            $close = (float) $bars[$i]['close'];
            if ($low <= $zoneHigh && $low >= $zoneLow && $close >= $minimumClose) {
                $latest = ['index' => $i, 'low' => $low];
            }
        }

        return $latest;
    }

    private function scoreEntry(
        string $trigger,
        float $breakoutVolumeRatio,
        float $entryVolumeRatio,
        float $retestDepthPct,
        float $closePosition,
        float $bodyRangeFraction,
        float $upperWickFraction,
        float $emaSpreadPct,
        float $aboveVwapPct,
        float $riskPct,
        float $move1mPct,
        float $move3mPct
    ): array {
        $depthScore = 1.0 - $this->clamp(abs($retestDepthPct - 0.10) / 0.70);
        $breakoutVolumeScore = $this->clamp(($breakoutVolumeRatio - 0.60) / 2.20);
        $retestScore = 100.0 * ((0.62 * $depthScore) + (0.38 * $breakoutVolumeScore));

        $entryVolumeScore = $this->clamp(($entryVolumeRatio - 0.60) / 1.80);
        $closeScore = $this->clamp(($closePosition - 0.45) / 0.50);
        $bodyScore = $this->clamp(($bodyRangeFraction - 0.18) / 0.70);
        $wickScore = 1.0 - $this->clamp($upperWickFraction / max(0.01, $this->maxUpperWickFraction));
        $triggerBonus = match ($trigger) {
            'ORB_RETEST_CONFIRMATION_BREAK_1M' => 1.00,
            'ORB_RETEST_RECLAIM_1M' => 0.92,
            default => 0.80,
        };
        $confirmationScore = 100.0 * (
            (0.25 * $entryVolumeScore)
            + (0.25 * $closeScore)
            + (0.20 * $bodyScore)
            + (0.15 * $wickScore)
            + (0.15 * $triggerBonus)
        );

        $emaScore = $this->clamp(($emaSpreadPct + 0.10) / 0.55);
        $vwapScore = 1.0 - $this->clamp($aboveVwapPct / max(0.01, $this->maxAboveVwapPct));
        $move1Score = $this->clamp(($move1mPct + 0.02) / 0.60);
        $move3Score = $this->clamp(($move3mPct + 0.05) / 1.20);
        $trendScore = 100.0 * (
            (0.35 * $emaScore)
            + (0.25 * $vwapScore)
            + (0.18 * $move1Score)
            + (0.22 * $move3Score)
        );

        $riskCenter = 1.15;
        $riskScore = 100.0 * (1.0 - $this->clamp(abs($riskPct - $riskCenter) / 1.25));

        $score =
            (0.34 * $retestScore)
            + (0.31 * $confirmationScore)
            + (0.23 * $trendScore)
            + (0.12 * $riskScore);

        return [
            'score' => round($this->clamp($score, 0.0, 100.0), 3),
            'retest_score' => round($retestScore, 3),
            'confirmation_score' => round($confirmationScore, 3),
            'trend_score' => round($trendScore, 3),
            'risk_score' => round($riskScore, 3),
        ];
    }

    /** @param array<int, array<string, int|float|string>> $bars */
    private function maxField(array $bars, int $start, int $end, string $field): float
    {
        $values = [];
        for ($i = max(0, $start); $i <= min($end, count($bars) - 1); $i++) {
            $values[] = (float) $bars[$i][$field];
        }

        return $values === [] ? 0.0 : max($values);
    }

    /** @param array<int, array<string, int|float|string>> $bars */
    private function minField(array $bars, int $start, int $end, string $field): float
    {
        $values = [];
        for ($i = max(0, $start); $i <= min($end, count($bars) - 1); $i++) {
            $values[] = (float) $bars[$i][$field];
        }

        return $values === [] ? 0.0 : min($values);
    }

    /** @param array<int, array<string, int|float|string>> $bars */
    private function movePct(array $bars, int $start, int $end): float
    {
        if (! isset($bars[$start], $bars[$end])) {
            return 0.0;
        }

        $startClose = (float) $bars[$start]['close'];
        $endClose = (float) $bars[$end]['close'];

        return $startClose > 0 ? (($endClose - $startClose) / $startClose) * 100.0 : 0.0;
    }

    private function isAllowedTime(string $timestamp): bool
    {
        $minute = $this->minuteOfDay($timestamp);

        return $minute >= $this->entryStartMinute && $minute <= $this->entryEndMinute;
    }

    private function minuteOfDay(string $timestamp): int
    {
        $time = substr($timestamp, 11, 5);
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return ($hour * 60) + $minute;
    }

    private function maybeLogDebug(): void
    {
        if (! $this->debug) {
            return;
        }

        $every = max(1, $this->debugEvery);
        if ((self::$dbg['called'] % $every) !== 0) {
            return;
        }

        Log::info('[EntryFinderV57_0] debug counters', array_merge(self::$dbg, [
            'pid' => getmypid(),
        ]));
    }

    private function clamp(float $value, float $min = 0.0, float $max = 1.0): float
    {
        return max($min, min($max, $value));
    }
}
