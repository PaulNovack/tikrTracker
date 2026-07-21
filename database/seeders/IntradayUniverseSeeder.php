<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class IntradayUniverseSeeder extends Seeder
{
    /** @var array<int, array<string, mixed>> */
    private array $data;

    public function __construct()
    {
        $dataFile = __DIR__.'/_intraday_universe_data.php';

        if (file_exists($dataFile)) {
            $this->data = require $dataFile;
        }
    }

    public function run(): void
    {
        if (empty($this->data)) {
            $this->command?->error('IntradayUniverseSeeder::$data is empty — populate it before running.');

            return;
        }

        DB::table('intraday_universe')->truncate();

        foreach (array_chunk($this->data, 100) as $chunk) {
            DB::table('intraday_universe')->insert(array_map(fn ($row) => [
                'symbol'                         => $row['symbol'],
                'asset_type'                     => $row['asset_type'],
                'universe_score'                 => $row['universe_score'],
                'days_seen'                      => $row['days_seen'],
                'total_1m_bars'                  => $row['total_1m_bars'],
                'avg_bars_per_day'               => $row['avg_bars_per_day'],
                'avg_price'                      => $row['avg_price'],
                'avg_daily_dollar_volume'        => $row['avg_daily_dollar_volume'],
                'avg_dollar_volume_1m'           => $row['avg_dollar_volume_1m'],
                'max_dollar_volume_1m'           => $row['max_dollar_volume_1m'],
                'avg_volume_1m'                  => $row['avg_volume_1m'],
                'avg_atr_pct'                    => $row['avg_atr_pct'],
                'avg_range_1m_pct'               => $row['avg_range_1m_pct'],
                'max_range_1m_pct'               => $row['max_range_1m_pct'],
                'avg_liquid_minutes_25k_per_day' => $row['avg_liquid_minutes_25k_per_day'],
                'avg_liquid_minutes_50k_per_day' => $row['avg_liquid_minutes_50k_per_day'],
                'avg_liquid_minutes_100k_per_day' => $row['avg_liquid_minutes_100k_per_day'],
                'days_avg_1m_dollar_vol_over_25k' => $row['days_avg_1m_dollar_vol_over_25k'],
                'days_avg_1m_dollar_vol_over_50k' => $row['days_avg_1m_dollar_vol_over_50k'],
                'avg_above_vwap_ratio'           => $row['avg_above_vwap_ratio'],
                'avg_ema_bull_ratio'             => $row['avg_ema_bull_ratio'],
            ], $chunk));
        }

        $this->command?->info('IntradayUniverseSeeder: '.count($this->data).' rows inserted.');
    }
}
