<?php

namespace App\Console\Commands;

use App\Models\AssetInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ImportNasdaqCompanies extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nasdaq:import-companies {--file=storage/nasdaqcompanies.csv : Path to the CSV file} {--fetch-descriptions : Fetch Wikipedia descriptions} {--force : Overwrite existing companies} {--limit= : Limit number of companies to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import NASDAQ companies from CSV file with optional Wikipedia descriptions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $filePath = $this->option('file');
        $fetchDescriptions = $this->option('fetch-descriptions');
        $force = $this->option('force');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        // Check if file exists
        if (! file_exists($filePath)) {
            $this->error("CSV file not found: {$filePath}");

            return self::FAILURE;
        }

        $this->info("Importing NASDAQ companies from: {$filePath}");
        if ($fetchDescriptions) {
            $this->info('Wikipedia descriptions will be fetched');
        }

        // Read and parse CSV
        $handle = fopen($filePath, 'r');
        if (! $handle) {
            $this->error('Failed to open CSV file');

            return self::FAILURE;
        }

        // Skip header line
        $header = fgetcsv($handle, 0, '|');
        if (! $header) {
            $this->error('Failed to read CSV header');
            fclose($handle);

            return self::FAILURE;
        }

        $this->info('CSV Header: '.implode(' | ', $header));

        // Count total lines for progress bar
        $totalLines = 0;
        while (fgets($handle) !== false) {
            $totalLines++;
        }
        rewind($handle);
        fgetcsv($handle, 0, '|'); // Skip header again

        if ($limit && $limit < $totalLines) {
            $totalLines = $limit;
        }

        $this->info("Processing {$totalLines} companies...");

        $bar = $this->output->createProgressBar($totalLines);
        $bar->start();

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $processed = 0;

        while (($data = fgetcsv($handle, 0, '|')) !== false && (! $limit || $processed < $limit)) {
            try {
                $symbol = trim($data[0] ?? '');
                $securityName = trim($data[1] ?? '');
                $marketCategory = trim($data[2] ?? '');

                // Skip if essential data is missing
                if (empty($symbol) || empty($securityName)) {
                    $skipped++;
                    $bar->advance();
                    $processed++;

                    continue;
                }

                // Check if company already exists
                $existingAsset = AssetInfo::where('symbol', $symbol)
                    ->where('asset_type', 'stock')
                    ->first();

                if ($existingAsset && ! $force) {
                    $skipped++;
                    $bar->advance();
                    $processed++;

                    continue;
                }

                $description = null;
                if ($fetchDescriptions) {
                    $description = $this->fetchWikipediaDescription($securityName);
                    // Rate limiting for Wikipedia API
                    usleep(100000); // 100ms delay
                }

                // Prepare asset data
                $assetData = [
                    'symbol' => $symbol,
                    'asset_type' => 'stock',
                    'common_name' => $securityName,
                    'sector' => $this->mapMarketCategoryToSector($marketCategory),
                ];

                if ($description) {
                    $assetData['description'] = $description;
                }

                if ($existingAsset) {
                    $existingAsset->update($assetData);
                    $updated++;
                } else {
                    AssetInfo::create($assetData);
                    $imported++;
                }
            } catch (\Exception $e) {
                $this->error("\nFailed to process row: {$e->getMessage()}");
                $failed++;
            }

            $bar->advance();
            $processed++;
        }

        fclose($handle);
        $bar->finish();

        $this->newLine(2);
        $this->info('Import completed!');
        $this->info("Imported: {$imported}");
        $this->info("Updated: {$updated}");
        $this->info("Skipped: {$skipped}");
        $this->warn("Failed: {$failed}");

        return self::SUCCESS;
    }

    private function fetchWikipediaDescription(string $companyName): ?string
    {
        try {
            // Wikipedia API endpoint - User-Agent required by Wikipedia API policy
            $response = Http::withHeaders([
                'User-Agent' => 'TikrTracker/1.0 (Educational Project; Contact: admin@tikrtracker.com)',
            ])
                ->timeout(10)
                ->get('https://en.wikipedia.org/w/api.php', [
                    'action' => 'query',
                    'format' => 'json',
                    'prop' => 'extracts',
                    'exintro' => true,
                    'explaintext' => true,
                    'titles' => $companyName,
                    'redirects' => 1,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            $pages = $data['query']['pages'] ?? [];
            $page = reset($pages);

            if (! isset($page['extract']) || empty($page['extract'])) {
                return null;
            }

            $extract = $page['extract'];

            // Get first paragraph (before first double newline)
            $paragraphs = explode("\n", $extract);
            $firstParagraph = trim($paragraphs[0]);

            // Limit to reasonable length (around 500 chars)
            if (strlen($firstParagraph) > 500) {
                $firstParagraph = substr($firstParagraph, 0, 500);
                $lastPeriod = strrpos($firstParagraph, '.');
                if ($lastPeriod !== false) {
                    $firstParagraph = substr($firstParagraph, 0, $lastPeriod + 1);
                }
            }

            return $firstParagraph;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function mapMarketCategoryToSector(string $marketCategory): ?string
    {
        return match (trim($marketCategory)) {
            'Q' => 'NASDAQ Global Select Market',
            'G' => 'NASDAQ Global Market',
            'S' => 'NASDAQ Capital Market',
            default => null,
        };
    }
}
