<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateEligibleSymbols extends Command
{
    protected $signature = 'eligible:generate
        {--date= : Trading date (EST) YYYY-mm-dd, defaults to today}
        {--backfill-days= : Backfill N days of historical data}
        {--asset=stock : stock|crypto}
        {--require-intraday=1 : 1=only symbols that exist in five_minute_prices for window, 0=allow daily-only symbols}';

    protected $description = 'Generate eligible symbols for trading based on recent performance (no forward data).';

    public function handle(): int
    {
        if ($backfillDays = $this->option('backfill-days')) {
            return $this->backfill((int) $backfillDays);
        }

        $date = $this->option('date') ?: date('Y-m-d');

        return $this->generateForDate($date);
    }

    protected function backfill(int $days): int
    {
        $assetType = (string) ($this->option('asset') ?: 'stock');

        $this->info("Backfilling {$days} days of eligible symbols data... (asset={$assetType})");

        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        $endDate = date('Y-m-d');

        $this->info("Date range: {$startDate} to {$endDate}");

        $currentDate = $startDate;
        $processed = 0;

        while ($currentDate <= $endDate) {
            $dow = (int) date('N', strtotime($currentDate));
            if ($dow < 6) {
                $this->generateForDate($currentDate);
                $processed++;
            }
            $currentDate = date('Y-m-d', strtotime($currentDate.' +1 day'));
        }

        $this->info("✅ Backfill complete: processed {$processed} trading days");

        return 0;
    }

    protected function generateForDate(string $date): int
    {
        $assetType = (string) ($this->option('asset') ?: 'stock');
        $requireIntraday = (int) ($this->option('require-intraday') ?? 1) === 1;

        // windows (NO forward data)
        $dailyStart = date('Y-m-d', strtotime($date.' -12 days'));
        $intradayStart = date('Y-m-d', strtotime($date.' -5 days'));
        $intradayEnd = $date;

        $this->info("Generating eligible symbols for {$date} (no forward data)...");
        $this->info("Asset: {$assetType}");
        $this->info("Daily window:   {$dailyStart} → {$date}");
        $this->info("Intraday window: {$intradayStart} → {$intradayEnd}");
        $this->info('Require intraday presence: '.($requireIntraday ? 'YES' : 'NO'));

        // Idempotent delete
        $deleted = DB::delete('DELETE FROM eligible_symbols_daily WHERE trading_date_est = ? AND asset_type = ?', [$date, $assetType]);
        if ($deleted > 0) {
            $this->info("Deleted {$deleted} existing records for {$date}");
        }

        // ✅ KEY CHANGE:
        // Universe comes from five_minute_prices (symbols you actually have intraday for the window),
        // then daily stats are computed only for that universe.
        $sql = '
INSERT INTO eligible_symbols_daily (
    trading_date_est,
    symbol,
    asset_type,
    avg_range_3d,
    avg_range_10d,
    green_ratio_7d,
    intraday_big_days_5d
)
WITH universe AS (
    SELECT DISTINCT f.symbol, f.asset_type
    FROM five_minute_prices f
    WHERE f.asset_type = ?
      AND f.trading_date_est BETWEEN ? AND ?
),
daily AS (
    SELECT
        d.symbol,
        d.asset_type,
        d.date,
        (d.high - d.low) / NULLIF(d.price,0) AS range_pct,
        (d.price > d.open) AS green
    FROM daily_prices d
    '.($requireIntraday ? 'JOIN universe u ON u.symbol = d.symbol AND u.asset_type = d.asset_type' : '').'
    WHERE d.asset_type = ?
      AND d.date >= ?
      AND d.date <= ?
),
daily_agg AS (
    SELECT
        symbol,
        asset_type,
        AVG(CASE WHEN date >= DATE_SUB(?, INTERVAL 3 DAY) THEN range_pct END) AS avg_range_3d,
        AVG(range_pct) AS avg_range_10d,
        AVG(CASE WHEN date >= DATE_SUB(?, INTERVAL 7 DAY) THEN green END) AS green_ratio_7d
    FROM daily
    GROUP BY symbol, asset_type
),
intraday AS (
    SELECT
        f.symbol,
        f.asset_type,
        f.trading_date_est,
        (MAX(f.high) - MIN(f.low)) / NULLIF(MIN(f.low),0) AS intraday_range
    FROM five_minute_prices f
    WHERE f.asset_type = ?
      AND f.trading_date_est BETWEEN ? AND ?
    GROUP BY f.symbol, f.asset_type, f.trading_date_est
),
intraday_agg AS (
    SELECT
        symbol,
        asset_type,
        SUM(intraday_range >= 0.012) AS intraday_big_days_5d
    FROM intraday
    GROUP BY symbol, asset_type
)
SELECT
    ? AS trading_date_est,
    d.symbol,
    d.asset_type,
    d.avg_range_3d,
    d.avg_range_10d,
    d.green_ratio_7d,
    COALESCE(i.intraday_big_days_5d, 0) AS intraday_big_days_5d
FROM daily_agg d
LEFT JOIN intraday_agg i
  ON i.symbol = d.symbol AND i.asset_type = d.asset_type
WHERE
    d.avg_range_3d >= d.avg_range_10d * 1.20
    AND d.avg_range_3d >= 0.030
    AND d.green_ratio_7d >= 0.57
    AND COALESCE(i.intraday_big_days_5d, 0) >= 2
';

        DB::insert($sql, [
            // universe
            $assetType, $intradayStart, $intradayEnd,

            // daily (and join universe optionally)
            $assetType, $dailyStart, $date,

            // daily_agg (no forward)
            $date, $date,

            // intraday
            $assetType, $intradayStart, $intradayEnd,

            // select date
            $date,
        ]);

        $count = DB::selectOne('
            SELECT COUNT(*) AS total
            FROM eligible_symbols_daily
            WHERE trading_date_est = ? AND asset_type = ?
        ', [$date, $assetType]);

        $total = (int) ($count->total ?? 0);

        $this->info("✅ Generated {$total} eligible symbols for {$date}");

        if ($total > 0) {
            $this->newLine();
            $this->info('Sample eligible symbols:');
            $samples = DB::select('
                SELECT symbol, avg_range_3d, green_ratio_7d, intraday_big_days_5d
                FROM eligible_symbols_daily
                WHERE trading_date_est = ? AND asset_type = ?
                ORDER BY avg_range_3d DESC
                LIMIT 10
            ', [$date, $assetType]);

            $this->table(
                ['Symbol', 'Avg Range 3D', 'Green Ratio 7D', 'Big Days 5D'],
                array_map(fn ($s) => [
                    $s->symbol,
                    number_format(((float) $s->avg_range_3d) * 100, 2).'%',
                    number_format(((float) $s->green_ratio_7d) * 100, 1).'%',
                    (int) $s->intraday_big_days_5d,
                ], $samples)
            );
        }

        return 0;
    }
}
