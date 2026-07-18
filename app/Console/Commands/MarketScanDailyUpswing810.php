<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MarketScanDailyUpswing810 extends Command
{
    protected $signature = 'market:scan-daily-upswing
        {asset_type=stock : stock|crypto}
        {--days=45 : Lookback in calendar days}
        {--limit=200 : Max symbols to output}
        {--low=8 : Low pct bound (e.g. 8)}
        {--high=10 : High pct bound (e.g. 10)}
        {--minBars=30 : Minimum 5m bars required in the day}
        {--minDayVol=500000 : Minimum total day volume (sum of 5m volumes)}
        {--minBarVol=50000 : Minimum single 5m bar volume (max of 5m volume)}
        {--marketOpen=09:30:00 : Market open time (local exchange time)}
        {--marketClose=16:00:00 : Market close time (local exchange time)}
        {--tz=America/New_York : Timezone for ts_est window}
        {--noUpdate : Do not update asset_info}
        {--dryRun : Do not update; print what would be updated}
        {--debug : Print per-day progress to STDERR}';

    protected $description = 'Find symbols with an 8–10% intraday upswing (low->high) in last N days with volume filters using five_minute_prices; optionally set asset_info.1_min=1.';

    public function handle(): int
    {
        $assetType = (string) $this->argument('asset_type');
        $days = (int) $this->option('days');
        $limit = (int) $this->option('limit');
        $lowPct = (float) $this->option('low');
        $highPct = (float) $this->option('high');
        $minBars = (int) $this->option('minBars');
        $minDayVol = (int) $this->option('minDayVol');
        $minBarVol = (int) $this->option('minBarVol');
        $marketOpen = (string) $this->option('marketOpen');
        $marketClose = (string) $this->option('marketClose');
        $tz = (string) $this->option('tz');
        $debug = (bool) $this->option('debug');
        $noUpdate = (bool) $this->option('noUpdate');
        $dryRun = (bool) $this->option('dryRun');

        if ($lowPct <= 0 || $highPct <= 0 || $highPct < $lowPct) {
            $this->error('Invalid --low/--high. Example: --low=8 --high=10');

            return self::FAILURE;
        }

        $lowFrac = $lowPct / 100.0;
        $highFrac = $highPct / 100.0;

        $today = CarbonImmutable::now($tz)->startOfDay();
        $start = $today->subDays($days)->startOfDay();

        // symbol => ['hits'=>int, 'bestPct'=>float, 'lastDate'=>'Y-m-d']
        $stats = [];

        $sql = '
            SELECT
                symbol,
                COUNT(*)      AS bars,
                SUM(volume)   AS day_volume,
                MAX(volume)   AS max_bar_volume,
                MIN(low)      AS day_low,
                MAX(high)     AS day_high,
                (MAX(high) - MIN(low)) / MIN(low) AS pct_move
            FROM five_minute_prices
            WHERE asset_type = ?
              AND ts_est >= ?
              AND ts_est <= ?
            GROUP BY symbol
            HAVING bars >= ?
               AND day_volume >= ?
               AND max_bar_volume >= ?
               AND pct_move BETWEEN ? AND ?
            ORDER BY pct_move DESC
        ';

        $daysScanned = 0;
        $daysQueried = 0;

        for ($d = $start; $d <= $today; $d = $d->addDay()) {
            $daysScanned++;

            // Skip weekends quickly
            $dow = (int) $d->format('N'); // 6=Sat, 7=Sun
            if ($dow >= 6) {
                continue;
            }

            $dateStr = $d->format('Y-m-d');
            $dayStart = "{$dateStr} {$marketOpen}";
            $dayEnd = "{$dateStr} {$marketClose}";

            if ($debug) {
                fwrite(STDERR, "Scanning {$dateStr}...\n");
            }
            $t0 = microtime(true);

            $rows = DB::select($sql, [
                $assetType,
                $dayStart,
                $dayEnd,
                $minBars,
                $minDayVol,
                $minBarVol,
                $lowFrac,
                $highFrac,
            ]);

            $daysQueried++;

            foreach ($rows as $r) {
                $sym = (string) $r->symbol;
                $pct = ((float) $r->pct_move) * 100.0;

                if (! isset($stats[$sym])) {
                    $stats[$sym] = [
                        'hits' => 0,
                        'bestPct' => $pct,
                        'lastDate' => $dateStr,
                    ];
                }

                $stats[$sym]['hits']++;
                if ($pct > $stats[$sym]['bestPct']) {
                    $stats[$sym]['bestPct'] = $pct;
                }
                if ($dateStr > $stats[$sym]['lastDate']) {
                    $stats[$sym]['lastDate'] = $dateStr;
                }
            }

            if ($debug) {
                $ms = (microtime(true) - $t0) * 1000.0;
                fwrite(STDERR, '  matched='.count($rows).' in '.round($ms, 1)."ms\n");
            }
        }

        // Sort final symbols
        $symbols = array_keys($stats);
        usort($symbols, function ($a, $b) use ($stats) {
            $A = $stats[$a];
            $B = $stats[$b];
            if ($A['hits'] !== $B['hits']) {
                return $B['hits'] <=> $A['hits'];
            }
            if (abs($A['bestPct'] - $B['bestPct']) > 1e-9) {
                return $B['bestPct'] <=> $A['bestPct'];
            }

            return strcmp($B['lastDate'], $A['lastDate']);
        });

        $symbols = array_slice($symbols, 0, max(0, $limit));

        // Optional: Update asset_info.`1_min` = 1 for these symbols
        $updated = 0;
        if (! $noUpdate) {
            if ($dryRun) {
                fwrite(STDERR, 'DRY RUN: would update asset_info.`1_min`=1 for '.count($symbols)." symbols (asset_type={$assetType}).\n");
            } else {
                // Chunk to avoid giant IN() lists
                foreach (array_chunk($symbols, 800) as $chunk) {
                    // If your table is laravelInvest.asset_info specifically, use the fully qualified name
                    $updated += DB::table('asset_info')
                        ->where('asset_type', $assetType)
                        ->whereIn('symbol', $chunk)
                        ->update(['1_min' => 1]);
                }
                fwrite(STDERR, "Updated asset_info.`1_min`=1 rows={$updated} (asset_type={$assetType}).\n");
            }
        }

        // CSV output
        $this->line('symbol,hits,best_day_pct,last_hit_date');
        foreach ($symbols as $sym) {
            $s = $stats[$sym];
            $this->line(sprintf('%s,%d,%.2f,%s', $sym, $s['hits'], $s['bestPct'], $s['lastDate']));
        }

        fwrite(
            STDERR,
            "Done. calendar_days_scanned={$daysScanned}, trading_days_queried={$daysQueried}, unique_symbols_matched=".count($stats).', output='.count($symbols)."\n"
        );

        return self::SUCCESS;
    }
}
