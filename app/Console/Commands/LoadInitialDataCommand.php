<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LoadInitialDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:load-initial-data';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Load initial database schema from SQL file';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $schemaFile = storage_path('init-database/laravelInvestSchema.sql');

        if (! file_exists($schemaFile)) {
            $this->error("Schema file not found: {$schemaFile}");

            return self::FAILURE;
        }

        $this->info('Loading schema...');
        DB::unprepared(file_get_contents($schemaFile));
        $this->info('Schema loaded successfully.');

        return self::SUCCESS;
    }
}
