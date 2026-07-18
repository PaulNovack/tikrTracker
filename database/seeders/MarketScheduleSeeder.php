<?php

namespace Database\Seeders;

use App\Models\MarketSchedule;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class MarketScheduleSeeder extends Seeder
{
    /**
     * US Stock Market holidays (2025-2028)
     * Based on NYSE & Nasdaq official holiday calendar
     * Source: Intercontinental Exchange, NASDAQ Trader
     */
    private array $holidays = [
        // 2025
        '2025-01-01' => "New Year's Day",
        '2025-01-20' => 'Martin Luther King Jr. Day',
        '2025-02-17' => "Presidents' Day",
        '2025-04-18' => 'Good Friday',
        '2025-05-26' => 'Memorial Day',
        '2025-06-19' => 'Juneteenth National Independence Day',
        '2025-07-04' => 'Independence Day',
        '2025-09-01' => 'Labor Day',
        '2025-11-27' => 'Thanksgiving Day',
        '2025-12-25' => 'Christmas Day',

        // 2026
        '2026-01-01' => "New Year's Day",
        '2026-01-19' => 'Martin Luther King Jr. Day',
        '2026-02-16' => "Presidents' Day",
        '2026-04-03' => 'Good Friday',
        '2026-05-25' => 'Memorial Day',
        '2026-06-19' => 'Juneteenth National Independence Day',
        '2026-07-03' => 'Independence Day (observed)',
        '2026-09-07' => 'Labor Day',
        '2026-11-26' => 'Thanksgiving Day',
        '2026-12-25' => 'Christmas Day',

        // 2027
        '2027-01-01' => "New Year's Day",
        '2027-01-18' => 'Martin Luther King Jr. Day',
        '2027-02-15' => "Presidents' Day",
        '2027-03-26' => 'Good Friday',
        '2027-05-31' => 'Memorial Day',
        '2027-06-18' => 'Juneteenth National Independence Day (observed)',
        '2027-07-05' => 'Independence Day (observed)',
        '2027-09-06' => 'Labor Day',
        '2027-11-25' => 'Thanksgiving Day',
        '2027-12-24' => 'Christmas Day (observed)',
        '2027-12-31' => "New Year's Day (observed for Jan 1, 2028)",

        // 2028
        '2028-01-17' => 'Martin Luther King Jr. Day',
        '2028-02-21' => "Presidents' Day",
        '2028-04-14' => 'Good Friday',
        '2028-05-29' => 'Memorial Day',
        '2028-06-19' => 'Juneteenth National Independence Day',
        '2028-07-04' => 'Independence Day',
        '2028-09-04' => 'Labor Day',
        '2028-11-23' => 'Thanksgiving Day',
        '2028-12-25' => 'Christmas Day',
    ];

    /**
     * Early close days (close at 1:00 p.m. ET)
     * Based on NYSE & Nasdaq official early-closing calendar
     * Source: Intercontinental Exchange, NASDAQ Trader
     */
    private array $earlyCloseDays = [
        // 2025
        '2025-07-03' => 'Day before Independence Day',
        '2025-11-28' => 'Day after Thanksgiving',
        '2025-12-24' => 'Christmas Eve',

        // 2026
        '2026-11-27' => 'Day after Thanksgiving',
        '2026-12-24' => 'Christmas Eve',

        // 2027
        '2027-11-26' => 'Day after Thanksgiving',

        // 2028
        '2028-07-03' => 'Day before Independence Day',
        '2028-11-24' => 'Day after Thanksgiving',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing data
        MarketSchedule::truncate();

        // Coverage: 2025-2028 (full years)
        $startDate = Carbon::parse('2025-01-01');
        $endDate = Carbon::parse('2028-12-31');

        // Generate market schedules for stock market
        $this->seedStockMarketSchedule($startDate, $endDate);

        // Generate market schedules for crypto market (always open)
        $this->seedCryptoMarketSchedule($startDate, $endDate);

        $this->command->info('Market schedules seeded successfully!');
    }

    /**
     * Seed stock market schedule
     */
    private function seedStockMarketSchedule(Carbon $startDate, Carbon $endDate): void
    {
        $currentDate = $startDate->clone();

        while ($currentDate <= $endDate) {
            $dateString = $currentDate->toDateString();

            // Check if it's a holiday
            if (isset($this->holidays[$dateString])) {
                MarketSchedule::create([
                    'date' => $currentDate->toDateString(),
                    'market_type' => 'stock',
                    'status' => 'holiday',
                    'reason' => $this->holidays[$dateString],
                    'opens_at' => null,
                    'closes_at' => null,
                    'is_early_close' => false,
                ]);
            }
            // Check if it's an early close day
            elseif (isset($this->earlyCloseDays[$dateString])) {
                MarketSchedule::create([
                    'date' => $currentDate->toDateString(),
                    'market_type' => 'stock',
                    'status' => 'open',
                    'reason' => $this->earlyCloseDays[$dateString],
                    'opens_at' => '09:30:00',
                    'closes_at' => '13:00:00',
                    'is_early_close' => true,
                ]);
            }
            // Check if it's a weekend
            elseif ($currentDate->isWeekend()) {
                MarketSchedule::create([
                    'date' => $currentDate->toDateString(),
                    'market_type' => 'stock',
                    'status' => 'closed',
                    'reason' => $currentDate->format('l'),
                    'opens_at' => null,
                    'closes_at' => null,
                    'is_early_close' => false,
                ]);
            }
            // Regular trading day
            else {
                MarketSchedule::create([
                    'date' => $currentDate->toDateString(),
                    'market_type' => 'stock',
                    'status' => 'open',
                    'reason' => null,
                    'opens_at' => '09:30:00',
                    'closes_at' => '16:00:00',
                    'is_early_close' => false,
                ]);
            }

            $currentDate->addDay();
        }

        $this->command->info("Stock market schedules created: {$startDate->toDateString()} to {$endDate->toDateString()}");
    }

    /**
     * Seed crypto market schedule (always open)
     */
    private function seedCryptoMarketSchedule(Carbon $startDate, Carbon $endDate): void
    {
        $currentDate = $startDate->clone();

        while ($currentDate <= $endDate) {
            MarketSchedule::create([
                'date' => $currentDate->toDateString(),
                'market_type' => 'crypto',
                'status' => 'open',
                'reason' => null,
                'opens_at' => '00:00:00',
                'closes_at' => '23:59:59',
                'is_early_close' => false,
            ]);

            $currentDate->addDay();
        }

        $this->command->info("Crypto market schedules created: {$startDate->toDateString()} to {$endDate->toDateString()}");
    }
}
