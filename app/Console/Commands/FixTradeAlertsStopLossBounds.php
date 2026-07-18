<?php

namespace App\Console\Commands;

use App\Services\TradingSettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixTradeAlertsStopLossBounds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fix:trade-alerts-stop-loss-bounds
        {--table=trade_alerts : Table to update (trade_alerts or trade_alerts_unfiltered)}
        {--dry-run : Show what would be changed without updating}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recompute all trade_alerts stop loss values using ATR and config bounds (0.5%-2.0%)';

    /**
     * Get ATR multiplier based on algorithm version
     */
    private function getAtrMultiplier(string $version): float
    {
        // v700.x uses its own config (now 2.0 to match standard)
        if (str_starts_with($version, 'v700')) {
            return (float) config('trading.v700.stop_loss_atr_multiplier', 2.0);
        }

        // All others use the DB-backed trading setting
        return (float) TradingSettingService::getStopLossAtrMultiplier();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $table = $this->option('table');
        $dryRun = (bool) $this->option('dry-run');

        $minPct = (float) TradingSettingService::getStopLossAtrMinPct();
        $maxPct = (float) TradingSettingService::getStopLossAtrMaxPct();

        $this->info('🔧 Recomputing Trade Alerts Stop Loss Values');
        $this->info("📊 Table: {$table}");
        $this->info("📉 Min bound: {$minPct}%");
        $this->info("📈 Max bound: {$maxPct}%");
        if ($dryRun) {
            $this->warn('🔍 DRY RUN - No changes will be made');
        }
        $this->newLine();

        // Get all alerts with required data for recomputation
        $alerts = DB::table($table)
            ->whereNotNull('atr')
            ->whereNotNull('entry')
            ->whereNotNull('version')
            ->where('atr', '>', 0)
            ->where('entry', '>', 0)
            ->get(['id', 'symbol', 'version', 'entry', 'atr', 'suggested_trailing_stop_pct', 'suggested_trailing_stop']);

        if ($alerts->isEmpty()) {
            $this->warn('No alerts found with required data (atr, entry, version)');

            return 0;
        }

        $this->info("Found {$alerts->count()} alerts to recompute...");
        $this->newLine();

        $updated = 0;
        $cappedMin = 0;
        $cappedMax = 0;
        $unchanged = 0;

        $progressBar = $this->output->createProgressBar($alerts->count());

        foreach ($alerts as $alert) {
            $entry = (float) $alert->entry;
            $atr = (float) $alert->atr;
            $version = (string) $alert->version;

            // Recompute using ATR formula
            $atrMultiplier = $this->getAtrMultiplier($version);
            $calculatedPct = (($atr * $atrMultiplier) / $entry) * 100.0;

            // Apply config bounds
            $boundedPct = max($minPct, min($maxPct, $calculatedPct));

            // Determine if capped
            $wasCappedMin = $calculatedPct < $minPct;
            $wasCappedMax = $calculatedPct > $maxPct;

            // Check if this is different from current value
            $currentPct = (float) ($alert->suggested_trailing_stop_pct ?? 0);
            $changed = abs($boundedPct - $currentPct) > 0.001;

            if ($changed) {
                $updated++;

                if ($wasCappedMin) {
                    $cappedMin++;
                } elseif ($wasCappedMax) {
                    $cappedMax++;
                }

                if (! $dryRun) {
                    // Recompute suggested_trailing_stop
                    $newTrailingStop = $entry * ($boundedPct / 100);

                    DB::table($table)
                        ->where('id', $alert->id)
                        ->update([
                            'suggested_trailing_stop_pct' => $boundedPct,
                            'suggested_trailing_stop' => $newTrailingStop,
                            'updated_at' => now(),
                        ]);
                }
            } else {
                $unchanged++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info('📊 Results:');
        $this->line("   Total alerts: {$alerts->count()}");
        $this->line("   Unchanged: {$unchanged}");
        $this->line("   Updated: {$updated}");
        $this->line("   - Capped to min ({$minPct}%): {$cappedMin}");
        $this->line("   - Capped to max ({$maxPct}%): {$cappedMax}");
        $this->newLine();

        if ($dryRun && $updated > 0) {
            $this->warn('This was a dry run. Run without --dry-run to apply changes.');
            $this->newLine();

            // Show some examples
            $this->info('Examples of changes that would be made:');
            $examples = 0;
            foreach ($alerts as $alert) {
                if ($examples >= 5) {
                    break;
                }

                $entry = (float) $alert->entry;
                $atr = (float) $alert->atr;
                $version = (string) $alert->version;

                $atrMultiplier = $this->getAtrMultiplier($version);
                $calculatedPct = (($atr * $atrMultiplier) / $entry) * 100.0;
                $boundedPct = max($minPct, min($maxPct, $calculatedPct));
                $currentPct = (float) ($alert->suggested_trailing_stop_pct ?? 0);

                if (abs($boundedPct - $currentPct) > 0.001) {
                    $this->line(sprintf('   %s: %.2f%% → %.2f%% (ATR: %.2f, Entry: %.2f, Mult: %.1fx)',
                        $alert->symbol,
                        $currentPct,
                        $boundedPct,
                        $atr,
                        $entry,
                        $atrMultiplier
                    ));
                    $examples++;
                }
            }
        } elseif (! $dryRun && $updated > 0) {
            $this->info("✅ Updated {$updated} alerts");
        }

        return 0;
    }
}
