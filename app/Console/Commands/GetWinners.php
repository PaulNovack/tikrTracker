<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GetWinners extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'winners:analyze 
                            {--date= : Specific date to analyze (YYYY-MM-DD)}
                            {--days=1 : Number of days to analyze}
                            {--min-range=4 : Minimum 5-min bar gain percentage}
                            {--min-volume=1000 : Minimum bar volume}
                            {--force : Replace existing records for the date}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze and store winning trades that pipelines missed';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $minRange = (float) $this->option('min-range');
        $minVolume = (int) $this->option('min-volume');
        $force = $this->option('force');

        if ($date = $this->option('date')) {
            $dates = [$date];
        } else {
            $days = (int) $this->option('days');
            $dates = $this->getLastTradingDays($days);
        }

        $this->info("Analyzing winners with {$minRange}%+ 5-min bar gain and {$minVolume}+ volume");
        $this->newLine();

        $totalInserted = 0;
        $totalSkipped = 0;

        foreach ($dates as $date) {
            $this->line("Processing {$date}...");

            if ($force) {
                $deleted = DB::table('winner_analysis')->where('trading_date_est', $date)->delete();
                if ($deleted > 0) {
                    $this->line("  Deleted {$deleted} existing records");
                }
            }

            $inserted = $this->analyzeDate($date, $minRange, $minVolume);

            if ($inserted === 0) {
                $existing = DB::table('winner_analysis')->where('trading_date_est', $date)->count();
                if ($existing > 0) {
                    $this->line("  Skipped (already analyzed, {$existing} winners)");
                    $totalSkipped += $existing;
                } else {
                    $this->line('  No winners found');
                }
            } else {
                $this->line("  Inserted {$inserted} winners");
                $totalInserted += $inserted;
            }
        }

        $this->newLine();
        $this->info('✓ Complete!');
        $this->line("  Total inserted: {$totalInserted}");
        if ($totalSkipped > 0) {
            $this->line("  Total skipped: {$totalSkipped}");
        }

        return Command::SUCCESS;
    }

    private function getLastTradingDays(int $days): array
    {
        $dates = DB::table('five_minute_prices')
            ->select('trading_date_est')
            ->where('asset_type', 'stock')
            ->distinct()
            ->orderBy('trading_date_est', 'desc')
            ->limit($days)
            ->pluck('trading_date_est')
            ->toArray();

        return $dates;
    }

    private function analyzeDate(string $date, float $minRange, int $minVolume): int
    {
        // Check if already analyzed
        $existing = DB::table('winner_analysis')->where('trading_date_est', $date)->count();
        if ($existing > 0) {
            return 0;
        }

        // Insert top 5-minute bar gainers for the day
        $inserted = DB::insert("
            INSERT INTO winner_analysis (
                symbol, asset_type, trading_date_est,
                open_price, low_price, high_price, close_price,
                range_pct, gain_from_open_pct,
                time_of_low, time_of_high,
                total_volume,
                rsi_14, ema9, ema21, atr, atr_pct, vwap,
                optimal_entry, risk_pct,
                created_at, updated_at
            )
            SELECT 
                symbol,
                asset_type,
                ? as trading_date_est,
                open as open_price,
                low as low_price,
                high as high_price,
                price as close_price,
                ROUND(((high - low) / low * 100), 3) as range_pct,
                ROUND(((price - open) / open * 100), 3) as gain_from_open_pct,
                TIME(ts_est) as time_of_low,
                TIME(ts_est) as time_of_high,
                volume as total_volume,
                rsi_14,
                ema9,
                ema21,
                atr,
                atr_pct,
                vwap,
                low * 1.02 as optimal_entry,
                2.0 as risk_pct,
                NOW(), NOW()
            FROM five_minute_prices
            WHERE trading_date_est = ?
            AND asset_type = 'stock'
            AND open > 0
            AND ((price - open) / open * 100) >= ?
            AND volume >= ?
            ORDER BY ((price - open) / open * 100) DESC
            LIMIT 100
        ", [$date, $date, $minRange, $minVolume]);

        return $inserted;
    }
}
