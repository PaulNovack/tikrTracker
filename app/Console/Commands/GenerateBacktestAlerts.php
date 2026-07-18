<?php

namespace App\Console\Commands;

use App\Services\Trading\OneMinuteEntryFinderV13_0;
use App\Services\Trading\OneMinuteUniverseScannerV1;
use App\Services\Trading\TradeAlertWriterV1;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateBacktestAlerts extends Command
{
    protected $signature = 'backtest:generate-alerts
        {date? : Trading date YYYY-MM-DD (defaults to today)}
        {--start=09:30 : Start time HH:MM in EST}
        {--end=now : End time HH:MM in EST or "now"}
        {--interval=10 : Minutes between scans}
        {--universe-limit=50 : Max symbols to scan}
        {--min-vol=100000 : Minimum volume for universe}
        {--max-alerts=50 : Maximum alerts to generate}
        {--min-score= : Minimum score threshold (e.g., 4.0, 5.0)}
    ';

    protected $description = 'Generate backtest alerts and store them in database for web review';

    public function handle(
        OneMinuteUniverseScannerV1 $universe,
        OneMinuteEntryFinderV13_0 $finder,
        TradeAlertWriterV1 $writer
    ): int {
        $date = $this->argument('date') ?: now('America/New_York')->format('Y-m-d');
        $start = $this->option('start');
        $endOpt = $this->option('end');
        $interval = (int) $this->option('interval');
        $universeLimit = (int) $this->option('universe-limit');
        $minVol = (int) $this->option('min-vol');
        $maxAlerts = (int) $this->option('max-alerts');
        $minScore = $this->option('min-score');

        // Handle "now" end time
        if ($endOpt === 'now') {
            $end = now('America/New_York')->format('H:i');
        } else {
            $end = $endOpt;
        }

        $this->info("🚀 Generating Backtest Alerts for {$date}");
        $this->info("⏰ Time Range: {$start} - {$end} EST (scanning every {$interval} minutes)");
        $this->info("📊 Universe: Max {$universeLimit} symbols, Min volume {$minVol}");
        $this->info("🎯 Max Alerts: {$maxAlerts}");
        if ($minScore) {
            $this->info("📈 Min Score: {$minScore}");
        }
        $this->newLine();

        // Generate time points for scanning
        $startTime = strtotime("{$date} {$start}:00");
        if ($end === now('America/New_York')->format('H:i')) {
            $endTime = now('America/New_York')->timestamp;
        } else {
            $endTime = strtotime("{$date} {$end}:00");
        }
        $currentTime = $startTime;

        $scanCount = 0;
        $alertsGenerated = 0;
        $totalOpportunities = 0;

        while ($currentTime <= $endTime && $alertsGenerated < $maxAlerts) {
            $asOfTsEst = date('Y-m-d H:i:s', $currentTime);

            $scanCount++;
            $this->line("🔍 Scanning at {$asOfTsEst}...");

            // Check market regime
            if (! $this->checkMarketRegime($asOfTsEst)) {
                $this->line('   ❌ Market regime unfavorable - skipping');
                $currentTime += ($interval * 60);

                continue;
            }

            // Get active universe
            $symbols = $universe->activeSymbols(
                'stock',
                $asOfTsEst,
                3, // 3-minute activity window
                $minVol,
                0, // No minimum notional
                $universeLimit
            );

            if (empty($symbols)) {
                $this->line('   ⚠️  No active symbols');
                $currentTime += ($interval * 60);

                continue;
            }

            $this->line('   📈 Scanning '.count($symbols).' active symbols');

            // Find trading opportunities
            $opportunities = [];
            foreach ($symbols as $symbolData) {
                $symbol = $symbolData['symbol'];

                $result = $finder->findBestLong(
                    $symbol,
                    'stock',
                    $asOfTsEst,
                    $asOfTsEst,
                    30, // before minutes
                    15, // after minutes
                    20, // vol lookback
                    15, // pivot lookback
                    'next_open'
                );

                if ($result['ok'] && ! empty($result['best_entry'])) {
                    $entry = $result['best_entry'];

                    // Apply score filter if specified
                    if ($minScore && ($entry['score'] ?? 0) < $minScore) {
                        continue;
                    }

                    // Apply stale filter (only entries within last 30 minutes for backtest)
                    $entryTime = strtotime($entry['entry_ts_est']);
                    $asOfTime = strtotime($asOfTsEst);
                    $timeDiff = $asOfTime - $entryTime;

                    if ($timeDiff <= (30 * 60)) {
                        $opportunities[] = [
                            'symbol' => $symbol,
                            'entry' => $entry,
                            'notional' => $symbolData['notional_sum'] ?? 0,
                            'scan_time' => $asOfTsEst,
                        ];
                        $this->info("  ✅ Added opportunity: {$symbol} at {$entry['entry_ts_est']} (score: {$entry['score']}, risk: {$entry['risk_pct']}%)");
                    } else {
                        $this->info("  ⏰ Stale entry: {$symbol} at {$entry['entry_ts_est']} ({$timeDiff}s old, limit: 1800s)");
                    }
                } elseif ($result['ok']) {
                    $this->info("  ❌ No entry found for {$symbol}");
                }
            }

            $totalOpportunities += count($opportunities);

            // Sort opportunities by score and risk
            usort($opportunities, function ($a, $b) {
                $riskA = $a['entry']['risk_pct'] ?? 999;
                $riskB = $b['entry']['risk_pct'] ?? 999;
                if ($riskA == $riskB) {
                    return ($b['entry']['score'] ?? 0) <=> ($a['entry']['score'] ?? 0);
                }

                return $riskA <=> $riskB;
            });

            // Take best opportunities (max 2 per scan to spread across day)
            $maxPerScan = min(2, $maxAlerts - $alertsGenerated);
            $taken = 0;

            $this->info('  📊 Processing '.count($opportunities)." opportunities, max per scan: {$maxPerScan}");

            foreach (array_slice($opportunities, 0, $maxPerScan) as $opp) {
                $this->info("  🎯 Attempting to generate alert for {$opp['symbol']} (score: {$opp['entry']['score']}, risk: {$opp['entry']['risk_pct']}%)");
                $this->info('  📋 Entry data: '.json_encode(array_keys($opp['entry'])));
                $success = $this->generateAlert($opp, $writer, $universe);
                if ($success) {
                    $alertsGenerated++;
                    $taken++;
                    $this->info('  ✅ Alert generated successfully');
                } else {
                    $this->info('  ❌ Alert generation failed');
                }
            }

            if ($taken > 0) {
                $this->line("   ✅ Generated {$taken} alerts (score range: ".
                    number_format($opportunities[0]['entry']['score'] ?? 0, 1).' - '.
                    number_format($opportunities[min($taken - 1, count($opportunities) - 1)]['entry']['score'] ?? 0, 1).')');
            } else {
                $this->line('   ➡️  No qualifying opportunities');
            }

            $currentTime += ($interval * 60);
        }

        // Display results
        $this->newLine();
        $this->info('📊 BACKTEST ALERT GENERATION COMPLETE');
        $this->line(str_repeat('═', 50));

        $this->table([
            'Metric', 'Value',
        ], [
            ['Date Scanned', $date],
            ['Time Range', "{$start} - {$end} EST"],
            ['Total Scans', $scanCount],
            ['Total Opportunities Found', $totalOpportunities],
            ['Alerts Generated', $alertsGenerated],
            ['Selection Rate', $totalOpportunities > 0 ? number_format(($alertsGenerated / $totalOpportunities) * 100, 1).'%' : '0%'],
        ]);

        $this->newLine();
        $this->info('🌐 Visit the Trade Alerts page to review generated alerts:');
        $this->line('   → /trade-alerts');

        return 0;
    }

    private function checkMarketRegime(string $asOfTsEst): bool
    {
        // Check SPY momentum for market regime
        $spyBars = DB::select('
            SELECT price as close
            FROM one_minute_prices 
            WHERE asset_type = ? AND symbol = ? 
              AND ts_est >= ? AND ts_est <= ?
            ORDER BY ts_est ASC
            LIMIT 20
        ', [
            'stock',
            'SPY',
            date('Y-m-d H:i:s', strtotime($asOfTsEst.' -20 minutes')),
            $asOfTsEst,
        ]);

        if (count($spyBars) < 15) {
            return true; // Default allow if insufficient data
        }

        $prices = array_map(fn ($b) => (float) $b->close, $spyBars);
        $currentPrice = end($prices);
        $sma15 = array_sum(array_slice($prices, -15)) / 15;

        return $currentPrice > $sma15 * 0.9995; // Very close to 15-period SMA
    }

    private function generateAlert(array $opportunity, TradeAlertWriterV1 $writer, OneMinuteUniverseScannerV1 $universe): bool
    {
        $symbol = $opportunity['symbol'];
        $entry = $opportunity['entry'];
        $scanTime = $opportunity['scan_time'];

        // Create a signal record for the database
        $signal = [
            'symbol' => $symbol,
            'asset_type' => 'stock',
            'signal_type' => 'BACKTEST_OPTIMIZED',
            'signal_ts_est' => $scanTime,
            'score' => $entry['score'] ?? null,
            'meta' => [
                'backtest_version' => 'optimized_v1',
                'scan_time' => $scanTime,
                'notional_sum' => $opportunity['notional'] ?? 0,
                'market_regime_passed' => true,
            ],
        ];

        // Write the alert to the database
        return $writer->upsertAlert($signal, $entry, $scanTime, $universe->getVersion(), 'C');
    }
}
