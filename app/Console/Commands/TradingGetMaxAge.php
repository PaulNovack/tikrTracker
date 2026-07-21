<?php

namespace App\Console\Commands;

use App\Services\TradingSettingService;
use Illuminate\Console\Command;

class TradingGetMaxAge extends Command
{
    protected $signature = 'trading:get-max-age
                            {pipeline : Pipeline letter (a, b, c, etc.)}';

    protected $description = 'Get the max age in minutes for a pipeline (DB settings with config fallback)';

    public function handle(): int
    {
        $pipeline = strtolower((string) $this->argument('pipeline'));

        $minutes = TradingSettingService::getPipelineMaxAgeMinutes($pipeline);

        $this->line((string) $minutes);

        return self::SUCCESS;
    }
}
