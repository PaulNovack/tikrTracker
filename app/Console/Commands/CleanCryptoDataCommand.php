<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanCryptoDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crypto:clean-data';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean all existing crypto data from daily_prices, hourly_prices, and five_minute_prices tables';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $cryptoSymbols = ['BTC', 'ETH', 'BNB', 'SOL', 'ADA', 'DOGE'];

        $this->info('Cleaning existing crypto data...');

        // Count existing records
        $dailyCount = DB::table('daily_prices')
            ->whereIn('symbol', $cryptoSymbols)
            ->where('asset_type', 'crypto')
            ->count();

        $hourlyCount = DB::table('hourly_prices')
            ->whereIn('symbol', $cryptoSymbols)
            ->where('asset_type', 'crypto')
            ->count();

        $fiveMinCount = DB::table('five_minute_prices')
            ->whereIn('symbol', $cryptoSymbols)
            ->where('asset_type', 'crypto')
            ->count();

        $this->info("Found {$dailyCount} daily records, {$hourlyCount} hourly records, {$fiveMinCount} 5-minute records");

        if (! $this->confirm('Are you sure you want to delete all existing crypto data?')) {
            $this->info('Operation cancelled.');

            return 0;
        }

        // Delete daily prices
        $deleted = DB::table('daily_prices')
            ->whereIn('symbol', $cryptoSymbols)
            ->where('asset_type', 'crypto')
            ->delete();
        $this->info("Deleted {$deleted} daily price records");

        // Delete hourly prices
        $deleted = DB::table('hourly_prices')
            ->whereIn('symbol', $cryptoSymbols)
            ->where('asset_type', 'crypto')
            ->delete();
        $this->info("Deleted {$deleted} hourly price records");

        // Delete five minute prices
        $deleted = DB::table('five_minute_prices')
            ->whereIn('symbol', $cryptoSymbols)
            ->where('asset_type', 'crypto')
            ->delete();
        $this->info("Deleted {$deleted} five-minute price records");

        $this->info('✅ Crypto data cleanup completed!');

        return 0;
    }
}
