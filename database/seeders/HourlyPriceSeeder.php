<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class HourlyPriceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Fetching hourly prices from yFinance (last 90 days)...');

        // 3 days = 72 hours
        $hoursBack = 72;

        // Call the market:yfinance-stocks-hourly command
        $this->command->call('market:yfinance-stocks-hourly', [
            'hoursBack' => $hoursBack,
        ]);

        $this->command->info('✅ Hourly prices seeded from yFinance');
    }
}
