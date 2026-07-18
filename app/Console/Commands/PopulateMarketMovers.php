<?php

namespace App\Console\Commands;

use App\Models\MarketMover;
use App\Models\Setting;
use App\Services\MarketMoversService;
use Illuminate\Console\Command;

class PopulateMarketMovers extends Command
{
    protected $signature = 'market-movers:populate {--date= : Specific date to populate (Y-m-d)} {--days=30 : Number of days to populate (default 30)} {--force : Force overwrite existing records} {--full-tables : Use five_minute_prices_full for calculation}';

    protected $description = 'Populate the market_movers table with calculated market strength and movers data';

    public function handle(MarketMoversService $service): int
    {
        $specificDate = $this->option('date');
        $days = (int) $this->option('days');
        $force = $this->option('force');
        $useFullTables = (bool) $this->option('full-tables');

        if ($specificDate) {
            return $this->populateSpecificDate($service, $specificDate, $force, $useFullTables);
        }

        return $this->populateDateRange($service, $days, $force, $useFullTables);
    }

    private function populateSpecificDate(MarketMoversService $service, string $date, bool $force, bool $useFullTables = false): int
    {
        $this->info("Calculating market movers for {$date}...");

        $data = $service->calculateForDate($date, $useFullTables);

        if (empty($data)) {
            $this->warn("No data found for {$date}");

            return self::FAILURE;
        }

        $this->saveMarketMoverData($data, $force);

        $this->info("✓ Populated market movers for {$date}");

        // Update settings to track date range
        $this->updateDateRangeTracking();

        return self::SUCCESS;
    }

    private function populateDateRange(MarketMoversService $service, int $days, bool $force, bool $useFullTables = false): int
    {
        $endDate = now('America/New_York')->format('Y-m-d');
        $startDate = now('America/New_York')->subDays($days)->format('Y-m-d');

        $this->info("Calculating market movers from {$startDate} to {$endDate}...");

        $dataArray = $service->calculateForDateRange($startDate, $endDate, $useFullTables);

        if (empty($dataArray)) {
            $this->warn('No data found for the specified date range (likely non-trading day); skipping.');

            return self::SUCCESS;
        }

        $progressBar = $this->output->createProgressBar(count($dataArray));
        $progressBar->start();

        foreach ($dataArray as $data) {
            $this->saveMarketMoverData($data, $force);
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $count = count($dataArray);
        $this->info("✓ Populated {$count} days of market movers data");

        // Update settings to track date range
        $this->updateDateRangeTracking();

        return self::SUCCESS;
    }

    private function updateDateRangeTracking(): void
    {
        $oldestRecord = MarketMover::orderBy('trading_date', 'asc')->first();
        $newestRecord = MarketMover::orderBy('trading_date', 'desc')->first();

        if ($oldestRecord && $newestRecord) {
            Setting::set('market_movers_oldest_date', $oldestRecord->trading_date->format('Y-m-d'));
            Setting::set('market_movers_newest_date', $newestRecord->trading_date->format('Y-m-d'));
            Setting::set('market_movers_last_populated_at', now()->toISOString());

            $this->info("Date range tracked: {$oldestRecord->trading_date->format('Y-m-d')} to {$newestRecord->trading_date->format('Y-m-d')}");
        }
    }

    private function saveMarketMoverData(array $data, bool $force): void
    {
        $existing = MarketMover::where('trading_date', $data['date'])->first();

        if ($existing && ! $force) {
            return; // Skip if record exists and not forcing
        }

        MarketMover::updateOrCreate(
            ['trading_date' => $data['date']],
            [
                'bars_4pct_plus' => $data['bars_4pct_plus'],
                'bars_5pct_plus' => $data['bars_5pct_plus'],
                'bars_10pct_plus' => $data['bars_10pct_plus'],
                'max_gain' => $data['max_gain'],
                'strength' => $data['strength'],
                'label' => $data['label'],
                'movers' => $data['movers'],
            ]
        );
    }
}
