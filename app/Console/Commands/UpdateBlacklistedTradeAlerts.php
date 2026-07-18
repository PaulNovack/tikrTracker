<?php

namespace App\Console\Commands;

use App\Trading\SymbolBlacklist;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateBlacklistedTradeAlerts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trade-alerts:update-blacklisted {--dry-run : Show what would be updated without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark existing trade alerts as blacklisted if their symbol is in the blacklist (73 symbols with 3+ losses and 70%+ ML confidence)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        $this->info('Blacklist contains '.SymbolBlacklist::count().' symbols');

        // Count total trade alerts
        $totalAlerts = DB::table('trade_alerts')->count();
        $this->info("Total trade alerts: {$totalAlerts}");

        // Get blacklisted symbols as array
        $blacklist = SymbolBlacklist::getBlacklist();

        // Count how many trade alerts have blacklisted symbols
        $affectedCount = DB::table('trade_alerts')
            ->whereIn('symbol', $blacklist)
            ->where('blacklisted', false)
            ->count();

        if ($affectedCount === 0) {
            $this->info('No trade alerts need to be updated.');

            return 0;
        }

        $this->warn("Found {$affectedCount} trade alerts with blacklisted symbols that need updating");

        if ($isDryRun) {
            // Show breakdown by symbol
            $breakdown = DB::table('trade_alerts')
                ->select('symbol', DB::raw('COUNT(*) as count'))
                ->whereIn('symbol', $blacklist)
                ->where('blacklisted', false)
                ->groupBy('symbol')
                ->orderBy('count', 'desc')
                ->get();

            $this->newLine();
            $this->info('Breakdown by symbol:');
            $this->table(
                ['Symbol', 'Count'],
                $breakdown->map(fn ($row) => [$row->symbol, $row->count])->toArray()
            );

            $this->newLine();
            $this->comment('DRY RUN: No changes were made. Run without --dry-run to update.');

            return 0;
        }

        // Confirm before updating
        if (! $this->confirm("Update {$affectedCount} trade alerts to mark them as blacklisted?", true)) {
            $this->info('Operation cancelled.');

            return 1;
        }

        // Update the records
        $updated = DB::table('trade_alerts')
            ->whereIn('symbol', $blacklist)
            ->where('blacklisted', false)
            ->update([
                'blacklisted' => true,
                'updated_at' => now(),
            ]);

        $this->info("Successfully marked {$updated} trade alerts as blacklisted");

        // Show final stats
        $totalBlacklisted = DB::table('trade_alerts')->where('blacklisted', true)->count();
        $this->info("Total blacklisted trade alerts: {$totalBlacklisted}");

        return 0;
    }
}
