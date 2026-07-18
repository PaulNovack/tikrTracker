<?php

namespace App\Console\Commands;

use App\Models\MarketMover;
use App\Models\Setting;
use Illuminate\Console\Command;

class VerifyMarketMovers extends Command
{
    protected $signature = 'market-movers:verify 
        {--from= : Start date (Y-m-d)}
        {--to= : End date (Y-m-d)}
        {--auto-populate : Automatically populate missing dates}';

    protected $description = 'Verify market_movers data exists for a date range and optionally populate missing dates';

    public function handle(): int
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $autoPopulate = $this->option('auto-populate');

        if (! $from || ! $to) {
            $this->error('Both --from and --to dates are required (Y-m-d format)');

            return self::FAILURE;
        }

        // Validate date format
        if (! strtotime($from) || ! strtotime($to)) {
            $this->error('Invalid date format. Use Y-m-d (e.g., 2025-12-01)');

            return self::FAILURE;
        }

        $this->info("Checking market_movers data from {$from} to {$to}...");
        $this->newLine();

        // Show current coverage from settings
        $oldestDate = Setting::get('market_movers_oldest_date');
        $newestDate = Setting::get('market_movers_newest_date');
        $lastPopulated = Setting::get('market_movers_last_populated_at');

        if ($oldestDate && $newestDate) {
            $this->info('Current Coverage:');
            $this->line("  Oldest: {$oldestDate}");
            $this->line("  Newest: {$newestDate}");
            $this->line("  Last populated: {$lastPopulated}");
            $this->newLine();
        }

        // Check each date in range
        $startDate = strtotime($from);
        $endDate = strtotime($to);
        $missingDates = [];
        $existingDates = [];

        for ($d = $startDate; $d <= $endDate; $d += 86400) {
            $date = date('Y-m-d', $d);
            $dayOfWeek = (int) date('N', $d);

            // Skip weekends (Saturday=6, Sunday=7)
            if ($dayOfWeek >= 6) {
                continue;
            }

            $exists = MarketMover::where('trading_date', $date)->exists();

            if ($exists) {
                $existingDates[] = $date;
            } else {
                $missingDates[] = $date;
            }
        }

        // Report results
        $this->info('Results:');
        $this->line('  <info>Existing dates: '.count($existingDates).'</info>');
        $this->line('  <comment>Missing dates: '.count($missingDates).'</comment>');
        $this->newLine();

        if (empty($missingDates)) {
            $this->info('✓ All dates have market_movers data!');

            return self::SUCCESS;
        }

        // Show missing dates (up to 20)
        $this->warn('Missing dates:');
        foreach (array_slice($missingDates, 0, 20) as $date) {
            $this->line("  - {$date}");
        }

        if (count($missingDates) > 20) {
            $this->line('  ... and '.(count($missingDates) - 20).' more');
        }

        $this->newLine();

        // Auto-populate if requested
        if ($autoPopulate) {
            $this->info('Auto-populating missing dates...');
            $this->newLine();

            foreach ($missingDates as $date) {
                $this->line("Populating {$date}...");
                $this->call('market-movers:populate', [
                    '--date' => $date,
                    '--no-interaction' => true,
                ]);
            }

            $this->newLine();
            $this->info('✓ Finished auto-populating missing dates');

            return self::SUCCESS;
        }

        // Suggest command to populate
        $this->newLine();
        $this->comment('To populate missing dates, run:');
        $this->line("  php artisan market-movers:verify --from={$from} --to={$to} --auto-populate");

        return self::FAILURE;
    }
}
