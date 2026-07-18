<?php

namespace App\Console\Commands;

use App\Models\AssetInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ImportNyseCompanies extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nyse:import-companies {--file=storage/nyse-listed.csv : Path to the CSV file} {--fetch-descriptions : Fetch Wikipedia descriptions} {--force : Overwrite existing companies} {--limit= : Limit number of companies to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import NYSE companies from CSV file with optional Wikipedia descriptions';

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

        $this->info("Importing NYSE companies from: {$filePath}");
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
        $header = fgetcsv($handle);
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
        fgetcsv($handle); // Skip header again

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

        while (($data = fgetcsv($handle)) !== false && (! $limit || $processed < $limit)) {
            try {
                $symbol = trim($data[0] ?? '');
                $companyName = trim($data[1] ?? '');

                // Skip if essential data is missing
                if (empty($symbol) || empty($companyName)) {
                    $skipped++;
                    $bar->advance();
                    $processed++;

                    continue;
                }

                // Clean up symbol - NYSE symbols might have suffixes like .U, .W
                $cleanSymbol = $this->cleanNyseSymbol($symbol);

                // Check if company already exists
                $existingAsset = AssetInfo::where('symbol', $cleanSymbol)
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
                    $description = $this->fetchWikipediaDescription($companyName);
                    // Rate limiting for Wikipedia API
                    usleep(100000); // 100ms delay
                }

                // Prepare asset data
                $assetData = [
                    'symbol' => $cleanSymbol,
                    'asset_type' => 'stock',
                    'common_name' => $companyName,
                    'sector' => $this->mapNyseSymbolToSector($symbol),
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

    private function cleanNyseSymbol(string $symbol): string
    {
        // NYSE symbols can have suffixes like:
        // .U (units), .W (warrants), $A (preferred stock series), etc.
        // Keep the base symbol for stock tracking

        // Handle preferred stock symbols with $ (e.g., TEST$A -> TEST)
        if (str_contains($symbol, '$')) {
            $parts = explode('$', $symbol);

            return $parts[0];
        }

        // Handle unit/warrant symbols with . (e.g., TEST.U -> TEST)
        if (str_contains($symbol, '.')) {
            $parts = explode('.', $symbol);

            return $parts[0];
        }

        return $symbol;
    }

    private function mapNyseSymbolToSector(string $symbol): ?string
    {
        // Determine sector based on NYSE symbol patterns
        if (str_contains($symbol, '.U')) {
            return 'NYSE - Units';
        }

        if (str_contains($symbol, '.W')) {
            return 'NYSE - Warrants';
        }

        if (str_contains($symbol, '$')) {
            return 'NYSE - Preferred Stock';
        }

        return 'NYSE - Common Stock';
    }

    private function fetchWikipediaDescription(string $companyName): ?string
    {
        try {
            // Clean company name for Wikipedia search - remove common stock suffixes
            $cleanName = $this->cleanCompanyNameForWikipedia($companyName);

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
                    'titles' => $cleanName,
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

    private function cleanCompanyNameForWikipedia(string $companyName): string
    {
        // Remove common stock suffixes and patterns
        $patterns = [
            '/\s+Common\s+Stock.*$/i',
            '/\s+Class\s+[A-Z]\s+.*$/i',
            '/\s+American\s+Depositary\s+.*$/i',
            '/\s+Units.*$/i',
            '/\s+Warrants.*$/i',
            '/\s+each\s+.*$/i',
            '/\s+Inc\.?\s*$/i',
            '/\s+Corp\.?\s*$/i',
            '/\s+Corporation\s*$/i',
            '/\s+Company\s*$/i',
            '/\s+Ltd\.?\s*$/i',
            '/\s+Limited\s*$/i',
            '/\s+L\.P\.?\s*$/i',
            '/\s+LLC\s*$/i',
        ];

        $cleanName = $companyName;

        foreach ($patterns as $pattern) {
            $cleanName = preg_replace($pattern, '', $cleanName);
        }

        return trim($cleanName);
    }
}
