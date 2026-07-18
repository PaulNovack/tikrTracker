<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * Pipeline P Daily Backtest Service (v3000.0 — Opening Drive Continuation)
 *
 * Runs ONE combined SQL query per trading day that:
 *  1. Finds symbols that gapped open >= 1.5% above prior close (gap-and-go universe).
 *  2. Requires every 5m bar since open to close above VWAP (zero breaches).
 *  3. Scores by move_30m × 1.2 + rvol × 1.0 + vwap_stability × 1.5.
 *  4. Computes future outcome stats (targets, stops, MFE, MAE, late return).
 *  5. Labels entries and ranks by composite score.
 *
 * Returns fully-formed signal+entry arrays ready for TradeAlertWriterV1::upsertAlert().
 */
class PipelinePDailyBacktestService
{
    use HasPriceTables;

    private string $version = 'v3000.0';

    private float $target4Pct = 4.0;

    private float $target5Pct = 5.0;

    private float $maxStopPct = 2.0;

    private float $atrStopMult = 3.0;

    private float $minLateRetPct = 1.5;

    private float $minGapOpenPct = 1.5;

    private float $minVwapStability = 0.70;

    private float $minRvol5m = 1.5;

    private float $minNotional5m = 100000;

    /** Max symbols in the gap-open universe gate. */
    private int $maxUniverseSymbols = 1000;

    /** Max signals with future-stats computation (expensive 300-min join). */
    private int $maxFutureStatsSignals = 100;

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Run a single day's backtest.
     *
     * @return array<int, array{signal: array, entry: array}>
     */
    public function backtestDay(
        string $assetType,
        string $tradingDate,
        int $lookbackMinutes = 60,
        float $minMovePct = 0.4,
        float $volMult = 1.5,
        int $top = 50,
    ): array {
        set_time_limit(0);

        if (config('database.default') === 'mysql') {
            DB::statement('SET SESSION innodb_lock_wait_timeout = 600');
        }

        $safeTop = max(1, min($top, $this->maxFutureStatsSignals));
        $target4Mult = 1.0 + ($this->target4Pct / 100.0);
        $target5Mult = 1.0 + ($this->target5Pct / 100.0);
        $maxStopMult = $this->maxStopPct / 100.0;

        // Clean up stale temp tables
        DB::statement('DROP TEMPORARY TABLE IF EXISTS tmp_p_signals');
        DB::statement('DROP TEMPORARY TABLE IF EXISTS tmp_p_entries');
        DB::statement('DROP TEMPORARY TABLE IF EXISTS tmp_p_outcomes');

        // ═══════════════════════════════════════════════════
        // Step 1: Build temp table of prior closes (fast, one query)
        // ═══════════════════════════════════════════════════
        DB::statement('
            CREATE TEMPORARY TABLE tmp_p_prior_close (
                symbol VARCHAR(20) NOT NULL,
                prev_close DOUBLE NOT NULL,
                prev_date VARCHAR(10) NOT NULL,
                PRIMARY KEY (symbol)
            ) ENGINE=MEMORY
        ');

        DB::insert('
            INSERT INTO tmp_p_prior_close (symbol, prev_close, prev_date)
            SELECT
                symbol,
                price AS prev_close,
                trading_date_est AS prev_date
            FROM five_minute_prices
            WHERE (symbol, trading_date_est, ts_est) IN (
                SELECT symbol, MAX(trading_date_est), MAX(ts_est)
                FROM five_minute_prices
                WHERE asset_type = ?
                  AND trading_date_est < ?
                GROUP BY symbol
            )
        ', [$assetType, $tradingDate]);

        // ═══════════════════════════════════════════════════
        // Step 2: Build temp table of today's first bar + gap check
        // ═══════════════════════════════════════════════════
        DB::statement('
            CREATE TEMPORARY TABLE tmp_p_gap_check (
                symbol VARCHAR(20) NOT NULL,
                first_open DOUBLE NOT NULL,
                first_ts VARCHAR(19) NOT NULL,
                prev_close DOUBLE NOT NULL,
                gap_open_pct DOUBLE NOT NULL,
                PRIMARY KEY (symbol)
            ) ENGINE=MEMORY
        ');

        DB::insert("
            INSERT INTO tmp_p_gap_check (symbol, first_open, first_ts, prev_close, gap_open_pct)
            SELECT
                f.symbol,
                f.open,
                f.ts_est,
                pc.prev_close,
                ((f.open - pc.prev_close) / NULLIF(pc.prev_close, 0)) * 100
            FROM five_minute_prices f
            JOIN tmp_p_prior_close pc ON pc.symbol = f.symbol
            WHERE f.asset_type = ?
              AND f.trading_date_est = ?
              AND f.ts_est = (
                SELECT MIN(f2.ts_est)
                FROM five_minute_prices f2
                WHERE f2.symbol = f.symbol
                  AND f2.asset_type = ?
                  AND f2.trading_date_est = ?
              )
              AND f.open IS NOT NULL AND f.open > 0
              AND pc.prev_close > 0
              AND ((f.open - pc.prev_close) / pc.prev_close) * 100 >= {$this->minGapOpenPct}
        ", [$assetType, $tradingDate, $assetType, $tradingDate]);

        // ═══════════════════════════════════════════════════
        // Step 3: Build temp table of VWAP-stable symbols
        // (all bars since open above VWAP, score >= threshold)
        // ═══════════════════════════════════════════════════
        DB::statement('
            CREATE TEMPORARY TABLE tmp_p_vwap_stable (
                symbol VARCHAR(20) NOT NULL,
                vwap_stability_score DOUBLE NOT NULL,
                bars_above INT NOT NULL,
                total_bars INT NOT NULL,
                PRIMARY KEY (symbol)
            ) ENGINE=MEMORY
        ');

        DB::insert("
            INSERT INTO tmp_p_vwap_stable (symbol, vwap_stability_score, bars_above, total_bars)
            SELECT
                symbol,
                CAST(SUM(CASE WHEN price >= vwap THEN 1 ELSE 0 END) AS DECIMAL(10,4))
                    / NULLIF(COUNT(*), 0) AS vwap_stability_score,
                SUM(CASE WHEN price >= vwap THEN 1 ELSE 0 END) AS bars_above,
                COUNT(*) AS total_bars
            FROM five_minute_prices
            WHERE asset_type = ?
              AND trading_date_est = ?
              AND trading_time_est <= '11:00:00'
              AND symbol IN (SELECT symbol FROM tmp_p_gap_check)
            GROUP BY symbol
            HAVING bars_above = total_bars
               AND vwap_stability_score >= {$this->minVwapStability}
        ", [$assetType, $tradingDate]);

        // ═══════════════════════════════════════════════════
        // Step 4: Populate tmp_p_signals (only vwap-stable gap symbols)
        // ═══════════════════════════════════════════════════
        DB::statement('
            CREATE TEMPORARY TABLE tmp_p_signals (
                symbol VARCHAR(20) NOT NULL,
                signal_ts_est VARCHAR(19) NOT NULL,
                signal_price DOUBLE NOT NULL,
                signal_open DOUBLE NOT NULL,
                bar_move_pct DOUBLE,
                signal_vol_ratio DOUBLE,
                signal_atr DOUBLE,
                signal_atr_pct DOUBLE,
                signal_vwap DOUBLE,
                above_vwap INT,
                ema9 DOUBLE,
                ema21 DOUBLE,
                ema9_ema21_spread DOUBLE,
                ema9_above_ema21 INT,
                signal_time VARCHAR(8),
                vwap_stability_score DOUBLE,
                gap_open_pct DOUBLE,
                move_30m_pct DOUBLE,
                rvol_5m DOUBLE,
                notional_last5m DOUBLE,
                PRIMARY KEY (symbol, signal_ts_est)
            ) ENGINE=MEMORY
        ');

        // Get 30m-ago close for each symbol (one query for all)
        DB::statement('
            CREATE TEMPORARY TABLE tmp_p_close_30m_ago (
                symbol VARCHAR(20) NOT NULL,
                close_30m_ago DOUBLE,
                PRIMARY KEY (symbol)
            ) ENGINE=MEMORY
        ');

        DB::insert('
            INSERT INTO tmp_p_close_30m_ago (symbol, close_30m_ago)
            SELECT symbol, price
            FROM (
                SELECT symbol, price,
                    ROW_NUMBER() OVER (PARTITION BY symbol ORDER BY ts_est DESC) AS rn
                FROM five_minute_prices
                WHERE asset_type = ?
                  AND trading_date_est = ?
                  AND symbol IN (SELECT symbol FROM tmp_p_vwap_stable)
            ) ranked
            WHERE rn = 7
        ', [$assetType, $tradingDate]);

        // Insert signals — simple join on temp tables
        DB::insert("
            INSERT INTO tmp_p_signals
            SELECT
                f.symbol,
                f.ts_est,
                f.price,
                f.open,
                ((f.price - f.open) / NULLIF(f.open, 0)) * 100,
                NULL,
                f.atr,
                f.atr_pct,
                f.vwap,
                f.above_vwap,
                f.ema9,
                f.ema21,
                f.ema9_ema21_spread,
                f.ema9_above_ema21,
                f.trading_time_est,
                vs.vwap_stability_score,
                gc.gap_open_pct,
                ((f.price - m.close_30m_ago) / NULLIF(m.close_30m_ago, 0)) * 100,
                NULL,
                f.price * f.volume
            FROM five_minute_prices f
            JOIN tmp_p_vwap_stable vs ON vs.symbol = f.symbol
            JOIN tmp_p_gap_check gc ON gc.symbol = f.symbol
            LEFT JOIN tmp_p_close_30m_ago m ON m.symbol = f.symbol
            WHERE f.asset_type = ?
              AND f.trading_date_est = ?
              AND f.trading_time_est BETWEEN '09:30:00' AND '11:00:00'
              AND f.price IS NOT NULL AND f.price > 0
              AND f.open IS NOT NULL AND f.open > 0
              AND ((f.price - f.open) / NULLIF(f.open, 0)) * 100 >= ?
              AND (f.price * f.volume) >= {$this->minNotional5m}
            ORDER BY
              ((f.price - f.open) / NULLIF(f.open, 0)) * 100 DESC,
              f.volume DESC,
              f.symbol ASC
            LIMIT {$safeTop}
        ", [$assetType, $tradingDate, $minMovePct]);

        $signalCount = DB::select('SELECT COUNT(*) as c FROM tmp_p_signals')[0]->c ?? 0;

        // Load roughBySymbol from gap check
        $roughRows = DB::select('SELECT * FROM tmp_p_gap_check');
        $symbols = [];
        $roughBySymbol = [];
        foreach ($roughRows as $r) {
            $sym = (string) $r->symbol;
            $symbols[] = $sym;
            $roughBySymbol[$sym] = [
                'first_open' => (float) $r->first_open,
                'first_ts' => (string) $r->first_ts,
                'prev_close' => (float) $r->prev_close,
                'gap_open_pct' => (float) $r->gap_open_pct,
            ];
        }
        $symbols = array_values(array_unique($symbols));

        if ($signalCount === 0) {
            return [];
        }

        // ═══════════════════════════════════════════════════
        // Step 3: Best 1m entry per signal → tmp_p_entries
        // ═══════════════════════════════════════════════════
        DB::statement('
            CREATE TEMPORARY TABLE tmp_p_entries (
                symbol VARCHAR(20) NOT NULL,
                signal_ts_est VARCHAR(19) NOT NULL,
                signal_price DOUBLE NOT NULL,
                entry_ts_est VARCHAR(19) NOT NULL,
                entry_price DOUBLE NOT NULL,
                entry_open DOUBLE,
                entry_high DOUBLE,
                entry_low DOUBLE,
                entry_volume DOUBLE,
                entry_atr DOUBLE,
                entry_atr_pct DOUBLE,
                entry_vwap DOUBLE,
                entry_vwap_dist_pct DOUBLE,
                entry_above_vwap INT,
                entry_ema9 DOUBLE,
                entry_ema21 DOUBLE,
                entry_ema9_ema21_spread DOUBLE,
                entry_ema9_above_ema21 INT,
                stop_price DOUBLE NOT NULL,
                risk_per_share DOUBLE NOT NULL,
                target4_price DOUBLE NOT NULL,
                target5_price DOUBLE NOT NULL,
                entry_vol_ratio DOUBLE,
                PRIMARY KEY (symbol, signal_ts_est)
            ) ENGINE=MEMORY
        ');

        DB::insert("
            INSERT INTO tmp_p_entries
            SELECT
                s.symbol,
                s.signal_ts_est,
                s.signal_price,
                e.ts_est,
                e.price,
                e.open,
                e.high,
                e.low,
                e.volume,
                e.atr,
                e.atr_pct,
                e.vwap,
                e.vwap_dist_pct,
                e.above_vwap,
                e.ema9,
                e.ema21,
                e.ema9_ema21_spread,
                e.ema9_above_ema21,
                e.price - CASE
                    WHEN e.atr IS NULL OR e.atr <= 0 THEN e.price * {$maxStopMult}
                    ELSE LEAST(e.atr * {$this->atrStopMult}, e.price * {$maxStopMult})
                END,
                CASE
                    WHEN e.atr IS NULL OR e.atr <= 0 THEN e.price * {$maxStopMult}
                    ELSE LEAST(e.atr * {$this->atrStopMult}, e.price * {$maxStopMult})
                END,
                e.price * {$target4Mult},
                e.price * {$target5Mult},
                NULL
            FROM tmp_p_signals s
            JOIN one_minute_prices e
              ON e.symbol = s.symbol
             AND e.asset_type = ?
             AND e.trading_date_est = ?
             AND e.ts_est > s.signal_ts_est
             AND e.ts_est <= DATE_ADD(s.signal_ts_est, INTERVAL 15 MINUTE)
            WHERE e.price IS NOT NULL AND e.price > 0
              AND e.high IS NOT NULL
              AND e.low IS NOT NULL
              AND e.ts_est = (
                  SELECT e2.ts_est
                  FROM one_minute_prices e2
                  WHERE e2.symbol = s.symbol
                    AND e2.asset_type = ?
                    AND e2.trading_date_est = ?
                    AND e2.ts_est > s.signal_ts_est
                    AND e2.ts_est <= DATE_ADD(s.signal_ts_est, INTERVAL 15 MINUTE)
                    AND e2.price IS NOT NULL AND e2.price > 0
                    AND e2.high IS NOT NULL AND e2.low IS NOT NULL
                  ORDER BY (e2.price * e2.volume) DESC
                  LIMIT 1
              )
        ", [$assetType, $tradingDate, $assetType, $tradingDate]);

        $signalCount = DB::select('SELECT COUNT(*) as c FROM tmp_p_signals')[0]->c ?? 0;
        $entryCount = DB::select('SELECT COUNT(*) as c FROM tmp_p_entries')[0]->c ?? 0;

        if ($entryCount === 0) {
            return [];
        }

        // Volume ratio not computed — volume is not a gate for this pipeline

        // ═══════════════════════════════════════════════════
        // ═══════════════════════════════════════════════════
        // Step 4: Future outcome stats — materialized tmp_p_future_stats
        // ═══════════════════════════════════════════════════
        DB::statement('
            CREATE TEMPORARY TABLE tmp_p_future_stats (
                symbol VARCHAR(20) NOT NULL,
                entry_ts_est VARCHAR(19) NOT NULL,
                first_stop_ts VARCHAR(19),
                first_target4_ts VARCHAR(19),
                first_target5_ts VARCHAR(19),
                mfe_300_pct DOUBLE,
                mae_300_pct DOUBLE,
                late_ret_pct DOUBLE,
                future_minutes INT,
                PRIMARY KEY (symbol, entry_ts_est)
            ) ENGINE=MEMORY
        ');

        DB::insert('
            INSERT INTO tmp_p_future_stats
            SELECT
                pe.symbol,
                pe.entry_ts_est,
                MIN(CASE WHEN fp.low <= pe.stop_price THEN fp.ts_est END),
                MIN(CASE WHEN fp.high >= pe.target4_price THEN fp.ts_est END),
                MIN(CASE WHEN fp.high >= pe.target5_price THEN fp.ts_est END),
                MAX(((fp.high - pe.entry_price) / NULLIF(pe.entry_price, 0)) * 100),
                MIN(((fp.low - pe.entry_price) / NULLIF(pe.entry_price, 0)) * 100),
                MAX(CASE WHEN fp.ts_est >= DATE_ADD(pe.entry_ts_est, INTERVAL 280 MINUTE)
                    THEN ((fp.price - pe.entry_price) / NULLIF(pe.entry_price, 0)) * 100 END),
                COUNT(*)
            FROM tmp_p_entries pe
            JOIN one_minute_prices fp
              ON fp.symbol = pe.symbol
             AND fp.asset_type = ?
             AND fp.trading_date_est = ?
             AND fp.ts_est > pe.entry_ts_est
             AND fp.ts_est <= DATE_ADD(pe.entry_ts_est, INTERVAL 300 MINUTE)
            WHERE fp.price IS NOT NULL AND fp.high IS NOT NULL AND fp.low IS NOT NULL
            GROUP BY pe.symbol, pe.entry_ts_est, pe.stop_price, pe.target4_price, pe.target5_price, pe.entry_price
        ', [$assetType, $tradingDate]);

        DB::statement('
            CREATE TEMPORARY TABLE tmp_p_outcomes (
                symbol VARCHAR(20) NOT NULL,
                signal_ts_est VARCHAR(19) NOT NULL,
                signal_price DOUBLE NOT NULL,
                signal_open DOUBLE NOT NULL,
                bar_move_pct DOUBLE,
                signal_vol_ratio DOUBLE,
                signal_atr DOUBLE,
                signal_atr_pct DOUBLE,
                signal_vwap DOUBLE,
                above_vwap INT,
                ema9 DOUBLE,
                ema21 DOUBLE,
                ema9_ema21_spread DOUBLE,
                ema9_above_ema21 INT,
                signal_time VARCHAR(8),
                entry_ts_est VARCHAR(19) NOT NULL,
                entry_price DOUBLE NOT NULL,
                entry_open DOUBLE,
                entry_high DOUBLE,
                entry_low DOUBLE,
                entry_volume DOUBLE,
                entry_atr DOUBLE,
                entry_atr_pct DOUBLE,
                entry_vwap DOUBLE,
                entry_vwap_dist_pct DOUBLE,
                entry_above_vwap INT,
                entry_ema9 DOUBLE,
                entry_ema21 DOUBLE,
                entry_ema9_ema21_spread DOUBLE,
                entry_ema9_above_ema21 INT,
                stop_price DOUBLE NOT NULL,
                risk_per_share DOUBLE NOT NULL,
                target4_price DOUBLE NOT NULL,
                target5_price DOUBLE NOT NULL,
                entry_vol_ratio DOUBLE,
                first_stop_ts VARCHAR(19),
                first_target4_ts VARCHAR(19),
                first_target5_ts VARCHAR(19),
                mfe_300_pct DOUBLE,
                mae_300_pct DOUBLE,
                late_ret_pct DOUBLE,
                future_minutes INT,
                entry_label_t4 INT DEFAULT 0,
                entry_label_t5 INT DEFAULT 0,
                PRIMARY KEY (symbol, signal_ts_est)
            ) ENGINE=MEMORY
        ');

        DB::insert('
            INSERT INTO tmp_p_outcomes
            SELECT
                s.symbol, s.signal_ts_est, s.signal_price, s.signal_open,
                s.bar_move_pct, s.signal_vol_ratio, s.signal_atr, s.signal_atr_pct,
                s.signal_vwap, s.above_vwap, s.ema9, s.ema21,
                s.ema9_ema21_spread, s.ema9_above_ema21, s.signal_time,
                pe.entry_ts_est, pe.entry_price, pe.entry_open, pe.entry_high, pe.entry_low,
                pe.entry_volume, pe.entry_atr, pe.entry_atr_pct, pe.entry_vwap,
                pe.entry_vwap_dist_pct, pe.entry_above_vwap,
                pe.entry_ema9, pe.entry_ema21,
                pe.entry_ema9_ema21_spread, pe.entry_ema9_above_ema21,
                pe.stop_price, pe.risk_per_share, pe.target4_price, pe.target5_price,
                pe.entry_vol_ratio,
                fs.first_stop_ts,
                fs.first_target4_ts,
                fs.first_target5_ts,
                fs.mfe_300_pct,
                fs.mae_300_pct,
                fs.late_ret_pct,
                fs.future_minutes,
                0, 0
            FROM tmp_p_signals s
            JOIN tmp_p_entries pe
              ON pe.symbol = s.symbol AND pe.signal_ts_est = s.signal_ts_est
            LEFT JOIN tmp_p_future_stats fs
              ON fs.symbol = pe.symbol AND fs.entry_ts_est = pe.entry_ts_est
        ');

        // ═══════════════════════════════════════════════════
        // Step 5: Label and final SELECT
        // ═══════════════════════════════════════════════════
        DB::update('
            UPDATE tmp_p_outcomes SET
                entry_label_t4 = CASE
                    WHEN first_target4_ts IS NOT NULL AND first_stop_ts IS NULL THEN 1 ELSE 0 END,
                entry_label_t5 = CASE
                    WHEN first_target5_ts IS NOT NULL AND first_stop_ts IS NULL THEN 1 ELSE 0 END
        ');

        $rows = DB::select("
            SELECT *
            FROM tmp_p_outcomes
            WHERE first_stop_ts IS NULL
              AND first_target4_ts IS NOT NULL
            ORDER BY
                entry_label_t5 DESC,
                COALESCE(late_ret_pct, 0) DESC,
                COALESCE(mfe_300_pct, 0) DESC,
                COALESCE(entry_vol_ratio, 1) DESC,
                entry_ts_est ASC
            LIMIT {$safeTop}
        ");

        // Step 6: PHP-side chronological +5% run detection, scoring, output
        // ═══════════════════════════════════════════════════
        $finalSymbols = [];
        foreach ($rows as $r) {
            $finalSymbols[] = (string) $r->symbol;
        }
        $runContextBySymbol = $this->queryRunContexts(array_values(array_unique($finalSymbols)), $assetType, $tradingDate);

        $results = [];
        foreach ($rows as $rank => $r) {
            $symbol = (string) $r->symbol;
            $run = $runContextBySymbol[$symbol] ?? [];

            if (empty($run)) {
                continue;
            }

            $rough = $roughBySymbol[$symbol] ?? [];
            $entryPrice = (float) $r->entry_price;
            $stopPrice = (float) $r->stop_price;
            $riskPerShare = max(0.01, (float) $r->risk_per_share);
            $riskPct = $entryPrice > 0 ? round(($riskPerShare / $entryPrice) * 100, 2) : 0.0;

            $entryLabelT4 = (int) $r->entry_label_t4;
            $entryLabelT5 = (int) $r->entry_label_t5;
            $first5PctRunPct = $run['first_5pct_run_pct'] ?? ($rough['rough_range_pct'] ?? 0.0);

            $entryOpen = (float) ($r->entry_open ?? 0);
            $entryHigh = (float) ($r->entry_high ?? 0);
            $entryLow = (float) ($r->entry_low ?? 0);
            $entryVolume = (float) ($r->entry_volume ?? 0);
            $entryVwap = (float) ($r->entry_vwap ?? 0);

            $hod = $this->queryHod($symbol, $assetType, $tradingDate);
            $roomToHodPct = ($hod !== null && $entryPrice > 0) ? round((($hod - $entryPrice) / $entryPrice) * 100, 4) : null;
            $roomToHodAtr = ($roomToHodPct !== null && $r->entry_atr > 0)
                ? round(($hod - $entryPrice) / (float) $r->entry_atr, 4) : null;
            $aboveVwapEntryPct = $entryVwap > 0 ? round((($entryPrice - $entryVwap) / $entryVwap) * 100, 4) : null;

            $score = round(
                ($r->move_30m_pct !== null ? (float) $r->move_30m_pct * 1.2 : 0)
                + ($r->rvol_5m !== null ? (float) $r->rvol_5m * 1.0 : 0)
                + ($r->vwap_stability_score !== null ? (float) $r->vwap_stability_score * 1.5 : 0),
                3
            );

            $results[] = [
                'signal' => [
                    'symbol' => $symbol,
                    'asset_type' => $assetType,
                    'signal_type' => 'MOMO_5M_V3000',
                    'signal_ts_est' => (string) $r->signal_ts_est,
                    'score' => $score,
                    'atr' => $r->signal_atr !== null ? (float) $r->signal_atr : null,
                    'atr_pct' => $r->signal_atr_pct !== null ? (float) $r->signal_atr_pct : null,
                    'meta' => [
                        'version' => $this->version,
                        'forward_bias' => true,
                        'rank' => $rank + 1,
                        'signal_price' => (float) $r->signal_price,
                        'setup_price' => (float) $r->signal_price,
                        'bar_move_pct' => $r->bar_move_pct !== null ? round((float) $r->bar_move_pct, 3) : null,
                        'volume_ratio' => $r->signal_vol_ratio !== null ? round((float) $r->signal_vol_ratio, 3) : null,
                        'run_start_ts_est' => $run['run_start_ts_est'] ?? null,
                        'run_start_price' => isset($run['run_start_price']) ? round((float) $run['run_start_price'], 4) : null,
                        'first_5pct_ts_est' => $run['first_5pct_ts_est'] ?? null,
                        'first_5pct_high' => isset($run['first_5pct_high']) ? round((float) $run['first_5pct_high'], 4) : null,
                        'first_5pct_run_pct' => isset($run['first_5pct_run_pct']) ? round((float) $run['first_5pct_run_pct'], 3) : null,
                        'rough_day_low' => isset($rough['rough_day_low']) ? round((float) $rough['rough_day_low'], 4) : null,
                        'rough_day_high' => isset($rough['rough_day_high']) ? round((float) $rough['rough_day_high'], 4) : null,
                        'rough_range_pct' => isset($rough['rough_range_pct']) ? round((float) $rough['rough_range_pct'], 3) : null,
                        'day_volume' => $rough['day_volume'] ?? null,
                        'target4_pct' => $this->target4Pct,
                        'target5_pct' => $this->target5Pct,
                        'atr_stop_mult' => $this->atrStopMult,
                        'max_stop_pct' => $this->maxStopPct,
                        'lookback_minutes' => $lookbackMinutes,
                        'min_move_pct' => $minMovePct,
                        'vol_mult' => $volMult,
                    ],
                ],
                'entry' => [
                    'type' => 'CONSOLIDATION_CONTINUATION',
                    'entry_ts_est' => (string) $r->entry_ts_est,
                    'entry' => round($entryPrice, 4),
                    'stop' => round($stopPrice, 4),
                    'risk_pct' => $riskPct,
                    'risk_per_share' => round($riskPerShare, 4),
                    'score' => $score,
                    'vol_ratio' => $r->entry_vol_ratio !== null ? round((float) $r->entry_vol_ratio, 3) : null,
                    'atr' => $r->entry_atr !== null ? (float) $r->entry_atr : null,
                    'atr_pct' => $r->entry_atr_pct !== null ? (float) $r->entry_atr_pct : null,
                    'suggested_trailing_stop' => round($riskPerShare, 4),
                    'suggested_trailing_stop_pct' => $riskPct,
                    'targets' => $this->buildTargets($entryPrice, $riskPerShare),
                    'hod' => $hod,
                    'room_to_hod_pct' => $roomToHodPct,
                    'room_to_hod_atr' => $roomToHodAtr,
                    'above_vwap_entry_pct' => $aboveVwapEntryPct,

                    // Entry quality metrics (V25_2-style)
                    'rsi' => null,
                    'five_min_directional_changes' => null,
                    'five_min_green_bar_pct' => null,
                    'five_min_net_progress' => null,
                    'entry_body_pct' => $entryOpen > 0 ? round(abs($entryPrice - $entryOpen) / $entryOpen * 100, 4) : null,
                    'entry_close_position' => ($entryHigh > $entryLow) ? round(($entryPrice - $entryLow) / ($entryHigh - $entryLow), 6) : null,
                    'entry_volume_ratio' => $r->entry_vol_ratio !== null ? round((float) $r->entry_vol_ratio, 4) : null,
                    'entry_notional_1m' => round($entryPrice * $entryVolume, 2),
                    'entry_spread_strength' => $this->computeSpreadStrength($entryPrice, (float) ($r->entry_ema9_ema21_spread ?? 0)),
                    'entry_vwap_dist_score' => $this->computeVwapDistScore($entryPrice, (float) ($r->entry_vwap ?? 0)),
                    'entry_atr_score' => $this->computeAtrScore($r->entry_atr_pct !== null ? (float) $r->entry_atr_pct : null),
                    'entry_vol_score' => $this->computeVolScore($entryVolume, $r->entry_vol_ratio !== null ? (float) $r->entry_vol_ratio : null),
                    'entry_candle_score' => $this->computeCandleScore($entryPrice, $entryOpen, $entryHigh, $entryLow),
                    'entry_time_bonus' => $this->computeTimeBonus((string) $r->entry_ts_est),

                    'meta' => [
                        'version' => $this->version,
                        'forward_bias' => true,
                        'signal_ts_est' => (string) $r->signal_ts_est,
                        'signal_price' => $r->signal_price !== null ? round((float) $r->signal_price, 4) : null,
                        'run_start_ts_est' => $run['run_start_ts_est'] ?? null,
                        'run_start_price' => isset($run['run_start_price']) ? round((float) $run['run_start_price'], 4) : null,
                        'first_5pct_ts_est' => $run['first_5pct_ts_est'] ?? null,
                        'first_5pct_high' => isset($run['first_5pct_high']) ? round((float) $run['first_5pct_high'], 4) : null,
                        'first_5pct_run_pct' => isset($run['first_5pct_run_pct']) ? round((float) $run['first_5pct_run_pct'], 3) : null,
                        'entry_label_t4' => $entryLabelT4,
                        'entry_label_t5' => $entryLabelT5,
                        'first_target4_ts' => $r->first_target4_ts !== null ? (string) $r->first_target4_ts : null,
                        'first_target5_ts' => $r->first_target5_ts !== null ? (string) $r->first_target5_ts : null,
                        'first_stop_ts' => $r->first_stop_ts !== null ? (string) $r->first_stop_ts : null,
                        'mfe_300_pct' => $r->mfe_300_pct !== null ? round((float) $r->mfe_300_pct, 3) : null,
                        'mae_300_pct' => $r->mae_300_pct !== null ? round((float) $r->mae_300_pct, 3) : null,
                        'late_ret_pct' => $r->late_ret_pct !== null ? round((float) $r->late_ret_pct, 3) : null,
                        'future_minutes' => (int) $r->future_minutes,
                        'target4_pct' => $this->target4Pct,
                        'target5_pct' => $this->target5Pct,
                        'atr_stop_mult' => $this->atrStopMult,
                        'max_stop_pct' => $this->maxStopPct,
                    ],
                ],
            ];
        }

        return $results;
    }

    /**
     * PHP-side chronological +5% run detection.
     *
     * @param  array<int,string>  $symbols
     * @return array<string,array<string,mixed>>
     */
    private function queryRunContexts(array $symbols, string $assetType, string $tradeDate): array
    {
        $symbols = array_values(array_unique(array_filter($symbols)));
        if (empty($symbols)) {
            return [];
        }

        $symbolPlaceholders = implode(',', array_fill(0, count($symbols), '?'));

        $bars = $this->dbSelect("
            SELECT symbol, ts_est, high, low
            FROM five_minute_prices
            WHERE asset_type = ?
              AND trading_date_est = ?
              AND symbol IN ({$symbolPlaceholders})
              AND trading_time_est BETWEEN '09:30:00' AND '16:00:00'
              AND high IS NOT NULL AND high > 0
              AND low IS NOT NULL AND low > 0
            ORDER BY symbol ASC, ts_est ASC
        ", array_merge([$assetType, $tradeDate], $symbols));

        $contexts = [];
        $currentSymbol = null;
        $runningLow = null;
        $runningLowTs = null;
        $targetMult = 1.0 + ($this->target5Pct / 100.0);

        foreach ($bars as $b) {
            $sym = (string) $b->symbol;
            $low = (float) $b->low;
            $high = (float) $b->high;
            $ts = (string) $b->ts_est;

            if ($sym !== $currentSymbol) {
                $currentSymbol = $sym;
                $runningLow = null;
                $runningLowTs = null;
            }

            if ($runningLow === null || $low < $runningLow) {
                $runningLow = $low;
                $runningLowTs = $ts;
            }

            if (! isset($contexts[$sym]) && $runningLow > 0 && $high >= $runningLow * $targetMult) {
                $contexts[$sym] = [
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

    private function computeSpreadStrength(float $price, float $spread): float
    {
        if ($price <= 0) {
            return 0.0;
        }

        $frac = $spread / $price;

        return round($this->clamp01(($frac - 0.0005) / (0.0030 - 0.0005)), 6);
    }

    private function computeVwapDistScore(float $price, float $vwap): float
    {
        if ($vwap <= 0) {
            return 0.0;
        }

        $distPct = (($price - $vwap) / $vwap) * 100;

        return round(max(0.0, 1.0 - (abs($distPct - 0.15) / 0.30)), 6);
    }

    private function computeAtrScore(?float $atrPct): float
    {
        if ($atrPct === null) {
            return 0.0;
        }

        $atrLowOk = $this->clamp01(($atrPct - 0.08) / (0.20 - 0.08));
        $atrHighPen = $this->clamp01(($atrPct - 0.50) / (1.50 - 0.50));

        return round($atrLowOk * (1.0 - $atrHighPen), 6);
    }

    private function computeVolScore(float $volume, ?float $volRatio): float
    {
        if ($volRatio === null) {
            return 0.0;
        }

        return round($this->clamp01(($volRatio - 0.8) / (2.5 - 0.8)), 6);
    }

    private function computeCandleScore(float $price, float $open, float $high, float $low): float
    {
        if ($high <= $low) {
            return 0.0;
        }

        $pos = ($price - $low) / ($high - $low);

        return round($this->clamp01(($pos - 0.45) / (0.80 - 0.45)), 6);
    }

    private function computeTimeBonus(string $entryTsEst): float
    {
        $timeStr = strlen($entryTsEst) >= 19 ? substr($entryTsEst, 11, 8) : $entryTsEst;

        if ($timeStr <= '10:30:00') {
            return 1.0;
        }

        if ($timeStr <= '11:00:00') {
            return 0.5;
        }

        return 0.0;
    }

    private function clamp01(float $x): float
    {
        if ($x < 0.0) {
            return 0.0;
        }

        if ($x > 1.0) {
            return 1.0;
        }

        return $x;
    }
}
