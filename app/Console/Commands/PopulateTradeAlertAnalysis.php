<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateTradeAlertAnalysis extends Command
{
    protected $signature = 'populate:trade-alert-analysis 
                            {--pipeline= : Pipeline version to populate (e.g., v810.0)}
                            {--from= : Start date (YYYY-MM-DD)}
                            {--to= : End date (YYYY-MM-DD)}
                            {--limit= : Limit number of alerts to process}
                            {--force : Overwrite existing analysis records}';

    protected $description = 'Populate trade_alert_analysis table with historical 5-minute data for analyzed alerts';

    public function handle(): int
    {
        $version = $this->option('pipeline');
        $from = $this->option('from');
        $to = $this->option('to');
        $limit = (int) $this->option('limit');
        $force = $this->option('force');

        if (! $version) {
            $this->error('--pipeline is required (e.g., v810.0)');

            return 1;
        }

        $this->info("Populating trade_alert_analysis for version: {$version}");

        // Query analyzed alerts (those with exit_price populated)
        $query = DB::table('trade_alerts')
            ->where('version', $version)
            ->whereNotNull('exit_price')
            ->whereNotNull('pnl_percent')
            ->orderBy('signal_ts_est');

        if ($from) {
            $query->where('trading_date_est', '>=', $from);
            $this->info("From date: {$from}");
        }
        if ($to) {
            $query->where('trading_date_est', '<=', $to);
            $this->info("To date: {$to}");
        }
        if ($limit > 0) {
            $query->limit($limit);
            $this->info("Limit: {$limit} alerts");
        }

        $alerts = $query->get();

        if ($alerts->isEmpty()) {
            $this->warn('No analyzed alerts found for this version/date range');

            return 0;
        }

        $this->info("Found {$alerts->count()} analyzed alerts to process");
        $this->newLine();

        $bar = $this->output->createProgressBar($alerts->count());
        $bar->start();

        $inserted = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($alerts as $alert) {
            try {
                // Check if analysis already exists
                if (! $force) {
                    $exists = DB::table('trade_alert_analysis')
                        ->where('trade_alert_id', $alert->id)
                        ->exists();

                    if ($exists) {
                        $skipped++;
                        $bar->advance();

                        continue;
                    }
                }

                // Get historical 5-minute data at 10-minute intervals
                $historicalData = $this->getHistoricalData(
                    $alert->symbol,
                    $alert->asset_type,
                    $alert->entry_ts_est,
                    (float) $alert->entry
                );

                // Calculate is_winner
                $isWinner = $alert->pnl_percent > 0 ? 1 : 0;

                // Insert or update analysis record
                DB::table('trade_alert_analysis')->updateOrInsert(
                    ['trade_alert_id' => $alert->id],
                    [
                        'trade_alert_id' => $alert->id,
                        'symbol' => $alert->symbol,
                        'asset_type' => $alert->asset_type,
                        'pipeline_version' => $version,
                        'trading_date_est' => $alert->trading_date_est,
                        'signal_ts_est' => $alert->signal_ts_est,
                        'is_winner' => $isWinner,
                        'pnl_percent' => $alert->pnl_percent,
                        'pnl_dollar' => $alert->pnl_dollar,
                        'exit_reason' => $alert->exit_reason,
                        'r_multiple' => $alert->r_multiple,
                        'data_200m_back' => $historicalData['data_200m_back'],
                        'data_190m_back' => $historicalData['data_190m_back'],
                        'data_180m_back' => $historicalData['data_180m_back'],
                        'data_170m_back' => $historicalData['data_170m_back'],
                        'data_160m_back' => $historicalData['data_160m_back'],
                        'data_150m_back' => $historicalData['data_150m_back'],
                        'data_140m_back' => $historicalData['data_140m_back'],
                        'data_130m_back' => $historicalData['data_130m_back'],
                        'data_120m_back' => $historicalData['data_120m_back'],
                        'data_110m_back' => $historicalData['data_110m_back'],
                        'data_100m_back' => $historicalData['data_100m_back'],
                        'data_90m_back' => $historicalData['data_90m_back'],
                        'data_80m_back' => $historicalData['data_80m_back'],
                        'data_70m_back' => $historicalData['data_70m_back'],
                        'data_60m_back' => $historicalData['data_60m_back'],
                        'data_50m_back' => $historicalData['data_50m_back'],
                        'data_40m_back' => $historicalData['data_40m_back'],
                        'data_30m_back' => $historicalData['data_30m_back'],
                        'data_20m_back' => $historicalData['data_20m_back'],
                        'data_10m_back' => $historicalData['data_10m_back'],
                        'data_signal' => $historicalData['data_signal'],
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

                $inserted++;
            } catch (\Exception $e) {
                $errors++;
                $this->newLine();
                $this->error("Error processing alert {$alert->id} ({$alert->symbol}): {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('✅ Complete!');
        $this->table(
            ['Status', 'Count'],
            [
                ['Inserted/Updated', $inserted],
                ['Skipped (existing)', $skipped],
                ['Errors', $errors],
            ]
        );

        return 0;
    }

    private function getHistoricalData(string $symbol, string $assetType, string $entryTs, float $entryPrice): array
    {
        $intervals = [200, 190, 180, 170, 160, 150, 140, 130, 120, 110, 100, 90, 80, 70, 60, 50, 40, 30, 20, 10, 0];
        $data = [];

        foreach ($intervals as $minutesBack) {
            $targetTs = date('Y-m-d H:i:s', strtotime($entryTs) - ($minutesBack * 60));

            // Get closest 5-minute bar (within 2.5 minutes before/after target)
            $row = DB::table('five_minute_prices')
                ->select('price')
                ->where('symbol', $symbol)
                ->where('asset_type', $assetType)
                ->where('ts_est', '>=', date('Y-m-d H:i:s', strtotime($targetTs) - 150)) // -2.5 min
                ->where('ts_est', '<=', date('Y-m-d H:i:s', strtotime($targetTs) + 150)) // +2.5 min
                ->orderByRaw('ABS(TIMESTAMPDIFF(SECOND, ts_est, ?))', [$targetTs])
                ->first();

            if ($row && $entryPrice > 0) {
                // Store single price ratio relative to entry (entry = 1.0)
                $dataStr = (string) round($row->price / $entryPrice, 6);
            } else {
                $dataStr = null;
            }

            $key = $minutesBack === 0 ? 'data_signal' : "data_{$minutesBack}m_back";
            $data[$key] = $dataStr;
        }

        return $data;
    }
}
