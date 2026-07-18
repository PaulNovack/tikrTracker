<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MarketRotationMonitor extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:rotation-monitor 
                            {--detailed : Show detailed per-symbol statistics}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor the performance and coverage of the rotating sync system';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $detailed = $this->option('detailed');

        $this->info('📊 Market Data Rotation Monitor');
        $this->info('================================');

        // Get rotation status
        $this->displayRotationStatus();

        // Get data freshness statistics
        $this->displayDataFreshness();

        // Get coverage statistics
        $this->displayCoverageStats();

        if ($detailed) {
            $this->displayDetailedStats();
        }

        return self::SUCCESS;
    }

    private function displayRotationStatus(): void
    {
        $totalSymbols = DB::table('asset_info')
            ->where('asset_type', 'stock')
            ->whereNull('deleted_at')
            ->count();

        $chunkSize = 200; // Default chunk size
        $totalChunks = (int) ceil($totalSymbols / $chunkSize);
        $currentChunk = Cache::get('yfinance_rotation_chunk', 0);

        $this->info("\n🔄 Rotation Status:");
        $this->line("   Total symbols: {$totalSymbols}");
        $this->line('   Current chunk: '.($currentChunk + 1)."/{$totalChunks}");

        $progress = round(($currentChunk / $totalChunks) * 100, 1);
        $this->line("   Progress: {$progress}%");

        $estimatedMinutes = ($totalChunks - $currentChunk) * 5;
        $estimatedHours = round($estimatedMinutes / 60, 1);
        $this->line("   Time to completion: ~{$estimatedHours} hours");
    }

    private function displayDataFreshness(): void
    {
        $this->info("\n📈 Data Freshness (5-minute intervals):");

        // Get symbols with recent data (last 2 hours)
        $recentCount = DB::table('five_minute_prices')
            ->where('asset_type', 'stock')
            ->where('ts', '>', now()->subHours(2))
            ->distinct('symbol')
            ->count();

        // Get symbols with today's data
        $todayCount = DB::table('five_minute_prices')
            ->where('asset_type', 'stock')
            ->whereDate('ts', today())
            ->distinct('symbol')
            ->count();

        // Get symbols with any data
        $totalWithData = DB::table('five_minute_prices')
            ->where('asset_type', 'stock')
            ->distinct('symbol')
            ->count();

        $totalSymbols = DB::table('asset_info')
            ->where('asset_type', 'stock')
            ->whereNull('deleted_at')
            ->count();

        $this->line("   Recent (last 2 hours): {$recentCount} symbols");
        $this->line("   Today's data: {$todayCount} symbols");
        $this->line("   Total with data: {$totalWithData}/{$totalSymbols}");

        $coverage = round(($totalWithData / $totalSymbols) * 100, 1);
        $this->line("   Overall coverage: {$coverage}%");
    }

    private function displayCoverageStats(): void
    {
        $this->info("\n📊 Coverage Statistics:");

        // Get latest update times
        $oldestUpdate = DB::table('five_minute_prices')
            ->where('asset_type', 'stock')
            ->min('ts');

        $newestUpdate = DB::table('five_minute_prices')
            ->where('asset_type', 'stock')
            ->max('ts');

        if ($oldestUpdate && $newestUpdate) {
            $this->line("   Oldest data: {$oldestUpdate}");
            $this->line("   Newest data: {$newestUpdate}");
        }

        // Get symbols that might need attention (no recent data)
        $staleSymbols = DB::table('asset_info')
            ->leftJoin('five_minute_prices', function ($join) {
                $join->on('asset_info.symbol', '=', 'five_minute_prices.symbol')
                    ->where('five_minute_prices.asset_type', '=', 'stock')
                    ->where('five_minute_prices.ts', '>', now()->subHours(24));
            })
            ->where('asset_info.asset_type', 'stock')
            ->whereNull('asset_info.deleted_at')
            ->whereNull('five_minute_prices.symbol')
            ->count();

        if ($staleSymbols > 0) {
            $this->warn("   ⚠️  Symbols without recent data (24h): {$staleSymbols}");
        } else {
            $this->line('   ✅ All symbols have recent data');
        }
    }

    private function displayDetailedStats(): void
    {
        $this->info("\n🔍 Detailed Statistics:");

        // Top 10 most recently updated symbols
        $recentSymbols = DB::table('five_minute_prices')
            ->select('symbol', DB::raw('MAX(ts) as latest_update'), DB::raw('COUNT(*) as record_count'))
            ->where('asset_type', 'stock')
            ->groupBy('symbol')
            ->orderBy('latest_update', 'desc')
            ->limit(10)
            ->get();

        $this->line("\n   📈 Most Recently Updated:");
        foreach ($recentSymbols as $symbol) {
            $this->line("      {$symbol->symbol}: {$symbol->latest_update} ({$symbol->record_count} records)");
        }

        // Symbols needing updates
        $staleSymbols = DB::table('asset_info')
            ->leftJoin('five_minute_prices', function ($join) {
                $join->on('asset_info.symbol', '=', 'five_minute_prices.symbol')
                    ->where('five_minute_prices.asset_type', '=', 'stock')
                    ->where('five_minute_prices.ts', '>', now()->subHours(6));
            })
            ->where('asset_info.asset_type', 'stock')
            ->whereNull('asset_info.deleted_at')
            ->whereNull('five_minute_prices.symbol')
            ->select('asset_info.symbol', 'asset_info.common_name')
            ->limit(10)
            ->get();

        if ($staleSymbols->isNotEmpty()) {
            $this->line("\n   ⏰ Symbols Needing Updates (no data in 6h):");
            foreach ($staleSymbols as $symbol) {
                $this->line("      {$symbol->symbol}: {$symbol->common_name}");
            }
        }
    }
}
