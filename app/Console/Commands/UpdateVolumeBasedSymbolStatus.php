<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateVolumeBasedSymbolStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'symbols:update-volume-status 
                            {--dry-run : Show what would be updated without making changes}
                            {--min-dollar-volume=5000000 : Minimum daily dollar volume threshold (default $5M)}
                            {--days-back=30 : Days to look back for volume analysis}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update symbol status based on trading dollar volume - institutional grade liquidity ($5M+ daily dollar volume)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $minDollarVolume = (float) $this->option('min-dollar-volume');
        $daysBack = (int) $this->option('days-back');

        $this->info('Analyzing symbol dollar volume status...');
        $this->info('Min daily dollar volume threshold: $'.number_format($minDollarVolume / 1000000, 1).'M');
        $this->info("Analysis period: {$daysBack} days");
        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }
        $this->newLine();

        // Get symbols with recent price data and their dollar volume
        $allSymbolsWithVolumeData = $this->getAllSymbolVolumeData($daysBack);
        $symbolsWithGoodVolume = collect($allSymbolsWithVolumeData)->where('avg_daily_dollar_volume', '>=', $minDollarVolume);

        // Get symbols currently marked as active
        $allActiveSymbols = DB::table('asset_info')
            ->where('deleted_at', null)
            ->where('asset_type', 'stock')
            ->get();

        $symbolsWithGoodVolumeSymbols = $symbolsWithGoodVolume->pluck('symbol')->toArray();

        $toSoftDelete = [];
        $toRestore = [];

        // Check active symbols that don't meet threshold - get their volume data if available
        foreach ($allActiveSymbols as $activeSymbol) {
            if (! in_array($activeSymbol->symbol, $symbolsWithGoodVolumeSymbols)) {
                // Find volume data for this symbol
                $volumeData = collect($allSymbolsWithVolumeData)->firstWhere('symbol', $activeSymbol->symbol);
                if ($volumeData) {
                    // Merge the asset_info data with volume data
                    $combinedData = (object) array_merge((array) $activeSymbol, (array) $volumeData);
                    $toSoftDelete[] = $combinedData;
                } else {
                    // No volume data means no recent trading - also soft delete
                    $activeSymbol->avg_daily_dollar_volume = 0;
                    $toSoftDelete[] = $activeSymbol;
                }
            }
        }

        // Check symbols that meet threshold but are inactive or not marked over_1mil
        foreach ($symbolsWithGoodVolume as $symbol) {
            $isCurrentlyActive = (bool) ! $symbol->deleted_at;
            $isCurrentlyOver1Mil = (bool) $symbol->over_1mil;

            if (! $isCurrentlyActive || ! $isCurrentlyOver1Mil) {
                $toRestore[] = $symbol;
            }
        }

        $this->displayAnalysis($toSoftDelete, $toRestore, $minDollarVolume);

        if (! $dryRun && ($toSoftDelete || $toRestore)) {
            if ($this->confirm('Apply these changes?')) {
                $this->applyChanges($toSoftDelete, $toRestore, $minDollarVolume);
            } else {
                $this->info('Operation cancelled.');
            }
        }

        return Command::SUCCESS;
    }

    private function getAllSymbolVolumeData(int $daysBack): array
    {
        $cutoffDate = now()->subDays($daysBack)->toDateString();

        return DB::select("
            SELECT 
                ai.symbol,
                ai.asset_type,
                ai.deleted_at,
                ai.over_1mil,
                ai.common_name,
                vol_data.avg_daily_volume,
                vol_data.avg_daily_price,
                vol_data.avg_daily_dollar_volume,
                vol_data.days_traded,
                vol_data.last_trade_date
            FROM asset_info ai
            LEFT JOIN (
                SELECT 
                    symbol,
                    asset_type,
                    AVG(daily_volume) as avg_daily_volume,
                    AVG(avg_daily_price) as avg_daily_price,
                    AVG(daily_dollar_volume) as avg_daily_dollar_volume,
                    COUNT(*) as days_traded,
                    MAX(trade_date) as last_trade_date
                FROM (
                    SELECT 
                        symbol,
                        asset_type,
                        DATE(ts_est) as trade_date,
                        SUM(volume) as daily_volume,
                        AVG(price) as avg_daily_price,
                        SUM(volume * price) as daily_dollar_volume
                    FROM five_minute_prices 
                    WHERE DATE(ts_est) >= ?
                    GROUP BY symbol, asset_type, DATE(ts_est)
                ) daily_volumes
                GROUP BY symbol, asset_type
                HAVING COUNT(*) >= 3
            ) vol_data ON ai.symbol = vol_data.symbol AND ai.asset_type = vol_data.asset_type
            WHERE ai.asset_type = 'stock'
              AND vol_data.avg_daily_dollar_volume IS NOT NULL
            ORDER BY vol_data.avg_daily_dollar_volume DESC
        ", [$cutoffDate]);
    }

    private function displayAnalysis($toSoftDelete, $toRestore, $minDollarVolume): void
    {
        if ($toSoftDelete) {
            $this->error('Symbols to SOFT DELETE (below $'.number_format($minDollarVolume).' daily dollar volume):');
            $this->table(
                ['Symbol', 'Name', 'Avg Daily Dollar Volume', 'Currently Active'],
                collect($toSoftDelete)->map(fn ($s) => [
                    $s->symbol,
                    substr($s->common_name ?? 'N/A', 0, 40),
                    '$'.number_format($s->avg_daily_dollar_volume ?? 0, 0),
                    $s->deleted_at ? 'No' : 'Yes',
                ])->toArray()
            );
            $this->newLine();
        }

        if ($toRestore) {
            $this->info('Symbols to RESTORE/ACTIVATE (above $'.number_format($minDollarVolume).' daily dollar volume):');
            $this->table(
                ['Symbol', 'Name', 'Avg Daily Dollar Volume', 'Currently Active', 'Currently Over 1M'],
                collect($toRestore)->map(fn ($s) => [
                    $s->symbol,
                    substr($s->common_name ?? 'N/A', 0, 40),
                    '$'.number_format($s->avg_daily_dollar_volume ?? 0, 0),
                    $s->deleted_at ? 'No' : 'Yes',
                    $s->over_1mil ? 'Yes' : 'No',
                ])->toArray()
            );
            $this->newLine();
        }

        if (! $toSoftDelete && ! $toRestore) {
            $this->info('No changes needed. All symbols are correctly classified.');
        }
    }

    private function applyChanges($toSoftDelete, $toRestore, $minDollarVolume): void
    {
        DB::beginTransaction();

        try {
            // Soft delete low volume symbols
            foreach ($toSoftDelete as $symbol) {
                DB::table('asset_info')
                    ->where('symbol', $symbol->symbol)
                    ->where('asset_type', $symbol->asset_type)
                    ->update([
                        'deleted_at' => now(),
                        'over_1mil' => false,
                        'reason_for_delete' => 'Low liquidity - trading under $'.number_format($minDollarVolume).' daily average',
                    ]);

                $this->line("✗ Soft deleted: {$symbol->symbol} (avg: $".number_format($symbol->avg_daily_dollar_volume ?? 0, 0).')');
            }

            // Restore/activate high volume symbols
            foreach ($toRestore as $symbol) {
                DB::table('asset_info')
                    ->where('symbol', $symbol->symbol)
                    ->where('asset_type', $symbol->asset_type)
                    ->update([
                        'deleted_at' => null,
                        'over_1mil' => true,
                        'reason_for_delete' => 'restored better than $'.number_format($minDollarVolume).' average volume',
                    ]);

                $this->line("✓ Restored: {$symbol->symbol} (avg: $".number_format($symbol->avg_daily_dollar_volume ?? 0, 0).')');
            }

            DB::commit();

            $this->newLine();
            $this->info('Successfully updated '.(count($toSoftDelete) + count($toRestore)).' symbols');

            if ($toSoftDelete) {
                $this->warn('Soft deleted '.count($toSoftDelete).' low volume symbols');
            }
            if ($toRestore) {
                $this->info('Restored '.count($toRestore).' high volume symbols');
            }

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Error updating symbols: '.$e->getMessage());

            return;
        }
    }
}
