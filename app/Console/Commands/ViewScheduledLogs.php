<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ViewScheduledLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logs:scheduled 
                            {--lines=50 : Number of lines to show}
                            {--follow : Follow the log file (like tail -f)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'View scheduled jobs log file (laravel-scheduled.log)';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $lines = $this->option('lines');
        $follow = $this->option('follow');

        $logPath = storage_path('logs/laravel-scheduled-'.now()->format('Y-m-d').'.log');

        if (! file_exists($logPath)) {
            $this->error("Scheduled log file not found: {$logPath}");
            $this->info('No scheduled commands have run today or logging is not configured.');

            return;
        }

        if ($follow) {
            $this->info('Following scheduled log file (Ctrl+C to exit):');
            $this->info("File: {$logPath}");
            $this->newLine();

            passthru('tail -f '.escapeshellarg($logPath));
        } else {
            $this->info("Last {$lines} lines from scheduled log:");
            $this->info("File: {$logPath}");
            $this->newLine();

            $output = shell_exec('tail -n '.escapeshellarg($lines).' '.escapeshellarg($logPath));
            $this->line($output ?: 'No log entries found.');
        }
    }
}
