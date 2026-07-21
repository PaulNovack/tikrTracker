<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Log;

/**
 * Version 45 - Confirmed VWAP Higher-Low entry finder.
 *
 * The finder returns only one pattern so its ML training data remains clean:
 * VWAP_HIGHER_LOW_CONFIRMATION.
 *
 * Required sequence:
 * 1. A measurable impulse with volume.
 * 2. A 2-6 bar controlled pullback toward VWAP on generally contracting volume.
 * 3. The pullback low remains above the impulse base (higher low).
 * 4. A fresh confirmation candle breaks the previous bar high and holds
 *    within 0.05% of it while closing above VWAP and EMA9 with volume.
 * 5. The actual structural stop is between the configured risk limits.
 * 6. There is enough room back to the pre-entry high for the required R:R.
 */
class OneMinuteEntryFinderV45
{
    use HasPriceTables;

    private string $version = 'v45';

    private static array $dbg = [
        'called' => 0,
        'not_enough_bars' => 0,
        'time_blocked' => 0,
        'entry_time_blocked' => 0,
        'bad_data' => 0,

        // Aggregate confirmation count plus exact first-failure reasons.
        'confirmation_failed' => 0,
        'confirm_not_green' => 0,
        'confirm_no_high_break' => 0,
        'confirm_below_vwap' => 0,
        'confirm_below_ema9' => 0,
        'confirm_ema_trend' => 0,
        'confirm_low_volume' => 0,
        'confirm_small_body' => 0,
        'confirm_weak_close' => 0,
        'confirm_extended' => 0,
        'confirm_low_notional' => 0,
        'confirmation_break' => 0,

        // Aggregate impulse count plus exact first-failure reasons.
        'impulse_failed' => 0,
        'impulse_structure_failed' => 0,
        'impulse_move_failed' => 0,
        'impulse_atr_failed' => 0,
        'impulse_green_failed' => 0,
        'impulse_volume_failed' => 0,

        // Aggregate pullback count plus exact first-failure reasons.
        'pullback_failed' => 0,
        'pullback_depth_failed' => 0,
        'higher_low_failed' => 0,
        'pullback_volume_failed' => 0,
        'pullback_bear_failed' => 0,
        'pullback_vwap_failed' => 0,

        'risk_failed' => 0,
        'room_failed' => 0,
        'no_valid_sequence' => 0,
        'signal_stale' => 0,
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

    /**
     * Required pipeline signature.
     *
     * @return array<string, mixed>
     */
    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        ...$rest
    ): array {
        self::$dbg['called']++;

        $cfg = (array) config('trading.v45.entry', []);

        $allowLunch = (bool) ($cfg['allow_lunch'] ?? false);
        if (! $allowLunch && ! $this->isAllowedTime($asOfTsEst)) {
            self::$dbg['time_blocked']++;

            return ['ok' => 0, 'best_entry' => null, 'reason' => 'time_blocked'];
        }

        $minBars = (int) ($cfg['min_bars'] ?? 12);
        $atrPeriod = (int) ($cfg['atr_period_1m'] ?? 14);
        $volLookback = (int) ($cfg['vol_lookback_1m'] ?? 20);
        $maxEntryAgeMinutes = (int) ($cfg['max_entry_age_minutes'] ?? 4);
        $maxSignalAgeMinutes = (int) ($cfg['max_signal_age_minutes']
            ?? config('trading.v45.scanner.active_window_minutes', 8));

        $minNotional1m = (float) ($cfg['min_notional_1m'] ?? 50000.0);
        $minConfirmationVolRatio = (float) ($cfg['min_confirmation_vol_ratio'] ?? 0.85);
        $minConfirmationBodyPct = (float) ($cfg['min_confirmation_body_pct'] ?? 0.03);
        $minConfirmationClosePosition = (float) ($cfg['min_confirmation_close_position'] ?? 0.55);
        $maxAboveVwapPct = (float) ($cfg['max_above_vwap_entry_pct'] ?? 1.25);

        $minImpulsePct = (float) ($cfg['min_impulse_pct'] ?? 0.40);
        $minImpulseAtr = (float) ($cfg['min_impulse_atr'] ?? 0.90);
        $minImpulseVolRatio = (float) ($cfg['min_impulse_vol_ratio'] ?? 0.80);
        $minImpulseBars = (int) ($cfg['min_impulse_bars'] ?? 2);
        $maxImpulseBars = (int) ($cfg['max_impulse_bars'] ?? 8);

        $minPullbackPct = (float) ($cfg['min_pullback_pct'] ?? 8.0);
        $maxPullbackPct = (float) ($cfg['max_pullback_pct'] ?? 70.0);
        $minPullbackBars = (int) ($cfg['min_pullback_bars'] ?? 2);
        $maxPullbackBars = (int) ($cfg['max_pullback_bars'] ?? 6);
        $maxPullbackVolumeRatio = (float) ($cfg['max_pullback_volume_ratio'] ?? 1.00);
        $maxCloseBelowVwapPct = (float) ($cfg['max_close_below_vwap_pct'] ?? 0.25);
        $maxPullbackDistanceToVwapPct = (float) ($cfg['max_pullback_distance_to_vwap_pct'] ?? 0.50);
        $maxBearBodyAtr = (float) ($cfg['max_bear_body_atr'] ?? 0.85);
        $minHigherLowPct = (float) ($cfg['min_higher_low_pct'] ?? 0.03);

        $stopAtrBuffer = (float) ($cfg['stop_atr_buffer'] ?? 0.10);
        $stopPriceBufferPct = (float) ($cfg['stop_price_buffer_pct'] ?? 0.05);
        $minStopPct = (float) ($cfg['min_stop_pct'] ?? 0.35);
        $maxStopPct = (float) ($cfg['max_stop_pct'] ?? 1.00);
        $minRewardRisk = (float) ($cfg['min_reward_risk'] ?? 1.50);

        $asOfEpoch = strtotime($asOfTsEst);
        $signalEpoch = strtotime($signalTsEst);
        if ($asOfEpoch === false || $signalEpoch === false) {
            return ['ok' => 0, 'best_entry' => null, 'reason' => 'bad_timestamp'];
        }

        $signalAgeSeconds = $asOfEpoch - $signalEpoch;
        if (
            $signalAgeSeconds < 0
            || ($maxSignalAgeMinutes > 0 && $signalAgeSeconds > ($maxSignalAgeMinutes * 60))
        ) {
            self::$dbg['signal_stale']++;

            return ['ok' => 0, 'best_entry' => null, 'reason' => 'signal_stale'];
        }

        $tradeDate = substr($signalTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';
        $table = $this->oneMinuteTable;

        $bars = $this->dbSelect("
            SELECT ts_est, `open`, high, low, price AS close, volume
            FROM {$table}
            WHERE asset_type = ?
              AND symbol = ?
              AND trading_date_est = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ", [$assetType, $symbol, $tradeDate, $marketOpen, $asOfTsEst]);

        if (count($bars) < $minBars) {
            self::$dbg['not_enough_bars']++;

            return ['ok' => 0, 'best_entry' => null, 'reason' => 'not_enough_bars'];
        }

        if (! $this->passesDataQuality($bars)) {
            self::$dbg['bad_data']++;

            return ['ok' => 0, 'best_entry' => null, 'reason' => 'bad_data'];
        }

        $norm = $this->normalizeBars($bars);
        if (count($norm) < $minBars) {
            self::$dbg['not_enough_bars']++;

            return ['ok' => 0, 'best_entry' => null, 'reason' => 'not_enough_bars'];
        }

        $candidates = [];
        $lastIndex = count($norm) - 1;
        $earliestConfirmationIndex = max(
            max(2, $minBars - 1),
            $lastIndex - max(1, $maxEntryAgeMinutes + 1)
        );

        // Search freshest confirmation bars first, but retain all valid candidates
        // so score can break a tie between adjacent confirmation candles.
        for ($confirmationIndex = $lastIndex; $confirmationIndex >= $earliestConfirmationIndex; $confirmationIndex--) {
            $confirmation = $norm[$confirmationIndex];

            // Validate the actual entry candle time. The finder can search backward
            // several minutes, so checking only $asOfTsEst can accidentally admit
            // a lunch-period candle when the finder is called at 14:00.
            if (! $allowLunch && ! $this->isAllowedTime((string) $confirmation['ts'])) {
                self::$dbg['entry_time_blocked']++;

                continue;
            }

            $entryEpoch = strtotime((string) $confirmation['ts']);
            if ($entryEpoch === false) {
                continue;
            }

            $entryAgeSeconds = $asOfEpoch - $entryEpoch;
            if (
                $entryAgeSeconds < 0
                || ($maxEntryAgeMinutes > 0 && $entryAgeSeconds > ($maxEntryAgeMinutes * 60))
            ) {
                continue;
            }

            $atr = $this->atrAt($norm, $confirmationIndex, $atrPeriod);
            if ($atr <= 0) {
                continue;
            }

            $confirmationMetrics = $this->confirmationMetrics(
                $norm,
                $confirmationIndex,
                $volLookback
            );

            if (! $this->passesConfirmation(
                $confirmationMetrics,
                $minNotional1m,
                $minConfirmationVolRatio,
                $minConfirmationBodyPct,
                $minConfirmationClosePosition,
                $maxAboveVwapPct
            )) {
                continue;
            }

            $bestSequence = null;

            for ($pullbackBars = $minPullbackBars; $pullbackBars <= $maxPullbackBars; $pullbackBars++) {
                $pullbackStart = $confirmationIndex - $pullbackBars;
                $pullbackEnd = $confirmationIndex - 1;
                if ($pullbackStart < 3) {
                    continue;
                }

                // The confirmation must clear the immediately prior pullback bar.
                // Requiring two-bar clearance eliminated nearly every live/backtest setup.
                $priorPullbackHigh = (float) $norm[$pullbackEnd]['high'];
                $confirmationHigh = (float) $confirmation['high'];
                $confirmationClose = (float) $confirmation['close'];

                $wickBrokeHigh = $confirmationHigh > $priorPullbackHigh;
                $closeHeldHigh = $confirmationClose >= ($priorPullbackHigh * 0.9995);

                if (! $wickBrokeHigh || ! $closeHeldHigh) {
                    self::$dbg['confirmation_break']++;

                    continue;
                }

                for ($impulseBars = $minImpulseBars; $impulseBars <= $maxImpulseBars; $impulseBars++) {
                    $impulseEnd = $pullbackStart - 1;
                    $impulseStart = $impulseEnd - $impulseBars + 1;
                    if ($impulseStart < 2) {
                        continue;
                    }

                    $sequence = $this->evaluateSequence(
                        $norm,
                        $confirmationIndex,
                        $impulseStart,
                        $impulseEnd,
                        $pullbackStart,
                        $pullbackEnd,
                        $atr,
                        $volLookback,
                        $minImpulsePct,
                        $minImpulseAtr,
                        $minImpulseVolRatio,
                        $minPullbackPct,
                        $maxPullbackPct,
                        $maxPullbackVolumeRatio,
                        $maxCloseBelowVwapPct,
                        $maxPullbackDistanceToVwapPct,
                        $maxBearBodyAtr,
                        $minHigherLowPct,
                        $stopAtrBuffer,
                        $stopPriceBufferPct,
                        $minStopPct,
                        $maxStopPct,
                        $minRewardRisk,
                        $confirmationMetrics
                    );

                    if ($sequence === null) {
                        continue;
                    }

                    if (
                        $bestSequence === null
                        || (float) $sequence['score'] > (float) $bestSequence['score']
                    ) {
                        $bestSequence = $sequence;
                    }
                }
            }

            if ($bestSequence !== null) {
                $bestSequence['entry_age_seconds'] = $entryAgeSeconds;
                $bestSequence['signal_age_seconds'] = $signalAgeSeconds;
                $candidates[] = $bestSequence;
            }
        }

        if ($candidates === []) {
            self::$dbg['no_valid_sequence']++;

            return ['ok' => 0, 'best_entry' => null, 'reason' => 'no_valid_sequence'];
        }

        usort($candidates, static function (array $a, array $b): int {
            $ageCompare = ($a['entry_age_seconds'] ?? PHP_INT_MAX)
                <=> ($b['entry_age_seconds'] ?? PHP_INT_MAX);
            if ($ageCompare !== 0) {
                return $ageCompare;
            }

            return ($b['score'] ?? 0.0) <=> ($a['score'] ?? 0.0);
        });

        $best = $candidates[0];
        $risk = max(1e-9, (float) $best['entry'] - (float) $best['stop']);
        $best['risk_per_share'] = round($risk, 6);
        $best['risk_pct'] = round(($risk / (float) $best['entry']) * 100.0, 4);
        $best['targets'] = [
            '1R' => round((float) $best['entry'] + $risk, 6),
            '2R' => round((float) $best['entry'] + (2.0 * $risk), 6),
            '3R' => round((float) $best['entry'] + (3.0 * $risk), 6),
        ];

        $atrMultiplier = \App\Services\TradingSettingService::getStopLossAtrMultiplier();
        $minPct = \App\Services\TradingSettingService::getStopLossAtrMinPct();
        $maxPct = \App\Services\TradingSettingService::getStopLossAtrMaxPct();
        $calculatedPct = ((float) $best['entry'] > 0)
            ? (((float) $best['atr'] * $atrMultiplier) / (float) $best['entry']) * 100.0
            : $minPct;
        $trailPct = max($minPct, min($maxPct, $calculatedPct));
        $trail = (float) $best['entry'] * ($trailPct / 100.0);
        $best['suggested_trailing_stop'] = round($trail, 6);
        $best['suggested_trailing_stop_pct'] = round($trailPct, 4);

        if ($this->isDebugEnabled()) {
            Log::info('[EntryFinderV45] accepted entry', [
                'symbol' => $symbol,
                'signal_ts_est' => $signalTsEst,
                'as_of_ts_est' => $asOfTsEst,
                'entry_ts_est' => $best['entry_ts_est'] ?? null,
                'entry' => $best['entry'] ?? null,
                'stop' => $best['stop'] ?? null,
                'score' => $best['score'] ?? null,
                'atr_pct' => $best['atr_pct'] ?? null,
                'required_stop_pct' => $best['required_stop_pct'] ?? null,
                'room_to_hod_r' => $best['room_to_hod_r'] ?? null,
                'above_vwap_pct' => $best['above_vwap_pct'] ?? null,
                'confirmation_volume_ratio' => $best['confirmation_volume_ratio'] ?? null,
                'confirmation_body_pct' => $best['confirmation_body_pct'] ?? null,
                'confirmation_close_position' => $best['confirmation_close_position'] ?? null,
                'confirmation_break_pct' => $best['confirmation_break_pct'] ?? null,
                'impulse_pct' => $best['impulse_pct'] ?? null,
                'impulse_atr' => $best['impulse_atr'] ?? null,
                'impulse_volume_ratio' => $best['impulse_volume_ratio'] ?? null,
                'pullback_bars' => $best['pullback_bars'] ?? null,
                'pullback_depth_pct' => $best['pullback_depth_pct'] ?? null,
                'pullback_volume_contraction' => $best['pullback_volume_contraction'] ?? null,
                'higher_low_pct' => $best['higher_low_pct'] ?? null,
                'ema9_ema21_spread_pct' => $best['ema9_ema21_spread_pct'] ?? null,
                'impulse_score' => $best['impulse_score'] ?? null,
                'pullback_score' => $best['pullback_score'] ?? null,
                'confirmation_score' => $best['confirmation_score'] ?? null,
                'risk_room_score' => $best['risk_room_score'] ?? null,
                'pid' => getmypid(),
            ]);
        }

        self::$dbg['returned']++;
        $this->maybeLogDebug();

        $best['symbol'] = $symbol;
        $best['asset_type'] = $assetType;
        $best['signal_ts_est'] = $signalTsEst;

        return [
            'ok' => 1,
            'best_entry' => $best,
            'meta' => [
                'version' => $this->version,
                'pattern' => 'VWAP_HIGHER_LOW_CONFIRMATION',
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

    /** @return array<string, mixed>|null */
    private function findEntry(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst
    ): ?array {
        $cfg = (array) config('trading.v45.entry', []);

        $allowLunch = (bool) ($cfg['allow_lunch'] ?? false);
        if (! $allowLunch && ! $this->isAllowedTime($asOfTsEst)) {
            self::$dbg['time_blocked']++;

            return null;
        }

        $minBars = (int) ($cfg['min_bars'] ?? 12);
        $atrPeriod = (int) ($cfg['atr_period_1m'] ?? 14);
        $volLookback = (int) ($cfg['vol_lookback_1m'] ?? 20);
        $maxEntryAgeMinutes = (int) ($cfg['max_entry_age_minutes'] ?? 4);
        $maxSignalAgeMinutes = (int) ($cfg['max_signal_age_minutes']
            ?? config('trading.v45.scanner.active_window_minutes', 8));

        $minNotional1m = (float) ($cfg['min_notional_1m'] ?? 50000.0);
        $minConfirmationVolRatio = (float) ($cfg['min_confirmation_vol_ratio'] ?? 0.85);
        $minConfirmationBodyPct = (float) ($cfg['min_confirmation_body_pct'] ?? 0.03);
        $minConfirmationClosePosition = (float) ($cfg['min_confirmation_close_position'] ?? 0.55);
        $maxAboveVwapPct = (float) ($cfg['max_above_vwap_entry_pct'] ?? 1.25);

        $minImpulsePct = (float) ($cfg['min_impulse_pct'] ?? 0.40);
        $minImpulseAtr = (float) ($cfg['min_impulse_atr'] ?? 0.90);
        $minImpulseVolRatio = (float) ($cfg['min_impulse_vol_ratio'] ?? 0.80);
        $minImpulseBars = (int) ($cfg['min_impulse_bars'] ?? 2);
        $maxImpulseBars = (int) ($cfg['max_impulse_bars'] ?? 8);

        $minPullbackPct = (float) ($cfg['min_pullback_pct'] ?? 8.0);
        $maxPullbackPct = (float) ($cfg['max_pullback_pct'] ?? 70.0);
        $minPullbackBars = (int) ($cfg['min_pullback_bars'] ?? 2);
        $maxPullbackBars = (int) ($cfg['max_pullback_bars'] ?? 6);
        $maxPullbackVolumeRatio = (float) ($cfg['max_pullback_volume_ratio'] ?? 1.00);
        $maxCloseBelowVwapPct = (float) ($cfg['max_close_below_vwap_pct'] ?? 0.25);
        $maxPullbackDistanceToVwapPct = (float) ($cfg['max_pullback_distance_to_vwap_pct'] ?? 0.50);
        $maxBearBodyAtr = (float) ($cfg['max_bear_body_atr'] ?? 0.85);
        $minHigherLowPct = (float) ($cfg['min_higher_low_pct'] ?? 0.03);

        $stopAtrBuffer = (float) ($cfg['stop_atr_buffer'] ?? 0.10);
        $stopPriceBufferPct = (float) ($cfg['stop_price_buffer_pct'] ?? 0.05);
        $minStopPct = (float) ($cfg['min_stop_pct'] ?? 0.35);
        $maxStopPct = (float) ($cfg['max_stop_pct'] ?? 1.00);
        $minRewardRisk = (float) ($cfg['min_reward_risk'] ?? 1.50);

        $asOfEpoch = strtotime($asOfTsEst);
        $signalEpoch = strtotime($signalTsEst);
        if ($asOfEpoch === false || $signalEpoch === false) {
            return null;
        }

        $signalAgeSeconds = $asOfEpoch - $signalEpoch;
        if (
            $signalAgeSeconds < 0
            || ($maxSignalAgeMinutes > 0 && $signalAgeSeconds > ($maxSignalAgeMinutes * 60))
        ) {
            self::$dbg['signal_stale']++;

            return null;
        }

        $tradeDate = substr($signalTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';
        $table = $this->oneMinuteTable;

        $bars = $this->dbSelect("
            SELECT ts_est, `open`, high, low, price AS close, volume
            FROM {$table}
            WHERE asset_type = ?
              AND symbol = ?
              AND trading_date_est = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ", [$assetType, $symbol, $tradeDate, $marketOpen, $asOfTsEst]);

        if (count($bars) < $minBars) {
            self::$dbg['not_enough_bars']++;

            return null;
        }

        if (! $this->passesDataQuality($bars)) {
            self::$dbg['bad_data']++;

            return null;
        }

        $norm = $this->normalizeBars($bars);
        if (count($norm) < $minBars) {
            self::$dbg['not_enough_bars']++;

            return null;
        }

        $candidates = [];
        $lastIndex = count($norm) - 1;
        $earliestConfirmationIndex = max(
            max(2, $minBars - 1),
            $lastIndex - max(1, $maxEntryAgeMinutes + 1)
        );

        // Search freshest confirmation bars first, but retain all valid candidates
        // so score can break a tie between adjacent confirmation candles.
        for ($confirmationIndex = $lastIndex; $confirmationIndex >= $earliestConfirmationIndex; $confirmationIndex--) {
            $confirmation = $norm[$confirmationIndex];

            // Validate the actual entry candle time. The finder can search backward
            // several minutes, so checking only $asOfTsEst can accidentally admit
            // a lunch-period candle when the finder is called at 14:00.
            if (! $allowLunch && ! $this->isAllowedTime((string) $confirmation['ts'])) {
                self::$dbg['entry_time_blocked']++;

                continue;
            }

            $entryEpoch = strtotime((string) $confirmation['ts']);
            if ($entryEpoch === false) {
                continue;
            }

            $entryAgeSeconds = $asOfEpoch - $entryEpoch;
            if (
                $entryAgeSeconds < 0
                || ($maxEntryAgeMinutes > 0 && $entryAgeSeconds > ($maxEntryAgeMinutes * 60))
            ) {
                continue;
            }

            $atr = $this->atrAt($norm, $confirmationIndex, $atrPeriod);
            if ($atr <= 0) {
                continue;
            }

            $confirmationMetrics = $this->confirmationMetrics(
                $norm,
                $confirmationIndex,
                $volLookback
            );

            if (! $this->passesConfirmation(
                $confirmationMetrics,
                $minNotional1m,
                $minConfirmationVolRatio,
                $minConfirmationBodyPct,
                $minConfirmationClosePosition,
                $maxAboveVwapPct
            )) {
                continue;
            }

            $bestSequence = null;

            for ($pullbackBars = $minPullbackBars; $pullbackBars <= $maxPullbackBars; $pullbackBars++) {
                $pullbackStart = $confirmationIndex - $pullbackBars;
                $pullbackEnd = $confirmationIndex - 1;
                if ($pullbackStart < 3) {
                    continue;
                }

                // The confirmation must clear the immediately prior pullback bar.
                // Requiring two-bar clearance eliminated nearly every live/backtest setup.
                $priorPullbackHigh = (float) $norm[$pullbackEnd]['high'];
                $confirmationHigh = (float) $confirmation['high'];
                $confirmationClose = (float) $confirmation['close'];

                $wickBrokeHigh = $confirmationHigh > $priorPullbackHigh;
                $closeHeldHigh = $confirmationClose >= ($priorPullbackHigh * 0.9995);

                if (! $wickBrokeHigh || ! $closeHeldHigh) {
                    self::$dbg['confirmation_break']++;

                    continue;
                }

                for ($impulseBars = $minImpulseBars; $impulseBars <= $maxImpulseBars; $impulseBars++) {
                    $impulseEnd = $pullbackStart - 1;
                    $impulseStart = $impulseEnd - $impulseBars + 1;
                    if ($impulseStart < 2) {
                        continue;
                    }

                    $sequence = $this->evaluateSequence(
                        $norm,
                        $confirmationIndex,
                        $impulseStart,
                        $impulseEnd,
                        $pullbackStart,
                        $pullbackEnd,
                        $atr,
                        $volLookback,
                        $minImpulsePct,
                        $minImpulseAtr,
                        $minImpulseVolRatio,
                        $minPullbackPct,
                        $maxPullbackPct,
                        $maxPullbackVolumeRatio,
                        $maxCloseBelowVwapPct,
                        $maxPullbackDistanceToVwapPct,
                        $maxBearBodyAtr,
                        $minHigherLowPct,
                        $stopAtrBuffer,
                        $stopPriceBufferPct,
                        $minStopPct,
                        $maxStopPct,
                        $minRewardRisk,
                        $confirmationMetrics
                    );

                    if ($sequence === null) {
                        continue;
                    }

                    if (
                        $bestSequence === null
                        || (float) $sequence['score'] > (float) $bestSequence['score']
                    ) {
                        $bestSequence = $sequence;
                    }
                }
            }

            if ($bestSequence !== null) {
                $bestSequence['entry_age_seconds'] = $entryAgeSeconds;
                $bestSequence['signal_age_seconds'] = $signalAgeSeconds;
                $candidates[] = $bestSequence;
            }
        }

        if ($candidates === []) {
            self::$dbg['no_valid_sequence']++;

            return null;
        }

        usort($candidates, static function (array $a, array $b): int {
            $ageCompare = ($a['entry_age_seconds'] ?? PHP_INT_MAX)
                <=> ($b['entry_age_seconds'] ?? PHP_INT_MAX);
            if ($ageCompare !== 0) {
                return $ageCompare;
            }

            return ($b['score'] ?? 0.0) <=> ($a['score'] ?? 0.0);
        });

        $best = $candidates[0];
        $risk = max(1e-9, (float) $best['entry'] - (float) $best['stop']);
        $best['risk_per_share'] = round($risk, 6);
        $best['risk_pct'] = round(($risk / (float) $best['entry']) * 100.0, 4);
        $best['targets'] = [
            '1R' => round((float) $best['entry'] + $risk, 6),
            '2R' => round((float) $best['entry'] + (2.0 * $risk), 6),
            '3R' => round((float) $best['entry'] + (3.0 * $risk), 6),
        ];

        $atrMultiplier = \App\Services\TradingSettingService::getStopLossAtrMultiplier();
        $minPct = \App\Services\TradingSettingService::getStopLossAtrMinPct();
        $maxPct = \App\Services\TradingSettingService::getStopLossAtrMaxPct();
        $calculatedPct = ((float) $best['entry'] > 0)
            ? (((float) $best['atr'] * $atrMultiplier) / (float) $best['entry']) * 100.0
            : $minPct;
        $trailPct = max($minPct, min($maxPct, $calculatedPct));
        $trail = (float) $best['entry'] * ($trailPct / 100.0);
        $best['suggested_trailing_stop'] = round($trail, 6);
        $best['suggested_trailing_stop_pct'] = round($trailPct, 4);

        if ($this->isDebugEnabled()) {
            Log::info('[EntryFinderV45] accepted entry', [
                'symbol' => $symbol,
                'signal_ts_est' => $signalTsEst,
                'as_of_ts_est' => $asOfTsEst,
                'entry_ts_est' => $best['entry_ts_est'] ?? null,
                'entry' => $best['entry'] ?? null,
                'stop' => $best['stop'] ?? null,
                'score' => $best['score'] ?? null,
                'atr_pct' => $best['atr_pct'] ?? null,
                'required_stop_pct' => $best['required_stop_pct'] ?? null,
                'room_to_hod_r' => $best['room_to_hod_r'] ?? null,
                'above_vwap_pct' => $best['above_vwap_pct'] ?? null,
                'confirmation_volume_ratio' => $best['confirmation_volume_ratio'] ?? null,
                'confirmation_body_pct' => $best['confirmation_body_pct'] ?? null,
                'confirmation_close_position' => $best['confirmation_close_position'] ?? null,
                'confirmation_break_pct' => $best['confirmation_break_pct'] ?? null,
                'impulse_pct' => $best['impulse_pct'] ?? null,
                'impulse_atr' => $best['impulse_atr'] ?? null,
                'impulse_volume_ratio' => $best['impulse_volume_ratio'] ?? null,
                'pullback_bars' => $best['pullback_bars'] ?? null,
                'pullback_depth_pct' => $best['pullback_depth_pct'] ?? null,
                'pullback_volume_contraction' => $best['pullback_volume_contraction'] ?? null,
                'higher_low_pct' => $best['higher_low_pct'] ?? null,
                'ema9_ema21_spread_pct' => $best['ema9_ema21_spread_pct'] ?? null,
                'impulse_score' => $best['impulse_score'] ?? null,
                'pullback_score' => $best['pullback_score'] ?? null,
                'confirmation_score' => $best['confirmation_score'] ?? null,
                'risk_room_score' => $best['risk_room_score'] ?? null,
                'pid' => getmypid(),
            ]);
        }

        return $best;
    }

    /**
     * @param  array<int, array<string, float|string>>  $norm
     * @param  array<string, float>  $confirmation
     * @return array<string, mixed>|null
     */
    private function evaluateSequence(
        array $norm,
        int $confirmationIndex,
        int $impulseStart,
        int $impulseEnd,
        int $pullbackStart,
        int $pullbackEnd,
        float $atr,
        int $volLookback,
        float $minImpulsePct,
        float $minImpulseAtr,
        float $minImpulseVolRatio,
        float $minPullbackPct,
        float $maxPullbackPct,
        float $maxPullbackVolumeRatio,
        float $maxCloseBelowVwapPct,
        float $maxPullbackDistanceToVwapPct,
        float $maxBearBodyAtr,
        float $minHigherLowPct,
        float $stopAtrBuffer,
        float $stopPriceBufferPct,
        float $minStopPct,
        float $maxStopPct,
        float $minRewardRisk,
        array $confirmation
    ): ?array {
        $impulseBase = $this->minField($norm, $impulseStart, min($impulseEnd, $impulseStart + 1), 'low');
        $impulsePeak = $this->maxField($norm, $impulseStart, $impulseEnd, 'high');
        $impulsePeakIndex = $this->indexOfMaxField($norm, $impulseStart, $impulseEnd, 'high');
        $impulseRange = $impulsePeak - $impulseBase;
        if ($impulseBase <= 0 || $impulseRange <= 0) {
            return null;
        }

        // A valid impulse should finish reasonably near its peak before the pullback.
        // Permit the peak within the final three impulse bars and up to 1 ATR of
        // consolidation so valid stair-step impulses are not discarded.
        if ($impulsePeakIndex < ($impulseEnd - 2)) {
            self::$dbg['impulse_failed']++;
            self::$dbg['impulse_structure_failed']++;

            return null;
        }
        $impulseEndClose = (float) $norm[$impulseEnd]['close'];
        if (($impulsePeak - $impulseEndClose) > (1.00 * $atr)) {
            self::$dbg['impulse_failed']++;
            self::$dbg['impulse_structure_failed']++;

            return null;
        }

        $impulsePct = ($impulseRange / $impulseBase) * 100.0;
        $impulseAtr = $impulseRange / $atr;
        if ($impulsePct < $minImpulsePct) {
            self::$dbg['impulse_failed']++;
            self::$dbg['impulse_move_failed']++;

            return null;
        }
        if ($impulseAtr < $minImpulseAtr) {
            self::$dbg['impulse_failed']++;
            self::$dbg['impulse_atr_failed']++;

            return null;
        }

        $impulseGreenBars = 0;
        for ($i = $impulseStart; $i <= $impulseEnd; $i++) {
            if ((float) $norm[$i]['close'] > (float) $norm[$i]['open']) {
                $impulseGreenBars++;
            }
        }
        if ($impulseGreenBars < 2) {
            self::$dbg['impulse_failed']++;
            self::$dbg['impulse_green_failed']++;

            return null;
        }

        $impulseAvgVolume = $this->averageField($norm, $impulseStart, $impulseEnd, 'volume');
        $baselineVolume = $this->averageField(
            $norm,
            max(0, $impulseStart - $volLookback),
            $impulseStart - 1,
            'volume'
        );
        $impulseVolumeRatio = $baselineVolume > 0 ? $impulseAvgVolume / $baselineVolume : 0.0;
        if ($impulseVolumeRatio < $minImpulseVolRatio) {
            self::$dbg['impulse_failed']++;
            self::$dbg['impulse_volume_failed']++;

            return null;
        }

        $pullbackLow = $this->minField($norm, $pullbackStart, $pullbackEnd, 'low');
        $pullbackLowIndex = $this->indexOfMinField($norm, $pullbackStart, $pullbackEnd, 'low');
        $pullbackDepthPct = (($impulsePeak - $pullbackLow) / $impulseRange) * 100.0;
        if ($pullbackDepthPct < $minPullbackPct || $pullbackDepthPct > $maxPullbackPct) {
            self::$dbg['pullback_failed']++;
            self::$dbg['pullback_depth_failed']++;

            return null;
        }

        $higherLowPct = (($pullbackLow - $impulseBase) / $impulseBase) * 100.0;
        if ($higherLowPct < $minHigherLowPct) {
            self::$dbg['pullback_failed']++;
            self::$dbg['higher_low_failed']++;

            return null;
        }

        $pullbackAverageVolume = $this->averageField($norm, $pullbackStart, $pullbackEnd, 'volume');
        $pullbackVolumeRatio = $impulseAvgVolume > 0
            ? $pullbackAverageVolume / $impulseAvgVolume
            : 999.0;
        // A pullback may use up to 1.05x impulse volume only when the
        // confirmation candle itself has returned to at least average volume.
        $allowedPullbackVolumeRatio = $confirmation['volume_ratio'] >= 1.00
            ? max($maxPullbackVolumeRatio, 1.05)
            : $maxPullbackVolumeRatio;

        if ($pullbackVolumeRatio > $allowedPullbackVolumeRatio) {
            self::$dbg['pullback_failed']++;
            self::$dbg['pullback_volume_failed']++;

            return null;
        }

        $redBars = 0;
        $minVwapDistancePct = 999.0;
        $maxCloseBelowVwapObservedPct = 0.0;
        $maxBearBodyAtrObserved = 0.0;

        for ($i = $pullbackStart; $i <= $pullbackEnd; $i++) {
            $bar = $norm[$i];
            $open = (float) $bar['open'];
            $close = (float) $bar['close'];
            $low = (float) $bar['low'];
            $vwap = (float) $bar['vwap'];

            if ($close < $open) {
                $redBars++;
                $bearBodyAtr = ($open - $close) / $atr;
                $maxBearBodyAtrObserved = max($maxBearBodyAtrObserved, $bearBodyAtr);
                if ($bearBodyAtr > $maxBearBodyAtr) {
                    self::$dbg['pullback_failed']++;
                    self::$dbg['pullback_bear_failed']++;

                    return null;
                }
            }

            if ($vwap > 0) {
                $closeBelowVwapPct = max(0.0, (($vwap - $close) / $vwap) * 100.0);
                $maxCloseBelowVwapObservedPct = max(
                    $maxCloseBelowVwapObservedPct,
                    $closeBelowVwapPct
                );
                if ($closeBelowVwapPct > $maxCloseBelowVwapPct) {
                    self::$dbg['pullback_failed']++;
                    self::$dbg['pullback_vwap_failed']++;

                    return null;
                }

                $distancePct = abs($low - $vwap) / $vwap * 100.0;
                $minVwapDistancePct = min($minVwapDistancePct, $distancePct);
            }
        }

        if ($redBars < 1 || $minVwapDistancePct > $maxPullbackDistanceToVwapPct) {
            self::$dbg['pullback_failed']++;
            self::$dbg['pullback_vwap_failed']++;

            return null;
        }

        // Require the low to occur before the confirmation, but not on the first
        // pullback bar only; this gives the setup time to prove support.
        if ($pullbackLowIndex < $pullbackStart || $pullbackLowIndex > $pullbackEnd) {
            return null;
        }

        $entryBar = $norm[$confirmationIndex];
        $entry = (float) $entryBar['close'];
        $stopBuffer = max(
            $atr * $stopAtrBuffer,
            $entry * ($stopPriceBufferPct / 100.0)
        );
        $stop = $pullbackLow - $stopBuffer;
        $risk = $entry - $stop;
        if ($entry <= 0 || $stop <= 0 || $risk <= 0) {
            return null;
        }

        // Do not move the stop to make the trade fit. Reject the setup when the
        // real higher-low stop lies outside the configured risk range.
        $riskPct = ($risk / $entry) * 100.0;
        if ($riskPct < $minStopPct || $riskPct > $maxStopPct) {
            self::$dbg['risk_failed']++;

            return null;
        }

        $hodBeforeEntry = $this->maxField($norm, 0, $confirmationIndex - 1, 'high');
        $roomToHod = $hodBeforeEntry - $entry;
        $roomToHodR = $roomToHod / $risk;
        if ($roomToHod <= 0 || $roomToHodR < $minRewardRisk) {
            self::$dbg['room_failed']++;

            return null;
        }

        $roomToHodPct = ($roomToHod / $entry) * 100.0;
        $roomToHodAtr = $roomToHod / $atr;
        $scoreParts = $this->scoreSequence(
            $impulsePct,
            $impulseAtr,
            $impulseVolumeRatio,
            $pullbackDepthPct,
            $pullbackVolumeRatio,
            $higherLowPct,
            $confirmation,
            $roomToHodR,
            (string) $entryBar['ts']
        );

        return [
            'type' => 'VWAP_HIGHER_LOW_CONFIRMATION',
            'entry_ts_est' => (string) $entryBar['ts'],
            'entry' => round($entry, 6),
            'stop' => round($stop, 6),
            'score' => $scoreParts['score'],
            'atr' => round($atr, 6),
            'atr_pct' => round(($atr / $entry) * 100.0, 4),
            'hod' => round($hodBeforeEntry, 6),
            'room_to_hod_pct' => round($roomToHodPct, 4),
            'room_to_hod_atr' => round($roomToHodAtr, 4),
            'room_to_hod_r' => round($roomToHodR, 4),
            'required_stop_pct' => round($riskPct, 4),

            // Generic entry features retained for existing ML feature loaders.
            'above_vwap_pct' => round($confirmation['above_vwap_pct'], 4),
            'above_vwap_entry_pct' => round($confirmation['above_vwap_pct'], 4),
            'entry_body_pct' => round($confirmation['body_pct'], 4),
            'entry_close_position' => round($confirmation['close_position'], 6),
            // Keep all common aliases so existing reports and ML loaders do not
            // display 0.0x merely because they expect a different field name.
            'vol_ratio' => round($confirmation['volume_ratio'], 4),
            'entry_volume_ratio' => round($confirmation['volume_ratio'], 4),
            'confirmation_volume_ratio' => round($confirmation['volume_ratio'], 4),
            'entry_notional_1m' => round($confirmation['notional'], 2),
            'entry_spread_strength' => $scoreParts['trend_score'],
            'entry_vwap_dist_score' => $scoreParts['vwap_location_score'],
            'entry_atr_score' => $scoreParts['risk_room_score'],
            'entry_vol_score' => $scoreParts['confirmation_score'],
            'entry_candle_score' => round($confirmation['close_position'], 6),
            'entry_time_bonus' => $scoreParts['time_score'],

            // Pattern-specific first-class ML features.
            'impulse_start_ts_est' => (string) $norm[$impulseStart]['ts'],
            'impulse_end_ts_est' => (string) $norm[$impulseEnd]['ts'],
            'impulse_bars' => ($impulseEnd - $impulseStart) + 1,
            'impulse_pct' => round($impulsePct, 4),
            'impulse_atr' => round($impulseAtr, 4),
            'impulse_green_bars' => $impulseGreenBars,
            'impulse_volume_ratio' => round($impulseVolumeRatio, 4),
            'pullback_start_ts_est' => (string) $norm[$pullbackStart]['ts'],
            'pullback_end_ts_est' => (string) $norm[$pullbackEnd]['ts'],
            'pullback_bars' => ($pullbackEnd - $pullbackStart) + 1,
            'pullback_depth_pct' => round($pullbackDepthPct, 4),
            'pullback_volume_contraction' => round($pullbackVolumeRatio, 4),
            'pullback_red_bars' => $redBars,
            'higher_low_pct' => round($higherLowPct, 4),
            'pullback_low' => round($pullbackLow, 6),
            'pullback_low_ts_est' => (string) $norm[$pullbackLowIndex]['ts'],
            'pullback_min_vwap_distance_pct' => round($minVwapDistancePct, 4),
            'pullback_max_close_below_vwap_pct' => round($maxCloseBelowVwapObservedPct, 4),
            'pullback_max_bear_body_atr' => round($maxBearBodyAtrObserved, 4),
            'confirmation_body_pct' => round($confirmation['body_pct'], 4),
            'confirmation_close_position' => round($confirmation['close_position'], 6),
            'confirmation_break_pct' => round($confirmation['break_pct'], 4),
            'ema9' => round((float) $entryBar['ema9'], 6),
            'ema21' => round((float) $entryBar['ema21'], 6),
            'ema9_ema21_spread_pct' => round($confirmation['ema_spread_pct'], 4),
            'session_vwap' => round((float) $entryBar['vwap'], 6),

            // Score components.
            'impulse_score' => $scoreParts['impulse_score'],
            'pullback_score' => $scoreParts['pullback_score'],
            'higher_low_score' => $scoreParts['higher_low_score'],
            'confirmation_score' => $scoreParts['confirmation_score'],
            'trend_score' => $scoreParts['trend_score'],
            'vwap_location_score' => $scoreParts['vwap_location_score'],
            'risk_room_score' => $scoreParts['risk_room_score'],
            'time_score' => $scoreParts['time_score'],
        ];
    }

    /**
     * @param  array<int, array<string, float|string>>  $norm
     * @return array<string, float>
     */
    private function confirmationMetrics(array $norm, int $index, int $volLookback): array
    {
        $bar = $norm[$index];
        $previous = $norm[$index - 1];
        $entry = (float) $bar['close'];
        $open = (float) $bar['open'];
        $high = (float) $bar['high'];
        $low = (float) $bar['low'];
        $vwap = (float) $bar['vwap'];
        $ema9 = (float) $bar['ema9'];
        $ema21 = (float) $bar['ema21'];
        $averageVolume = $this->averageField(
            $norm,
            max(0, $index - $volLookback),
            $index - 1,
            'volume'
        );
        $volume = (float) $bar['volume'];
        $volumeRatio = $averageVolume > 0 ? $volume / $averageVolume : 0.0;
        $bodyPct = $open > 0 ? abs($entry - $open) / $open * 100.0 : 0.0;
        $closePosition = $high > $low ? ($entry - $low) / ($high - $low) : 0.0;
        $aboveVwapPct = $vwap > 0 ? (($entry - $vwap) / $vwap) * 100.0 : 999.0;
        $emaSpreadPct = $ema21 > 0 ? (($ema9 - $ema21) / $ema21) * 100.0 : 0.0;
        $previousHigh = (float) $previous['high'];
        $highBrokePrevious = $high > $previousHigh;
        $closeHeldBreak = $previousHigh > 0 && $entry >= ($previousHigh * 0.9995);
        $breakPct = $previousHigh > 0
            ? (($entry - $previousHigh) / $previousHigh) * 100.0
            : 0.0;

        return [
            'green' => $entry > $open ? 1.0 : 0.0,
            'above_previous_high' => ($highBrokePrevious && $closeHeldBreak) ? 1.0 : 0.0,
            'above_vwap' => $entry > $vwap ? 1.0 : 0.0,
            'above_ema9' => $entry > $ema9 ? 1.0 : 0.0,
            'ema9_above_ema21' => $ema9 > $ema21 ? 1.0 : 0.0,
            'volume_ratio' => $volumeRatio,
            'body_pct' => $bodyPct,
            'close_position' => $closePosition,
            'above_vwap_pct' => $aboveVwapPct,
            'ema_spread_pct' => $emaSpreadPct,
            'notional' => $entry * $volume,
            'break_pct' => $breakPct,
        ];
    }

    /** @param array<string, float> $m */
    private function passesConfirmation(
        array $m,
        float $minNotional1m,
        float $minVolumeRatio,
        float $minBodyPct,
        float $minClosePosition,
        float $maxAboveVwapPct
    ): bool {
        if ($m['green'] <= 0) {
            self::$dbg['confirmation_failed']++;
            self::$dbg['confirm_not_green']++;

            return false;
        }

        if ($m['above_previous_high'] <= 0) {
            self::$dbg['confirmation_failed']++;
            self::$dbg['confirm_no_high_break']++;

            return false;
        }

        if ($m['above_vwap'] <= 0) {
            self::$dbg['confirmation_failed']++;
            self::$dbg['confirm_below_vwap']++;

            return false;
        }

        if ($m['above_ema9'] <= 0) {
            self::$dbg['confirmation_failed']++;
            self::$dbg['confirm_below_ema9']++;

            return false;
        }

        if ($m['ema9_above_ema21'] <= 0) {
            self::$dbg['confirmation_failed']++;
            self::$dbg['confirm_ema_trend']++;

            return false;
        }

        if ($m['volume_ratio'] < $minVolumeRatio) {
            self::$dbg['confirmation_failed']++;
            self::$dbg['confirm_low_volume']++;

            return false;
        }

        if ($m['body_pct'] < $minBodyPct) {
            self::$dbg['confirmation_failed']++;
            self::$dbg['confirm_small_body']++;

            return false;
        }

        if ($m['close_position'] < $minClosePosition) {
            self::$dbg['confirmation_failed']++;
            self::$dbg['confirm_weak_close']++;

            return false;
        }

        if ($m['above_vwap_pct'] > $maxAboveVwapPct) {
            self::$dbg['confirmation_failed']++;
            self::$dbg['confirm_extended']++;

            return false;
        }

        if ($m['notional'] < $minNotional1m) {
            self::$dbg['confirmation_failed']++;
            self::$dbg['confirm_low_notional']++;

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, float>  $confirmation
     * @return array<string, float>
     */
    private function scoreSequence(
        float $impulsePct,
        float $impulseAtr,
        float $impulseVolumeRatio,
        float $pullbackDepthPct,
        float $pullbackVolumeRatio,
        float $higherLowPct,
        array $confirmation,
        float $roomToHodR,
        string $entryTsEst
    ): array {
        $impulseMoveScore = $this->clamp(($impulsePct - 0.45) / 1.80);
        $impulseAtrScore = $this->clamp(($impulseAtr - 0.90) / 2.30);
        $impulseVolumeScore = $this->clamp(($impulseVolumeRatio - 0.80) / 1.50);
        $impulseScore = 100.0 * (
            (0.40 * $impulseMoveScore)
            + (0.35 * $impulseAtrScore)
            + (0.25 * $impulseVolumeScore)
        );

        $depthScore = 1.0 - $this->clamp(abs($pullbackDepthPct - 35.0) / 22.0);
        $contractionScore = $this->clamp((0.90 - $pullbackVolumeRatio) / 0.45);
        $pullbackScore = 100.0 * ((0.55 * $depthScore) + (0.45 * $contractionScore));

        $higherLowScore = 100.0 * $this->clamp(($higherLowPct - 0.05) / 1.00);

        $confirmationVolumeScore = $this->clamp(($confirmation['volume_ratio'] - 0.85) / 1.60);
        $confirmationCloseScore = $this->clamp(($confirmation['close_position'] - 0.55) / 0.40);
        $confirmationBodyScore = $this->clamp(($confirmation['body_pct'] - 0.03) / 0.40);
        $confirmationScore = 100.0 * (
            (0.45 * $confirmationVolumeScore)
            + (0.35 * $confirmationCloseScore)
            + (0.20 * $confirmationBodyScore)
        );

        $trendScore = 100.0 * $this->clamp(($confirmation['ema_spread_pct'] - 0.02) / 0.40);
        $vwapLocationScore = 100.0 * (
            1.0 - $this->clamp(abs($confirmation['above_vwap_pct'] - 0.15) / 0.65)
        );
        $riskRoomScore = 100.0 * $this->clamp(($roomToHodR - 1.50) / 2.25);
        $timeScore = 100.0 * $this->timeMultiplier($entryTsEst);

        $final =
            (0.18 * $impulseScore)
            + (0.22 * $pullbackScore)
            + (0.10 * $higherLowScore)
            + (0.22 * $confirmationScore)
            + (0.10 * $trendScore)
            + (0.06 * $vwapLocationScore)
            + (0.08 * $riskRoomScore)
            + (0.04 * $timeScore);

        return [
            'score' => round($final, 3),
            'impulse_score' => round($impulseScore, 3),
            'pullback_score' => round($pullbackScore, 3),
            'higher_low_score' => round($higherLowScore, 3),
            'confirmation_score' => round($confirmationScore, 3),
            'trend_score' => round($trendScore, 3),
            'vwap_location_score' => round($vwapLocationScore, 3),
            'risk_room_score' => round($riskRoomScore, 3),
            'time_score' => round($timeScore, 3),
        ];
    }

    /** @param array<int, object> $bars */
    private function passesDataQuality(array $bars): bool
    {
        for ($i = 1; $i < count($bars); $i++) {
            $previousClose = (float) $bars[$i - 1]->close;
            $currentOpen = (float) $bars[$i]->open;
            if ($previousClose <= 0 || $currentOpen <= 0) {
                return false;
            }

            $changePct = (($currentOpen - $previousClose) / $previousClose) * 100.0;
            if (abs($changePct) > 50.0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, object>  $bars
     * @return array<int, array<string, float|string>>
     */
    private function normalizeBars(array $bars): array
    {
        $norm = [];
        $cumPv = 0.0;
        $cumVolume = 0.0;
        $ema9 = null;
        $ema21 = null;
        $k9 = 2.0 / 10.0;
        $k21 = 2.0 / 22.0;
        $hod = 0.0;

        foreach ($bars as $bar) {
            $open = (float) $bar->open;
            $high = (float) $bar->high;
            $low = (float) $bar->low;
            $close = (float) $bar->close;
            $volume = max(0.0, (float) $bar->volume);
            if ($open <= 0 || $high <= 0 || $low <= 0 || $close <= 0) {
                continue;
            }

            $typical = ($high + $low + $close) / 3.0;
            $cumPv += $typical * $volume;
            $cumVolume += $volume;
            $vwap = $cumVolume > 0 ? $cumPv / $cumVolume : $close;

            $ema9 = $ema9 === null ? $close : (($close * $k9) + ($ema9 * (1.0 - $k9)));
            $ema21 = $ema21 === null ? $close : (($close * $k21) + ($ema21 * (1.0 - $k21)));
            $hod = max($hod, $high);

            $norm[] = [
                'ts' => (string) $bar->ts_est,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => $volume,
                'vwap' => $vwap,
                'ema9' => $ema9,
                'ema21' => $ema21,
                'hod' => $hod,
            ];
        }

        return $norm;
    }

    /** @param array<int, array<string, float|string>> $norm */
    private function atrAt(array $norm, int $endIndex, int $period): float
    {
        if ($endIndex < 2) {
            return 0.0;
        }

        $start = max(1, $endIndex - $period + 1);
        $sum = 0.0;
        $count = 0;

        for ($i = $start; $i <= $endIndex; $i++) {
            $previousClose = (float) $norm[$i - 1]['close'];
            $high = (float) $norm[$i]['high'];
            $low = (float) $norm[$i]['low'];
            $sum += max(
                $high - $low,
                abs($high - $previousClose),
                abs($low - $previousClose)
            );
            $count++;
        }

        return $count > 0 ? $sum / $count : 0.0;
    }

    /** @param array<int, array<string, float|string>> $bars */
    private function averageField(
        array $bars,
        int $start,
        int $end,
        string $field
    ): float {
        if ($end < $start || $start < 0 || $end >= count($bars)) {
            return 0.0;
        }

        $sum = 0.0;
        $count = 0;
        for ($i = $start; $i <= $end; $i++) {
            $sum += (float) $bars[$i][$field];
            $count++;
        }

        return $count > 0 ? $sum / $count : 0.0;
    }

    /** @param array<int, array<string, float|string>> $bars */
    private function minField(array $bars, int $start, int $end, string $field): float
    {
        $value = INF;
        for ($i = $start; $i <= $end; $i++) {
            $value = min($value, (float) $bars[$i][$field]);
        }

        return is_finite($value) ? $value : 0.0;
    }

    /** @param array<int, array<string, float|string>> $bars */
    private function maxField(array $bars, int $start, int $end, string $field): float
    {
        $value = -INF;
        for ($i = $start; $i <= $end; $i++) {
            $value = max($value, (float) $bars[$i][$field]);
        }

        return is_finite($value) ? $value : 0.0;
    }

    /** @param array<int, array<string, float|string>> $bars */
    private function indexOfMinField(array $bars, int $start, int $end, string $field): int
    {
        $bestIndex = $start;
        $bestValue = (float) $bars[$start][$field];
        for ($i = $start + 1; $i <= $end; $i++) {
            $value = (float) $bars[$i][$field];
            if ($value < $bestValue) {
                $bestValue = $value;
                $bestIndex = $i;
            }
        }

        return $bestIndex;
    }

    /** @param array<int, array<string, float|string>> $bars */
    private function indexOfMaxField(array $bars, int $start, int $end, string $field): int
    {
        $bestIndex = $start;
        $bestValue = (float) $bars[$start][$field];
        for ($i = $start + 1; $i <= $end; $i++) {
            $value = (float) $bars[$i][$field];
            if ($value > $bestValue) {
                $bestValue = $value;
                $bestIndex = $i;
            }
        }

        return $bestIndex;
    }

    private function isAllowedTime(string $tsEst): bool
    {
        $hour = (int) substr($tsEst, 11, 2);
        $minute = (int) substr($tsEst, 14, 2);
        $time = $hour + ($minute / 60.0);

        // Frequency-tuned windows: 09:40-11:30 and 14:55-15:30 ET.
        // The weak 14:00-14:55 block remains excluded.
        return ($time >= 9.6667 && $time <= 11.50)
            || ($time >= 14.9167 && $time <= 15.50);
    }

    private function timeMultiplier(string $tsEst): float
    {
        $hour = (int) substr($tsEst, 11, 2);
        $minute = (int) substr($tsEst, 14, 2);
        $time = $hour + ($minute / 60.0);

        if ($time >= 9.6667 && $time < 10.50) {
            return 1.0;
        }
        if ($time >= 10.50 && $time <= 11.50) {
            return 0.90;
        }
        if ($time >= 14.9167 && $time <= 15.50) {
            return 0.95;
        }

        return 0.0;
    }

    private function isDebugEnabled(): bool
    {
        $values = [
            env('TRADING_V45_DEBUG', '0'),
            env('ENTRYFINDER_V45_DEBUG', '0'),
            config('trading.v45.debug', false),
        ];

        foreach ($values as $value) {
            if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        return false;
    }

    private function maybeLogDebug(): void
    {
        if (! $this->isDebugEnabled()) {
            return;
        }

        // Default to every call while debugging. Increase this value for a large run.
        $every = max(1, (int) env('ENTRYFINDER_V45_DEBUG_EVERY', 1));
        if (self::$dbg['called'] > 0 && self::$dbg['called'] % $every === 0) {
            Log::info('[EntryFinderV45] debug counters', array_merge(self::$dbg, [
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
