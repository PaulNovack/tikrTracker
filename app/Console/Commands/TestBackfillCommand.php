<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TestBackfillCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:backfill';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test command';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Command is running!');

        return Command::SUCCESS;
    }
}
