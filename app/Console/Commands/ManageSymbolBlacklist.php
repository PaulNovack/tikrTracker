<?php

namespace App\Console\Commands;

use App\Models\AssetInfo;
use App\Services\SymbolBlacklistService;
use Illuminate\Console\Command;

class ManageSymbolBlacklist extends Command
{
    protected $signature = 'symbols:blacklist 
                            {action : Action to perform (blacklist|restore|status)}
                            {symbol : Symbol to manage}
                            {--type=stock : Asset type (stock|crypto)}
                            {--reason= : Reason for blacklisting/restoration}';

    protected $description = 'Manually manage symbol blacklisting';

    public function handle(SymbolBlacklistService $blacklistService): int
    {
        $action = $this->argument('action');
        $symbol = strtoupper($this->argument('symbol'));
        $assetType = $this->option('type');

        match ($action) {
            'blacklist' => $this->blacklistSymbol($symbol, $assetType),
            'restore' => $this->restoreSymbol($blacklistService, $symbol, $assetType),
            'status' => $this->showSymbolStatus($symbol, $assetType),
            default => $this->error('Invalid action. Use: blacklist, restore, or status')
        };

        return 0;
    }

    private function blacklistSymbol(string $symbol, string $assetType): void
    {
        $reason = $this->option('reason') ?? $this->ask('Enter reason for blacklisting');

        if (empty($reason)) {
            $this->error('Reason is required for blacklisting.');

            return;
        }

        $asset = AssetInfo::where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->first();

        if (! $asset) {
            $this->error("Symbol {$symbol} ({$assetType}) not found in database.");

            return;
        }

        if ($asset->trashed()) {
            $this->warn("Symbol {$symbol} is already blacklisted.");
            $this->line("Current reason: {$asset->reason_for_delete}");

            return;
        }

        $asset->update([
            'reason_for_delete' => $reason,
            'deleted_at' => now(),
        ]);

        $this->info("✅ Successfully blacklisted {$symbol} ({$assetType})");
        $this->line("Reason: {$reason}");
    }

    private function restoreSymbol(SymbolBlacklistService $blacklistService, string $symbol, string $assetType): void
    {
        $reason = $this->option('reason') ?? $this->ask('Enter reason for restoration', 'Manual restoration');

        if ($blacklistService->restoreSymbol($symbol, $assetType, $reason)) {
            $this->info("✅ Successfully restored {$symbol} ({$assetType})");
            $this->line("Reason: {$reason}");
        } else {
            $this->error("Failed to restore {$symbol} ({$assetType})");
        }
    }

    private function showSymbolStatus(string $symbol, string $assetType): void
    {
        // Check asset info
        $asset = AssetInfo::withTrashed()
            ->where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->first();

        if (! $asset) {
            $this->error("Symbol {$symbol} ({$assetType}) not found in database.");

            return;
        }

        $this->info("Symbol Status for {$symbol} ({$assetType}):");
        $this->newLine();

        // Asset info status
        if ($asset->trashed()) {
            $this->line('🚫 Status: <fg=red>BLACKLISTED</>');
            $this->line("   Reason: {$asset->reason_for_delete}");
            $this->line("   Blacklisted: {$asset->deleted_at}");
        } else {
            $this->line('✅ Status: <fg=green>ACTIVE</>');
            $this->line('   Over $1M volume: '.($asset->over_1mil ? 'Yes' : 'No'));
            $this->line("   Sector: {$asset->sector}");
        }

        // Check failure history
        $failures = \App\Models\SymbolFailure::where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($failures->isNotEmpty()) {
            $this->newLine();
            $this->line('📊 Recent Failure History:');

            foreach ($failures as $failure) {
                $this->line("   • {$failure->failure_type}: {$failure->consecutive_count} consecutive failures");
                $this->line("     Last: {$failure->last_failure_at} | Auto-blacklisted: ".($failure->auto_blacklisted ? 'Yes' : 'No'));
                $this->line('     Error: '.substr($failure->error_message, 0, 80).'...');
                $this->newLine();
            }
        } else {
            $this->newLine();
            $this->line('📈 No recent failures recorded.');
        }
    }
}
