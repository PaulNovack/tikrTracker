<?php

namespace App\Console\Commands;

use App\Services\CpuUsageService;
use Illuminate\Console\Command;

class CheckCpuUsage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cpu:check {--detailed : Show detailed CPU information}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check current CPU usage';

    /**
     * Execute the console command.
     */
    public function handle(CpuUsageService $cpuService): int
    {
        if ($this->option('detailed')) {
            $info = $cpuService->getDetailedInfo();

            $this->info('=== CPU Information ===');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['CPU Usage', $info['usage_percent'].'%'],
                    ['Load Average (1min)', $info['load_average_1min']],
                    ['Load Average (5min)', $info['load_average_5min']],
                    ['Load Average (15min)', $info['load_average_15min']],
                    ['CPU Cores', $info['cpu_cores']],
                    ['Timestamp', $info['timestamp']],
                ]
            );
        } else {
            $usage = $cpuService->getCurrentUsage();
            $this->info("Current CPU Usage: {$usage}%");

            if ($cpuService->isHighUsage(80.0)) {
                $this->warn('⚠️  CPU usage is high (above 80%)');
            }
        }

        return Command::SUCCESS;
    }
}
