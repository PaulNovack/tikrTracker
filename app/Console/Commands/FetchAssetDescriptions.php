<?php

namespace App\Console\Commands;

use App\Models\AssetInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class FetchAssetDescriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'assets:fetch-descriptions {--limit=10 : Number of assets to update} {--force : Overwrite existing descriptions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch Wikipedia descriptions for assets';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $force = $this->option('force');

        $query = AssetInfo::query()
            ->where('asset_type', 'stock'); // Focus on stocks first

        if (! $force) {
            $query->where(function ($q) {
                $q->whereNull('description')
                    ->orWhere('description', '');
            });
        }

        $assets = $query->limit($limit)->get();

        if ($assets->isEmpty()) {
            $this->info('No assets to update.');

            return self::SUCCESS;
        }

        $this->info("Fetching descriptions for {$assets->count()} assets...");

        $bar = $this->output->createProgressBar($assets->count());
        $bar->start();

        $updated = 0;
        $failed = 0;

        foreach ($assets as $asset) {
            try {
                $description = $this->fetchWikipediaDescription($asset->common_name);

                if ($description) {
                    $asset->update(['description' => $description]);
                    $updated++;
                } else {
                    $failed++;
                }

                // Rate limiting - Wikipedia API guidelines suggest max 200 req/sec for bots
                usleep(100000); // 100ms delay = 10 requests/second
            } catch (\Exception $e) {
                $this->error("\nFailed to fetch description for {$asset->symbol}: {$e->getMessage()}");
                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();

        $this->newLine(2);
        $this->info("Updated: {$updated}");
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
}
