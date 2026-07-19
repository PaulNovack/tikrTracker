<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ScanValidEntries extends Command
{
    protected $signature = 'market:scan-valid-entries
                            {--limit=750 : Number of symbols to scan}
                            {--lookback_from= : Datetime to look back from (defaults to now)}';

    protected $description = 'Scan for valid entries (5m engulfing + 1m confirmation) using the Flask screener';

    public function handle(): int
    {
        $lookbackFrom = $this->option('lookback_from')
            ?: now('America/New_York')->format('Y-m-d\TH:i:s');

        // Normalize: replace space with T for ISO compatibility
        $lookbackFrom = str_replace(' ', 'T', $lookbackFrom);

        $checkDate = Carbon::parse($lookbackFrom, 'America/New_York');

        $this->info(sprintf(
            '[%s] Scanning for valid entries (lookback: %s)...',
            now('America/New_York')->format('Y-m-d H:i:s T'),
            $checkDate->format('Y-m-d H:i T'),
        ));

        $limit = (int) $this->option('limit');

        $response = Http::timeout(120)->get('http://127.0.0.1:5000/api/scan-valid-entry', [
            'limit' => $limit,
            'lookback_from' => $lookbackFrom,
        ]);

        if (! $response->successful()) {
            $this->error('Flask screener returned error: ' . $response->status());

            return self::FAILURE;
        }

        $data = $response->json();

        $hits = $data['hits'] ?? 0;
        $total = $data['total_scanned'] ?? 0;

        $this->info(sprintf(
            'Scanned %d symbols — %d valid entries found',
            $total,
            $hits,
        ));

        if ($hits > 0) {
            $this->newLine();
            $this->table(
                ['Symbol', 'Entry Price', 'Engulfing High', 'Engulfing Close', 'Vol Ratio'],
                collect($data['results'])->map(fn ($r) => [
                    $r['symbol'],
                    '$' . number_format($r['entry_price'], 2),
                    '$' . number_format($r['engulfing_high'], 2),
                    '$' . number_format($r['engulfing_close'], 2),
                    number_format($r['volume_ratio'], 2) . 'x',
                ])->toArray(),
            );
        }

        return self::SUCCESS;
    }
}
