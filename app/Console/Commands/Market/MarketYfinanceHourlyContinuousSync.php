<?php

namespace App\Console\Commands\Market;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MarketYfinanceHourlyContinuousSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:yfinance-hourly-continuous-sync 
                            {--batch-size=200 : Symbols per API request}
                            {--parallel-jobs=2 : Number of parallel batch jobs}
                            {--stagger-delay=5 : Seconds to stagger job starts (rate limiting)}
                            {--hours=48 : Hours of data to fetch}
                            {--max-symbols= : Maximum symbols to process (for testing)}
                            {--conservative : Use conservative rate limiting (slower but safer)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Keep ALL symbols hourly data up-to-date using rate-limited parallel batch processing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $batchSize = (int) $this->option('batch-size');
        $parallelJobs = (int) $this->option('parallel-jobs');
        $staggerDelay = (int) $this->option('stagger-delay');
        $hours = (int) $this->option('hours');
        $maxSymbols = $this->option('max-symbols') ? (int) $this->option('max-symbols') : null;
        $conservative = $this->option('conservative');

        // Apply conservative settings if requested
        if ($conservative) {
            $parallelJobs = min($parallelJobs, 1);
            $staggerDelay = max($staggerDelay, 10);
            $batchSize = min($batchSize, 100);
            $this->info("🛡️  Conservative mode: reduced to {$parallelJobs} jobs with {$staggerDelay}s stagger, batch size {$batchSize}");
        }

        $this->info('🚀 Starting rate-limited hourly continuous sync for ALL symbols');
        $this->info("   Batch size: {$batchSize} symbols per API request");
        $this->info("   Parallel jobs: {$parallelJobs}");
        $this->info("   Stagger delay: {$staggerDelay}s between job starts");
        $this->info("   Hours: {$hours}");

        // Get total symbol count
        $totalSymbols = DB::table('asset_info')
            ->where('asset_type', 'stock')
            ->whereNull('deleted_at')
            ->count();

        if ($maxSymbols) {
            $totalSymbols = min($totalSymbols, $maxSymbols);
            $this->info("   Limited to: {$totalSymbols} symbols (testing)");
        }

        // Calculate batches needed
        $totalBatches = (int) ceil($totalSymbols / $batchSize);
        $batchesPerJob = (int) ceil($totalBatches / $parallelJobs);

        $this->info("   Total symbols: {$totalSymbols}");
        $this->info("   Total batches: {$totalBatches}");
        $this->info("   Batches per parallel job: {$batchesPerJob}");

        $startTime = microtime(true);
        $processes = [];

        // Launch parallel batch jobs with staggered starts for rate limiting
        for ($jobId = 0; $jobId < $parallelJobs; $jobId++) {
            $startOffset = $jobId * $batchesPerJob * $batchSize;
            $jobSymbolLimit = min($batchesPerJob * $batchSize, $totalSymbols - $startOffset);

            if ($jobSymbolLimit <= 0) {
                break; // No more symbols for this job
            }

            $this->info("🔄 Starting parallel job {$jobId}: offset={$startOffset}, limit={$jobSymbolLimit}");

            // Build command for this parallel job
            $command = [
                PHP_BINARY,
                'artisan',
                'market:yfinance-stocks-hourly-batch',
                $hours,
                '--batch-size='.$batchSize,
                '--limit='.$jobSymbolLimit,
                '--offset='.$startOffset,
            ];

            // Start the process
            $process = new \Symfony\Component\Process\Process($command, base_path());
            $process->setTimeout(7200); // 2 hour timeout for hourly data
            $process->start();

            $processes[] = [
                'process' => $process,
                'jobId' => $jobId,
                'offset' => $startOffset,
                'limit' => $jobSymbolLimit,
            ];

            // Stagger the starts for rate limiting
            if ($jobId < $parallelJobs - 1 && $staggerDelay > 0) {
                $this->info("⏳ Rate limiting: waiting {$staggerDelay}s before starting next job...");
                sleep($staggerDelay);
            }
        }

        $this->info('✅ All '.count($processes).' parallel jobs started');
        $this->newLine();

        // Monitor all processes
        $completedJobs = 0;
        $totalJobs = count($processes);

        while ($completedJobs < $totalJobs) {
            foreach ($processes as $index => &$jobData) {
                if (! isset($jobData['completed']) && ! $jobData['process']->isRunning()) {
                    $jobData['completed'] = true;
                    $completedJobs++;

                    $exitCode = $jobData['process']->getExitCode();
                    $jobId = $jobData['jobId'];

                    if ($exitCode === 0) {
                        $this->info("✅ Job {$jobId} completed successfully ({$completedJobs}/{$totalJobs})");
                    } else {
                        $this->error("❌ Job {$jobId} failed with exit code {$exitCode} ({$completedJobs}/{$totalJobs})");
                        $this->line('Output: '.$jobData['process']->getOutput());
                        $this->line('Error: '.$jobData['process']->getErrorOutput());
                    }
                }
            }

            if ($completedJobs < $totalJobs) {
                sleep(5); // Check every 5 seconds
            }
        }

        $duration = round(microtime(true) - $startTime, 2);

        $this->newLine();
        $this->info('🎉 Hourly continuous sync complete!');
        $this->info("   Duration: {$duration}s");
        $this->info("   Parallel jobs: {$totalJobs}");
        $this->info("   Total symbols: {$totalSymbols}");
        $this->info("   Batch size: {$batchSize}");
        $this->info('   Estimated API calls: '.ceil($totalSymbols / $batchSize));

        // Calculate API rate
        $apiCalls = ceil($totalSymbols / $batchSize);
        $apiRate = round($apiCalls / ($duration / 60), 2); // calls per minute
        $this->info("   API rate: {$apiRate} calls/minute");

        // Warn if rate seems high
        if ($apiRate > 30) { // 30 calls/minute = 0.5 calls/second
            $this->warn('⚠️  High API rate detected. Consider increasing batch size or stagger delay.');
        }

        $this->newLine();
        $this->info('💡 Remember to warm hourly caches with: php artisan market:warm-hourly-prices-cache');

        return self::SUCCESS;
    }
}
