<?php

namespace App\Services\Trading;

/**
 * Version 2100.0 - Forward-Looking 5% Runner Signal Scanner
 *
 * WARNING:
 * This scanner intentionally uses forward-looking data.
 * Use for label generation, training data creation, and research backtests only.
 * Do not use this scanner for live trading.
 *
 * Safer optimization:
 * - First query builds a cheap +5% daily-range symbol universe.
 * - Main query only scans that symbol list.
 * - The main SQL avoids the previous post-final window-function CTE chain.
 * - Chronological run-start details are calculated in PHP only for final rows.
 */
class FiveMinuteSignalScannerV2100_0
{
    use HasPriceTables;

    private string $version = 'v2100.0';

    private string $name = 'Biased Forward-Looking 5-Hour';

    private float $target4Pct = 4.0;

    private float $target5Pct = 5.0;

    private float $maxStopPct = 2.0;

    private float $atrStopMult = 3.0;

    private float $minLateRetPct = 1.5;

    private float $maxImmediateMovePct = 3.5;

    private float $minGoodEntryRatioT4 = 0.25;

    private float $minGoodEntryRatioT5 = 0.15;

    /**
     * Cheap daily 5% mover universe cap.
     */
    private int $maxUniverseSymbols = 1000;

    /**
     * Expensive forward scan hard cap.
     */
    private int $maxForwardScanSymbols = 300;

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

        $safeLookbackMinutes = max(1, (int) $lookbackMinutes);
        $safeLimit = max(1, (int) $limit);
        $safeUniverseLimit = max(1, min($safeLimit, $this->maxUniverseSymbols));
        $safeForwardScanLimit = max(1, min($safeLimit, $this->maxForwardScanSymbols));

        $target4Mult = 1.0 + ($this->target4Pct / 100.0);
        $target5Mult = 1.0 + ($this->target5Pct / 100.0);
        $maxStopMult = $this->maxStopPct / 100.0;

        // Step 1: cheap universe gate. No window function here.
        $roughRows = $this->dbSelect(''
            ."SELECT\n"
            ."    f.symbol,\n"
            ."    f.asset_type,\n"
            ."    f.trading_date_est,\n"
            ."    MIN(f.low) AS rough_day_low,\n"
            ."    MAX(f.high) AS rough_day_high,\n"
            ."    ((MAX(f.high) - MIN(f.low)) / NULLIF(MIN(f.low), 0)) * 100 AS rough_range_pct,\n"
            ."    SUM(COALESCE(f.volume, 0)) AS day_volume\n"
            ."FROM five_minute_prices f\n"
            ."WHERE f.asset_type = ?\n"
            ."  AND f.trading_date_est = ?\n"
            ."  AND f.trading_time_est BETWEEN '09:30:00' AND '16:00:00'\n"
            ."  AND f.high IS NOT NULL\n"
            ."  AND f.high > 0\n"
            ."  AND f.low IS NOT NULL\n"
            ."  AND f.low > 0\n"
            ."  AND f.price IS NOT NULL\n"
            ."  AND f.price > 0\n"
            ."GROUP BY f.symbol, f.asset_type, f.trading_date_est\n"
            ."HAVING rough_range_pct >= ?\n"
            ."ORDER BY rough_range_pct DESC, day_volume DESC, symbol ASC\n"
            ."LIMIT {$safeUniverseLimit}",
            [$assetType, $tradeDate, $this->target5Pct]
        );

        if (empty($roughRows)) {
            return [];
        }

        $symbols = [];
        $roughBySymbol = [];
        foreach ($roughRows as $row) {
            $symbol = (string) $row->symbol;
            $symbols[] = $symbol;
            $roughBySymbol[$symbol] = [
                'rough_day_low' => $row->rough_day_low !== null ? (float) $row->rough_day_low : null,
                'rough_day_high' => $row->rough_day_high !== null ? (float) $row->rough_day_high : null,
                'rough_range_pct' => $row->rough_range_pct !== null ? (float) $row->rough_range_pct : null,
                'day_volume' => $row->day_volume !== null ? (float) $row->day_volume : null,
            ];
        }

        $symbols = array_values(array_unique($symbols));
        $symbolPlaceholders = implode(',', array_fill(0, count($symbols), '?'));

        // Step 2: expensive logic only for the pre-filtered symbol universe.
        $sql = "
            WITH latest_ts AS (
                SELECT
                    f.symbol,
                    f.asset_type,
                    f.trading_date_est,
                    MAX(f.ts_est) AS signal_ts_est
                FROM five_minute_prices f
                WHERE f.asset_type = ?
                  AND f.trading_date_est = ?
                  AND f.ts_est <= ?
                  AND f.ts_est >= DATE_SUB(?, INTERVAL {$safeLookbackMinutes} MINUTE)
                  AND f.trading_time_est BETWEEN '09:30:00' AND '11:00:00'
                  AND f.price IS NOT NULL
                  AND f.price > 0
                  AND f.open IS NOT NULL
                  AND f.open > 0
                  AND f.symbol IN ({$symbolPlaceholders})
                GROUP BY
                    f.symbol,
                    f.asset_type,
                    f.trading_date_est
            ),

            latest_raw AS (
                SELECT
                    f.symbol,
                    f.asset_type,
                    f.trading_date_est,
                    f.ts_est AS signal_ts_est,
                    f.price AS close_price,
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
                    f.trading_time_est,
                    ((f.price - f.open) / NULLIF(f.open, 0)) * 100 AS bar_move_pct
                FROM latest_ts lt
                JOIN five_minute_prices f
                  ON f.symbol = lt.symbol
                 AND f.asset_type = lt.asset_type
                 AND f.trading_date_est = lt.trading_date_est
                 AND f.ts_est = lt.signal_ts_est
            ),

            top_latest AS (
                SELECT *
                FROM latest_raw
                WHERE bar_move_pct >= ?
                ORDER BY
                    bar_move_pct DESC,
                    volume DESC,
                    symbol ASC
                LIMIT {$safeForwardScanLimit}
            ),

            candidates AS (
                SELECT
                    tl.*,
                    AVG(CASE WHEN pv.volume > 0 THEN pv.volume END) AS avg_prior_volume,
                    CASE
                        WHEN AVG(CASE WHEN pv.volume > 0 THEN pv.volume END) > 0
                        THEN tl.volume / AVG(CASE WHEN pv.volume > 0 THEN pv.volume END)
                        ELSE NULL
                    END AS volume_ratio
                FROM top_latest tl
                LEFT JOIN five_minute_prices pv
                  ON pv.symbol = tl.symbol
                 AND pv.asset_type = tl.asset_type
                 AND pv.trading_date_est = tl.trading_date_est
                 AND pv.ts_est < tl.signal_ts_est
                 AND pv.ts_est >= DATE_SUB(tl.signal_ts_est, INTERVAL 60 MINUTE)
                GROUP BY
                    tl.symbol,
                    tl.asset_type,
                    tl.trading_date_est,
                    tl.signal_ts_est,
                    tl.close_price,
                    tl.open,
                    tl.high,
                    tl.low,
                    tl.volume,
                    tl.atr,
                    tl.atr_pct,
                    tl.vwap,
                    tl.vwap_dist_pct,
                    tl.above_vwap,
                    tl.ema9,
                    tl.ema21,
                    tl.ema9_ema21_spread,
                    tl.ema9_above_ema21,
                    tl.trading_time_est,
                    tl.bar_move_pct
                HAVING avg_prior_volume IS NULL
                    OR avg_prior_volume <= 0
                    OR tl.volume / avg_prior_volume >= ?
            ),

            entry_candidates AS (
                SELECT
                    c.symbol,
                    c.asset_type,
                    c.trading_date_est,
                    c.signal_ts_est,
                    c.close_price AS signal_price,
                    c.bar_move_pct,
                    c.volume_ratio,
                    c.atr AS signal_atr,
                    c.atr_pct AS signal_atr_pct,
                    c.above_vwap,
                    c.ema9_above_ema21,

                    e.ts_est AS entry_ts_est,
                    e.price AS entry_price,
                    e.atr AS entry_atr,
                    e.atr_pct AS entry_atr_pct,

                    e.price - CASE
                        WHEN e.atr IS NULL OR e.atr <= 0
                            THEN e.price * {$maxStopMult}
                        ELSE LEAST(e.atr * {$this->atrStopMult}, e.price * {$maxStopMult})
                    END AS stop_price,

                    e.price * {$target4Mult} AS target4_price,
                    e.price * {$target5Mult} AS target5_price,

                    ((e.high - c.close_price) / NULLIF(c.close_price, 0)) * 100 AS ret_from_signal_pct

                FROM candidates c
                JOIN one_minute_prices e
                  ON e.symbol = c.symbol
                 AND e.asset_type = c.asset_type
                 AND e.trading_date_est = c.trading_date_est
                 AND e.ts_est > c.signal_ts_est
                 AND e.ts_est <= DATE_ADD(c.signal_ts_est, INTERVAL 15 MINUTE)

                WHERE e.price IS NOT NULL
                  AND e.price > 0
                  AND e.high IS NOT NULL
                  AND e.low IS NOT NULL
            ),

            future_stats AS (
                SELECT
                    ec.symbol,
                    ec.asset_type,
                    ec.trading_date_est,
                    ec.signal_ts_est,
                    ec.signal_price,
                    ec.bar_move_pct,
                    ec.volume_ratio,
                    ec.signal_atr,
                    ec.signal_atr_pct,
                    ec.above_vwap,
                    ec.ema9_above_ema21,

                    ec.entry_ts_est,
                    ec.entry_price,
                    ec.entry_atr,
                    ec.entry_atr_pct,
                    ec.stop_price,
                    ec.target4_price,
                    ec.target5_price,
                    ec.ret_from_signal_pct,

                    MIN(CASE WHEN fp.low <= ec.stop_price THEN fp.ts_est END) AS first_stop_ts,
                    MIN(CASE WHEN fp.high >= ec.target4_price THEN fp.ts_est END) AS first_target4_ts,
                    MIN(CASE WHEN fp.high >= ec.target5_price THEN fp.ts_est END) AS first_target5_ts,

                    MAX(((fp.high - ec.entry_price) / NULLIF(ec.entry_price, 0)) * 100) AS mfe_300_pct,
                    MIN(((fp.low - ec.entry_price) / NULLIF(ec.entry_price, 0)) * 100) AS mae_300_pct,

                    MAX(
                        CASE
                            WHEN fp.ts_est >= DATE_ADD(ec.entry_ts_est, INTERVAL 280 MINUTE)
                            THEN ((fp.price - ec.entry_price) / NULLIF(ec.entry_price, 0)) * 100
                        END
                    ) AS late_ret_pct,

                    MAX(
                        CASE
                            WHEN fp.ts_est > DATE_ADD(ec.signal_ts_est, INTERVAL 15 MINUTE)
                            THEN ((fp.high - ec.signal_price) / NULLIF(ec.signal_price, 0)) * 100
                        END
                    ) AS max_ret_15_315_from_signal_pct,

                    COUNT(*) AS future_minutes

                FROM entry_candidates ec
                JOIN one_minute_prices fp
                  ON fp.symbol = ec.symbol
                 AND fp.asset_type = ec.asset_type
                 AND fp.trading_date_est = ec.trading_date_est
                 AND fp.ts_est > ec.entry_ts_est
                 AND fp.ts_est <= DATE_ADD(ec.entry_ts_est, INTERVAL 300 MINUTE)

                WHERE fp.price IS NOT NULL
                  AND fp.high IS NOT NULL
                  AND fp.low IS NOT NULL

                GROUP BY
                    ec.symbol,
                    ec.asset_type,
                    ec.trading_date_est,
                    ec.signal_ts_est,
                    ec.signal_price,
                    ec.bar_move_pct,
                    ec.volume_ratio,
                    ec.signal_atr,
                    ec.signal_atr_pct,
                    ec.above_vwap,
                    ec.ema9_above_ema21,
                    ec.entry_ts_est,
                    ec.entry_price,
                    ec.entry_atr,
                    ec.entry_atr_pct,
                    ec.stop_price,
                    ec.target4_price,
                    ec.target5_price,
                    ec.ret_from_signal_pct
            ),

            entry_labels AS (
                SELECT
                    fs.*,

                    CASE
                        WHEN fs.future_minutes >= 280
                         AND fs.first_target4_ts IS NOT NULL
                         AND fs.first_target4_ts >= DATE_ADD(fs.signal_ts_est, INTERVAL 5 MINUTE)
                         AND fs.first_stop_ts IS NULL
                         AND COALESCE(fs.late_ret_pct, -999) >= {$this->minLateRetPct}
                        THEN 1 ELSE 0
                    END AS entry_label_t4,

                    CASE
                        WHEN fs.future_minutes >= 280
                         AND fs.first_target5_ts IS NOT NULL
                         AND fs.first_target5_ts >= DATE_ADD(fs.signal_ts_est, INTERVAL 5 MINUTE)
                         AND fs.first_stop_ts IS NULL
                         AND COALESCE(fs.late_ret_pct, -999) >= {$this->minLateRetPct}
                        THEN 1 ELSE 0
                    END AS entry_label_t5

                FROM future_stats fs
            ),

            signal_agg AS (
                SELECT
                    el.symbol,
                    el.asset_type,
                    el.trading_date_est,
                    el.signal_ts_est,

                    MAX(el.signal_price) AS signal_price,
                    MAX(el.bar_move_pct) AS bar_move_pct,
                    MAX(el.volume_ratio) AS volume_ratio,
                    MAX(el.signal_atr) AS signal_atr,
                    MAX(el.signal_atr_pct) AS signal_atr_pct,
                    MAX(el.above_vwap) AS above_vwap,
                    MAX(el.ema9_above_ema21) AS ema9_above_ema21,

                    COUNT(*) AS candidate_entries,

                    SUM(el.entry_label_t4) AS good_entries_t4,
                    SUM(el.entry_label_t5) AS good_entries_t5,

                    SUM(el.entry_label_t4) / COUNT(*) AS good_entry_ratio_t4,
                    SUM(el.entry_label_t5) / COUNT(*) AS good_entry_ratio_t5,

                    MIN(CASE WHEN el.entry_label_t4 = 1 THEN el.entry_ts_est END) AS first_good_entry_ts,
                    MAX(CASE WHEN el.entry_label_t4 = 1 THEN el.entry_ts_est END) AS last_good_entry_ts,

                    MAX(el.mfe_300_pct) AS best_mfe_300_pct,
                    MIN(el.mae_300_pct) AS worst_mae_300_pct,
                    MAX(el.late_ret_pct) AS best_late_ret_pct,

                    MAX(el.ret_from_signal_pct) AS max_ret_0_15_pct,
                    MAX(el.max_ret_15_315_from_signal_pct) AS max_ret_15_315_pct

                FROM entry_labels el
                GROUP BY
                    el.symbol,
                    el.asset_type,
                    el.trading_date_est,
                    el.signal_ts_est
            )

            SELECT
                sa.symbol,
                sa.asset_type,
                sa.trading_date_est,
                sa.signal_ts_est,
                sa.signal_price,
                sa.bar_move_pct,
                sa.volume_ratio,
                sa.signal_atr,
                sa.signal_atr_pct,
                sa.above_vwap,
                sa.ema9_above_ema21,

                sa.candidate_entries,
                sa.good_entries_t4,
                sa.good_entries_t5,
                sa.good_entry_ratio_t4,
                sa.good_entry_ratio_t5,
                sa.first_good_entry_ts,
                sa.last_good_entry_ts,
                sa.best_mfe_300_pct,
                sa.worst_mae_300_pct,
                sa.best_late_ret_pct,

                sa.max_ret_0_15_pct,
                sa.max_ret_15_315_pct,

                1 AS signal_label_t4,

                CASE
                    WHEN sa.candidate_entries >= 12
                     AND sa.good_entry_ratio_t5 >= {$this->minGoodEntryRatioT5}
                     AND sa.last_good_entry_ts >= DATE_ADD(sa.signal_ts_est, INTERVAL 12 MINUTE)
                     AND COALESCE(sa.max_ret_0_15_pct, 0) < {$this->target4Pct}
                     AND COALESCE(sa.max_ret_15_315_pct, 0) >= {$this->target5Pct}
                    THEN 1 ELSE 0
                END AS signal_label_t5

            FROM signal_agg sa

            WHERE sa.candidate_entries >= 12
              AND sa.good_entry_ratio_t4 >= {$this->minGoodEntryRatioT4}
              AND sa.last_good_entry_ts >= DATE_ADD(sa.signal_ts_est, INTERVAL 12 MINUTE)
              AND COALESCE(sa.max_ret_0_15_pct, 0) < {$this->maxImmediateMovePct}
              AND COALESCE(sa.max_ret_15_315_pct, 0) >= {$this->target4Pct}

            ORDER BY
                (
                    COALESCE(sa.good_entry_ratio_t5, 0) * 100
                    + COALESCE(sa.good_entry_ratio_t4, 0) * 50
                    + COALESCE(sa.best_mfe_300_pct, 0) * 5
                    + COALESCE(sa.best_late_ret_pct, 0) * 3
                    + COALESCE(sa.volume_ratio, 1) * 2
                    - COALESCE(sa.max_ret_0_15_pct, 0)
                ) DESC,
                sa.signal_ts_est DESC,
                sa.symbol ASC

            LIMIT {$safeLimit}
        ";

        $bindings = array_merge(
            [$assetType, $tradeDate, $asOfTsEst, $asOfTsEst],
            $symbols,
            [$minMovePct, $volMult]
        );

        $rows = $this->dbSelect($sql, $bindings);

        if (empty($rows)) {
            return [];
        }

        $finalSymbols = [];
        foreach ($rows as $row) {
            $finalSymbols[] = (string) $row->symbol;
        }
        $runContextBySymbol = $this->queryRunContexts(array_values(array_unique($finalSymbols)), $assetType, $tradeDate);

        $out = [];

        foreach ($rows as $rank => $row) {
            $symbol = (string) $row->symbol;
            $rough = $roughBySymbol[$symbol] ?? [];
            $run = $runContextBySymbol[$symbol] ?? [];

            $first5PctRunPct = $run['first_5pct_run_pct'] ?? ($rough['rough_range_pct'] ?? 0.0);

            $score = round(
                ((float) ($row->good_entry_ratio_t5 ?? 0) * 100)
                + ((float) ($row->good_entry_ratio_t4 ?? 0) * 50)
                + ((float) ($row->best_mfe_300_pct ?? 0) * 5)
                + ((float) ($row->best_late_ret_pct ?? 0) * 3)
                + ((float) ($row->volume_ratio ?? 1) * 2)
                + ((float) $first5PctRunPct)
                - ((float) ($row->max_ret_0_15_pct ?? 0)),
                3
            );

            $out[] = [
                'symbol' => $symbol,
                'asset_type' => (string) $row->asset_type,
                'signal_type' => 'FORWARD_5PCT_RUNNER_5M',
                'signal_ts_est' => (string) $row->signal_ts_est,
                'score' => $score,
                'atr' => $row->signal_atr !== null ? (float) $row->signal_atr : null,
                'atr_pct' => $row->signal_atr_pct !== null ? (float) $row->signal_atr_pct : null,
                'meta' => [
                    'version' => $this->version,
                    'forward_bias' => true,
                    'rank' => $rank + 1,

                    'signal_price' => (float) $row->signal_price,
                    'bar_move_pct' => $row->bar_move_pct !== null ? round((float) $row->bar_move_pct, 3) : null,
                    'volume_ratio' => $row->volume_ratio !== null ? round((float) $row->volume_ratio, 3) : null,

                    'run_start_ts_est' => $run['run_start_ts_est'] ?? null,
                    'run_start_price' => isset($run['run_start_price']) ? round((float) $run['run_start_price'], 4) : null,
                    'first_5pct_ts_est' => $run['first_5pct_ts_est'] ?? null,
                    'first_5pct_high' => isset($run['first_5pct_high']) ? round((float) $run['first_5pct_high'], 4) : null,
                    'first_5pct_run_pct' => isset($run['first_5pct_run_pct']) ? round((float) $run['first_5pct_run_pct'], 3) : null,
                    'rough_day_low' => isset($rough['rough_day_low']) ? round((float) $rough['rough_day_low'], 4) : null,
                    'rough_day_high' => isset($rough['rough_day_high']) ? round((float) $rough['rough_day_high'], 4) : null,
                    'rough_range_pct' => isset($rough['rough_range_pct']) ? round((float) $rough['rough_range_pct'], 3) : null,
                    'day_volume' => $rough['day_volume'] ?? null,

                    'candidate_entries' => (int) $row->candidate_entries,
                    'good_entries_t4' => (int) $row->good_entries_t4,
                    'good_entries_t5' => (int) $row->good_entries_t5,
                    'good_entry_ratio_t4' => round((float) $row->good_entry_ratio_t4, 4),
                    'good_entry_ratio_t5' => round((float) $row->good_entry_ratio_t5, 4),

                    'first_good_entry_ts' => $row->first_good_entry_ts !== null ? (string) $row->first_good_entry_ts : null,
                    'last_good_entry_ts' => $row->last_good_entry_ts !== null ? (string) $row->last_good_entry_ts : null,

                    'best_mfe_300_pct' => $row->best_mfe_300_pct !== null ? round((float) $row->best_mfe_300_pct, 3) : null,
                    'worst_mae_300_pct' => $row->worst_mae_300_pct !== null ? round((float) $row->worst_mae_300_pct, 3) : null,
                    'best_late_ret_pct' => $row->best_late_ret_pct !== null ? round((float) $row->best_late_ret_pct, 3) : null,

                    'max_ret_0_15_pct' => $row->max_ret_0_15_pct !== null ? round((float) $row->max_ret_0_15_pct, 3) : null,
                    'max_ret_15_315_pct' => $row->max_ret_15_315_pct !== null ? round((float) $row->max_ret_15_315_pct, 3) : null,

                    'signal_label_t4' => (int) $row->signal_label_t4,
                    'signal_label_t5' => (int) $row->signal_label_t5,

                    'target4_pct' => $this->target4Pct,
                    'target5_pct' => $this->target5Pct,
                    'atr_stop_mult' => $this->atrStopMult,
                    'max_stop_pct' => $this->maxStopPct,
                    'lookback_minutes' => $lookbackMinutes,
                    'min_move_pct' => $minMovePct,
                    'vol_mult' => $volMult,
                    'max_universe_symbols' => $this->maxUniverseSymbols,
                    'max_forward_scan_symbols' => $this->maxForwardScanSymbols,
                ],
            ];
        }

        return $out;
    }

    /**
     * Calculates chronological +5% run context in PHP for final symbols only.
     * This avoids a large MySQL window-function CTE in the main scanner query.
     *
     * @param  array<int,string>  $symbols
     * @return array<string,array<string,mixed>> keyed by symbol
     */
    private function queryRunContexts(array $symbols, string $assetType, string $tradeDate): array
    {
        $symbols = array_values(array_unique(array_filter($symbols)));
        if (empty($symbols)) {
            return [];
        }

        $symbolPlaceholders = implode(',', array_fill(0, count($symbols), '?'));

        $bars = $this->dbSelect("
            SELECT
                symbol,
                asset_type,
                trading_date_est,
                ts_est,
                high,
                low
            FROM five_minute_prices
            WHERE asset_type = ?
              AND trading_date_est = ?
              AND symbol IN ({$symbolPlaceholders})
              AND trading_time_est BETWEEN '09:30:00' AND '16:00:00'
              AND high IS NOT NULL
              AND high > 0
              AND low IS NOT NULL
              AND low > 0
            ORDER BY symbol ASC, ts_est ASC
        ", array_merge([$assetType, $tradeDate], $symbols));

        $contexts = [];
        $currentSymbol = null;
        $runningLow = null;
        $runningLowTs = null;
        $targetMult = 1.0 + ($this->target5Pct / 100.0);

        foreach ($bars as $bar) {
            $symbol = (string) $bar->symbol;
            $low = (float) $bar->low;
            $high = (float) $bar->high;
            $ts = (string) $bar->ts_est;

            if ($symbol !== $currentSymbol) {
                $currentSymbol = $symbol;
                $runningLow = null;
                $runningLowTs = null;
            }

            if ($runningLow === null || $low < $runningLow) {
                $runningLow = $low;
                $runningLowTs = $ts;
            }

            if (! isset($contexts[$symbol]) && $runningLow !== null && $runningLow > 0 && $high >= $runningLow * $targetMult) {
                $contexts[$symbol] = [
                    'run_start_ts_est' => $runningLowTs,
                    'run_start_price' => $runningLow,
                    'first_5pct_ts_est' => $ts,
                    'first_5pct_high' => $high,
                    'first_5pct_run_pct' => (($high - $runningLow) / $runningLow) * 100.0,
                ];
            }
        }

        return $contexts;
    }
}
