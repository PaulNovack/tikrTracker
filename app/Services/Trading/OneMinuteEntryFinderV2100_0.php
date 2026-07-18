<?php

namespace App\Services\Trading;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Version 2100.0 - Forward-Looking 5% Runner Entry Finder
 *
 * WARNING:
 * This entry finder intentionally uses forward-looking data.
 * It may return an entry timestamp after asOfTsEst.
 *
 * Use for:
 * - training label generation
 * - research backtesting
 * - delayed-entry simulation
 *
 * Do not use live.
 *
 * Fixes / optimizations:
 * - Matches FiveMinuteSignalScannerV2100_0_OPTIMIZED's cheap 5% universe gate.
 * - Uses a cheap +5% daily-range check before PHP chronological run verification.
 * - Removes the correlated AVG(volume) subquery from entry_candidates.
 * - Adds trading_date_est to the future 1-minute join so the query does not scan old days.
 * - Fixes the T5 label time check to use first_target5_ts.
 */
class OneMinuteEntryFinderV2100_0
{
    use HasPriceTables;

    private string $version = 'v2100.0';

    private float $target4Pct = 4.0;

    private float $target5Pct = 5.0;

    private float $maxStopPct = 2.0;

    private float $atrStopMult = 3.0;

    private float $minLateRetPct = 1.5;

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
        $tradingDate = substr($signalTsEst, 0, 10);

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
                trading_date_est,
                trading_time_est
            FROM five_minute_prices
            WHERE symbol = ?
              AND asset_type = ?
              AND trading_date_est = ?
              AND ts_est = ?
            LIMIT 1
        ', [$symbol, $assetType, $tradingDate, $signalTsEst]);

        if (empty($signalBar)) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'signal_not_found',
                'meta' => [
                    'version' => $this->version,
                    'symbol' => $symbol,
                    'asset_type' => $assetType,
                    'signal_ts_est' => $signalTsEst,
                    'as_of_ts_est' => $asOfTsEst,
                ],
            ];
        }

        $signalBar = $signalBar[0];
        $signalPrice = (float) $signalBar->price;

        if ($signalPrice <= 0) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'invalid_signal_price',
                'meta' => [
                    'version' => $this->version,
                    'symbol' => $symbol,
                    'asset_type' => $assetType,
                    'signal_ts_est' => $signalTsEst,
                    'as_of_ts_est' => $asOfTsEst,
                ],
            ];
        }

        $entryWindowEndTsEst = $this->addMinutes($signalTsEst, 15);
        $entrySearchStartTsEst = $this->maxTsEst($signalTsEst, $asOfTsEst);

        if ($entrySearchStartTsEst > $entryWindowEndTsEst) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'entry_window_closed',
                'meta' => [
                    'version' => $this->version,
                    'forward_bias' => true,
                    'symbol' => $symbol,
                    'asset_type' => $assetType,
                    'signal_ts_est' => $signalTsEst,
                    'as_of_ts_est' => $asOfTsEst,
                    'entry_search_start_ts_est' => $entrySearchStartTsEst,
                    'entry_window_end_ts_est' => $entryWindowEndTsEst,
                ],
            ];
        }

        $target4Mult = 1.0 + ($this->target4Pct / 100.0);
        $target5Mult = 1.0 + ($this->target5Pct / 100.0);
        $maxStopMult = $this->maxStopPct / 100.0;

        $roughContext = $this->queryRoughFivePctContext($symbol, $assetType, $tradingDate);
        if ($roughContext === null) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'not_rough_5pct_runner',
                'meta' => [
                    'version' => $this->version,
                    'forward_bias' => true,
                    'symbol' => $symbol,
                    'asset_type' => $assetType,
                    'signal_ts_est' => $signalTsEst,
                    'as_of_ts_est' => $asOfTsEst,
                ],
            ];
        }

        // Chronological run-start context is calculated in PHP for this one symbol.
        // That avoids a MySQL running-window CTE inside the heavy entry query.
        $runContext = $this->queryRunContext($symbol, $assetType, $tradingDate);
        if ($runContext === null) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'no_chronological_5pct_run',
                'meta' => [
                    'version' => $this->version,
                    'forward_bias' => true,
                    'symbol' => $symbol,
                    'asset_type' => $assetType,
                    'signal_ts_est' => $signalTsEst,
                    'as_of_ts_est' => $asOfTsEst,
                    'rough_day_low' => $roughContext['rough_day_low'],
                    'rough_day_high' => $roughContext['rough_day_high'],
                    'rough_range_pct' => $roughContext['rough_range_pct'],
                    'day_volume' => $roughContext['day_volume'],
                ],
            ];
        }

        $rows = $this->dbSelect(''
            ."WITH entry_candidates AS (\n"
            ."    SELECT\n"
            ."        e.symbol,\n"
            ."        e.asset_type,\n"
            ."        e.trading_date_est,\n"
            ."        ? AS signal_price,\n"
            ."        e.ts_est AS entry_ts_est,\n"
            ."        e.price AS entry_price,\n"
            ."        e.open,\n"
            ."        e.high,\n"
            ."        e.low,\n"
            ."        e.volume,\n"
            ."        e.atr AS entry_atr,\n"
            ."        e.atr_pct AS entry_atr_pct,\n"
            ."        e.vwap,\n"
            ."        e.vwap_dist_pct,\n"
            ."        e.above_vwap,\n"
            ."        e.ema9,\n"
            ."        e.ema21,\n"
            ."        e.ema9_ema21_spread,\n"
            ."        e.ema9_above_ema21,\n"
            ."        e.price - CASE\n"
            ."            WHEN e.atr IS NULL OR e.atr <= 0\n"
            ."                THEN e.price * {$maxStopMult}\n"
            ."            ELSE LEAST(e.atr * {$this->atrStopMult}, e.price * {$maxStopMult})\n"
            ."        END AS stop_price,\n"
            ."        CASE\n"
            ."            WHEN e.atr IS NULL OR e.atr <= 0\n"
            ."                THEN e.price * {$maxStopMult}\n"
            ."            ELSE LEAST(e.atr * {$this->atrStopMult}, e.price * {$maxStopMult})\n"
            ."        END AS risk_per_share,\n"
            ."        e.price * {$target4Mult} AS target4_price,\n"
            ."        e.price * {$target5Mult} AS target5_price\n"
            ."    FROM one_minute_prices e\n"
            ."    WHERE e.symbol = ?\n"
            ."      AND e.asset_type = ?\n"
            ."      AND e.trading_date_est = ?\n"
            ."      AND e.ts_est >= ?\n"
            ."      AND e.ts_est > ?\n"
            ."      AND e.ts_est <= ?\n"
            ."      AND e.price IS NOT NULL\n"
            ."      AND e.price > 0\n"
            ."      AND e.high IS NOT NULL\n"
            ."      AND e.low IS NOT NULL\n"
            ."),\n"
            ."entry_avg_volume AS (\n"
            ."    SELECT\n"
            ."        ec.symbol,\n"
            ."        ec.asset_type,\n"
            ."        ec.trading_date_est,\n"
            ."        ec.entry_ts_est,\n"
            ."        AVG(CASE WHEN p.volume > 0 THEN p.volume END) AS avg_prior_volume\n"
            ."    FROM entry_candidates ec\n"
            ."    LEFT JOIN one_minute_prices p\n"
            ."      ON p.symbol = ec.symbol\n"
            ."     AND p.asset_type = ec.asset_type\n"
            ."     AND p.trading_date_est = ec.trading_date_est\n"
            ."     AND p.ts_est < ec.entry_ts_est\n"
            ."     AND p.ts_est >= DATE_SUB(ec.entry_ts_est, INTERVAL 20 MINUTE)\n"
            ."    GROUP BY ec.symbol, ec.asset_type, ec.trading_date_est, ec.entry_ts_est\n"
            ."),\n"
            ."future_stats AS (\n"
            ."    SELECT\n"
            ."        ec.symbol,\n"
            ."        ec.asset_type,\n"
            ."        ec.trading_date_est,\n"
            ."        ec.signal_price,\n"
            ."        ec.entry_ts_est,\n"
            ."        ec.entry_price,\n"
            ."        ec.open,\n"
            ."        ec.high,\n"
            ."        ec.low,\n"
            ."        ec.volume,\n"
            ."        ec.entry_atr,\n"
            ."        ec.entry_atr_pct,\n"
            ."        ec.vwap,\n"
            ."        ec.vwap_dist_pct,\n"
            ."        ec.above_vwap,\n"
            ."        ec.ema9,\n"
            ."        ec.ema21,\n"
            ."        ec.ema9_ema21_spread,\n"
            ."        ec.ema9_above_ema21,\n"
            ."        ec.stop_price,\n"
            ."        ec.risk_per_share,\n"
            ."        ec.target4_price,\n"
            ."        ec.target5_price,\n"
            ."        av.avg_prior_volume,\n"
            ."        CASE\n"
            ."            WHEN av.avg_prior_volume IS NOT NULL AND av.avg_prior_volume > 0\n"
            ."            THEN ec.volume / av.avg_prior_volume\n"
            ."            ELSE NULL\n"
            ."        END AS volume_ratio,\n"
            ."        MIN(CASE WHEN fp.low <= ec.stop_price THEN fp.ts_est END) AS first_stop_ts,\n"
            ."        MIN(CASE WHEN fp.high >= ec.target4_price THEN fp.ts_est END) AS first_target4_ts,\n"
            ."        MIN(CASE WHEN fp.high >= ec.target5_price THEN fp.ts_est END) AS first_target5_ts,\n"
            ."        MAX(((fp.high - ec.entry_price) / NULLIF(ec.entry_price, 0)) * 100) AS mfe_300_pct,\n"
            ."        MIN(((fp.low - ec.entry_price) / NULLIF(ec.entry_price, 0)) * 100) AS mae_300_pct,\n"
            ."        MAX(\n"
            ."            CASE\n"
            ."                WHEN fp.ts_est >= DATE_ADD(ec.entry_ts_est, INTERVAL 280 MINUTE)\n"
            ."                THEN ((fp.price - ec.entry_price) / NULLIF(ec.entry_price, 0)) * 100\n"
            ."            END\n"
            ."        ) AS late_ret_pct,\n"
            ."        COUNT(*) AS future_minutes\n"
            ."    FROM entry_candidates ec\n"
            ."    LEFT JOIN entry_avg_volume av\n"
            ."      ON av.symbol = ec.symbol\n"
            ."     AND av.asset_type = ec.asset_type\n"
            ."     AND av.trading_date_est = ec.trading_date_est\n"
            ."     AND av.entry_ts_est = ec.entry_ts_est\n"
            ."    JOIN one_minute_prices fp\n"
            ."      ON fp.symbol = ec.symbol\n"
            ."     AND fp.asset_type = ec.asset_type\n"
            ."     AND fp.trading_date_est = ec.trading_date_est\n"
            ."     AND fp.ts_est > ec.entry_ts_est\n"
            ."     AND fp.ts_est <= DATE_ADD(ec.entry_ts_est, INTERVAL 300 MINUTE)\n"
            ."    WHERE fp.price IS NOT NULL\n"
            ."      AND fp.high IS NOT NULL\n"
            ."      AND fp.low IS NOT NULL\n"
            ."    GROUP BY\n"
            ."        ec.symbol, ec.asset_type, ec.trading_date_est, ec.signal_price, ec.entry_ts_est,\n"
            ."        ec.entry_price, ec.open, ec.high, ec.low, ec.volume, ec.entry_atr, ec.entry_atr_pct,\n"
            ."        ec.vwap, ec.vwap_dist_pct, ec.above_vwap, ec.ema9, ec.ema21,\n"
            ."        ec.ema9_ema21_spread, ec.ema9_above_ema21, ec.stop_price, ec.risk_per_share,\n"
            ."        ec.target4_price, ec.target5_price, av.avg_prior_volume\n"
            .")\n"
            ."SELECT\n"
            ."    fs.*,\n"
            ."    CASE\n"
            ."        WHEN fs.future_minutes >= 280\n"
            ."         AND fs.first_target4_ts IS NOT NULL\n"
            ."         AND fs.first_target4_ts >= DATE_ADD(?, INTERVAL 5 MINUTE)\n"
            ."         AND fs.first_stop_ts IS NULL\n"
            ."         AND COALESCE(fs.late_ret_pct, -999) >= {$this->minLateRetPct}\n"
            ."        THEN 1 ELSE 0\n"
            ."    END AS entry_label_t4,\n"
            ."    CASE\n"
            ."        WHEN fs.future_minutes >= 280\n"
            ."         AND fs.first_target5_ts IS NOT NULL\n"
            ."         AND fs.first_target5_ts >= DATE_ADD(?, INTERVAL 5 MINUTE)\n"
            ."         AND fs.first_stop_ts IS NULL\n"
            ."         AND COALESCE(fs.late_ret_pct, -999) >= {$this->minLateRetPct}\n"
            ."        THEN 1 ELSE 0\n"
            ."    END AS entry_label_t5\n"
            ."FROM future_stats fs\n"
            ."WHERE fs.future_minutes >= 280\n"
            ."  AND fs.first_stop_ts IS NULL\n"
            ."  AND fs.first_target4_ts IS NOT NULL\n"
            ."  AND fs.first_target4_ts >= DATE_ADD(?, INTERVAL 5 MINUTE)\n"
            ."  AND COALESCE(fs.late_ret_pct, -999) >= {$this->minLateRetPct}\n"
            ."ORDER BY\n"
            ."    CASE WHEN fs.first_target5_ts IS NOT NULL THEN 1 ELSE 0 END DESC,\n"
            ."    COALESCE(fs.late_ret_pct, 0) DESC,\n"
            ."    COALESCE(fs.mfe_300_pct, 0) DESC,\n"
            ."    COALESCE(fs.volume_ratio, 1) DESC,\n"
            ."    fs.entry_ts_est ASC\n"
            .'LIMIT 1',
            [
                $signalPrice,
                $symbol,
                $assetType,
                $tradingDate,
                $entrySearchStartTsEst,
                $signalTsEst,
                $entryWindowEndTsEst,
                $signalTsEst,
                $signalTsEst,
                $signalTsEst,
            ]
        );

        if (empty($rows)) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'no_forward_valid_delayed_entry',
                'meta' => [
                    'version' => $this->version,
                    'forward_bias' => true,
                    'symbol' => $symbol,
                    'asset_type' => $assetType,
                    'signal_ts_est' => $signalTsEst,
                    'as_of_ts_est' => $asOfTsEst,
                    'entry_search_start_ts_est' => $entrySearchStartTsEst,
                    'entry_window_end_ts_est' => $entryWindowEndTsEst,
                    'target4_pct' => $this->target4Pct,
                    'target5_pct' => $this->target5Pct,
                    'atr_stop_mult' => $this->atrStopMult,
                    'max_stop_pct' => $this->maxStopPct,
                    'note' => 'This can also happen when the symbol did not have a chronological +5% 5-minute run on this date.',
                ],
            ];
        }

        $best = $rows[0];

        $best->run_start_ts_est = $runContext['run_start_ts_est'] ?? null;
        $best->run_start_price = $runContext['run_start_price'] ?? null;
        $best->first_5pct_ts_est = $runContext['first_5pct_ts_est'] ?? null;
        $best->first_5pct_high = $runContext['first_5pct_high'] ?? null;
        $best->first_5pct_run_pct = $runContext['first_5pct_run_pct'] ?? null;
        $best->rough_day_low = $roughContext['rough_day_low'] ?? null;
        $best->rough_day_high = $roughContext['rough_day_high'] ?? null;
        $best->rough_range_pct = $roughContext['rough_range_pct'] ?? null;
        $best->day_volume = $roughContext['day_volume'] ?? null;

        $entryPrice = (float) $best->entry_price;
        $stopPrice = (float) $best->stop_price;
        $riskPerShare = max(0.01, (float) $best->risk_per_share);
        $riskPct = $entryPrice > 0 ? round(($riskPerShare / $entryPrice) * 100, 2) : 0.0;

        $entryLabelT4 = (int) $best->entry_label_t4;
        $entryLabelT5 = (int) $best->entry_label_t5;

        $score = round(
            (($entryLabelT5 ? 30 : 0) + ($entryLabelT4 ? 20 : 0))
            + ((float) ($best->mfe_300_pct ?? 0) * 5)
            + ((float) ($best->late_ret_pct ?? 0) * 3)
            + ((float) ($best->volume_ratio ?? 1) * 2)
            - abs((float) ($best->mae_300_pct ?? 0)),
            3
        );

        // --- Compute entry quality metrics from the selected bar ---
        $entryOpen = (float) ($best->open ?? 0);
        $entryHigh = (float) ($best->high ?? 0);
        $entryLow = (float) ($best->low ?? 0);
        $entryVolume = (float) ($best->volume ?? 0);
        $entryVwap = (float) ($best->vwap ?? 0);
        $entryEma9 = (float) ($best->ema9 ?? 0);
        $entryEma21 = (float) ($best->ema21 ?? 0);

        $bodyPct = $entryOpen > 0 ? abs($entryPrice - $entryOpen) / $entryOpen * 100.0 : 0.0;

        $entryClosePosition = $entryHigh > $entryLow
            ? ($entryPrice - $entryLow) / ($entryHigh - $entryLow)
            : null;

        $entryNotional1m = $entryPrice * $entryVolume;

        $aboveVwapEntryPct = $entryVwap > 0 ? (($entryPrice - $entryVwap) / $entryVwap) * 100.0 : null;

        // --- HOD + room-to-run ---
        $hod = $this->queryHod($symbol, $assetType, $tradingDate);
        $roomToHodPct = ($hod !== null && $entryPrice > 0) ? (($hod - $entryPrice) / $entryPrice) * 100.0 : null;
        $entryAtrForRoom = $best->entry_atr !== null ? (float) $best->entry_atr : null;
        $roomToHodAtr = ($roomToHodPct !== null && $entryAtrForRoom !== null && $entryAtrForRoom > 0)
            ? (($hod - $entryPrice) / $entryAtrForRoom)
            : null;

        // --- 5-minute trend/choppiness ---
        $choppiness = $this->calculate5MinChoppiness($symbol, $assetType, $tradingDate, (string) $best->entry_ts_est);

        // --- RSI-14 (1-minute) ---
        $rsi = $this->calculateRsi14($symbol, $assetType, $tradingDate, (string) $best->entry_ts_est);

        // --- Entry scoring sub-components ---
        $sc = $this->computeEntryScoreComponents(
            $entryPrice,
            $entryOpen,
            $entryHigh,
            $entryLow,
            $entryVolume,
            $entryVwap,
            $entryEma9,
            $entryEma21,
            (float) ($best->ema9_ema21_spread ?? 0),
            (int) ($best->ema9_above_ema21 ?? 0),
            (int) ($best->above_vwap ?? 0),
            $best->entry_atr_pct !== null ? (float) $best->entry_atr_pct : null,
            $best->avg_prior_volume !== null ? (float) $best->avg_prior_volume : null,
            (string) $best->entry_ts_est
        );

        $bestEntry = [
            'type' => 'FORWARD_2H_RUNNER_1M',
            'entry_ts_est' => (string) $best->entry_ts_est,
            'entry' => round($entryPrice, 4),
            'stop' => round($stopPrice, 4),
            'risk_pct' => $riskPct,
            'risk_per_share' => round($riskPerShare, 4),
            'score' => $score,
            'vol_ratio' => $best->volume_ratio !== null ? round((float) $best->volume_ratio, 3) : null,
            'atr' => $best->entry_atr !== null ? (float) $best->entry_atr : null,
            'atr_pct' => $best->entry_atr_pct !== null ? (float) $best->entry_atr_pct : null,
            'suggested_trailing_stop' => round($riskPerShare, 4),
            'suggested_trailing_stop_pct' => $riskPct,
            'targets' => $this->buildTargets($entryPrice, $riskPerShare),

            // Room-to-run / HOD
            'hod' => $hod,
            'room_to_hod_pct' => $roomToHodPct !== null ? round($roomToHodPct, 4) : null,
            'room_to_hod_atr' => $roomToHodAtr !== null ? round($roomToHodAtr, 4) : null,
            'above_vwap_entry_pct' => $aboveVwapEntryPct !== null ? round($aboveVwapEntryPct, 4) : null,

            // Entry quality
            'entry_body_pct' => round($bodyPct, 4),
            'entry_close_position' => $entryClosePosition !== null ? round($entryClosePosition, 6) : null,
            'entry_volume_ratio' => $best->volume_ratio !== null ? round((float) $best->volume_ratio, 4) : null,
            'entry_notional_1m' => round($entryNotional1m, 2),

            // RSI
            'rsi' => $rsi,

            // 5-min trend/choppiness
            'five_min_directional_changes' => $choppiness['directional_changes'] ?? null,
            'five_min_green_bar_pct' => isset($choppiness['green_bar_pct']) ? round($choppiness['green_bar_pct'], 1) : null,
            'five_min_net_progress' => isset($choppiness['net_progress']) ? round($choppiness['net_progress'], 3) : null,

            // Entry scoring sub-components
            'entry_spread_strength' => $sc['spread_strength'],
            'entry_vwap_dist_score' => $sc['vwap_dist_score'],
            'entry_atr_score' => $sc['atr_score'],
            'entry_vol_score' => $sc['vol_score'],
            'entry_candle_score' => $sc['candle_score'],
            'entry_time_bonus' => $sc['time_bonus'],

            'meta' => [
                'version' => $this->version,
                'forward_bias' => true,

                'signal_ts_est' => $signalTsEst,
                'as_of_ts_est' => $asOfTsEst,
                'entry_search_start_ts_est' => $entrySearchStartTsEst,
                'entry_window_end_ts_est' => $entryWindowEndTsEst,
                'signal_price' => $best->signal_price !== null ? round((float) $best->signal_price, 4) : null,

                'run_start_ts_est' => $best->run_start_ts_est !== null ? (string) $best->run_start_ts_est : null,
                'run_start_price' => $best->run_start_price !== null ? round((float) $best->run_start_price, 4) : null,
                'first_5pct_ts_est' => $best->first_5pct_ts_est !== null ? (string) $best->first_5pct_ts_est : null,
                'first_5pct_high' => $best->first_5pct_high !== null ? round((float) $best->first_5pct_high, 4) : null,
                'first_5pct_run_pct' => $best->first_5pct_run_pct !== null ? round((float) $best->first_5pct_run_pct, 3) : null,

                'entry_label_t4' => $entryLabelT4,
                'entry_label_t5' => $entryLabelT5,

                'first_target4_ts' => $best->first_target4_ts !== null ? (string) $best->first_target4_ts : null,
                'first_target5_ts' => $best->first_target5_ts !== null ? (string) $best->first_target5_ts : null,
                'first_stop_ts' => $best->first_stop_ts !== null ? (string) $best->first_stop_ts : null,

                'mfe_300_pct' => $best->mfe_300_pct !== null ? round((float) $best->mfe_300_pct, 3) : null,
                'mae_300_pct' => $best->mae_300_pct !== null ? round((float) $best->mae_300_pct, 3) : null,
                'late_ret_pct' => $best->late_ret_pct !== null ? round((float) $best->late_ret_pct, 3) : null,
                'future_minutes' => (int) $best->future_minutes,

                'above_vwap' => $best->above_vwap !== null ? (int) $best->above_vwap : null,
                'ema9_above_ema21' => $best->ema9_above_ema21 !== null ? (int) $best->ema9_above_ema21 : null,
                'vwap_dist_pct' => $best->vwap_dist_pct !== null ? (float) $best->vwap_dist_pct : null,
                'ema9_ema21_spread' => $best->ema9_ema21_spread !== null ? (float) $best->ema9_ema21_spread : null,

                'target4_pct' => $this->target4Pct,
                'target5_pct' => $this->target5Pct,
                'atr_stop_mult' => $this->atrStopMult,
                'max_stop_pct' => $this->maxStopPct,
            ],
        ];

        return [
            'ok' => 1,
            'best_entry' => $bestEntry,
            'meta' => [
                'version' => $this->version,
                'forward_bias' => true,
                'as_of_ts_est' => $asOfTsEst,
                'signal_ts_est' => $signalTsEst,
            ],
        ];
    }

    /**
     * Cheap single-symbol +5% daily-range gate.
     *
     * @return array<string,mixed>|null
     */
    private function queryRoughFivePctContext(string $symbol, string $assetType, string $tradingDate): ?array
    {
        $rows = $this->dbSelect('
            SELECT
                MIN(low) AS rough_day_low,
                MAX(high) AS rough_day_high,
                ((MAX(high) - MIN(low)) / NULLIF(MIN(low), 0)) * 100 AS rough_range_pct,
                SUM(COALESCE(volume, 0)) AS day_volume
            FROM five_minute_prices
            WHERE symbol = ?
              AND asset_type = ?
              AND trading_date_est = ?
              AND trading_time_est BETWEEN \'09:30:00\' AND \'16:00:00\'
              AND high IS NOT NULL
              AND high > 0
              AND low IS NOT NULL
              AND low > 0
              AND price IS NOT NULL
              AND price > 0
            HAVING rough_range_pct >= ?
            LIMIT 1
        ', [$symbol, $assetType, $tradingDate, $this->target5Pct]);

        if (empty($rows) || $rows[0]->rough_range_pct === null) {
            return null;
        }

        return [
            'rough_day_low' => $rows[0]->rough_day_low !== null ? (float) $rows[0]->rough_day_low : null,
            'rough_day_high' => $rows[0]->rough_day_high !== null ? (float) $rows[0]->rough_day_high : null,
            'rough_range_pct' => $rows[0]->rough_range_pct !== null ? (float) $rows[0]->rough_range_pct : null,
            'day_volume' => $rows[0]->day_volume !== null ? (float) $rows[0]->day_volume : null,
        ];
    }

    /**
     * Calculates chronological +5% run context in PHP for one symbol.
     * This avoids MySQL window-function CTEs in the entry finder.
     *
     * @return array<string,mixed>|null
     */
    private function queryRunContext(string $symbol, string $assetType, string $tradingDate): ?array
    {
        $bars = $this->dbSelect('
            SELECT ts_est, high, low
            FROM five_minute_prices
            WHERE symbol = ?
              AND asset_type = ?
              AND trading_date_est = ?
              AND trading_time_est BETWEEN \'09:30:00\' AND \'16:00:00\'
              AND high IS NOT NULL
              AND high > 0
              AND low IS NOT NULL
              AND low > 0
            ORDER BY ts_est ASC
        ', [$symbol, $assetType, $tradingDate]);

        $runningLow = null;
        $runningLowTs = null;
        $targetMult = 1.0 + ($this->target5Pct / 100.0);

        foreach ($bars as $bar) {
            $low = (float) $bar->low;
            $high = (float) $bar->high;
            $ts = (string) $bar->ts_est;

            if ($runningLow === null || $low < $runningLow) {
                $runningLow = $low;
                $runningLowTs = $ts;
            }

            if ($runningLow !== null && $runningLow > 0 && $high >= $runningLow * $targetMult) {
                return [
                    'run_start_ts_est' => $runningLowTs,
                    'run_start_price' => $runningLow,
                    'first_5pct_ts_est' => $ts,
                    'first_5pct_high' => $high,
                    'first_5pct_run_pct' => (($high - $runningLow) / $runningLow) * 100.0,
                ];
            }
        }

        return null;
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

    private function addMinutes(string $tsEst, int $minutes): string
    {
        $dt = new DateTimeImmutable($tsEst, new DateTimeZone('America/New_York'));

        return $dt->add(new DateInterval('PT'.max(0, $minutes).'M'))->format('Y-m-d H:i:s');
    }

    private function maxTsEst(string $a, string $b): string
    {
        return $a >= $b ? $a : $b;
    }

    /**
     * Query the regular-session high-of-day for a symbol on a given trading date.
     */
    private function queryHod(string $symbol, string $assetType, string $tradingDate): ?float
    {
        $result = $this->dbSelect('
            SELECT MAX(high) as hod
            FROM one_minute_prices
            WHERE symbol = ?
              AND asset_type = ?
              AND trading_date_est = ?
        ', [$symbol, $assetType, $tradingDate]);

        if (empty($result) || $result[0]->hod === null) {
            return null;
        }

        return (float) $result[0]->hod;
    }

    /**
     * Calculate 5-minute choppiness metrics from bars leading up to the entry.
     */
    private function calculate5MinChoppiness(string $symbol, string $assetType, string $tradingDate, string $entryTsEst): array
    {
        $bars = $this->dbSelect('
            SELECT ts_est, open, high, low, price
            FROM five_minute_prices
            WHERE symbol = ?
              AND asset_type = ?
              AND trading_date_est = ?
              AND ts_est <= ?
            ORDER BY ts_est DESC
            LIMIT 12
        ', [$symbol, $assetType, $tradingDate, $entryTsEst]);

        if (count($bars) < 2) {
            return [
                'directional_changes' => 0,
                'green_bar_pct' => 0.0,
                'net_progress' => 0.0,
            ];
        }

        $bars = array_reverse($bars);

        $dirChanges = 0;
        $greenBars = 0;
        $totalRange = 0.0;
        $lastDir = null;

        foreach ($bars as $bar) {
            $open = (float) ($bar->open ?? 0);
            $close = (float) ($bar->price ?? 0);
            $high = (float) ($bar->high ?? $close);
            $low = (float) ($bar->low ?? $close);

            $currentDir = $close >= $open ? 'up' : 'down';
            if ($lastDir !== null && $currentDir !== $lastDir) {
                $dirChanges++;
            }
            $lastDir = $currentDir;

            if ($close >= $open) {
                $greenBars++;
            }

            $totalRange += ($high - $low);
        }

        $firstBar = $bars[0];
        $lastBar = $bars[count($bars) - 1];
        $netMove = abs((float) ($lastBar->price ?? 0) - (float) ($firstBar->open ?? 0));
        $netProgress = $totalRange > 0 ? $netMove / $totalRange : 0.0;

        return [
            'directional_changes' => $dirChanges,
            'green_bar_pct' => count($bars) > 0 ? ($greenBars / count($bars)) * 100.0 : 0.0,
            'net_progress' => round($netProgress, 3),
        ];
    }

    /**
     * Calculate RSI-14 from the 14 prior 1-minute bars before entry.
     */
    private function calculateRsi14(string $symbol, string $assetType, string $tradingDate, string $entryTsEst): ?float
    {
        $bars = $this->dbSelect('
            SELECT ts_est, price
            FROM one_minute_prices
            WHERE symbol = ?
              AND asset_type = ?
              AND trading_date_est = ?
              AND ts_est <= ?
            ORDER BY ts_est DESC
            LIMIT 15
        ', [$symbol, $assetType, $tradingDate, $entryTsEst]);

        if (count($bars) < 15) {
            return null;
        }

        $bars = array_reverse($bars);

        $gains = 0.0;
        $losses = 0.0;

        for ($i = 1; $i < count($bars); $i++) {
            $change = (float) $bars[$i]->price - (float) $bars[$i - 1]->price;
            if ($change > 0) {
                $gains += $change;
            } else {
                $losses += abs($change);
            }
        }

        $avgGain = $gains / 14;
        $avgLoss = $losses / 14;

        if ($avgLoss <= 0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;

        return round(100.0 - (100.0 / (1.0 + $rs)), 1);
    }

    /**
     * Compute entry score sub-components (same formula as V25_2 for ML consistency).
     *
     * @return array{score:float,spread_strength:float,vwap_dist_score:float,
     *               atr_score:float,vol_score:float,candle_score:float,time_bonus:float}
     */
    private function computeEntryScoreComponents(
        float $price,
        float $open,
        float $high,
        float $low,
        float $volume,
        float $vwap,
        float $ema9,
        float $ema21,
        float $emaSpread,
        int $ema9AboveEma21,
        int $aboveVwap,
        ?float $atrPct,
        ?float $avgPriorVolume,
        string $entryTsEst
    ): array {
        if ($price <= 0) {
            return [
                'score' => 0.0,
                'spread_strength' => 0.0,
                'vwap_dist_score' => 0.0,
                'atr_score' => 0.0,
                'vol_score' => 0.0,
                'candle_score' => 0.0,
                'time_bonus' => 0.0,
            ];
        }

        $spreadFrac = $emaSpread / $price;
        $spreadStrength = $this->clamp01(($spreadFrac - 0.0005) / (0.0030 - 0.0005));

        $vwapDistPct = $vwap > 0 ? (($price - $vwap) / $vwap) * 100 : 0;
        $vwapDistScore = max(0.0, 1.0 - (abs($vwapDistPct - 0.15) / 0.30));

        $atrPctVal = $atrPct ?? 0.0;
        $atrLowOk = $this->clamp01(($atrPctVal - 0.08) / (0.20 - 0.08));
        $atrHighPen = $this->clamp01(($atrPctVal - 0.50) / (1.50 - 0.50));
        $atrScore = $atrLowOk * (1.0 - $atrHighPen);

        $avgVol = $avgPriorVolume ?? 1.0;
        $volRatio = $avgVol > 0 ? $volume / $avgVol : 0.0;
        $volScore = $this->clamp01(($volRatio - 0.8) / (2.5 - 0.8));

        $candleScore = 0.0;
        if ($high > $low) {
            $pos = ($price - $low) / ($high - $low);
            $candleScore = $this->clamp01(($pos - 0.45) / (0.80 - 0.45));
        }

        $timeBonus = 0.0;
        $timeStr = strlen($entryTsEst) >= 19 ? substr($entryTsEst, 11, 8) : $entryTsEst;
        if ($timeStr <= '10:30:00') {
            $timeBonus = 1.0;
        } elseif ($timeStr <= '11:00:00') {
            $timeBonus = 0.5;
        }

        $sTrend = 0.70 * $ema9AboveEma21 + 0.30 * $spreadStrength;
        $sVwap = $aboveVwap * $vwapDistScore;

        $final = 100.0 * (
            0.30 * $sTrend +
            0.25 * $sVwap +
            0.10 * $atrScore +
            0.20 * $volScore +
            0.10 * $candleScore +
            0.05 * $timeBonus
        );

        return [
            'score' => round($final, 2),
            'spread_strength' => round($spreadStrength, 6),
            'vwap_dist_score' => round($vwapDistScore, 6),
            'atr_score' => round($atrScore, 6),
            'vol_score' => round($volScore, 6),
            'candle_score' => round($candleScore, 6),
            'time_bonus' => round($timeBonus, 6),
        ];
    }

    private function clamp01(float $x): float
    {
        if ($x < 0.0) {
            return 0.0;
        }
        if ($x > 1.0) {
            return 0.0 + 1.0;
        }

        return $x;
    }
}
