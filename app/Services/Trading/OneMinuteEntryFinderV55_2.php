<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Log;

/**
 * Version 55.2 - Balanced Trend Compression Continuation entry finder.
 *
 * Supported long triggers:
 * - TIGHT_FLAG_BREAKOUT_1M: breaks a compact 3-8 bar range.
 * - EMA9_PIVOT_BREAK_1M: resumes after a higher-low pullback near EMA9.
 *
 * The finder avoids chasing by limiting the breakout distance, VWAP extension,
 * upper wick, structural stop width, and nearby session-high resistance.
 */
class OneMinuteEntryFinderV55_2
{
    use HasPriceTables;

    private string $version = 'v55.2';

    // All v55.2 entry settings live in this class. Edit these properties to tune it.
    public bool $debug = false;

    public int $debugEvery = 100;

    public bool $allowLunch = false;

    public int $minBars = 18;

    public int $atrPeriod1m = 14;

    public int $volLookback1m = 20;

    public int $maxEntryAgeMinutes = 2;

    public int $maxSignalAgeMinutes = 10;

    public float $minNotional1m = 65000.0;

    public float $minVolumeRatio = 1.15;

    public float $minBreakoutVsFlagVolumeRatio = 1.15;

    public float $minBodyPct = 0.07;

    public float $minBodyRangeFraction = 0.45;

    public float $minClosePosition = 0.68;

    public float $maxUpperWickFraction = 0.30;

    public float $maxAboveVwapPct = 1.65;

    public float $minAtrPct = 0.14;

    public float $maxAtrPct = 2.10;

    public float $minEmaSpreadPct = 0.015;

    public float $minEma9SlopePct = 0.0075;

    public int $minFlagBars = 3;

    public int $maxFlagBars = 8;

    public float $maxFlagRangePct = 1.15;

    public float $maxFlagRangeAtr = 1.30;

    public float $breakBufferPct = 0.02;

    public float $maxBreakDistanceAtr = 0.45;

    public float $maxPullbackBelowVwapPct = 0.25;

    public int $impulseLookbackBars = 3;

    public float $minImpulsePct = 0.25;

    public float $minImpulseAtr = 0.70;

    public float $maxFlagRetraceFraction = 0.68;

    public float $maxFlagCompressionRatio = 0.92;

    public float $maxFlagToImpulseVolumeRatio = 1.10;

    public bool $allowEma9PivotBreak = true;

    public int $pivotLookbackBars = 3;

    public int $pivotOlderLookbackBars = 5;

    public float $maxPivotDepthPct = 1.80;

    public float $maxPivotDistanceFromEma9Atr = 0.50;

    public float $stopAtrBuffer = 0.15;

    public float $stopPriceBufferPct = 0.04;

    public float $minStopPct = 0.45;

    public float $maxStopPct = 1.50;

    public float $minRoomToSessionHighR = 0.90;

    public float $sessionHighBreakTolerancePct = 0.05;

    public float $trailingStopAtrMultiplier = 4.0;

    public float $minTrailingStopPct = 1.00;

    public float $maxTrailingStopPct = 2.00;

    public float $minEntryScore = 50.0;

    public float $minPivotEntryScore = 54.0;

    public bool $requireRedBarInFlag = false;

    public float $maxRedBarFractionInFlag = 0.75;

    public int $minEntryDelaySecondsAfterSignal = 0;

    // Trading windows are expressed as minutes after midnight, Eastern time.
    public int $morningStartMinute = 585;      // 09:45

    public int $morningEndMinute = 675;        // 11:15

    public int $afternoonStartMinute = 870;    // 14:30

    public int $afternoonEndMinute = 935;      // 15:35

    public int $morningScoreSplitMinute = 615; // 10:15

    public int $powerHourStartMinute = 900;    // 15:00

    public float $earlyMorningScoreMultiplier = 1.00;

    public float $lateMorningScoreMultiplier = 0.97;

    public float $earlyAfternoonScoreMultiplier = 0.95;

    public float $powerHourScoreMultiplier = 1.00;

    /** @var array<string, int> */
    private static array $dbg = [
        'called' => 0,
        'not_enough_bars' => 0,
        'time_blocked' => 0,
        'entry_time_blocked' => 0,
        'signal_stale' => 0,
        'before_signal' => 0,
        'bad_data' => 0,
        'trend_failed' => 0,
        'quality_failed' => 0,
        'body_range_failed' => 0,
        'volume_failed' => 0,
        'extension_failed' => 0,
        'no_trigger' => 0,
        'flag_detected' => 0,
        'pivot_detected' => 0,
        'risk_failed' => 0,
        'resistance_failed' => 0,
        'score_failed' => 0,
        'returned' => 0,
    ];

    public function getVersion(): string
    {
        return $this->version;
    }

    /** @return array<string, int|float|bool> */
    public function entryConfig(): array
    {
        return [
            'debug' => $this->debug,
            'debug_every' => $this->debugEvery,
            'allow_lunch' => $this->allowLunch,
            'min_bars' => $this->minBars,
            'atr_period_1m' => $this->atrPeriod1m,
            'vol_lookback_1m' => $this->volLookback1m,
            'max_entry_age_minutes' => $this->maxEntryAgeMinutes,
            'max_signal_age_minutes' => $this->maxSignalAgeMinutes,
            'min_notional_1m' => $this->minNotional1m,
            'min_volume_ratio' => $this->minVolumeRatio,
            'min_breakout_vs_flag_volume_ratio' => $this->minBreakoutVsFlagVolumeRatio,
            'min_body_pct' => $this->minBodyPct,
            'min_body_range_fraction' => $this->minBodyRangeFraction,
            'min_close_position' => $this->minClosePosition,
            'max_upper_wick_fraction' => $this->maxUpperWickFraction,
            'max_above_vwap_pct' => $this->maxAboveVwapPct,
            'min_atr_pct' => $this->minAtrPct,
            'max_atr_pct' => $this->maxAtrPct,
            'min_ema_spread_pct' => $this->minEmaSpreadPct,
            'min_ema9_slope_pct' => $this->minEma9SlopePct,
            'min_flag_bars' => $this->minFlagBars,
            'max_flag_bars' => $this->maxFlagBars,
            'max_flag_range_pct' => $this->maxFlagRangePct,
            'max_flag_range_atr' => $this->maxFlagRangeAtr,
            'break_buffer_pct' => $this->breakBufferPct,
            'max_break_distance_atr' => $this->maxBreakDistanceAtr,
            'max_pullback_below_vwap_pct' => $this->maxPullbackBelowVwapPct,
            'impulse_lookback_bars' => $this->impulseLookbackBars,
            'min_impulse_pct' => $this->minImpulsePct,
            'min_impulse_atr' => $this->minImpulseAtr,
            'max_flag_retrace_fraction' => $this->maxFlagRetraceFraction,
            'max_flag_compression_ratio' => $this->maxFlagCompressionRatio,
            'max_flag_to_impulse_volume_ratio' => $this->maxFlagToImpulseVolumeRatio,
            'allow_ema9_pivot_break' => $this->allowEma9PivotBreak,
            'pivot_lookback_bars' => $this->pivotLookbackBars,
            'pivot_older_lookback_bars' => $this->pivotOlderLookbackBars,
            'max_pivot_depth_pct' => $this->maxPivotDepthPct,
            'max_pivot_distance_from_ema9_atr' => $this->maxPivotDistanceFromEma9Atr,
            'stop_atr_buffer' => $this->stopAtrBuffer,
            'stop_price_buffer_pct' => $this->stopPriceBufferPct,
            'min_stop_pct' => $this->minStopPct,
            'max_stop_pct' => $this->maxStopPct,
            'min_room_to_session_high_r' => $this->minRoomToSessionHighR,
            'session_high_break_tolerance_pct' => $this->sessionHighBreakTolerancePct,
            'trailing_stop_atr_multiplier' => $this->trailingStopAtrMultiplier,
            'min_trailing_stop_pct' => $this->minTrailingStopPct,
            'max_trailing_stop_pct' => $this->maxTrailingStopPct,
            'min_entry_score' => $this->minEntryScore,
            'min_pivot_entry_score' => $this->minPivotEntryScore,
            'require_red_bar_in_flag' => $this->requireRedBarInFlag,
            'max_red_bar_fraction_in_flag' => $this->maxRedBarFractionInFlag,
            'min_entry_delay_seconds_after_signal' => $this->minEntryDelaySecondsAfterSignal,
            'morning_start_minute' => $this->morningStartMinute,
            'morning_end_minute' => $this->morningEndMinute,
            'afternoon_start_minute' => $this->afternoonStartMinute,
            'afternoon_end_minute' => $this->afternoonEndMinute,
        ];
    }

    public function setFullTable(bool $full): void
    {
        $this->fiveMinuteTable = $full ? 'five_minute_prices_full' : 'five_minute_prices';
        $this->oneMinuteTable = $full ? 'one_minute_prices_full' : 'one_minute_prices';
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
                'pattern' => 'BALANCED_TREND_COMPRESSION_CONTINUATION',
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

    /**
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function findEntry(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst
    ): array {
        $allowLunch = $this->allowLunch;
        $minBars = max(10, $this->minBars);
        $atrPeriod = max(5, $this->atrPeriod1m);
        $volLookback = max(5, $this->volLookback1m);
        $maxEntryAgeMinutes = max(1, $this->maxEntryAgeMinutes);
        $maxSignalAgeMinutes = max(1, $this->maxSignalAgeMinutes);

        $minNotional1m = $this->minNotional1m;
        $minVolumeRatio = $this->minVolumeRatio;
        $minBreakoutVsFlagVolumeRatio = $this->minBreakoutVsFlagVolumeRatio;
        $minBodyPct = $this->minBodyPct;
        $minBodyRangeFraction = $this->minBodyRangeFraction;
        $minClosePosition = $this->minClosePosition;
        $maxUpperWickFraction = $this->maxUpperWickFraction;
        $maxAboveVwapPct = $this->maxAboveVwapPct;
        $minAtrPct = $this->minAtrPct;
        $maxAtrPct = $this->maxAtrPct;
        $minEmaSpreadPct = $this->minEmaSpreadPct;
        $minEmaSlopePct = $this->minEma9SlopePct;

        $minFlagBars = max(3, $this->minFlagBars);
        $maxFlagBars = max($minFlagBars, $this->maxFlagBars);
        $maxFlagRangePct = $this->maxFlagRangePct;
        $maxFlagRangeAtr = $this->maxFlagRangeAtr;
        $breakBufferPct = $this->breakBufferPct;
        $maxBreakDistanceAtr = $this->maxBreakDistanceAtr;
        $maxPullbackBelowVwapPct = $this->maxPullbackBelowVwapPct;
        $impulseLookbackBars = max(2, $this->impulseLookbackBars);
        $minImpulsePct = $this->minImpulsePct;
        $minImpulseAtr = $this->minImpulseAtr;
        $maxFlagRetraceFraction = $this->maxFlagRetraceFraction;
        $maxFlagCompressionRatio = $this->maxFlagCompressionRatio;
        $maxFlagToImpulseVolumeRatio = $this->maxFlagToImpulseVolumeRatio;
        $allowEma9PivotBreak = $this->allowEma9PivotBreak;

        $pivotLookback = max(2, $this->pivotLookbackBars);
        $pivotOlderLookback = max(3, $this->pivotOlderLookbackBars);
        $maxPivotDepthPct = $this->maxPivotDepthPct;
        $maxPivotDistanceFromEma9Atr = $this->maxPivotDistanceFromEma9Atr;

        $stopAtrBuffer = $this->stopAtrBuffer;
        $stopPriceBufferPct = $this->stopPriceBufferPct;
        $minStopPct = $this->minStopPct;
        $maxStopPct = $this->maxStopPct;
        $minRoomToSessionHighR = $this->minRoomToSessionHighR;
        $sessionHighBreakTolerancePct = $this->sessionHighBreakTolerancePct;
        $minEntryScore = $this->minEntryScore;
        $minPivotEntryScore = $this->minPivotEntryScore;
        $requireRedBarInFlag = $this->requireRedBarInFlag;
        $maxRedBarFractionInFlag = $this->maxRedBarFractionInFlag;
        $minEntryDelaySecondsAfterSignal = max(0, $this->minEntryDelaySecondsAfterSignal);

        if (! $allowLunch && ! $this->isAllowedTime($asOfTsEst)) {
            self::$dbg['time_blocked']++;

            return [null, 'time_blocked'];
        }

        $asOfEpoch = strtotime($asOfTsEst);
        $signalEpoch = strtotime($signalTsEst);
        if ($asOfEpoch === false || $signalEpoch === false) {
            return [null, 'invalid_timestamp'];
        }

        $signalAgeSeconds = $asOfEpoch - $signalEpoch;
        if ($signalAgeSeconds < 0 || $signalAgeSeconds > ($maxSignalAgeMinutes * 60)) {
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

        if (count($rows) < $minBars) {
            self::$dbg['not_enough_bars']++;

            return [null, 'not_enough_bars'];
        }

        $bars = $this->normalizeBars($rows, $atrPeriod, $volLookback);
        if ($bars === null || count($bars) < $minBars) {
            self::$dbg['bad_data']++;

            return [null, 'bad_data'];
        }

        $lastIndex = count($bars) - 1;
        $earliestIndex = max($minBars - 1, $lastIndex - ($maxEntryAgeMinutes + 1));
        $candidates = [];

        for ($i = $lastIndex; $i >= $earliestIndex; $i--) {
            $bar = $bars[$i];
            $entryTs = (string) $bar['ts'];

            if (! $allowLunch && ! $this->isAllowedTime($entryTs)) {
                self::$dbg['entry_time_blocked']++;

                continue;
            }

            $entryEpoch = strtotime($entryTs);
            if ($entryEpoch === false) {
                continue;
            }
            $entryAgeSeconds = $asOfEpoch - $entryEpoch;
            if ($entryAgeSeconds < 0 || $entryAgeSeconds > ($maxEntryAgeMinutes * 60)) {
                continue;
            }
            if ($entryEpoch < ($signalEpoch + $minEntryDelaySecondsAfterSignal)) {
                self::$dbg['before_signal']++;

                continue;
            }

            $close = (float) $bar['close'];
            $open = (float) $bar['open'];
            $high = (float) $bar['high'];
            $low = (float) $bar['low'];
            $volume = (float) $bar['volume'];
            $atr = (float) $bar['atr'];
            $vwap = (float) $bar['vwap'];
            $ema9 = (float) $bar['ema9'];
            $ema21 = (float) $bar['ema21'];
            $volumeRatio = (float) $bar['volume_ratio'];

            if ($atr <= 0 || $vwap <= 0 || $ema9 <= 0 || $ema21 <= 0 || $close <= 0) {
                continue;
            }

            $range = max(1e-9, $high - $low);
            $bodyPct = abs($close - $open) / $close * 100.0;
            $bodyRangeFraction = abs($close - $open) / $range;
            $closePosition = ($close - $low) / $range;
            $upperWickFraction = ($high - max($open, $close)) / $range;
            $aboveVwapPct = (($close - $vwap) / $vwap) * 100.0;
            $atrPct = ($atr / $close) * 100.0;
            $emaSpreadPct = (($ema9 - $ema21) / $ema21) * 100.0;
            $emaSlopePct = $this->emaSlopePct($bars, $i, 3);
            $notional = $close * $volume;

            if (
                $close <= $open
                || $close < $vwap
                || $close < $ema9
                || $ema9 <= $ema21
                || $emaSpreadPct < $minEmaSpreadPct
                || $emaSlopePct < $minEmaSlopePct
            ) {
                self::$dbg['trend_failed']++;

                continue;
            }

            if ($bodyRangeFraction < $minBodyRangeFraction) {
                self::$dbg['body_range_failed']++;

                continue;
            }

            if (
                $bodyPct < $minBodyPct
                || $closePosition < $minClosePosition
                || $upperWickFraction > $maxUpperWickFraction
            ) {
                self::$dbg['quality_failed']++;

                continue;
            }

            if ($volumeRatio < $minVolumeRatio || $notional < $minNotional1m) {
                self::$dbg['volume_failed']++;

                continue;
            }

            if (
                $aboveVwapPct > $maxAboveVwapPct
                || $atrPct < $minAtrPct
                || $atrPct > $maxAtrPct
            ) {
                self::$dbg['extension_failed']++;

                continue;
            }

            $trigger = null;
            $structureLow = null;
            $breakLevel = null;
            $triggerMeta = [];

            $flag = $this->detectTightFlagBreakout(
                $bars,
                $i,
                $minFlagBars,
                $maxFlagBars,
                $maxFlagRangePct,
                $maxFlagRangeAtr,
                $breakBufferPct,
                $maxBreakDistanceAtr,
                $maxPullbackBelowVwapPct,
                $impulseLookbackBars,
                $minImpulsePct,
                $minImpulseAtr,
                $maxFlagRetraceFraction,
                $maxFlagCompressionRatio,
                $maxFlagToImpulseVolumeRatio,
                $minBreakoutVsFlagVolumeRatio,
                $requireRedBarInFlag,
                $maxRedBarFractionInFlag
            );

            if ($flag !== null) {
                $trigger = 'TIGHT_FLAG_BREAKOUT_1M';
                $structureLow = (float) $flag['structure_low'];
                $breakLevel = (float) $flag['break_level'];
                $triggerMeta = $flag;
                self::$dbg['flag_detected']++;
            } elseif ($allowEma9PivotBreak) {
                $pivot = $this->detectEma9PivotBreak(
                    $bars,
                    $i,
                    $pivotLookback,
                    $pivotOlderLookback,
                    $maxPivotDepthPct,
                    $maxPivotDistanceFromEma9Atr,
                    $breakBufferPct,
                    $maxBreakDistanceAtr
                );
                if ($pivot !== null) {
                    $trigger = 'EMA9_PIVOT_BREAK_1M';
                    $structureLow = (float) $pivot['structure_low'];
                    $breakLevel = (float) $pivot['break_level'];
                    $triggerMeta = $pivot;
                    self::$dbg['pivot_detected']++;
                }
            }

            if ($trigger === null || $structureLow === null || $breakLevel === null) {
                self::$dbg['no_trigger']++;

                continue;
            }

            $buffer = max($atr * $stopAtrBuffer, $close * ($stopPriceBufferPct / 100.0));
            $stop = max(0.01, $structureLow - $buffer);
            $riskPerShare = $close - $stop;
            $riskPct = $riskPerShare > 0 ? ($riskPerShare / $close) * 100.0 : 0.0;

            if ($riskPct > 0 && $riskPct < $minStopPct) {
                $stop = max(0.01, $close * (1.0 - ($minStopPct / 100.0)));
                $riskPerShare = $close - $stop;
                $riskPct = ($riskPerShare / $close) * 100.0;
            }

            if ($riskPerShare <= 0 || $riskPct < $minStopPct || $riskPct > $maxStopPct) {
                self::$dbg['risk_failed']++;

                continue;
            }

            $sessionHighBefore = $this->maxField($bars, 0, $i - 1, 'high');
            $breaksSessionHigh = $close >= ($sessionHighBefore * (1.0 - ($sessionHighBreakTolerancePct / 100.0)));
            $roomToSessionHighR = $sessionHighBefore > $close
                ? ($sessionHighBefore - $close) / $riskPerShare
                : 999.0;

            if (! $breaksSessionHigh && $roomToSessionHighR < $minRoomToSessionHighR) {
                self::$dbg['resistance_failed']++;

                continue;
            }

            $scoreParts = $this->scoreEntry(
                $trigger,
                $volumeRatio,
                $bodyPct,
                $closePosition,
                $upperWickFraction,
                $aboveVwapPct,
                $emaSpreadPct,
                $emaSlopePct,
                $riskPct,
                (float) ($triggerMeta['compression_ratio'] ?? 0.70),
                $entryTs
            );

            $requiredEntryScore = $trigger === 'EMA9_PIVOT_BREAK_1M'
                ? $minPivotEntryScore
                : $minEntryScore;

            if ((float) $scoreParts['score'] < $requiredEntryScore) {
                self::$dbg['score_failed']++;

                continue;
            }

            $rawTrail = $atr * $this->trailingStopAtrMultiplier;
            $minTrail = $close * ($this->minTrailingStopPct / 100.0);
            $maxTrail = $close * ($this->maxTrailingStopPct / 100.0);
            $suggestedTrail = max($minTrail, min($rawTrail, $maxTrail));

            $candidates[] = [
                'type' => $trigger,
                'pattern' => 'BALANCED_TREND_COMPRESSION_CONTINUATION',
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
                    '1R' => round($close + $riskPerShare, 6),
                    '2R' => round($close + (2.0 * $riskPerShare), 6),
                    '3R' => round($close + (3.0 * $riskPerShare), 6),
                    '3pct' => round($close * 1.03, 6),
                    '4pct' => round($close * 1.04, 6),
                    '5pct' => round($close * 1.05, 6),
                ],
                'entry_age_seconds' => $entryAgeSeconds,
                'signal_age_seconds' => $signalAgeSeconds,
                'meta' => array_merge($triggerMeta, [
                    'version' => $this->version,
                    'trigger' => $trigger,
                    'break_level' => round($breakLevel, 6),
                    'structure_low' => round($structureLow, 6),
                    'session_high_before_entry' => round($sessionHighBefore, 6),
                    'breaks_session_high' => $breaksSessionHigh,
                    'room_to_session_high_r' => round($roomToSessionHighR, 4),
                    'entry_open' => round($open, 6),
                    'entry_high' => round($high, 6),
                    'entry_low' => round($low, 6),
                    'entry_volume' => $volume,
                    'entry_notional' => round($notional, 2),
                    'body_pct' => round($bodyPct, 4),
                    'body_range_fraction' => round($bodyRangeFraction, 4),
                    'close_position' => round($closePosition, 4),
                    'upper_wick_fraction' => round($upperWickFraction, 4),
                    'ema9_slope_pct' => round($emaSlopePct, 4),
                    'trend_score' => $scoreParts['trend_score'],
                    'trigger_score' => $scoreParts['trigger_score'],
                    'quality_score' => $scoreParts['quality_score'],
                    'risk_score' => $scoreParts['risk_score'],
                    'required_entry_score' => $requiredEntryScore,
                ]),
            ];
        }

        if ($candidates === []) {
            return [null, 'no_valid_v55_2_entry'];
        }

        usort($candidates, static function (array $a, array $b): int {
            $age = ($a['entry_age_seconds'] ?? PHP_INT_MAX) <=> ($b['entry_age_seconds'] ?? PHP_INT_MAX);
            if ($age !== 0) {
                return $age;
            }

            return ($b['score'] ?? 0.0) <=> ($a['score'] ?? 0.0);
        });

        $best = $candidates[0];

        if ($this->isDebugEnabled()) {
            Log::info('[EntryFinderV55_2] accepted entry', [
                'symbol' => $symbol,
                'signal_ts_est' => $signalTsEst,
                'as_of_ts_est' => $asOfTsEst,
                'entry_ts_est' => $best['entry_ts_est'] ?? null,
                'type' => $best['type'] ?? null,
                'entry' => $best['entry'] ?? null,
                'stop' => $best['stop'] ?? null,
                'risk_pct' => $best['risk_pct'] ?? null,
                'score' => $best['score'] ?? null,
                'vol_ratio' => $best['vol_ratio'] ?? null,
                'above_vwap_pct' => $best['above_vwap_pct'] ?? null,
                'pid' => getmypid(),
            ]);
        }

        return [$best, 'ok'];
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<int, array<string, float|string>>|null
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
                $open <= 0
                || $high <= 0
                || $low <= 0
                || $close <= 0
                || $high < $low
                || $high < max($open, $close)
                || $low > min($open, $close)
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

            $priorVolumeSlice = array_slice($volumes, -min($volLookback, count($volumes)));
            $averagePriorVolume = $priorVolumeSlice === []
                ? 0.0
                : array_sum($priorVolumeSlice) / count($priorVolumeSlice);
            $volumeRatio = $averagePriorVolume > 0 ? $volume / $averagePriorVolume : 0.0;
            $volumes[] = $volume;

            $out[] = [
                'ts' => (string) $row->ts_est,
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
     * @param  array<int, array<string, float|string>>  $bars
     * @return array<string, float|int>|null
     */
    private function detectTightFlagBreakout(
        array $bars,
        int $index,
        int $minFlagBars,
        int $maxFlagBars,
        float $maxFlagRangePct,
        float $maxFlagRangeAtr,
        float $breakBufferPct,
        float $maxBreakDistanceAtr,
        float $maxPullbackBelowVwapPct,
        int $impulseLookbackBars,
        float $minImpulsePct,
        float $minImpulseAtr,
        float $maxFlagRetraceFraction,
        float $maxFlagCompressionRatio,
        float $maxFlagToImpulseVolumeRatio,
        float $minBreakoutVsFlagVolumeRatio,
        bool $requireRedBarInFlag,
        float $maxRedBarFractionInFlag
    ): ?array {
        $entry = $bars[$index];
        $close = (float) $entry['close'];
        $atr = (float) $entry['atr'];
        $vwap = (float) $entry['vwap'];

        $best = null;
        for ($window = $minFlagBars; $window <= $maxFlagBars; $window++) {
            $start = $index - $window;
            $end = $index - 1;
            if ($start < 2 || $end <= $start) {
                continue;
            }

            $high = $this->maxField($bars, $start, $end, 'high');
            $low = $this->minField($bars, $start, $end, 'low');
            $range = $high - $low;
            if ($range <= 0 || $atr <= 0) {
                continue;
            }

            $rangePct = ($range / $close) * 100.0;
            $rangeAtr = $range / $atr;
            $requiredBreak = $high * (1.0 + ($breakBufferPct / 100.0));
            $previousClose = (float) $bars[$index - 1]['close'];
            $breakDistanceAtr = max(0.0, $close - $high) / $atr;
            $pullbackBelowVwapPct = $low < $vwap
                ? (($vwap - $low) / $vwap) * 100.0
                : 0.0;

            $impulseStart = $start - $impulseLookbackBars;
            $impulseEnd = $start - 1;
            if ($impulseStart < 1 || $impulseEnd < $impulseStart) {
                continue;
            }
            $impulseStartClose = (float) $bars[$impulseStart]['close'];
            $impulseEndClose = (float) $bars[$impulseEnd]['close'];
            $impulseHigh = $this->maxField($bars, $impulseStart, $impulseEnd, 'high');
            $impulseLow = $this->minField($bars, $impulseStart, $impulseEnd, 'low');
            $impulseRange = max(1e-9, $impulseHigh - $impulseLow);
            $impulsePct = $impulseStartClose > 0
                ? (($impulseEndClose - $impulseStartClose) / $impulseStartClose) * 100.0
                : 0.0;
            $impulseAtr = $impulseRange / $atr;
            $flagRetraceFraction = max(0.0, $impulseHigh - $low) / $impulseRange;
            $flagAverageVolume = $this->averageField($bars, $start, $end, 'volume');
            $impulseAverageVolume = $this->averageField($bars, $impulseStart, $impulseEnd, 'volume');
            $flagToImpulseVolumeRatio = $impulseAverageVolume > 0
                ? $flagAverageVolume / $impulseAverageVolume
                : 999.0;
            $breakoutVsFlagVolumeRatio = $flagAverageVolume > 0
                ? ((float) $entry['volume']) / $flagAverageVolume
                : 0.0;

            if (
                $close <= $requiredBreak
                || $previousClose > $requiredBreak
                || $impulsePct < $minImpulsePct
                || $impulseAtr < $minImpulseAtr
                || $flagRetraceFraction > $maxFlagRetraceFraction
                || $flagToImpulseVolumeRatio > $maxFlagToImpulseVolumeRatio
                || $breakoutVsFlagVolumeRatio < $minBreakoutVsFlagVolumeRatio
                || $rangePct > $maxFlagRangePct
                || $rangeAtr > $maxFlagRangeAtr
                || $breakDistanceAtr > $maxBreakDistanceAtr
                || $pullbackBelowVwapPct > $maxPullbackBelowVwapPct
            ) {
                continue;
            }

            $redBars = 0;
            $ranges = [];
            for ($j = $start; $j <= $end; $j++) {
                if ((float) $bars[$j]['close'] < (float) $bars[$j]['open']) {
                    $redBars++;
                }
                $ranges[] = (float) $bars[$j]['high'] - (float) $bars[$j]['low'];
            }

            $firstHalf = array_slice($ranges, 0, max(1, intdiv(count($ranges), 2)));
            $secondHalf = array_slice($ranges, max(1, intdiv(count($ranges), 2)));
            $firstAverage = $firstHalf === [] ? $range : array_sum($firstHalf) / count($firstHalf);
            $secondAverage = $secondHalf === [] ? $range : array_sum($secondHalf) / count($secondHalf);
            $compressionRatio = $firstAverage > 0 ? $secondAverage / $firstAverage : 1.0;

            $redFraction = $window > 0 ? $redBars / $window : 1.0;
            if (
                ($requireRedBarInFlag && $redBars === 0)
                || $redFraction > $maxRedBarFractionInFlag
                || $compressionRatio > $maxFlagCompressionRatio
            ) {
                continue;
            }

            $candidate = [
                'flag_bars' => $window,
                'break_level' => $high,
                'structure_low' => $low,
                'flag_range_pct' => $rangePct,
                'flag_range_atr' => $rangeAtr,
                'break_distance_atr' => $breakDistanceAtr,
                'compression_ratio' => $compressionRatio,
                'red_bars_in_flag' => $redBars,
                'impulse_pct' => $impulsePct,
                'impulse_atr' => $impulseAtr,
                'flag_retrace_fraction' => $flagRetraceFraction,
                'flag_to_impulse_volume_ratio' => $flagToImpulseVolumeRatio,
                'breakout_vs_flag_volume_ratio' => $breakoutVsFlagVolumeRatio,
            ];

            if ($best === null || $rangeAtr < (float) $best['flag_range_atr']) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param  array<int, array<string, float|string>>  $bars
     * @return array<string, float|int>|null
     */
    private function detectEma9PivotBreak(
        array $bars,
        int $index,
        int $pivotLookback,
        int $olderLookback,
        float $maxPivotDepthPct,
        float $maxPivotDistanceFromEma9Atr,
        float $breakBufferPct,
        float $maxBreakDistanceAtr
    ): ?array {
        $recentStart = $index - $pivotLookback;
        $recentEnd = $index - 1;
        $olderEnd = $recentStart - 1;
        $olderStart = $olderEnd - $olderLookback + 1;
        if ($olderStart < 1 || $recentStart < 2) {
            return null;
        }

        $entry = $bars[$index];
        $close = (float) $entry['close'];
        $low = (float) $entry['low'];
        $atr = (float) $entry['atr'];
        $ema9 = (float) $entry['ema9'];

        $pivotHigh = $this->maxField($bars, $recentStart, $recentEnd, 'high');
        $recentLow = $this->minField($bars, $recentStart, $recentEnd, 'low');
        $olderLow = $this->minField($bars, $olderStart, $olderEnd, 'low');
        $recentHigh = $this->maxField($bars, $olderStart, $recentEnd, 'high');

        if ($pivotHigh <= 0 || $recentLow <= 0 || $olderLow <= 0 || $atr <= 0) {
            return null;
        }

        $requiredBreak = $pivotHigh * (1.0 + ($breakBufferPct / 100.0));
        $breakDistanceAtr = max(0.0, $close - $pivotHigh) / $atr;
        $pivotDepthPct = $recentHigh > 0 ? (($recentHigh - $recentLow) / $recentHigh) * 100.0 : 0.0;
        $distanceFromEma9Atr = abs($low - $ema9) / $atr;
        $higherLowPct = (($recentLow - $olderLow) / $olderLow) * 100.0;

        if (
            $close <= $requiredBreak
            || $breakDistanceAtr > $maxBreakDistanceAtr
            || $pivotDepthPct > $maxPivotDepthPct
            || $distanceFromEma9Atr > $maxPivotDistanceFromEma9Atr
            || $higherLowPct <= 0.00
        ) {
            return null;
        }

        return [
            'pivot_bars' => $pivotLookback,
            'break_level' => $pivotHigh,
            'structure_low' => $recentLow,
            'pivot_depth_pct' => $pivotDepthPct,
            'break_distance_atr' => $breakDistanceAtr,
            'distance_from_ema9_atr' => $distanceFromEma9Atr,
            'higher_low_pct' => $higherLowPct,
            'compression_ratio' => 0.70,
        ];
    }

    /**
     * @return array<string, float>
     */
    private function scoreEntry(
        string $trigger,
        float $volumeRatio,
        float $bodyPct,
        float $closePosition,
        float $upperWickFraction,
        float $aboveVwapPct,
        float $emaSpreadPct,
        float $emaSlopePct,
        float $riskPct,
        float $compressionRatio,
        string $entryTs
    ): array {
        $triggerBase = $trigger === 'TIGHT_FLAG_BREAKOUT_1M' ? 1.0 : 0.90;
        $volumeScore = $this->clamp(($volumeRatio - 1.0) / 1.75);
        $compressionScore = 1.0 - $this->clamp(($compressionRatio - 0.35) / 0.65);
        $triggerScore = 100.0 * ((0.45 * $triggerBase) + (0.30 * $volumeScore) + (0.25 * $compressionScore));

        $bodyScore = $this->clamp(($bodyPct - 0.05) / 0.45);
        $closeScore = $this->clamp(($closePosition - 0.60) / 0.38);
        $wickScore = 1.0 - $this->clamp($upperWickFraction / 0.40);
        $qualityScore = 100.0 * ((0.30 * $bodyScore) + (0.45 * $closeScore) + (0.25 * $wickScore));

        $spreadScore = $this->clamp(($emaSpreadPct - 0.01) / 0.35);
        $slopeScore = $this->clamp(($emaSlopePct - 0.005) / 0.20);
        $locationScore = 1.0 - $this->clamp(abs($aboveVwapPct - 0.55) / 1.35);
        $trendScore = 100.0 * ((0.35 * $spreadScore) + (0.35 * $slopeScore) + (0.30 * $locationScore));

        $riskScore = 100.0 * (1.0 - $this->clamp(abs($riskPct - 0.65) / 0.70));
        $timeScore = 100.0 * $this->timeMultiplier($entryTs);

        $score =
            (0.33 * $triggerScore)
            + (0.27 * $qualityScore)
            + (0.22 * $trendScore)
            + (0.13 * $riskScore)
            + (0.05 * $timeScore);

        return [
            'score' => round($this->clamp($score, 0.0, 100.0), 3),
            'trigger_score' => round($triggerScore, 3),
            'quality_score' => round($qualityScore, 3),
            'trend_score' => round($trendScore, 3),
            'risk_score' => round($riskScore, 3),
        ];
    }

    /** @param array<int, array<string, float|string>> $bars */
    private function averageField(array $bars, int $start, int $end, string $field): float
    {
        if ($start > $end || ! isset($bars[$start])) {
            return 0.0;
        }

        $sum = 0.0;
        $count = 0;
        for ($i = $start; $i <= $end; $i++) {
            if (isset($bars[$i])) {
                $sum += (float) $bars[$i][$field];
                $count++;
            }
        }

        return $count > 0 ? $sum / $count : 0.0;
    }

    /** @param array<int, array<string, float|string>> $bars */
    private function emaSlopePct(array $bars, int $index, int $lookback): float
    {
        $start = max(0, $index - $lookback);
        $base = (float) $bars[$start]['ema9'];
        $current = (float) $bars[$index]['ema9'];

        return $base > 0 ? (($current - $base) / $base) * 100.0 : 0.0;
    }

    /** @param array<int, array<string, float|string>> $bars */
    private function maxField(array $bars, int $start, int $end, string $field): float
    {
        if ($start > $end || ! isset($bars[$start])) {
            return 0.0;
        }

        $value = (float) $bars[$start][$field];
        for ($i = $start + 1; $i <= $end; $i++) {
            if (isset($bars[$i])) {
                $value = max($value, (float) $bars[$i][$field]);
            }
        }

        return $value;
    }

    /** @param array<int, array<string, float|string>> $bars */
    private function minField(array $bars, int $start, int $end, string $field): float
    {
        if ($start > $end || ! isset($bars[$start])) {
            return 0.0;
        }

        $value = (float) $bars[$start][$field];
        for ($i = $start + 1; $i <= $end; $i++) {
            if (isset($bars[$i])) {
                $value = min($value, (float) $bars[$i][$field]);
            }
        }

        return $value;
    }

    private function isAllowedTime(string $tsEst): bool
    {
        $minuteOfDay = $this->minuteOfDay($tsEst);

        return ($minuteOfDay >= $this->morningStartMinute && $minuteOfDay <= $this->morningEndMinute)
            || ($minuteOfDay >= $this->afternoonStartMinute && $minuteOfDay <= $this->afternoonEndMinute);
    }

    private function timeMultiplier(string $tsEst): float
    {
        $minuteOfDay = $this->minuteOfDay($tsEst);

        if ($minuteOfDay >= $this->morningStartMinute && $minuteOfDay < $this->morningScoreSplitMinute) {
            return $this->earlyMorningScoreMultiplier;
        }
        if ($minuteOfDay >= $this->morningScoreSplitMinute && $minuteOfDay <= $this->morningEndMinute) {
            return $this->lateMorningScoreMultiplier;
        }
        if ($minuteOfDay >= $this->afternoonStartMinute && $minuteOfDay < $this->powerHourStartMinute) {
            return $this->earlyAfternoonScoreMultiplier;
        }
        if ($minuteOfDay >= $this->powerHourStartMinute && $minuteOfDay <= $this->afternoonEndMinute) {
            return $this->powerHourScoreMultiplier;
        }

        return 0.0;
    }

    private function minuteOfDay(string $tsEst): int
    {
        $hour = (int) substr($tsEst, 11, 2);
        $minute = (int) substr($tsEst, 14, 2);

        return ($hour * 60) + $minute;
    }

    private function isDebugEnabled(): bool
    {
        return $this->debug;
    }

    private function maybeLogDebug(): void
    {
        if (! $this->isDebugEnabled()) {
            return;
        }

        $every = max(1, $this->debugEvery);
        if (self::$dbg['called'] > 0 && self::$dbg['called'] % $every === 0) {
            Log::info('[EntryFinderV55_2] debug counters', array_merge(self::$dbg, [
                'pid' => getmypid(),
                'debug_every' => $every,
            ]));
        }
    }

    private function clamp(float $value, float $min = 0.0, float $max = 1.0): float
    {
        return max($min, min($max, $value));
    }
}
