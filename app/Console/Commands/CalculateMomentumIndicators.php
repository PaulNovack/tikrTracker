<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CalculateMomentumIndicators extends Command
{
    protected $signature = 'indicators:calculate-momentum
        {--date= : Trading date to process (YYYY-MM-DD, defaults to today EST)}
        {--days=1 : Number of days back to process (used when --date not set)}';

    protected $description = 'Calculate change_from_open and relative_volume for five_minute_prices';

    public function handle(): int
    {
        $startTime = microtime(true);

        $dateOpt = $this->option('date');
        $days = max(1, (int) $this->option('days'));

        if ($dateOpt) {
            $dates = [$dateOpt];
        } else {
            $cutoff = now('America/New_York')->subDays($days - 1)->format('Y-m-d');
            $dates = DB::table('five_minute_prices')
                ->select('trading_date_est')
                ->where('trading_date_est', '>=', $cutoff)
                ->groupBy('trading_date_est')
                ->orderBy('trading_date_est')
                ->pluck('trading_date_est')
                ->toArray();
        }

        if (empty($dates)) {
            $this->info('No dates to process.');

            return self::SUCCESS;
        }

        $this->info('Calculating momentum indicators for '.count($dates).' date(s): '.implode(', ', $dates));

        $totalSymbols = 0;

        foreach ($dates as $date) {
            $updated = $this->processDate($date);
            $totalSymbols += $updated;
            $this->line("  {$date}: Updated {$updated} symbols");
        }

        $elapsed = round(microtime(true) - $startTime, 2);
        $this->info("Completed! {$totalSymbols} symbol-dates updated in {$elapsed}s");

        Log::info('[MomentumIndicators] Calculation complete', [
            'dates' => $dates,
            'symbols_updated' => $totalSymbols,
            'duration_seconds' => $elapsed,
        ]);

        return self::SUCCESS;
    }

    /**
     * Calculate change_from_open and relative_volume for all symbols on a given date.
     */
    private function processDate(string $date): int
    {
        // Opening price = first bar's price between 09:30 and 09:35
        $openPrices = DB::table('five_minute_prices')
            ->select('symbol', DB::raw('MIN(price) as open_price'))
            ->where('asset_type', 'stock')
            ->where('trading_date_est', $date)
            ->where('ts_est', '>=', $date.' 09:30:00')
            ->where('ts_est', '<=', $date.' 09:35:00')
            ->groupBy('symbol')
            ->get()
            ->keyBy('symbol');

        // 20-day average volume from daily_prices for relative_volume denominator
        $avgVolumes = DB::table('daily_prices')
            ->select('symbol', DB::raw('AVG(volume) as avg_volume'))
            ->where('asset_type', 'stock')
            ->where('date', '>=', DB::raw("DATE_SUB('{$date}', INTERVAL 20 DAY)"))
            ->where('date', '<', $date)
            ->groupBy('symbol')
            ->get()
            ->keyBy('symbol');

        $updated = 0;

        foreach ($openPrices->keys() as $symbol) {
            $openPrice = $openPrices[$symbol]->open_price ?? null;
            $avgVolume = $avgVolumes[$symbol]->avg_volume ?? null;

            if (! $openPrice || ! $avgVolume || $avgVolume == 0) {
                continue;
            }

            DB::statement("
                UPDATE five_minute_prices
                SET
                    change_from_open = ROUND((price - ?) / ? * 100, 4),
                    relative_volume  = LEAST(
                        ROUND(
                            IF(
                                TIMESTAMPDIFF(MINUTE, CONCAT(trading_date_est, ' 09:30:00'), ts_est) > 0,
                                (volume / TIMESTAMPDIFF(MINUTE, CONCAT(trading_date_est, ' 09:30:00'), ts_est)) / (? / 390),
                                0
                            ),
                            4
                        ),
                        9999.9999
                    )
                WHERE symbol           = ?
                  AND asset_type       = 'stock'
                  AND trading_date_est = ?
                  AND ts_est          >= CONCAT(?, ' 09:30:00')
            ", [$openPrice, $openPrice, $avgVolume, $symbol, $date, $date]);

            $updated++;
        }

        return $updated;
    }
}
