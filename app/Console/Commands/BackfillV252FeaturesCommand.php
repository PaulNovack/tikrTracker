<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfills v25.2 ML feature columns for existing trade alerts.
 *
 * The feature columns (move_30m_pct, room_to_hod_pct, entry_body_pct, etc.) were added
 * by the 2026_05_23_213025_add_v25_2_ml_features_to_trade_alerts migration and are
 * NULL for alerts written before the feature computation code was active.
 *
 * Source of truth per feature group:
 *   - Scanner features  → extracted from the meta JSON column (always stored there)
 *   - Entry features    → re-computed from one_minute_prices at entry_ts_est
 *   - Score components  → re-computed in PHP, matching OneMinuteEntryFinderV25_2
 *   - Pattern features  → re-computed per entry_type from price data
 *
 * Usage:
 *   php artisan trading:backfill-v252-features
 *   php artisan trading:backfill-v252-features --pipeline=H
 *   php artisan trading:backfill-v252-features --start=2026-01-01 --end=2026-05-22
 *   php artisan trading:backfill-v252-features --table=trade_alerts_unfiltered
 *   php artisan trading:backfill-v252-features --dry-run --limit=10
 */
class BackfillV252FeaturesCommand extends Command
{
    /** EMA periods — must match OneMinuteEntryFinderV25_2 */
    private const EMA_FAST = 9;

    private const EMA_SLOW = 21;

    private const ATR_PERIOD = 14;

    private const VOL_LOOKBACK = 20;

    protected $signature = 'trading:backfill-v252-features
        {--table=trade_alerts              : Table to backfill (trade_alerts|trade_alerts_unfiltered)}
        {--prices=one_minute_prices        : Price table to use (one_minute_prices|one_minute_prices_full)}
        {--pipeline=                       : Pipeline filter (e.g., H, A, or ALL). Defaults to all pipelines.}
        {--chunk=100                       : Number of alerts per DB batch}
        {--limit=0                         : Max alerts to process — 0 means all}
        {--dry-run                         : Preview computed values without writing to the database}
        {--start=                          : Restrict to alerts on or after this date (YYYY-MM-DD)}
        {--end=                            : Restrict to alerts on or before this date (YYYY-MM-DD)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill v25.2 ML feature columns for existing trade alerts';

    public function handle(): int
    {
        $table = $this->option('table');
        $pricesTable = $this->option('prices');

        if (! in_array($table, ['trade_alerts', 'trade_alerts_unfiltered'], true)) {
            $this->error('--table must be trade_alerts or trade_alerts_unfiltered');

            return self::FAILURE;
        }

        if (! in_array($pricesTable, ['one_minute_prices', 'one_minute_prices_full'], true)) {
            $this->error('--prices must be one_minute_prices or one_minute_prices_full');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $limit = (int) $this->option('limit');
        $start = $this->option('start');
        $end = $this->option('end');
        $pipelineFilter = $this->option('pipeline');

        if ($dryRun) {
            $this->warn('[DRY RUN] No changes will be written to the database.');
        }

        $query = DB::table($table)
            ->whereNotNull('entry')
            ->whereNotNull('entry_ts_est')
            ->where(function ($q): void {
                $q->whereNull('move_30m_pct')
                    ->orWhereNull('room_to_hod_pct')
                    ->orWhereNull('entry_body_pct');
            })
            ->orderBy('entry_ts_est');

        if ($pipelineFilter && strtoupper($pipelineFilter) !== 'ALL') {
            // Support comma-separated pipelines like "H,I,D"
            $pipelines = array_map('trim', explode(',', strtoupper($pipelineFilter)));
            $query->whereIn('pipeline_run', $pipelines);

            if ($start) {
                $query->where('trading_date_est', '>=', $start);
            }

            if ($end) {
                $query->where('trading_date_est', '<=', $end);
            }

            $total = $query->count();

            if ($total === 0) {
                $this->info("No alerts need backfilling for pipeline(s): {$pipelineFilter}");

                return self::SUCCESS;
            }

            $cap = ($limit > 0) ? min($total, $limit) : $total;
            $this->info("Backfilling {$cap} of {$total} alerts in [{$table}] for pipeline(s) [{$pipelineFilter}] using [{$pricesTable}].");
        } else {
            $this->info("Backfilling ALL pipelines in [{$table}] using [{$pricesTable}].");
            $total = $query->count();

            if ($total === 0) {
                $this->info('No alerts need backfilling.');

                return self::SUCCESS;
            }

            $cap = ($limit > 0) ? min($total, $limit) : $total;
            $this->info("Found {$total} alerts across all pipelines.");
        }

        $progress = $this->output->createProgressBar($cap);
        $progress->start();

        $processed = 0;
        $updated = 0;
        $skipped = 0;

        $query->chunk($chunkSize, function ($alerts) use ($table, $pricesTable, $dryRun, $limit, $progress, &$processed, &$updated, &$skipped): bool {
            foreach ($alerts as $alert) {
                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }

                $features = $this->computeFeatures($alert, $pricesTable);

                if ($features === null) {
                    $skipped++;
                } else {
                    if (! $dryRun) {
                        DB::table($table)->where('id', $alert->id)->update($features);
                    }

                    $updated++;
                }

                $processed++;
                $progress->advance();
            }

            return true;
        });

        $progress->finish();
        $this->newLine(2);
        $this->info("Done. Updated: {$updated} | Skipped (no price data): {$skipped}");

        return self::SUCCESS;
    }

    private function computeFeatures(object $alert, string $pricesTable = 'one_minute_prices'): ?array
    {
        $tradeDate = substr((string) $alert->trading_date_est, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';
        $entryType = (string) ($alert->entry_type ?? '');
        $meta = json_decode((string) ($alert->meta ?? '{}'), true) ?? [];

        // Load 1-minute bars from market open through the entry bar
        $bars = DB::select("
            SELECT ts_est, `open`, high, low, price AS close, volume
            FROM {$pricesTable}
            WHERE asset_type = ? AND symbol = ? AND trading_date_est = ?
              AND ts_est >= ? AND ts_est <= ?
            ORDER BY ts_est ASC
        ", [$alert->asset_type, $alert->symbol, $tradeDate, $marketOpen, $alert->entry_ts_est]);

        if (count($bars) < 5) {
            return null;
        }

        // Rebuild the full indicator series (VWAP, EMA9/21, HOD, OR high)
        $norm = $this->buildNorm($bars);
        $entry = $norm[count($norm) - 1]; // last bar = entry bar
        $prev = count($norm) >= 2 ? $norm[count($norm) - 2] : null;

        $hod = (float) $entry['hod'];
        $atr = $this->computeAtr($norm, self::ATR_PERIOD);
        $volAvg = $this->computeVolAvg($norm, self::VOL_LOOKBACK);
        $volRatio = $volAvg > 0 ? $entry['volume'] / $volAvg : 0.0;

        // Entry quality metrics
        $aboveVwapPct = $entry['vwap'] > 0 ? (($entry['close'] - $entry['vwap']) / $entry['vwap']) * 100.0 : null;
        $bodyPct = $entry['open'] > 0 ? abs($entry['close'] - $entry['open']) / $entry['open'] * 100.0 : null;
        $closePosition = ($entry['high'] > $entry['low']) ? (($entry['close'] - $entry['low']) / ($entry['high'] - $entry['low'])) : null;
        $notional1m = $entry['close'] * $entry['volume'];
        $roomToHodPct = $entry['close'] > 0 ? (($hod - $entry['close']) / $entry['close']) * 100.0 : null;
        $roomToHodAtr = ($atr > 0 && $roomToHodPct !== null) ? (($hod - $entry['close']) / $atr) : null;

        // Score sub-components — matches the entry finder's computeEntryScoreComponents()
        // (atr_pct and avg_vol_20 are not on norm bars, matching live behavior)
        $sc = $this->computeScoreComponents($entry);

        $features = [
            // Scanner features extracted from the meta JSON column
            'move_30m_pct' => $this->fnull($meta['move_30m_pct'] ?? null),
            'rvol_5m' => $this->fnull($meta['rvol_5m'] ?? null),
            'atr_pct_5m' => $this->fnull($meta['atr_pct_5m'] ?? null),
            'notional_last5m' => $this->fnull($meta['notional_last5m'] ?? null),
            'pct_nd' => $this->fnull($meta['pct_nd'] ?? null),
            'spy_move_30m_pct' => $this->fnull($meta['spy_move_30m_pct'] ?? null),
            'universe_size' => isset($meta['universe_size']) ? (int) $meta['universe_size'] : null,

            // Room-to-run (re-computed from price data)
            'hod' => round($hod, 8),
            'room_to_hod_pct' => $roomToHodPct !== null ? round($roomToHodPct, 4) : null,
            'room_to_hod_atr' => $roomToHodAtr !== null ? round($roomToHodAtr, 4) : null,

            // VWAP entry distance
            'above_vwap_entry_pct' => $aboveVwapPct !== null ? round($aboveVwapPct, 4) : null,

            // Entry quality
            'entry_body_pct' => $bodyPct !== null ? round($bodyPct, 4) : null,
            'entry_close_position' => $closePosition !== null ? round($closePosition, 6) : null,
            'entry_volume_ratio' => round($volRatio, 4),
            'entry_notional_1m' => round($notional1m, 2),

            // Score sub-components
            'entry_spread_strength' => $sc['spread_strength'],
            'entry_vwap_dist_score' => $sc['vwap_dist_score'],
            'entry_atr_score' => $sc['atr_score'],
            'entry_vol_score' => $sc['vol_score'],
            'entry_candle_score' => $sc['candle_score'],
            'entry_time_bonus' => $sc['time_bonus'],

            // Pattern-specific (null unless entry_type matches below)
            'vwap_reclaim_strength_pct' => null,
            'vwap_reclaim_wick_below_pct' => null,
            'or_high_v252' => null,
            'or_break_distance_pct' => null,
            'or_retest_depth_pct' => null,
            'or_hold_close_pct' => null,
            'bars_since_or_break' => null,
            'ema9_pullback_depth_pct' => null,
            'ema9_reclaim_pct' => null,
        ];

        // VWAP reclaim specific
        if ($entryType === 'VWAP_RECLAIM_STRONG') {
            $features['vwap_reclaim_strength_pct'] = $entry['vwap'] > 0 ? round((($entry['close'] - $entry['vwap']) / $entry['vwap']) * 100.0, 4) : null;
            $features['vwap_reclaim_wick_below_pct'] = $entry['vwap'] > 0 ? round((($entry['vwap'] - $entry['low']) / $entry['vwap']) * 100.0, 4) : null;
        }

        // ORB retest specific
        if ($entryType === 'ORB_RETEST') {
            $orHigh = $entry['or_high'];

            if ($orHigh) {
                $entryIdx = count($norm) - 1;
                $breakIdx = null;

                for ($j = max(5, $entryIdx - 12); $j < $entryIdx; $j++) {
                    if ($norm[$j]['close'] > $orHigh * 1.001) {
                        $breakIdx = $j;
                        break;
                    }
                }

                $features['or_high_v252'] = round($orHigh, 8);
                $features['or_break_distance_pct'] = round((($entry['close'] - $orHigh) / $orHigh) * 100.0, 4);
                $features['or_retest_depth_pct'] = round((($entry['low'] - $orHigh) / $orHigh) * 100.0, 4);
                $features['or_hold_close_pct'] = round((($entry['close'] - $orHigh) / $orHigh) * 100.0, 4);
                $features['bars_since_or_break'] = $breakIdx !== null ? ($entryIdx - $breakIdx) : null;
            }
        }

        // EMA9 pullback specific
        if ($entryType === 'EMA9_PULLBACK' && $prev !== null) {
            $features['ema9_pullback_depth_pct'] = $prev['ema_f'] > 0 ? round((($prev['low'] - $prev['ema_f']) / $prev['ema_f']) * 100.0, 4) : null;
            $features['ema9_reclaim_pct'] = $entry['ema_f'] > 0 ? round((($entry['close'] - $entry['ema_f']) / $entry['ema_f']) * 100.0, 4) : null;
        }

        return $features;
    }

    /**
     * Rebuild the per-bar indicator series (VWAP, EMA9/21, HOD, OR high).
     * Matches OneMinuteEntryFinderV25_2::findEntry() exactly.
     */
    private function buildNorm(array $bars): array
    {
        $kF = 2.0 / (self::EMA_FAST + 1);
        $kS = 2.0 / (self::EMA_SLOW + 1);
        $cumPV = 0.0;
        $cumV = 0.0;
        $emaF = null;
        $emaS = null;
        $hod = 0.0;
        $orHigh = null;
        $orCount = 0;
        $norm = [];

        foreach ($bars as $r) {
            $o = (float) $r->open;
            $h = (float) $r->high;
            $l = (float) $r->low;
            $c = (float) $r->close;
            $v = (float) $r->volume;

            if ($h > $hod) {
                $hod = $h;
            }

            $typ = ($h + $l + $c) / 3.0;

            if ($v > 0) {
                $cumPV += $typ * $v;
                $cumV += $v;
            }

            $vwap = $cumV > 0 ? $cumPV / $cumV : $c;
            $emaF = $emaF === null ? $c : (($c * $kF) + ($emaF * (1 - $kF)));
            $emaS = $emaS === null ? $c : (($c * $kS) + ($emaS * (1 - $kS)));

            if ($orCount < 5) {
                $orCount++;
                $orHigh = $orHigh === null ? $h : max($orHigh, $h);
            }

            $norm[] = [
                'ts' => (string) $r->ts_est,
                'open' => $o,
                'high' => $h,
                'low' => $l,
                'close' => $c,
                'volume' => $v,
                'vwap' => $vwap,
                'ema_f' => $emaF,
                'ema_s' => $emaS,
                'hod' => $hod,
                'or_high' => $orHigh,
            ];
        }

        return $norm;
    }

    private function computeAtr(array $norm, int $period): float
    {
        if (count($norm) < $period + 2) {
            return 0.0;
        }

        $trs = [];

        for ($i = 1; $i < count($norm); $i++) {
            $prev = (float) $norm[$i - 1]['close'];
            $h = (float) $norm[$i]['high'];
            $l = (float) $norm[$i]['low'];
            $trs[] = max($h - $l, abs($h - $prev), abs($l - $prev));
        }

        $count = min($period, count($trs));
        $sum = 0.0;

        for ($i = count($trs) - $count; $i < count($trs); $i++) {
            $sum += $trs[$i];
        }

        return $count > 0 ? $sum / $count : 0.0;
    }

    /**
     * Volume average of the $lookback bars BEFORE the entry bar.
     */
    private function computeVolAvg(array $norm, int $lookback): float
    {
        $n = count($norm);
        $from = max(0, $n - 1 - $lookback);
        $to = $n - 2; // exclude the entry bar itself

        if ($from > $to) {
            return 0.0;
        }

        $slice = array_slice($norm, $from, $to - $from + 1);

        if (empty($slice)) {
            return 0.0;
        }

        return array_sum(array_column($slice, 'volume')) / count($slice);
    }

    /**
     * Replicates OneMinuteEntryFinderV25_2::computeEntryScoreComponents().
     *
     * NOTE: atr_pct and avg_vol_20 are not present on norm bars in the live entry
     * finder either (the bar object is an array element cast to stdClass), so this
     * intentionally replicates that behavior: atr_score = 0.0, vol_score = 1.0 for
     * any bar with meaningful volume. Changing this would create a training/scoring
     * mismatch between old and new alerts.
     */
    private function computeScoreComponents(array $bar): array
    {
        $price = (float) $bar['close'];

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

        $ema9 = (float) $bar['ema_f'];
        $ema21 = (float) $bar['ema_s'];

        $spreadFrac = ($ema9 - $ema21) / $price;
        $spread_strength = $this->clamp(($spreadFrac - 0.0005) / (0.0030 - 0.0005));

        $vwap = (float) $bar['vwap'];
        $vwap_dist_pct = $vwap > 0 ? (($price - $vwap) / $vwap) * 100 : 0;
        $vwap_dist_score = max(0.0, 1.0 - (abs($vwap_dist_pct - 0.15) / 0.30));

        // atr_pct not on norm bar — matches live entry finder (atr_score = 0.0)
        $atr_score = 0.0;

        // avg_vol_20 not on norm bar — raw volume / 1, so vol_score clamps to 1.0
        $vol_ratio = (float) $bar['volume'];
        $vol_score = $this->clamp(($vol_ratio - 0.8) / (2.5 - 0.8));

        $high = (float) $bar['high'];
        $low = (float) $bar['low'];
        $candle_score = 0.0;

        if ($high > $low) {
            $pos = ($price - $low) / ($high - $low);
            $candle_score = $this->clamp(($pos - 0.45) / (0.80 - 0.45));
        }

        $ema9_above_ema21 = $ema9 > $ema21 ? 1.0 : 0.0;
        $above_vwap = $price > $vwap ? 1.0 : 0.0;

        $ts = (string) ($bar['ts'] ?? '');
        $time_bonus = 0.0;

        if ($ts) {
            $timeStr = strlen($ts) >= 19 ? substr($ts, 11, 8) : $ts;

            if ($timeStr <= '10:30:00') {
                $time_bonus = 1.0;
            } elseif ($timeStr <= '11:00:00') {
                $time_bonus = 0.5;
            }
        }

        $S_trend = 0.70 * $ema9_above_ema21 + 0.30 * $spread_strength;
        $S_vwap = $above_vwap * $vwap_dist_score;

        $final = 100.0 * (
            0.30 * $S_trend +
            0.25 * $S_vwap +
            0.10 * $atr_score +
            0.20 * $vol_score +
            0.10 * $candle_score +
            0.05 * $time_bonus
        );

        return [
            'score' => round($final, 2),
            'spread_strength' => round($spread_strength, 6),
            'vwap_dist_score' => round($vwap_dist_score, 6),
            'atr_score' => round($atr_score, 6),
            'vol_score' => round($vol_score, 6),
            'candle_score' => round($candle_score, 6),
            'time_bonus' => round($time_bonus, 6),
        ];
    }

    private function clamp(float $x, float $lo = 0.0, float $hi = 1.0): float
    {
        return max($lo, min($hi, $x));
    }

    private function fnull(mixed $v): ?float
    {
        return ($v !== null && $v !== '') ? (float) $v : null;
    }
}
