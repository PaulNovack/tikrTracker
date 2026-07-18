<?php

namespace App\Console\Commands;

use App\Models\AssetInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ImportSP500Stocks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:sp500 {--force : Force reimport even if stocks already exist}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import S&P 500 stocks from Wikipedia with sectors';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Fetching S&P 500 data from Wikipedia...');

        try {
            // Use a proper User-Agent to avoid being blocked by Wikipedia
            $response = Http::timeout(60)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                ])
                ->get('https://en.wikipedia.org/wiki/List_of_S%26P_500_companies');

            if (! $response->successful()) {
                $this->error('Failed to fetch data from Wikipedia. Status: '.$response->status());
                $this->error('Response: '.substr($response->body(), 0, 500));

                return self::FAILURE;
            }

            $html = $response->body();

            // Use DOMDocument to parse HTML table
            libxml_use_internal_errors(true);
            $dom = new \DOMDocument;
            $dom->loadHTML($html);
            libxml_clear_errors();

            $xpath = new \DOMXPath($dom);

            // Find the S&P 500 table - it's the first table with id="constituents"
            $rows = $xpath->query('//table[@id="constituents"]//tr');

            if ($rows->length === 0) {
                $this->error('Failed to find S&P 500 table in Wikipedia HTML.');

                return self::FAILURE;
            }

            $imported = 0;
            $skipped = 0;
            $updated = 0;
            $data = [];

            // Parse table rows (skip header row)
            for ($i = 1; $i < $rows->length; $i++) {
                $cells = $xpath->query('.//td', $rows->item($i));

                if ($cells->length < 3) {
                    continue;
                }

                $symbol = trim($cells->item(0)->textContent);
                $security = trim($cells->item(1)->textContent);
                $sector = trim($cells->item(2)->textContent);

                // Skip if symbol is empty or not valid
                if (empty($symbol) || strlen($symbol) > 20) {
                    continue;
                }

                $data[] = [
                    'symbol' => $symbol,
                    'security' => $security,
                    'sector' => $sector,
                ];
            }

            if (empty($data)) {
                $this->error('No valid data found in Wikipedia table.');

                return self::FAILURE;
            }

            $this->info('Processing '.count($data).' S&P 500 stocks...');
            $bar = $this->output->createProgressBar(count($data));

            foreach ($data as $row) {
                $existing = AssetInfo::withTrashed()
                    ->where('symbol', $row['symbol'])
                    ->where('asset_type', 'stock')
                    ->first();

                if ($existing && ! $this->option('force')) {
                    $skipped++;
                } elseif ($existing) {
                    $existing->update([
                        'common_name' => $row['security'],
                        'sector' => $row['sector'],
                        'deleted_at' => null, // Restore if soft deleted
                    ]);
                    $updated++;
                } else {
                    AssetInfo::create([
                        'symbol' => $row['symbol'],
                        'asset_type' => 'stock',
                        'common_name' => $row['security'],
                        'sector' => $row['sector'],
                        'description' => "S&P 500 component in the {$row['sector']} sector.",
                    ]);
                    $imported++;
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();

            $this->info('Import complete!');
            $this->info("Imported: {$imported}");
            $this->info("Updated: {$updated}");
            $this->info("Skipped: {$skipped}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
