<?php

namespace App\Console\Commands\Analysis;

use App\Services\Market\BestPerformers5mService;
use App\Services\Market\BuyZoneFromTopPerformersService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ShowBuyZoneCandidates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'analysis:buy-zone
                            {start : Start date/time in EST (e.g., "2025-12-15 10:00:00")}
                            {end : End date/time in EST (e.g., "2025-12-15 16:00:00")}
                            {--asset-type=stock : Asset type to analyze}
                            {--days=7 : Number of days for 7d window}
                            {--limit=50 : Limit number of results per interval}
                            {--interval=5 : Analysis interval in minutes}
                            {--debug : Show detailed filtering stats}
                            {--min-rvol=0.8 : Minimum RVOL threshold}
                            {--min-vol=0 : Minimum total volume across 7d period}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display buy zone candidates at intervals across a date/time range using historical data';

    public function __construct(
        private readonly BestPerformers5mService $bestPerformersService,
        private readonly BuyZoneFromTopPerformersService $buyZoneService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $start = $this->argument('start');
        $end = $this->argument('end');
        $assetType = $this->option('asset-type');
        $days = (int) $this->option('days');
        $limit = (int) $this->option('limit');
        $interval = (int) $this->option('interval');
        $debug = $this->option('debug');
        $minRvol = (float) $this->option('min-rvol');
        $minVol = (int) $this->option('min-vol');

        $startTime = CarbonImmutable::parse($start, 'America/New_York');
        $endTime = CarbonImmutable::parse($end, 'America/New_York');

        if ($startTime->gte($endTime)) {
            $this->error('Start time must be before end time.');

            return self::FAILURE;
        }

        $this->info("Analyzing buy zone candidates every {$interval} minutes from {$start} to {$end}");
        $this->newLine();

        // Track all unique candidates across all intervals
        $allCandidates = [];
        $candidateFrequency = [];
        $candidateEntryTime = []; // Track first appearance time

        $currentTime = $startTime;
        $intervalCount = 0;

        while ($currentTime->lte($endTime)) {
            $intervalCount++;
            $currentTimeStr = $currentTime->format('Y-m-d H:i:s');

            $this->line("⏰ Analyzing at {$currentTime->format('g:i A')} EST");

            // Step 1: Get top performers for this time
            $topPerformers = $this->bestPerformersService->getBestPerformers([
                'assetType' => $assetType,
                'testDateTime' => $currentTimeStr,
                'days' => $days,
                'minBars' => 200,
                'minVol' => $minVol,
                'rthOnly' => true, // Match controller: RTH only
                'limit' => 300,
            ]);

            if (! empty($topPerformers)) {
                $symbols = array_column($topPerformers, 'symbol');
                $this->comment('   Top performers: '.count($symbols));

                // Calculate appropriate min bars based on time since market open (9:30 AM)
                $marketOpen = CarbonImmutable::parse($currentTime->format('Y-m-d').' 09:30:00', 'America/New_York');
                $minutesSinceOpen = max(1, $marketOpen->diffInMinutes($currentTime, false));

                // If before market open, use very low requirement
                if ($minutesSinceOpen < 0) {
                    $minutesSinceOpen = 1;
                }

                $minBars = (int) min(120, floor($minutesSinceOpen * 0.8)); // Require 80% of available bars

                if ($debug) {
                    $this->comment("   Min 1m bars required: {$minBars} (market open {$minutesSinceOpen} min ago)");
                }

                // Step 2: Filter through buy zone service
                $candidates = $this->buyZoneService->filterBuyZone($symbols, [
                    'assetType' => $assetType,
                    'testDateTime' => $currentTimeStr,
                    'days' => $days,
                    'accountSize' => 18000,
                    'riskPerTradePct' => 0.005,
                    'min1mBarsToday' => $minBars,
                    'minRvol' => $minRvol,
                ]);

                if (! empty($candidates)) {
                    // Limit display per interval
                    $displayCandidates = array_slice($candidates, 0, $limit);

                    $this->info('   Found '.count($displayCandidates).' candidates:');

                    // Show top candidates inline
                    $symbolList = array_map(fn ($c) => $c['symbol'], $displayCandidates);
                    $this->line('   '.implode(', ', $symbolList));

                    // Track all candidates for summary
                    foreach ($candidates as $candidate) {
                        $symbol = $candidate['symbol'];
                        $candidateFrequency[$symbol] = ($candidateFrequency[$symbol] ?? 0) + 1;

                        if (! isset($allCandidates[$symbol])) {
                            $allCandidates[$symbol] = $candidate;
                            $candidateEntryTime[$symbol] = $currentTime->format('g:i A'); // Track first entry time
                        }
                    }
                } else {
                    $this->comment('   No candidates');
                }
            } else {
                $this->comment('   No top performers');
            }

            $this->newLine();

            // Move to next interval
            $currentTime = $currentTime->addMinutes($interval);
        }

        // Display comprehensive summary
        $this->displaySummary($allCandidates, $candidateFrequency, $candidateEntryTime, $intervalCount);

        return self::SUCCESS;
    }

    private function displaySummary(array $allCandidates, array $candidateFrequency, array $candidateEntryTime, int $intervalCount): void
    {
        if (empty($allCandidates)) {
            $this->warn('No candidates found across all intervals.');

            return;
        }

        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('SUMMARY: All Unique Candidates Across Time Period');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->newLine();

        // Sort by frequency (most frequent first), then by risk%
        uasort($allCandidates, function ($a, $b) use ($candidateFrequency) {
            $freqA = $candidateFrequency[$a['symbol']];
            $freqB = $candidateFrequency[$b['symbol']];

            if ($freqA !== $freqB) {
                return $freqB <=> $freqA; // Higher frequency first
            }

            return $a['risk_pct'] <=> $b['risk_pct']; // Lower risk first
        });

        $headers = [
            'Symbol',
            'Entry Time',
            'Freq',
            'Entry',
            'Stop',
            'Risk%',
            'Viable',
            'Shares',
            'Position$',
            'RVOL',
            '% Off High',
        ];

        $rows = [];
        foreach ($allCandidates as $c) {
            $frequency = $candidateFrequency[$c['symbol']];
            $freqPct = round(($frequency / $intervalCount) * 100);

            $rows[] = [
                $c['symbol'],
                $candidateEntryTime[$c['symbol']] ?? 'N/A',
                "{$frequency}/{$intervalCount} ({$freqPct}%)",
                '$'.number_format($c['entry_price'], 2),
                '$'.number_format($c['stop_price'], 2),
                number_format($c['risk_pct_pct'], 2).'%',
                $c['stop_viable_1pct'] ? '✓' : '✗',
                number_format($c['recommended_shares']),
                '$'.number_format($c['position_notional'], 0),
                number_format($c['rvol'] ?? 0, 2),
                number_format($c['dist_from_7d_high_pct'], 1).'%',
            ];
        }

        $this->table($headers, $rows);

        // Overall stats
        $this->newLine();
        $viableCount = count(array_filter($allCandidates, fn ($c) => $c['stop_viable_1pct']));
        $totalPosition = array_sum(array_column($allCandidates, 'position_notional'));
        $avgRisk = array_sum(array_column($allCandidates, 'risk_pct_pct')) / count($allCandidates);

        // Find most consistent candidates (appeared 50%+ of the time)
        $consistent = array_filter($candidateFrequency, fn ($freq) => $freq >= ($intervalCount / 2));

        $this->info('Overall Statistics:');
        $this->line('  Total Unique Candidates: '.count($allCandidates));
        $this->line("  Viable Stops (≤1%): {$viableCount}");
        $this->line('  Total Position Size: $'.number_format($totalPosition, 0));
        $this->line('  Average Risk: '.number_format($avgRisk, 2).'%');
        $this->line('  Consistent Candidates (≥50% intervals): '.count($consistent));

        if (! empty($consistent)) {
            $this->newLine();
            $this->info('Most Consistent Candidates:');
            arsort($consistent);
            foreach (array_slice($consistent, 0, 10, true) as $symbol => $freq) {
                $pct = round(($freq / $intervalCount) * 100);
                $this->line("  {$symbol}: {$freq}/{$intervalCount} ({$pct}%)");
            }
        }
    }
}
