<?php

namespace App\Console\Commands;

use App\Models\IntradayUniverse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class GenerateQualityUniverse extends Command
{
    protected $signature = 'universe:generate-quality
        {--date= : Trading date (EST) YYYY-MM-DD, defaults to today EST}
        {--backfill-days= : Backfill N days of historical data}
        {--table=one_minute_prices_full : Table to query (one_minute_prices_full for backfill, one_minute_prices for live)}
        {--limit=500 : Max symbols in universe}';

    protected $description = 'Generate a 60-day quality-scored trading universe and cache it in Redis.';

    private const REDIS_SORTED_SET = 'universe:quality:daily';

    private const REDIS_DETAIL_HASH = 'universe:quality:daily:detail';

    private const TTL_SECONDS = 93600; // 26 hours

    public function handle(): int
    {
        if ($backfillDays = $this->option('backfill-days')) {
            return $this->backfill((int) $backfillDays);
        }

        $date = $this->option('date') ?: $this->estToday();

        return $this->generateForDate($date);
    }

    private function backfill(int $days): int
    {
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        $endDate = $this->estToday();

        $this->info("Backfilling {$days} days of quality universe data...");
        $this->info("Date range: {$startDate} to {$endDate}");

        $current = $startDate;
        $processed = 0;
        $tradingDays = 0;

        while ($current <= $endDate) {
            $dow = (int) date('N', strtotime($current));
            if ($dow < 6) {
                $result = $this->generateForDate($current);
                if ($result === self::SUCCESS) {
                    $tradingDays++;
                }
                $processed++;
            }
            $current = date('Y-m-d', strtotime($current.' +1 day'));
        }

        $this->info("✅ Backfill complete: {$tradingDays}/{$processed} dates populated");

        return self::SUCCESS;
    }

    private function generateForDate(string $date): int
    {
        $limit = (int) $this->option('limit');
        $table = (string) ($this->option('table') ?: 'one_minute_prices_full');
        $startDate = date('Y-m-d', strtotime($date.' -60 days'));

        $this->info("Generating quality universe for {$date} (60-day window: {$startDate} → {$date}, table: {$table})...");

        $sql = "
            WITH params AS (
                SELECT
                    ? AS today_est,
                    ? AS start_date_est
            ),

            eligible_1m AS (
                SELECT
                    p.symbol,
                    p.asset_type,
                    p.trading_date_est,
                    p.trading_time_est,
                    p.price,
                    p.open,
                    p.high,
                    p.low,
                    p.volume,
                    p.atr_pct,
                    p.vwap,
                    p.above_vwap,
                    p.ema9_above_ema21,

                    p.price * p.volume AS dollar_volume_1m,

                    CASE
                        WHEN p.open > 0 AND p.high IS NOT NULL AND p.low IS NOT NULL
                        THEN ((p.high - p.low) / p.open) * 100
                        ELSE NULL
                    END AS range_1m_pct

                FROM {$table} p
                JOIN params prm
                  ON p.trading_date_est >= prm.start_date_est
                 AND p.trading_date_est < prm.today_est

                WHERE p.asset_type = 'stock'
                  AND p.trading_time_est BETWEEN '09:30:00' AND '16:00:00'
                  AND p.price BETWEEN 2 AND 150
                  AND p.volume IS NOT NULL
                  AND p.volume > 0
            ),

            daily_symbol_stats AS (
                SELECT
                    symbol,
                    asset_type,
                    trading_date_est,

                    COUNT(*) AS bars_in_day,

                    AVG(price) AS avg_price_day,
                    SUM(dollar_volume_1m) AS total_dollar_volume_day,
                    AVG(dollar_volume_1m) AS avg_dollar_volume_1m_day,
                    MAX(dollar_volume_1m) AS max_dollar_volume_1m_day,

                    AVG(volume) AS avg_volume_1m_day,
                    AVG(NULLIF(atr_pct, 0)) AS avg_atr_pct_day,

                    AVG(range_1m_pct) AS avg_range_1m_pct_day,
                    MAX(range_1m_pct) AS max_range_1m_pct_day,

                    SUM(CASE WHEN above_vwap = 1 THEN 1 ELSE 0 END) / COUNT(*) AS above_vwap_ratio_day,
                    SUM(CASE WHEN ema9_above_ema21 = 1 THEN 1 ELSE 0 END) / COUNT(*) AS ema_bull_ratio_day,

                    SUM(CASE WHEN dollar_volume_1m >= 25000 THEN 1 ELSE 0 END) AS liquid_minutes_25k,
                    SUM(CASE WHEN dollar_volume_1m >= 50000 THEN 1 ELSE 0 END) AS liquid_minutes_50k,
                    SUM(CASE WHEN dollar_volume_1m >= 100000 THEN 1 ELSE 0 END) AS liquid_minutes_100k

                FROM eligible_1m
                GROUP BY symbol, asset_type, trading_date_est
            ),

            symbol_stats AS (
                SELECT
                    symbol,
                    asset_type,

                    COUNT(*) AS days_seen,
                    SUM(bars_in_day) AS total_1m_bars,
                    AVG(bars_in_day) AS avg_bars_per_day,

                    AVG(avg_price_day) AS avg_price,

                    AVG(total_dollar_volume_day) AS avg_daily_dollar_volume,
                    AVG(avg_dollar_volume_1m_day) AS avg_dollar_volume_1m,
                    MAX(max_dollar_volume_1m_day) AS max_dollar_volume_1m,

                    AVG(avg_volume_1m_day) AS avg_volume_1m,

                    AVG(avg_atr_pct_day) AS avg_atr_pct,
                    AVG(avg_range_1m_pct_day) AS avg_range_1m_pct,
                    MAX(max_range_1m_pct_day) AS max_range_1m_pct,

                    AVG(above_vwap_ratio_day) AS avg_above_vwap_ratio,
                    AVG(ema_bull_ratio_day) AS avg_ema_bull_ratio,

                    AVG(liquid_minutes_25k) AS avg_liquid_minutes_25k_per_day,
                    AVG(liquid_minutes_50k) AS avg_liquid_minutes_50k_per_day,
                    AVG(liquid_minutes_100k) AS avg_liquid_minutes_100k_per_day,

                    SUM(CASE WHEN avg_dollar_volume_1m_day >= 25000 THEN 1 ELSE 0 END) AS days_avg_1m_dollar_vol_over_25k,
                    SUM(CASE WHEN avg_dollar_volume_1m_day >= 50000 THEN 1 ELSE 0 END) AS days_avg_1m_dollar_vol_over_50k

                FROM daily_symbol_stats
                GROUP BY symbol, asset_type
            ),

            ranked AS (
                SELECT
                    s.*,

                    (
                        35 * LEAST(s.avg_dollar_volume_1m / 100000, 1)
                        +
                        25 * LEAST(COALESCE(s.avg_atr_pct, 0) / 0.60, 1)
                        +
                        20 * LEAST(s.days_seen / 30, 1)
                        +
                        10 * LEAST(s.avg_bars_per_day / 300, 1)
                        +
                        10 * LEAST(COALESCE(s.avg_range_1m_pct, 0) / 0.35, 1)
                    ) AS universe_score

                FROM symbol_stats s
            )

            SELECT
                symbol,
                asset_type,

                ROUND(universe_score, 2) AS universe_score,

                days_seen,
                total_1m_bars,
                ROUND(avg_bars_per_day, 1) AS avg_bars_per_day,

                ROUND(avg_price, 2) AS avg_price,

                ROUND(avg_daily_dollar_volume, 0) AS avg_daily_dollar_volume,
                ROUND(avg_dollar_volume_1m, 0) AS avg_dollar_volume_1m,
                ROUND(max_dollar_volume_1m, 0) AS max_dollar_volume_1m,

                ROUND(avg_volume_1m, 0) AS avg_volume_1m,

                ROUND(avg_atr_pct, 4) AS avg_atr_pct,
                ROUND(avg_range_1m_pct, 4) AS avg_range_1m_pct,
                ROUND(max_range_1m_pct, 4) AS max_range_1m_pct,

                ROUND(avg_liquid_minutes_25k_per_day, 1) AS avg_liquid_minutes_25k_per_day,
                ROUND(avg_liquid_minutes_50k_per_day, 1) AS avg_liquid_minutes_50k_per_day,
                ROUND(avg_liquid_minutes_100k_per_day, 1) AS avg_liquid_minutes_100k_per_day,

                days_avg_1m_dollar_vol_over_25k,
                days_avg_1m_dollar_vol_over_50k,

                ROUND(avg_above_vwap_ratio, 3) AS avg_above_vwap_ratio,
                ROUND(avg_ema_bull_ratio, 3) AS avg_ema_bull_ratio

            FROM ranked

            WHERE days_seen >= 10
              AND total_1m_bars >= 1500
              AND avg_bars_per_day >= 150
              AND avg_dollar_volume_1m >= 25000
              AND avg_daily_dollar_volume >= 5000000
              AND avg_price BETWEEN 2 AND 150
              AND COALESCE(avg_atr_pct, 0) >= 0.10
              AND avg_liquid_minutes_25k_per_day >= 30

            ORDER BY universe_score DESC, avg_dollar_volume_1m DESC

            LIMIT ?
        ";

        $startMs = (int) (microtime(true) * 1000);
        $rows = DB::select($sql, [$date, $startDate, $limit]);
        $elapsedMs = (int) (microtime(true) * 1000) - $startMs;

        $count = count($rows);

        if ($count === 0) {
            $this->warn("No symbols passed quality gates for {$date}.");

            return self::FAILURE;
        }

        // Write to MySQL (truncate + insert) for server-side JOIN use
        try {
            IntradayUniverse::query()->truncate();

            $insertRows = array_map(fn ($row) => [
                'symbol' => (string) $row->symbol,
                'asset_type' => (string) $row->asset_type,
                'universe_score' => (float) $row->universe_score,
                'days_seen' => (int) $row->days_seen,
                'total_1m_bars' => (float) $row->total_1m_bars,
                'avg_bars_per_day' => (float) $row->avg_bars_per_day,
                'avg_price' => (float) $row->avg_price,
                'avg_daily_dollar_volume' => (float) $row->avg_daily_dollar_volume,
                'avg_dollar_volume_1m' => (float) $row->avg_dollar_volume_1m,
                'max_dollar_volume_1m' => (float) $row->max_dollar_volume_1m,
                'avg_volume_1m' => (float) $row->avg_volume_1m,
                'avg_atr_pct' => (float) $row->avg_atr_pct,
                'avg_range_1m_pct' => (float) $row->avg_range_1m_pct,
                'max_range_1m_pct' => (float) $row->max_range_1m_pct,
                'avg_liquid_minutes_25k_per_day' => (float) $row->avg_liquid_minutes_25k_per_day,
                'avg_liquid_minutes_50k_per_day' => (float) $row->avg_liquid_minutes_50k_per_day,
                'avg_liquid_minutes_100k_per_day' => (float) $row->avg_liquid_minutes_100k_per_day,
                'days_avg_1m_dollar_vol_over_25k' => (float) $row->days_avg_1m_dollar_vol_over_25k,
                'days_avg_1m_dollar_vol_over_50k' => (float) $row->days_avg_1m_dollar_vol_over_50k,
                'avg_above_vwap_ratio' => (float) $row->avg_above_vwap_ratio,
                'avg_ema_bull_ratio' => (float) $row->avg_ema_bull_ratio,
            ], $rows);

            IntradayUniverse::query()->insert($insertRows);

            $this->info("   MySQL: intraday_universe ({$count} rows)");
        } catch (\Throwable $e) {
            $this->error('MySQL write failed: '.$e->getMessage());

            return self::FAILURE;
        }

        // Write to Redis sorted set (score = universe_score, member = symbol)
        try {
            $redis = Redis::connection();

            $zaddArgs = [self::REDIS_SORTED_SET];
            foreach ($rows as $row) {
                $zaddArgs[] = (float) $row->universe_score;
                $zaddArgs[] = (string) $row->symbol;
            }
            $redis->del(self::REDIS_SORTED_SET);
            $redis->zadd(...$zaddArgs);
            $redis->expire(self::REDIS_SORTED_SET, self::TTL_SECONDS);

            // Write detail hash for per-symbol metadata lookups
            $redis->del(self::REDIS_DETAIL_HASH);
            foreach ($rows as $row) {
                $redis->hset(self::REDIS_DETAIL_HASH, (string) $row->symbol, json_encode($row));
            }
            $redis->expire(self::REDIS_DETAIL_HASH, self::TTL_SECONDS);

            $this->info("✅ Generated {$count} quality symbols for {$date} (query: {$elapsedMs}ms)");
            $this->info('   Redis: '.self::REDIS_SORTED_SET.' (sorted set, TTL: '.self::TTL_SECONDS.'s)');
            $this->info('   Redis: '.self::REDIS_DETAIL_HASH.' (hash, TTL: '.self::TTL_SECONDS.'s)');

            // Show top 10 sample
            $this->newLine();
            $this->info('Top 10 quality symbols:');
            $this->table(
                ['Symbol', 'Score', 'Avg $Vol/1m', 'ATR%', 'Days Seen'],
                array_map(fn ($r) => [
                    $r->symbol,
                    number_format((float) $r->universe_score, 2),
                    '$'.number_format((float) $r->avg_dollar_volume_1m),
                    number_format((float) $r->avg_atr_pct, 3).'%',
                    $r->days_seen,
                ], array_slice($rows, 0, 10)),
            );
        } catch (\Throwable $e) {
            $this->error('Redis write failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function estToday(): string
    {
        return (new \DateTime('now', new \DateTimeZone('America/New_York')))->format('Y-m-d');
    }
}
