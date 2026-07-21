<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class MonitorCriticalServices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:services {--restart : Auto-restart failed services}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor critical services (Reverb, Queue Worker) and optionally restart them';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔍 Checking critical services...');

        $issues = [];
        $autoRestart = $this->option('restart');

        // Check Reverb Server
        $reverbStatus = $this->checkReverbServer();
        if (! $reverbStatus['running']) {
            $issues[] = 'Reverb Server';
            $this->error('❌ Reverb Server: NOT RUNNING');

            if ($autoRestart) {
                $this->warn('🔄 Attempting to restart Reverb Server...');
                $this->restartReverbServer();
            }
        } else {
            $this->info('✅ Reverb Server: RUNNING (PID: '.$reverbStatus['pid'].')');
        }

        // Check Queue Worker
        $queueStatus = $this->checkQueueWorker();
        if (! $queueStatus['running']) {
            $issues[] = 'Queue Worker';
            $this->error('❌ Queue Worker: NOT RUNNING');

            if ($autoRestart) {
                $this->warn('🔄 Attempting to restart Queue Worker...');
                $this->restartQueueWorker();
            }
        } else {
            $this->info('✅ Queue Worker: RUNNING (PID: '.$queueStatus['pid'].')');
        }

        // Check Database Connection
        try {
            \DB::connection()->getPdo();
            $this->info('✅ Database: CONNECTED');
        } catch (\Exception $e) {
            $issues[] = 'Database';
            $this->error('❌ Database: CONNECTION FAILED - '.$e->getMessage());
        }

        // Check Broadcasting Configuration
        if ($this->checkBroadcastingConfig()) {
            $this->info('✅ Broadcasting: CONFIGURED');
        } else {
            $issues[] = 'Broadcasting Config';
            $this->error('❌ Broadcasting: MISCONFIGURED');
        }

        // Summary
        if (empty($issues)) {
            $this->info("\n🎉 All critical services are operational!");

            return 0;
        } else {
            $this->error("\n⚠️  Issues detected with: ".implode(', ', $issues));
            if (! $autoRestart) {
                $this->info('💡 Run with --restart flag to auto-restart failed services');
            }

            return 1;
        }
    }

    private function checkReverbServer(): array
    {
        $result = Process::run('pgrep -f "reverb:start"');

        return [
            'running' => $result->successful() && ! empty(trim($result->output())),
            'pid' => trim($result->output()) ?: null,
        ];
    }

    private function checkQueueWorker(): array
    {
        $result = Process::run('pgrep -f "queue:work"');

        return [
            'running' => $result->successful() && ! empty(trim($result->output())),
            'pid' => trim($result->output()) ?: null,
        ];
    }

    private function checkBroadcastingConfig(): bool
    {
        $driver = config('broadcasting.default');
        $reverbConfig = config('broadcasting.connections.reverb');

        return $driver === 'reverb' &&
               ! empty($reverbConfig['app_id']) &&
               ! empty($reverbConfig['key']) &&
               ! empty($reverbConfig['secret']) &&
               ! empty($reverbConfig['options']['host']);
    }

    private function restartReverbServer(): void
    {
        $this->warn('Reverb Server is down. Please restart it manually:');
        $this->info('Run: cd '.base_path().' && ./scripts/start-reverb.sh');
        $this->info('Or use: php artisan reverb:start --debug &');
    }

    private function restartQueueWorker(): void
    {
        $this->warn('Queue Worker is down. Please restart it manually:');
        $this->info('Run: cd '.base_path().' && ./scripts/start-queue.sh');
        $this->info('Or use: php artisan queue:work &');
    }
}
