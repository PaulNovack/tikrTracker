<?php

namespace App\Console\Commands;

use App\Services\SymbolBlacklistService;
use Illuminate\Console\Command;

class AnalyzeSymbolFailures extends Command
{
    protected $signature = 'symbols:analyze-failures 
                            {--days=7 : Number of days to analyze}
                            {--auto-blacklist : Automatically blacklist qualifying symbols}
                            {--show-recent : Show recently blacklisted symbols}';

    protected $description = 'Analyze symbol failures and identify blacklist candidates';

    public function handle(SymbolBlacklistService $blacklistService): int
    {
        $this->info('🔍 Analyzing Symbol Failures');
        $this->newLine();

        // Show recently blacklisted symbols
        if ($this->option('show-recent')) {
            $this->showRecentlyBlacklisted($blacklistService);

            return 0;
        }

        // Get blacklist candidates
        $candidates = $blacklistService->getBlacklistCandidates();

        if (empty($candidates)) {
            $this->info('✅ No symbols currently qualify for blacklisting.');

            return 0;
        }

        $this->warn("⚠️  Found {count($candidates)} symbols that may need blacklisting:");
        $this->newLine();

        // Display candidates in table format
        $tableData = [];
        foreach ($candidates as $candidate) {
            $tableData[] = [
                'Symbol' => $candidate['symbol'],
                'Type' => $candidate['asset_type'],
                'Failure Type' => $candidate['failure_type'],
                'Count' => $candidate['consecutive_count'],
                'First Failure' => $candidate['first_failure_at'],
                'Last Failure' => $candidate['last_failure_at'],
                'Error' => substr($candidate['error_message'], 0, 50).'...',
            ];
        }

        $this->table([
            'Symbol', 'Type', 'Failure Type', 'Count', 'First Failure', 'Last Failure', 'Error',
        ], $tableData);

        // Auto-blacklist if requested
        if ($this->option('auto-blacklist')) {
            $this->newLine();
            $this->info('🚫 Auto-blacklisting qualifying symbols...');

            foreach ($candidates as $candidate) {
                $failure = \App\Models\SymbolFailure::find($candidate['id']);
                if ($failure && $failure->shouldAutoBlacklist()) {
                    $blacklistService->recordFailure(
                        $candidate['symbol'],
                        $candidate['asset_type'],
                        $candidate['error_message'],
                        'consecutive_failures'
                    );
                    $this->line("  ✓ Blacklisted {$candidate['symbol']} ({$candidate['asset_type']})");
                }
            }
        } else {
            $this->newLine();
            $this->comment('💡 Run with --auto-blacklist to automatically blacklist qualifying symbols');
        }

        return 0;
    }

    private function showRecentlyBlacklisted(SymbolBlacklistService $blacklistService): void
    {
        $days = (int) $this->option('days');
        $recent = $blacklistService->getRecentlyBlacklisted($days);

        if (empty($recent)) {
            $this->info("✅ No symbols auto-blacklisted in the last {$days} days.");

            return;
        }

        $this->info("🚫 Recently Auto-Blacklisted Symbols (Last {$days} days):");
        $this->newLine();

        $tableData = [];
        foreach ($recent as $item) {
            $tableData[] = [
                'Symbol' => $item['symbol'],
                'Type' => $item['asset_type'],
                'Failure Type' => $item['failure_type'],
                'Count' => $item['consecutive_count'],
                'Blacklisted' => $item['blacklisted_at'],
                'Reason' => $item['asset_info']['reason_for_delete'] ?? 'Unknown',
            ];
        }

        $this->table([
            'Symbol', 'Type', 'Failure Type', 'Count', 'Blacklisted', 'Reason',
        ], $tableData);
    }
}
