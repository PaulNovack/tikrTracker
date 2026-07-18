<?php

namespace App\Console\Commands\Market;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class MarketYfinanceCryptoHourlyContinuousSync extends Command
{
    protected $signature = 'market:yfinance-crypto-hourly-continuous-sync
                            {--parallel-jobs=1 : Number of parallel jobs (crypto hourly uses single batch)}
                            {--batch-size=6 : Symbols per batch (all 6 cryptos in 1 API call)}
                            {--hours=48 : Hours of data to sync}
                            {--conservative : Use conservative mode with longer delays}';

    protected $description = 'Ultra-efficient continuous crypto hourly sync using batch processing (83-90% API reduction)';

    public function handle(): int
    {
        $parallelJobs = (int) $this->option('parallel-jobs');
        $batchSize = (int) $this->option('batch-size');
        $hours = (int) $this->option('hours');
        $conservative = $this->option('conservative');

        // Validation
        if ($parallelJobs < 1) {
            $this->error('Parallel jobs must be at least 1');

            return self::FAILURE;
        }

        if ($batchSize < 1) {
            $this->error('Batch size must be at least 1');

            return self::FAILURE;
        }

        if ($hours < 1) {
            $this->error('Hours must be at least 1');

            return self::FAILURE;
        }

        $this->info('=== Ultra-Efficient Crypto Hourly Continuous Sync ===');
        $this->info('⚡ Configuration:');
        $this->info("   🔄 Parallel jobs: {$parallelJobs}");
        $this->info("   📦 Batch size: {$batchSize} cryptos per API call");
        $this->info("   📅 Hours back: {$hours}");
        $this->info('   🛡️  Conservative mode: '.($conservative ? 'enabled' : 'disabled'));
        $this->newLine();

        // For crypto hourly, we typically use a single job due to the small number of symbols
        // But maintain parallel support for potential future expansion
        if ($parallelJobs === 1) {
            return $this->runSingleJob($batchSize, $hours, $conservative);
        } else {
            return $this->runParallelJobs($parallelJobs, $batchSize, $hours, $conservative);
        }
    }

    /**
     * Run single crypto hourly sync job (most common scenario).
     */
    private function runSingleJob(int $batchSize, int $hours, bool $conservative): int
    {
        $this->info('🚀 Starting single crypto hourly sync job...');

        $startTime = microtime(true);

        // Run the batch command
        $exitCode = $this->call('market:yfinance-crypto-hourly-batch', [
            'hoursBack' => $hours,
            '--batch-size' => $batchSize,
        ]);

        $duration = round(microtime(true) - $startTime, 2);

        if ($exitCode === 0) {
            $this->info("✅ Crypto hourly sync completed successfully in {$duration}s");
            $this->info('💰 Ultra-efficient: All 6 cryptos processed in ~1 API call');
        } else {
            $this->error('❌ Crypto hourly sync failed');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Run parallel crypto hourly sync jobs.
     */
    private function runParallelJobs(int $parallelJobs, int $batchSize, int $hours, bool $conservative): int
    {
        $this->info("🔄 Starting {$parallelJobs} parallel crypto hourly sync jobs...");

        // Get total crypto count to calculate job distribution
        $totalCryptos = \App\Models\AssetInfo::where('asset_type', 'crypto')
            ->whereNull('deleted_at')
            ->count();

        if ($totalCryptos === 0) {
            $this->warn('No crypto symbols found in database');

            return self::FAILURE;
        }

        $cryptosPerJob = (int) ceil($totalCryptos / $parallelJobs);
        $processes = [];
        $startTime = microtime(true);

        $this->info("📊 Job distribution: {$totalCryptos} cryptos across {$parallelJobs} jobs (~{$cryptosPerJob} per job)");
        $this->newLine();

        // Start all parallel jobs
        for ($job = 0; $job < $parallelJobs; $job++) {
            $offset = $job * $cryptosPerJob;
            $limit = min($cryptosPerJob, $totalCryptos - $offset);

            if ($limit <= 0) {
                break; // No more cryptos to process
            }

            $this->info('🚀 Starting job '.($job + 1)." (offset: {$offset}, limit: {$limit})");

            $process = new Process([
                'php', 'artisan', 'market:yfinance-crypto-hourly-batch',
                (string) $hours,
                '--batch-size='.$batchSize,
                '--limit='.$limit,
                '--offset='.$offset,
            ], base_path());

            $process->start();
            $processes[$job] = $process;

            // Staggered start to avoid overwhelming the API
            $delay = $conservative ? 2.0 : 1.0;
            if ($job < $parallelJobs - 1) {
                $this->info("⏳ Waiting {$delay}s before starting next job...");
                sleep((int) $delay);
            }
        }

        $this->newLine();
        $this->info('⏱️  Waiting for all jobs to complete...');

        // Wait for all processes and collect results
        $failedJobs = 0;
        foreach ($processes as $jobId => $process) {
            $process->wait();

            if ($process->isSuccessful()) {
                $this->info('✅ Job '.($jobId + 1).' completed successfully');
            } else {
                $this->error('❌ Job '.($jobId + 1).' failed');
                $this->error('Error output: '.$process->getErrorOutput());
                $failedJobs++;
            }
        }

        $duration = round(microtime(true) - $startTime, 2);
        $successfulJobs = count($processes) - $failedJobs;

        $this->newLine();
        $this->info("🏁 Parallel sync completed in {$duration}s");
        $this->info("📊 Results: {$successfulJobs}/{".count($processes).'} jobs successful');

        if ($failedJobs > 0) {
            $this->error("⚠️  {$failedJobs} jobs failed - some crypto data may be incomplete");

            return self::FAILURE;
        }

        $this->info('✅ All crypto hourly sync jobs completed successfully');
        $this->info('💰 Ultra-efficient: Batch processing achieved massive API reduction');

        return self::SUCCESS;
    }
}
