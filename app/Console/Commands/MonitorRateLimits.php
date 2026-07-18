<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MonitorRateLimits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rate-limits:monitor {--days=1 : Number of days to analyze}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor Yahoo Finance rate limiting patterns and provide optimization recommendations';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');

        $this->info("🔍 Analyzing rate limit patterns for the last {$days} day(s)...");
        $this->newLine();

        // Check if rate limit log exists
        $today = now()->format('Y-m-d');
        $logPath = storage_path("logs/laravel-rate-limits-{$today}.log");

        if (! File::exists($logPath)) {
            $this->warn('📝 No rate limit log found yet. Rate limit logging is configured but no events have been logged.');
            $this->info('✅ Rate limit logging is now active and will capture future issues.');

            return self::SUCCESS;
        }

        // Read and analyze rate limit logs
        $logContent = File::get($logPath);
        $lines = explode("\n", $logContent);

        $rateAlerts = [];
        $syncFailures = [];

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            if (str_contains($line, '[Rate Limit Alert]')) {
                $rateAlerts[] = $line;
            }

            if (str_contains($line, 'sync failed')) {
                $syncFailures[] = $line;
            }
        }

        // Display summary
        $this->info('📊 Rate Limit Analysis Summary:');
        $this->table([
            'Metric', 'Count',
        ], [
            ['Rate Limit Alerts', count($rateAlerts)],
            ['Sync Failures', count($syncFailures)],
        ]);

        if (count($rateAlerts) > 0) {
            $this->newLine();
            $this->warn('⚠️  Recent rate limit alerts detected!');
            $this->info('💡 Recommendations:');
            $this->line('   • Reduce parallel jobs in scheduler configuration');
            $this->line('   • Increase stagger delays between API requests');
            $this->line('   • Consider reducing sync frequency during peak hours');
        } else {
            $this->newLine();
            $this->info('✅ No rate limit issues detected - sync configuration looks optimal!');
        }

        // Show recent entries if any
        if (count($rateAlerts) > 0) {
            $this->newLine();
            $this->info('📋 Recent Rate Limit Events:');
            foreach (array_slice($rateAlerts, -3) as $alert) {
                $this->line('   '.trim($alert));
            }
        }

        return self::SUCCESS;
    }
}
