<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ScanThreeWhiteSoldiers extends Command
{
    protected $signature = 'scan:three-white-soldiers
                            {date : Date in YYYY-MM-DD format (America/New_York)}';

    protected $description = 'Scan for CDL3WHITESOLDIERS at every 5-min bar from 9:00 AM to 4:00 PM EST';

    private string $flaskBaseUrl = 'http://127.0.0.1:5000';

    public function handle(): int
    {
        $estDate = Carbon::parse($this->argument('date'), 'America/New_York')->startOfDay();

        $this->info("Scanning {$estDate->toDateString()} 9:00 AM – 4:00 PM EST at each 5-min bar...");

        $barTimes = $this->buildBarTimes($estDate);

        if (empty($barTimes)) {
            $this->warn('No trading hours for this date.');

            return self::SUCCESS;
        }

        $this->info(count($barTimes).' bar windows to scan.');

        $allHits = [];

        foreach ($barTimes as $i => $barTime) {
            $timeLabel = $barTime->format('g:i A T');

            // Convert EST bar time to UTC for the Flask API
            $utcBefore = $barTime->copy()->tz('UTC')->format('Y-m-d H:i:s');

            $result = $this->scanAtTime($utcBefore);

            if ($result['hits'] > 0) {
                $allHits[] = [
                    'bar_time_est' => $timeLabel,
                    'utc_before' => $utcBefore,
                    'symbols' => $result['symbols'],
                ];

                $this->line("  <info>{$timeLabel}</info> — {$result['hits']} symbol(s): <comment>".implode(', ', $result['symbols']).'</comment>');
            }

            if (($i + 1) % 12 === 0) {
                $this->line('  <fg=gray>Processed '.($i + 1).'/'.count($barTimes).' bars...</>');
            }
        }

        $this->newLine();

        if (empty($allHits)) {
            $this->warn('No Three Advancing White Soldiers detected at any 5-minute bar.');

            return self::SUCCESS;
        }

        $totalHits = array_sum(array_map(fn ($h) => count($h['symbols']), $allHits));
        $this->info("Total: {$totalHits} pattern detections at ".count($allHits).' bar times.');
        $this->newLine();

        $headers = ['Bar Time (EST)', 'UTC Before', 'Symbols'];
        $rows = [];
        foreach ($allHits as $hit) {
            $rows[] = [
                $hit['bar_time_est'],
                $hit['utc_before'],
                implode(', ', $hit['symbols']),
            ];
        }
        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    private function buildBarTimes(Carbon $estDate): array
    {
        $bars = [];
        $current = $estDate->copy()->setTime(9, 30);
        $end = $estDate->copy()->setTime(16, 0);

        while ($current->lte($end)) {
            $bars[] = $current->copy();
            $current->addMinutes(5);
        }

        return $bars;
    }

    private function scanAtTime(string $utcBefore): array
    {
        try {
            $response = Http::timeout(120)->get("{$this->flaskBaseUrl}/api/scan-intraday-at", [
                'pattern' => 'CDL3WHITESOLDIERS',
                'limit' => 750,
                'before' => $utcBefore,
            ]);

            if (! $response->successful()) {
                return ['hits' => 0, 'symbols' => []];
            }

            $data = $response->json();
            $results = $data['results'] ?? [];
            $symbols = array_column($results, 'symbol');

            return ['hits' => count($symbols), 'symbols' => $symbols];
        } catch (\Exception $e) {
            return ['hits' => 0, 'symbols' => []];
        }
    }
}
