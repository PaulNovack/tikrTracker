<?php

namespace App\Console\Commands;

use App\Models\IntradayUniverse;
use App\Models\StockNews;
use App\Models\StockNewsArticle;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class FetchStockNews extends Command
{
    protected $signature = 'news:fetch-stock
                            {symbol? : Single stock symbol to fetch (optional)}
                            {--batch-size=5 : Symbols per Python invocation}
                            {--no-article-text : Skip full-article extraction (faster)}
                            {--delay=1 : Seconds between Python calls}';

    protected $description = 'Fetch FinBERT-scored news for intraday_universe symbols (or a single symbol)';

    private string $pythonScript;

    private string $pythonBin;

    public function handle(): int
    {
        $this->pythonBin = base_path('.venv/bin/python');
        $this->pythonScript = base_path('python_ml/get_news2.py');

        if (! file_exists($this->pythonScript)) {
            $this->error("Python script not found: {$this->pythonScript}");

            return self::FAILURE;
        }

        $singleSymbol = $this->argument('symbol');

        if ($singleSymbol !== null) {
            return $this->fetchSingleSymbol(strtoupper(trim($singleSymbol)));
        }

        return $this->fetchAllSymbols();
    }

    private function fetchSingleSymbol(string $symbol): int
    {
        $this->info("Fetching news for {$symbol}...");

        $fetchedAt = Carbon::now('UTC');
        $noArticleText = $this->option('no-article-text');

        $output = $this->runPythonScript([$symbol], $noArticleText);

        if ($output === null) {
            $this->error("Failed to fetch news for {$symbol}.");

            return self::FAILURE;
        }

        $results = $output['results'] ?? [];

        if (empty($results)) {
            $this->warn("No results for {$symbol}.");

            return self::SUCCESS;
        }

        $data = $results[$symbol] ?? null;

        if ($data === null) {
            $this->warn("No data for {$symbol} in results.");

            return self::SUCCESS;
        }

        // Delete existing news for this symbol before storing fresh data.
        $existingIds = StockNews::where('symbol', $symbol)->pluck('id');
        StockNewsArticle::whereIn('stock_news_id', $existingIds)->delete();
        StockNews::where('symbol', $symbol)->delete();

        $articleCount = $this->storeSymbolResult($symbol, $data, $fetchedAt);

        $this->info("Stored news for {$symbol}".($articleCount !== null ? " ({$articleCount} articles)" : '').'.');

        return self::SUCCESS;
    }

    private function fetchAllSymbols(): int
    {
        $symbols = IntradayUniverse::query()->pluck('symbol')->toArray();

        if (empty($symbols)) {
            $this->info('No symbols found in intraday_universe.');

            return self::SUCCESS;
        }

        $totalSymbols = count($symbols);
        $batchSize = (int) $this->option('batch-size');
        $delay = (int) $this->option('delay');
        $noArticleText = $this->option('no-article-text');

        $this->info("Symbols: {$totalSymbols} | Batch: {$batchSize} | Delay: {$delay}s");

        $fetchedAt = Carbon::now('UTC');
        $batches = array_chunk($symbols, $batchSize);
        $totalProcessed = 0;
        $totalArticles = 0;

        foreach ($batches as $index => $batch) {
            $batchNum = $index + 1;
            $this->info("Batch {$batchNum}/".count($batches).': '.implode(', ', $batch));

            $output = $this->runPythonScript($batch, $noArticleText);

            if ($output === null) {
                $this->warn("  Failed for batch {$batchNum}, skipping.");

                continue;
            }

            $results = $output['results'] ?? [];

            if (empty($results)) {
                $this->warn("  No results for batch {$batchNum}.");

                continue;
            }

            foreach ($results as $symbol => $data) {
                $processed = $this->storeSymbolResult($symbol, $data, $fetchedAt);

                if ($processed !== null) {
                    $totalProcessed++;
                    $totalArticles += $processed;
                }
            }

            if ($index < count($batches) - 1) {
                sleep($delay);
            }
        }

        $this->info("Done. Stored news for {$totalProcessed} symbols ({$totalArticles} articles).");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $symbols
     * @return array<string, mixed>|null
     */
    private function runPythonScript(array $symbols, bool $noArticleText): ?array
    {
        $command = array_values(array_filter([
            $this->pythonBin,
            $this->pythonScript,
            ...$symbols,
            $noArticleText ? '--no-article-text' : null,
            '--compact',
        ]));

        $process = new Process($command, base_path());
        $process->setTimeout(300);

        $exitCode = $process->run();

        if ($exitCode !== 0) {
            $stderr = trim($process->getErrorOutput());
            $this->warn("  Python exited with code {$exitCode}");

            if ($stderr !== '') {
                $this->warn("  stderr: {$stderr}");
            }

            Log::error('FetchStockNews: Python failed for '.implode(',', $symbols), [
                'exit_code' => $exitCode,
                'stderr' => $stderr,
            ]);

            return null;
        }

        $json = trim($process->getOutput());

        if ($json === '') {
            Log::error('FetchStockNews: no output from Python for '.implode(',', $symbols));

            return null;
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            Log::error('FetchStockNews: invalid JSON from Python for '.implode(',', $symbols));

            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeSymbolResult(string $symbol, array $data, Carbon $fetchedAt): ?int
    {
        $isError = $data['sentiment'] === 'No usable headlines found' || isset($data['error']);
        $breakdown = $data['breakdown'] ?? [];
        $top = $data['top_explanation'] ?? null;

        $stockNews = StockNews::create([
            'symbol' => $symbol,
            'sentiment' => $isError ? null : ($data['sentiment'] ?? null),
            'confidence' => $isError ? null : (float) ($data['confidence'] ?? 0),
            'sentiment_score_1_100' => $isError ? null : (int) ($data['sentiment_score_1_100'] ?? 50),
            'headline_count' => (int) ($data['headline_count'] ?? 0),
            'positive_count' => (int) ($breakdown['positive'] ?? 0),
            'negative_count' => (int) ($breakdown['negative'] ?? 0),
            'neutral_count' => (int) ($breakdown['neutral'] ?? 0),
            'top_finding' => $top['finding'] ?? null,
            'top_matched_phrase' => $top['matched_phrase'] ?? null,
            'top_source' => $top['source'] ?? null,
            'top_title' => $top['title'] ?? null,
            'top_article_score' => isset($top['article_score_1_100']) ? (int) $top['article_score_1_100'] : null,
            'top_article_text_extracted' => (bool) ($top['article_text_extracted'] ?? false),
            'top_evidence' => $top['evidence'] ?? null,
            'top_url' => $top['url'] ?? null,
            'is_error' => $isError,
            'error_message' => $data['error'] ?? null,
            'fetched_at_utc' => $fetchedAt,
        ]);

        $articleCount = 0;
        $keyFindings = $data['key_findings'] ?? [];

        foreach ($keyFindings as $finding) {
            $probs = $finding['evidence_probabilities'] ?? [];

            StockNewsArticle::create([
                'stock_news_id' => $stockNews->id,
                'symbol' => $symbol,
                'title' => $finding['title'] ?? null,
                'source' => $finding['source'] ?? null,
                'url' => $finding['url'] ?? null,
                'pub_date' => $finding['pub_date'] ?? null,
                'article_text_extracted' => (bool) ($finding['article_text_extracted'] ?? false),
                'sentiment' => $finding['sentiment'] ?? null,
                'impact' => isset($finding['impact']) ? (float) $finding['impact'] : null,
                'score_1_100' => isset($finding['score_1_100']) ? (int) $finding['score_1_100'] : null,
                'finding_category' => $finding['finding_category'] ?? null,
                'matched_phrase' => $finding['matched_phrase'] ?? null,
                'evidence' => $finding['evidence'] ?? null,
                'evidence_positive' => isset($probs['positive']) ? (float) $probs['positive'] : null,
                'evidence_negative' => isset($probs['negative']) ? (float) $probs['negative'] : null,
                'evidence_neutral' => isset($probs['neutral']) ? (float) $probs['neutral'] : null,
                'fetched_at_utc' => $fetchedAt,
            ]);

            $articleCount++;
        }

        return $articleCount;
    }
}
