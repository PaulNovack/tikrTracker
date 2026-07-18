<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Log;

/**
 * Version 56.0 - One-minute momentum continuation entry finder.
 *
 * Supported long triggers:
 * - SESSION_HIGH_BREAK_1M
 * - LOCAL_HIGH_BREAK_1M
 * - MOMENTUM_ACCELERATION_1M
 * - EMA9_RECLAIM_CONTINUATION_1M
 *
 * Unlike the v55 family, this finder does not wait for a completed compact
 * flag. Trigger-specific gates are used so one overly strict global rule does
 * not suppress every momentum entry type.
 */
class OneMinuteEntryFinderV56_0
{
    use HasPriceTables;

    private string $version = 'v56.0';

    // All v56 entry configuration is kept in this class.
    public bool $debug = true;

    public int $debugEvery = 100;

    public bool $allowLunch = false;

    public int $minBars = 8;

    public int $atrPeriod1m = 14;

    public int $volLookback1m = 20;

    public int $maxEntryAgeMinutes = 3;

    public int $maxSignalAgeMinutes = 15;

    public float $minNotional1m = 30000.0;

    public float $minAtrPct = 0.10;

    public float $maxAtrPct = 3.75;

    public float $maxAboveVwapPct = 3.50;

    public float $minimumTrendEmaSpreadPct = -0.06;

    public float $strongThreeMinuteMovePct = 0.45;

    public float $minBodyPct = 0.05;

    public float $minBodyRangeFraction = 0.32;

    public float $minClosePosition = 0.58;

    public float $maxUpperWickFraction = 0.40;

    public int $localHighLookbackBars = 3;

    public float $breakBufferPct = 0.005;

    public float $sessionHighTolerancePct = 0.03;

    public float $maxBreakDistanceAtr = 1.20;

    public float $minHighBreakVolumeRatio = 0.90;

    public float $minHighBreakMove3mPct = 0.12;

    public int $accelerationLookbackBars = 3;

    public float $minAccelerationMove3mPct = 0.22;

    public float $minAccelerationLastBarPct = 0.05;

    public float $minAccelerationVolumeRatio = 0.80;

    public float $maxAccelerationPullbackPct = 0.65;

    public int $emaReclaimLookbackBars = 3;

    public float $maxEma9TouchDistanceAtr = 0.55;

    public float $maxEma9ReclaimDepthPct = 1.50;

    public float $minEmaReclaimVolumeRatio = 0.80;

    public float $stopAtrBuffer = 0.15;

    public float $stopPriceBufferPct = 0.04;

    public float $minStopPct = 0.80;

    public float $maxStopPct = 2.00;

    public float $minRoomToSessionHighR = 0.35;

    public float $trailingStopAtrMultiplier = 4.0;

    public float $minTrailingStopPct = 1.00;

    public float $maxTrailingStopPct = 2.25;

    public float $minSessionHighBreakScore = 40.0;

    public float $minLocalHighBreakScore = 41.0;

    public float $minAccelerationScore = 43.0;

    public float $minEmaReclaimScore = 44.0;

    // Trading windows are Eastern time and expressed as minutes after midnight.
    public int $morningStartMinute = 580;      // 09:40

    public int $morningEndMinute = 690;        // 11:30

    public int $afternoonStartMinute = 840;    // 14:00

    public int $afternoonEndMinute = 945;      // 15:45

    public int $morningScoreSplitMinute = 615; // 10:15

    public int $powerHourStartMinute = 900;    // 15:00

    public float $earlyMorningScoreMultiplier = 0.98;

    public float $lateMorningScoreMultiplier = 1.00;

    public float $earlyAfternoonScoreMultiplier = 0.96;

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
        'liquidity_failed' => 0,
        'extension_failed' => 0,
        'no_trigger' => 0,
        'session_high_detected' => 0,
        'local_high_detected' => 0,
        'acceleration_detected' => 0,
        'ema_reclaim_detected' => 0,
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
            'min_atr_pct' => $this->minAtrPct,
            'max_atr_pct' => $this->maxAtrPct,
            'max_above_vwap_pct' => $this->maxAboveVwapPct,
            'minimum_trend_ema_spread_pct' => $this->minimumTrendEmaSpreadPct,
            'strong_three_minute_move_pct' => $this->strongThreeMinuteMovePct,
            'min_body_pct' => $this->minBodyPct,
            'min_body_range_fraction' => $this->minBodyRangeFraction,
            'min_close_position' => $this->minClosePosition,
            'max_upper_wick_fraction' => $this->maxUpperWickFraction,
            'local_high_lookback_bars' => $this->localHighLookbackBars,
            'break_buffer_pct' => $this->breakBufferPct,
            'session_high_tolerance_pct' => $this->sessionHighTolerancePct,
            'max_break_distance_atr' => $this->maxBreakDistanceAtr,
            'min_high_break_volume_ratio' => $this->minHighBreakVolumeRatio,
            'min_high_break_move_3m_pct' => $this->minHighBreakMove3mPct,
            'acceleration_lookback_bars' => $this->accelerationLookbackBars,
            'min_acceleration_move_3m_pct' => $this->minAccelerationMove3mPct,
            'min_acceleration_last_bar_pct' => $this->minAccelerationLastBarPct,
            'min_acceleration_volume_ratio' => $this->minAccelerationVolumeRatio,
            'max_acceleration_pullback_pct' => $this->maxAccelerationPullbackPct,
            'ema_reclaim_lookback_bars' => $this->emaReclaimLookbackBars,
            'max_ema9_touch_distance_atr' => $this->maxEma9TouchDistanceAtr,
            'max_ema9_reclaim_depth_pct' => $this->maxEma9ReclaimDepthPct,
            'min_ema_reclaim_volume_ratio' => $this->minEmaReclaimVolumeRatio,
            'stop_atr_buffer' => $this->stopAtrBuffer,
            'stop_price_buffer_pct' => $this->stopPriceBufferPct,
            'min_stop_pct' => $this->minStopPct,
            'max_stop_pct' => $this->maxStopPct,
            'min_room_to_session_high_r' => $this->minRoomToSessionHighR,
            'trailing_stop_atr_multiplier' => $this->trailingStopAtrMultiplier,
            'min_trailing_stop_pct' => $this->minTrailingStopPct,
            'max_trailing_stop_pct' => $this->maxTrailingStopPct,
            'min_session_high_break_score' => $this->minSessionHighBreakScore,
            'min_local_high_break_score' => $this->minLocalHighBreakScore,
            'min_acceleration_score' => $this->minAccelerationScore,
            'min_ema_reclaim_score' => $this->minEmaReclaimScore,
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
                'pattern' => 'MOMENTUM_ACCELERATION_CONTINUATION',
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
        if (! $this->allowLunch && ! $this->isAllowedTime($asOfTsEst)) {
            self::$dbg['time_blocked']++;

            return [null, 'time_blocked'];
        }

        $asOfEpoch = strtotime($asOfTsEst);
        $signalEpoch = strtotime($signalTsEst);
        if ($asOfEpoch === false || $signalEpoch === false) {
            return [null, 'invalid_timestamp'];
        }

        $signalAgeSeconds = $asOfEpoch - $signalEpoch;
        if ($signalAgeSeconds < 0 || $signalAgeSeconds > (max(1, $this->maxSignalAgeMinutes) * 60)) {
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

        $minBars = max(6, $this->minBars);
        if (count($rows) < $minBars) {
            self::$dbg['not_enough_bars']++;

            return [null, 'not_enough_bars'];
        }

        $bars = $this->normalizeBars(
            $rows,
            max(5, $this->atrPeriod1m),
            max(5, $this->volLookback1m)
        );
        if ($bars === null || count($bars) < $minBars) {
            self::$dbg['bad_data']++;

            return [null, 'bad_data'];
        }

        $lastIndex = count($bars) - 1;
        $earliestIndex = max($minBars - 1, $lastIndex - (max(1, $this->maxEntryAgeMinutes) + 1));
        $candidates = [];

        for ($i = $lastIndex; $i >= $earliestIndex; $i--) {
            $bar = $bars[$i];
            $entryTs = (string) $bar['ts'];

            if (! $this->allowLunch && ! $this->isAllowedTime($entryTs)) {
                self::$dbg['entry_time_blocked']++;

                continue;
            }

            $entryEpoch = strtotime($entryTs);
            if ($entryEpoch === false) {
                continue;
            }

            $entryAgeSeconds = $asOfEpoch - $entryEpoch;
            if ($entryAgeSeconds < 0 || $entryAgeSeconds > (max(1, $this->maxEntryAgeMinutes) * 60)) {
                continue;
            }
            if ($entryEpoch < $signalEpoch) {
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

            if ($close <= 0 || $atr <= 0 || $vwap <= 0 || $ema9 <= 0 || $ema21 <= 0) {
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
            $notional = $close * $volume;
            $move1mPct = $i > 0 && (float) $bars[$i - 1]['close'] > 0
                ? (($close - (float) $bars[$i - 1]['close']) / (float) $bars[$i - 1]['close']) * 100.0
                : 0.0;
            $move3mPct = $this->movePct($bars, max(0, $i - 3), $i);

            $trendOk =
                $close > $vwap
                && $close >= ($ema9 * 0.9975)
                && (
                    $emaSpreadPct >= $this->minimumTrendEmaSpreadPct
                    || $move3mPct >= $this->strongThreeMinuteMovePct
                );
            if (! $trendOk) {
                self::$dbg['trend_failed']++;

                continue;
            }

            if (
                $close <= $open
                || $bodyPct < $this->minBodyPct
                || $bodyRangeFraction < $this->minBodyRangeFraction
                || $closePosition < $this->minClosePosition
                || $upperWickFraction > $this->maxUpperWickFraction
            ) {
                self::$dbg['quality_failed']++;

                continue;
            }

            if ($notional < $this->minNotional1m) {
                self::$dbg['liquidity_failed']++;

                continue;
            }

            if (
                $atrPct < $this->minAtrPct
                || $atrPct > $this->maxAtrPct
                || $aboveVwapPct > $this->maxAboveVwapPct
            ) {
                self::$dbg['extension_failed']++;

                continue;
            }

            $trigger = null;
            $structureLow = null;
            $breakLevel = null;
            $triggerMeta = [];

            $sessionHighBreak = $this->detectHighBreak(
                $bars,
                $i,
                0,
                $this->minHighBreakVolumeRatio,
                $this->minHighBreakMove3mPct,
                true
            );
            if ($sessionHighBreak !== null) {
                $trigger = 'SESSION_HIGH_BREAK_1M';
                $structureLow = (float) $sessionHighBreak['structure_low'];
                $breakLevel = (float) $sessionHighBreak['break_level'];
                $triggerMeta = $sessionHighBreak;
                self::$dbg['session_high_detected']++;
            }

            if ($trigger === null) {
                $localHighBreak = $this->detectHighBreak(
                    $bars,
                    $i,
                    max(2, $this->localHighLookbackBars),
                    $this->minHighBreakVolumeRatio,
                    $this->minHighBreakMove3mPct,
                    false
                );
                if ($localHighBreak !== null) {
                    $trigger = 'LOCAL_HIGH_BREAK_1M';
                    $structureLow = (float) $localHighBreak['structure_low'];
                    $breakLevel = (float) $localHighBreak['break_level'];
                    $triggerMeta = $localHighBreak;
                    self::$dbg['local_high_detected']++;
                }
            }

            if ($trigger === null) {
                $acceleration = $this->detectMomentumAcceleration($bars, $i);
                if ($acceleration !== null) {
                    $trigger = 'MOMENTUM_ACCELERATION_1M';
                    $structureLow = (float) $acceleration['structure_low'];
                    $breakLevel = (float) $acceleration['break_level'];
                    $triggerMeta = $acceleration;
                    self::$dbg['acceleration_detected']++;
                }
            }

            if ($trigger === null) {
                $emaReclaim = $this->detectEma9Reclaim($bars, $i);
                if ($emaReclaim !== null) {
                    $trigger = 'EMA9_RECLAIM_CONTINUATION_1M';
                    $structureLow = (float) $emaReclaim['structure_low'];
                    $breakLevel = (float) $emaReclaim['break_level'];
                    $triggerMeta = $emaReclaim;
                    self::$dbg['ema_reclaim_detected']++;
                }
            }

            if ($trigger === null || $structureLow === null || $breakLevel === null) {
                self::$dbg['no_trigger']++;

                continue;
            }

            $breakDistanceAtr = max(0.0, ($close - $breakLevel) / $atr);
            if ($breakDistanceAtr > $this->maxBreakDistanceAtr) {
                self::$dbg['extension_failed']++;

                continue;
            }

            $buffer = max(
                $atr * $this->stopAtrBuffer,
                $close * ($this->stopPriceBufferPct / 100.0)
            );
            $stop = max(0.01, $structureLow - $buffer);
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

            $sessionHighBefore = $this->maxField($bars, 0, $i - 1, 'high');
            $breaksSessionHigh = $close >= ($sessionHighBefore * (1.0 - ($this->sessionHighTolerancePct / 100.0)));
            $roomToSessionHighR = $sessionHighBefore > $close
                ? ($sessionHighBefore - $close) / $riskPerShare
                : 999.0;

            if (! $breaksSessionHigh && $roomToSessionHighR < $this->minRoomToSessionHighR) {
                self::$dbg['resistance_failed']++;

                continue;
            }

            $scoreParts = $this->scoreEntry(
                $trigger,
                $volumeRatio,
                $move1mPct,
                $move3mPct,
                $bodyRangeFraction,
                $closePosition,
                $upperWickFraction,
                $aboveVwapPct,
                $emaSpreadPct,
                $riskPct,
                $entryTs
            );

            $requiredScore = match ($trigger) {
                'SESSION_HIGH_BREAK_1M' => $this->minSessionHighBreakScore,
                'LOCAL_HIGH_BREAK_1M' => $this->minLocalHighBreakScore,
                'MOMENTUM_ACCELERATION_1M' => $this->minAccelerationScore,
                default => $this->minEmaReclaimScore,
            };

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
                'pattern' => 'MOMENTUM_ACCELERATION_CONTINUATION',
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
                    'break_distance_atr' => round($breakDistanceAtr, 4),
                    'structure_low' => round($structureLow, 6),
                    'session_high_before_entry' => round($sessionHighBefore, 6),
                    'breaks_session_high' => $breaksSessionHigh,
                    'room_to_session_high_r' => round($roomToSessionHighR, 4),
                    'entry_open' => round($open, 6),
                    'entry_high' => round($high, 6),
                    'entry_low' => round($low, 6),
                    'entry_volume' => $volume,
                    'entry_notional' => round($notional, 2),
                    'move_1m_pct' => round($move1mPct, 4),
                    'move_3m_pct' => round($move3mPct, 4),
                    'body_pct' => round($bodyPct, 4),
                    'body_range_fraction' => round($bodyRangeFraction, 4),
                    'close_position' => round($closePosition, 4),
                    'upper_wick_fraction' => round($upperWickFraction, 4),
                    'momentum_score' => $scoreParts['momentum_score'],
                    'trend_score' => $scoreParts['trend_score'],
                    'quality_score' => $scoreParts['quality_score'],
                    'risk_score' => $scoreParts['risk_score'],
                    'required_entry_score' => $requiredScore,
                ]),
            ];
        }

        if ($candidates === []) {
            return [null, 'no_valid_v56_entry'];
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
            Log::info('[EntryFinderV56] accepted entry', [
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

            $priorVolumes = array_slice($volumes, -min($volLookback, count($volumes)));
            $avgPriorVolume = $priorVolumes === [] ? 0.0 : array_sum($priorVolumes) / count($priorVolumes);
            $volumeRatio = $avgPriorVolume > 0 ? $volume / $avgPriorVolume : 0.0;
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
     * Detect a local or session high break.
     *
     * @param  array<int, array<string, float|string>>  $bars
     * @return array<string, float|int|bool>|null
     */
    private function detectHighBreak(
        array $bars,
        int $i,
        int $lookbackBars,
        float $minimumVolumeRatio,
        float $minimumMove3mPct,
        bool $sessionHigh
    ): ?array {
        if ($i < 2) {
            return null;
        }

        $start = $sessionHigh ? 0 : max(0, $i - max(2, $lookbackBars));
        $priorHigh = $this->maxField($bars, $start, $i - 1, 'high');
        if ($priorHigh <= 0) {
            return null;
        }

        $close = (float) $bars[$i]['close'];
        $atr = (float) $bars[$i]['atr'];
        $volumeRatio = (float) $bars[$i]['volume_ratio'];
        $move3mPct = $this->movePct($bars, max(0, $i - 3), $i);
        $threshold = $priorHigh * (1.0 + ($this->breakBufferPct / 100.0));
        $toleranceThreshold = $priorHigh * (1.0 - ($this->sessionHighTolerancePct / 100.0));

        $passesPrice = $sessionHigh ? $close >= $toleranceThreshold : $close >= $threshold;
        if (
            ! $passesPrice
            || $volumeRatio < $minimumVolumeRatio
            || $move3mPct < $minimumMove3mPct
            || ($atr > 0 && (($close - $priorHigh) / $atr) > $this->maxBreakDistanceAtr)
        ) {
            return null;
        }

        $structureStart = max(0, $i - 3);
        $structureLow = $this->minField($bars, $structureStart, $i - 1, 'low');

        return [
            'break_level' => $priorHigh,
            'structure_low' => $structureLow,
            'lookback_bars' => $i - $start,
            'move_3m_pct' => $move3mPct,
            'session_high_break' => $sessionHigh,
        ];
    }

    /**
     * @param  array<int, array<string, float|string>>  $bars
     * @return array<string, float|int>|null
     */
    private function detectMomentumAcceleration(array $bars, int $i): ?array
    {
        $lookback = max(2, $this->accelerationLookbackBars);
        if ($i < $lookback) {
            return null;
        }

        $close = (float) $bars[$i]['close'];
        $previousClose = (float) $bars[$i - 1]['close'];
        $twoBackClose = (float) $bars[$i - 2]['close'];
        $volumeRatio = (float) $bars[$i]['volume_ratio'];
        $move3mPct = $this->movePct($bars, max(0, $i - $lookback), $i);
        $lastBarPct = $previousClose > 0 ? (($close - $previousClose) / $previousClose) * 100.0 : 0.0;

        $recentHigh = $this->maxField($bars, max(0, $i - $lookback), $i - 1, 'high');
        $recentLow = $this->minField($bars, max(0, $i - $lookback), $i - 1, 'low');
        $pullbackPct = $recentHigh > 0 ? (($recentHigh - $recentLow) / $recentHigh) * 100.0 : 0.0;

        if (
            $close <= $previousClose
            || $previousClose < $twoBackClose
            || $move3mPct < $this->minAccelerationMove3mPct
            || $lastBarPct < $this->minAccelerationLastBarPct
            || $volumeRatio < $this->minAccelerationVolumeRatio
            || $pullbackPct > $this->maxAccelerationPullbackPct
        ) {
            return null;
        }

        return [
            'break_level' => max((float) $bars[$i - 1]['high'], $previousClose),
            'structure_low' => $recentLow,
            'move_3m_pct' => $move3mPct,
            'last_bar_pct' => $lastBarPct,
            'pullback_pct' => $pullbackPct,
        ];
    }

    /**
     * @param  array<int, array<string, float|string>>  $bars
     * @return array<string, float|int>|null
     */
    private function detectEma9Reclaim(array $bars, int $i): ?array
    {
        $lookback = max(2, $this->emaReclaimLookbackBars);
        if ($i < $lookback) {
            return null;
        }

        $previous = $bars[$i - 1];
        $current = $bars[$i];
        $currentClose = (float) $current['close'];
        $previousHigh = (float) $previous['high'];
        $currentEma9 = (float) $current['ema9'];
        $currentAtr = (float) $current['atr'];
        $volumeRatio = (float) $current['volume_ratio'];

        $start = max(0, $i - $lookback);
        $structureLow = $this->minField($bars, $start, $i - 1, 'low');
        $touchDistance = PHP_FLOAT_MAX;
        $reclaimDepthPct = 0.0;

        for ($j = $start; $j <= $i - 1; $j++) {
            $barLow = (float) $bars[$j]['low'];
            $barEma9 = (float) $bars[$j]['ema9'];
            $barAtr = max(1e-9, (float) $bars[$j]['atr']);
            $touchDistance = min($touchDistance, abs($barLow - $barEma9) / $barAtr);
            if ($barEma9 > 0 && $barLow < $barEma9) {
                $reclaimDepthPct = max($reclaimDepthPct, (($barEma9 - $barLow) / $barEma9) * 100.0);
            }
        }

        if (
            $currentClose <= $previousHigh
            || $currentClose <= $currentEma9
            || $volumeRatio < $this->minEmaReclaimVolumeRatio
            || $touchDistance > $this->maxEma9TouchDistanceAtr
            || $reclaimDepthPct > $this->maxEma9ReclaimDepthPct
            || ($currentAtr > 0 && (($currentClose - $previousHigh) / $currentAtr) > $this->maxBreakDistanceAtr)
        ) {
            return null;
        }

        return [
            'break_level' => $previousHigh,
            'structure_low' => $structureLow,
            'ema9_touch_distance_atr' => $touchDistance,
            'ema9_reclaim_depth_pct' => $reclaimDepthPct,
            'lookback_bars' => $lookback,
        ];
    }

    /**
     * @param  array<int, array<string, float|string>>  $bars
     * @return array<string, float>
     */
    private function scoreEntry(
        string $trigger,
        float $volumeRatio,
        float $move1mPct,
        float $move3mPct,
        float $bodyRangeFraction,
        float $closePosition,
        float $upperWickFraction,
        float $aboveVwapPct,
        float $emaSpreadPct,
        float $riskPct,
        string $entryTs
    ): array {
        $volumeScore = $this->clamp(($volumeRatio - 0.65) / 2.00);
        $move1Score = $this->clamp(($move1mPct - 0.01) / 0.75);
        $move3Score = $this->clamp(($move3mPct - 0.08) / 1.50);
        $triggerBonus = match ($trigger) {
            'SESSION_HIGH_BREAK_1M' => 1.00,
            'LOCAL_HIGH_BREAK_1M' => 0.88,
            'MOMENTUM_ACCELERATION_1M' => 0.78,
            default => 0.72,
        };
        $momentumScore = 100.0 * (
            (0.30 * $volumeScore)
            + (0.25 * $move1Score)
            + (0.30 * $move3Score)
            + (0.15 * $triggerBonus)
        );

        $emaScore = $this->clamp(($emaSpreadPct + 0.06) / 0.40);
        $vwapScore = 1.0 - $this->clamp($aboveVwapPct / max(0.01, $this->maxAboveVwapPct));
        $trendScore = 100.0 * ((0.60 * $emaScore) + (0.40 * $vwapScore));

        $bodyScore = $this->clamp(($bodyRangeFraction - 0.25) / 0.65);
        $closeScore = $this->clamp(($closePosition - 0.45) / 0.50);
        $wickScore = 1.0 - $this->clamp($upperWickFraction / max(0.01, $this->maxUpperWickFraction));
        $qualityScore = 100.0 * ((0.40 * $bodyScore) + (0.40 * $closeScore) + (0.20 * $wickScore));

        $idealRisk = 1.05;
        $riskScore = 100.0 * (1.0 - $this->clamp(abs($riskPct - $idealRisk) / 1.25));

        $rawScore =
            (0.42 * $momentumScore)
            + (0.20 * $trendScore)
            + (0.24 * $qualityScore)
            + (0.14 * $riskScore);

        $score = $rawScore * $this->timeScoreMultiplier($entryTs);

        return [
            'score' => round($this->clamp($score, 0.0, 100.0), 3),
            'momentum_score' => round($momentumScore, 3),
            'trend_score' => round($trendScore, 3),
            'quality_score' => round($qualityScore, 3),
            'risk_score' => round($riskScore, 3),
        ];
    }

    /** @param array<int, array<string, float|string>> $bars */
    private function movePct(array $bars, int $start, int $end): float
    {
        if (! isset($bars[$start], $bars[$end])) {
            return 0.0;
        }

        $startClose = (float) $bars[$start]['close'];
        $endClose = (float) $bars[$end]['close'];

        return $startClose > 0 ? (($endClose - $startClose) / $startClose) * 100.0 : 0.0;
    }

    /** @param array<int, array<string, float|string>> $bars */
    private function maxField(array $bars, int $start, int $end, string $field): float
    {
        if ($end < $start) {
            return 0.0;
        }

        $max = 0.0;
        for ($i = max(0, $start); $i <= min(count($bars) - 1, $end); $i++) {
            $max = max($max, (float) ($bars[$i][$field] ?? 0.0));
        }

        return $max;
    }

    /** @param array<int, array<string, float|string>> $bars */
    private function minField(array $bars, int $start, int $end, string $field): float
    {
        if ($end < $start) {
            return 0.0;
        }

        $min = PHP_FLOAT_MAX;
        for ($i = max(0, $start); $i <= min(count($bars) - 1, $end); $i++) {
            $min = min($min, (float) ($bars[$i][$field] ?? PHP_FLOAT_MAX));
        }

        return $min === PHP_FLOAT_MAX ? 0.0 : $min;
    }

    private function isAllowedTime(string $tsEst): bool
    {
        $epoch = strtotime($tsEst);
        if ($epoch === false) {
            return false;
        }

        $minute = ((int) date('H', $epoch) * 60) + (int) date('i', $epoch);

        return (
            $minute >= $this->morningStartMinute
            && $minute <= $this->morningEndMinute
        ) || (
            $minute >= $this->afternoonStartMinute
            && $minute <= $this->afternoonEndMinute
        );
    }

    private function timeScoreMultiplier(string $tsEst): float
    {
        $epoch = strtotime($tsEst);
        if ($epoch === false) {
            return 1.0;
        }

        $minute = ((int) date('H', $epoch) * 60) + (int) date('i', $epoch);
        if ($minute < $this->morningScoreSplitMinute) {
            return $this->earlyMorningScoreMultiplier;
        }
        if ($minute <= $this->morningEndMinute) {
            return $this->lateMorningScoreMultiplier;
        }
        if ($minute >= $this->powerHourStartMinute) {
            return $this->powerHourScoreMultiplier;
        }

        return $this->earlyAfternoonScoreMultiplier;
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

        Log::info('[EntryFinderV56] debug counters', array_merge(self::$dbg, [
            'pid' => getmypid(),
        ]));
    }

    private function clamp(float $value, float $min = 0.0, float $max = 1.0): float
    {
        return max($min, min($max, $value));
    }
}
