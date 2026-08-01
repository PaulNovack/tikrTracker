<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScanLast4OneMinUp extends Command
{
    protected $signature = 'scan:last-4-1min-up';

    protected $description = 'Scan for stocks with 4 consecutive up 1-minute bars and store results';

    public function handle(): int
    {
        $now = now('America/New_York');

        // Retry the delete+insert on deadlocks (MySQL 1213 / 40001) or lock
        // timeouts (1205) — matches the retry convention used across the other
        // sync commands. The whole block re-runs atomically on retry.
        $maxRetries = 3;
        $attempt = 0;

        while (true) {
            try {
                // Use DELETE (DML) instead of TRUNCATE (DDL) so it rolls inside the transaction
                DB::transaction(function () use ($now) {
                    DB::table('last_4_1_min_up')->delete();

                    DB::insert("
                        INSERT INTO last_4_1_min_up (symbol, asset_type, streak_start_ts_est, streak_end_ts_est,
                                                      bar_1_price, bar_2_price, bar_3_price, bar_4_price,
                                                      total_pct_change, created_at, updated_at)
                        SELECT symbol,
                               asset_type,
                               bar_4_ts                                 AS streak_start_ts_est,
                               bar_1_ts                                 AS streak_end_ts_est,
                               bar_4_price                              AS bar_1_price,
                               bar_3_price                              AS bar_2_price,
                               bar_2_price                              AS bar_3_price,
                               bar_1_price                              AS bar_4_price,
                               ROUND(((bar_1_price - bar_4_price) / bar_4_price) * 100, 3) AS total_pct_change,
                               ?                                        AS created_at,
                               ?                                        AS updated_at
                        FROM (
                            SELECT symbol,
                                   asset_type,
                                   MAX(CASE WHEN rn = 1 THEN price END)  AS bar_1_price,
                                   MAX(CASE WHEN rn = 2 THEN price END)  AS bar_2_price,
                                   MAX(CASE WHEN rn = 3 THEN price END)  AS bar_3_price,
                                   MAX(CASE WHEN rn = 4 THEN price END)  AS bar_4_price,
                                   MAX(CASE WHEN rn = 1 THEN ts_est END) AS bar_1_ts,
                                   MAX(CASE WHEN rn = 4 THEN ts_est END) AS bar_4_ts
                            FROM (
                                SELECT symbol,
                                       asset_type,
                                       ts_est,
                                       price,
                                       ROW_NUMBER() OVER (PARTITION BY symbol ORDER BY ts_est DESC) AS rn
                                FROM one_minute_prices
                                WHERE asset_type = 'stock'
                                  AND ts_est >= DATE_SUB(?, INTERVAL 10 MINUTE)
                            ) AS ranked
                            WHERE rn <= 4
                            GROUP BY symbol, asset_type
                            HAVING MAX(CASE WHEN rn = 1 THEN price END) >
                                   MAX(CASE WHEN rn = 2 THEN price END)
                               AND MAX(CASE WHEN rn = 2 THEN price END) >
                                   MAX(CASE WHEN rn = 3 THEN price END)
                               AND MAX(CASE WHEN rn = 3 THEN price END) >
                                   MAX(CASE WHEN rn = 4 THEN price END)
                        ) AS streaks
                    ", [$now, $now, $now]);
                });

                break;
            } catch (\Illuminate\Database\QueryException $e) {
                $attempt++;

                if (str_contains($e->getMessage(), 'Deadlock')
                    || str_contains($e->getMessage(), '40001')
                    || str_contains($e->getMessage(), '1213')
                    || str_contains($e->getMessage(), '1205')) {
                    if ($attempt < $maxRetries) {
                        $waitMs = $attempt * 100; // 100ms, 200ms, 300ms
                        usleep($waitMs * 1000);
                        Log::channel('scheduled')->warning('[ScanLast4OneMinUp] Deadlock detected, retrying', [
                            'attempt' => $attempt,
                            'wait_ms' => $waitMs,
                        ]);

                        continue;
                    }
                }

                Log::channel('scheduled')->error('[ScanLast4OneMinUp] Database scan failed', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        $symbols = DB::table('last_4_1_min_up')->pluck('symbol')->toArray();

        \Illuminate\Support\Facades\Redis::set('last_4_1min_up:symbols', json_encode($symbols));

        $this->info(sprintf(
            'Scan complete: %d stock(s) with 4 consecutive up 1-min bars at %s EST',
            count($symbols),
            $now->toDateTimeString()
        ));

        return 0;
    }
}
