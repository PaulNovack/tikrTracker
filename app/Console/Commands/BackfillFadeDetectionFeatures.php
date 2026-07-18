<?php

namespace App\Console\Commands;

use App\Services\Trading\FadeDetectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillFadeDetectionFeatures extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alerts:backfill-fade-detection
                            {--start-date= : Start date (Y-m-d)}
                            {--end-date= : End date (Y-m-d)}
                            {--pipeline= : Pipeline run filter (e.g. L)}
                            {--limit=1000 : Number of alerts to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill fade detection features for existing trade alerts';

    /**
     * Execute the console command.
     */
    public function handle(FadeDetectionService $fadeService)
    {
        $startDate = $this->option('start-date');
        $endDate = $this->option('end-date');
        $pipeline = $this->option('pipeline');
        $limit = (int) $this->option('limit');

        $this->info('Starting fade detection feature backfill...');

        $query = DB::table('trade_alerts')
            ->whereNull('pct_below_intraday_high')
            ->whereNotNull('entry_ts_est');

        if ($startDate) {
            $query->where('entry_ts_est', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('entry_ts_est', '<=', $endDate);
        }

        if ($pipeline) {
            $query->where('pipeline_run', strtoupper((string) $pipeline));
        }

        $alerts = $query->limit($limit)->get();

        $this->info("Found {$alerts->count()} alerts to process");

        $progressBar = $this->output->createProgressBar($alerts->count());
        $progressBar->start();

        $updated = 0;
        $errors = 0;

        foreach ($alerts as $alert) {
            try {
                $features = $fadeService->calculateFadeFeatures(
                    $alert->symbol,
                    $alert->asset_type,
                    $alert->entry_ts_est,
                    30 // lookback minutes
                );

                DB::table('trade_alerts')
                    ->where('id', $alert->id)
                    ->update($features);

                $updated++;
            } catch (\Exception $e) {
                $errors++;
                $this->error("\nError processing alert {$alert->id}: ".$e->getMessage());
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info('✅ Backfill complete!');
        $this->info("Updated: {$updated}");
        $this->info("Errors: {$errors}");

        return Command::SUCCESS;
    }
}
