<?php

namespace App\Console\Commands;

use App\Models\AssetInfo;
use App\Models\Notification;
use Illuminate\Console\Command;

class BackfillNotificationAssetIds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:backfill-asset-ids {--dry-run : Show what would be updated without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill asset_id for existing notifications by extracting symbols from their descriptions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->info('DRY RUN MODE: No changes will be made');
        }

        $notifications = Notification::whereNull('asset_id')->get();
        $updated = 0;
        $failed = 0;

        $this->info("Processing {$notifications->count()} notifications...");

        foreach ($notifications as $notification) {
            // Extract symbol from notification description
            // Look for patterns like "AAPL has reached" or "TSLA price alert"
            preg_match('/([A-Z]{1,5})\s+(has reached|price alert|has)/', $notification->description, $matches);

            if (empty($matches[1])) {
                // Try alternative pattern: "🔴 SYMBOL" or "🟢 SYMBOL"
                preg_match('/[🔴🟢🔵🟡⚪⚫]\s*([A-Z]{1,5})/', $notification->description, $matches);
            }

            if (empty($matches[1])) {
                $this->warn("Could not extract symbol from: {$notification->description}");
                $failed++;

                continue;
            }

            $symbol = $matches[1];

            // Find asset_info record for this symbol
            $asset = AssetInfo::where('symbol', $symbol)->first();

            if (! $asset) {
                $this->warn("No asset found for symbol: {$symbol}");
                $failed++;

                continue;
            }

            if (! $isDryRun) {
                $notification->update(['asset_id' => $asset->id]);
            }

            $this->info("Updated notification {$notification->id}: {$symbol} -> asset_id {$asset->id}");
            $updated++;
        }

        $this->info("Completed: {$updated} updated, {$failed} failed");

        if ($isDryRun) {
            $this->info('Run without --dry-run to actually update the notifications');
        }

        return Command::SUCCESS;
    }
}
