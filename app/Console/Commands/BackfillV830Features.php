<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillV830Features extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backfill:v830-features
                            {--pipeline=* : Pipeline versions to backfill (e.g., v810.0, v830.0)}
                            {--limit= : Limit number of trades to process}
                            {--dry-run : Show what would be updated without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill daily_trend_5d_pct and range_position_60m for existing trade alerts';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $versions = $this->option('pipeline') ?: ['v810.0', 'v820.0', 'v830.0'];
        $limit = $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->info('Backfilling v830 features for versions: '.implode(', ', $versions));
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        foreach ($versions as $version) {
            $this->info("\nProcessing version: {$version}");
            $this->backfillVersion($version, $limit, $dryRun);
        }

        $this->info("\n✓ Backfill complete!");

        return 0;
    }

    private function backfillVersion(string $version, ?int $limit, bool $dryRun): void
    {
        $query = DB::table('trade_alerts as ta')
            ->select([
                'ta.id',
                'ta.symbol',
                'ta.asset_type',
                'ta.trading_date_est',
                'ta.entry_ts_est',
                'ta.entry',
            ])
            ->where('ta.version', $version)
            ->whereNotNull('ta.entry')
            ->where(function ($q) {
                $q->whereNull('daily_trend_5d_pct')
                    ->orWhereNull('range_position_60m');
            });

        if ($limit) {
            $query->limit($limit);
        }

        $trades = $query->get();

        if ($trades->isEmpty()) {
            $this->info("  No trades need backfilling for {$version}");

            return;
        }

        $this->info("  Found {$trades->count()} trades to backfill");

        $bar = $this->output->createProgressBar($trades->count());
        $bar->start();

        $updated = 0;
        $errors = 0;

        foreach ($trades as $trade) {
            try {
                // Calculate daily_trend_5d_pct
                $dailyTrend = $this->calculateDailyTrend(
                    $trade->symbol,
                    $trade->asset_type,
                    $trade->trading_date_est
                );

                // Calculate range_position_60m
                $rangePosition = $this->calculateRangePosition(
                    $trade->symbol,
                    $trade->asset_type,
                    $trade->entry_ts_est,
                    $trade->entry
                );

                if (! $dryRun) {
                    DB::table('trade_alerts')
                        ->where('id', $trade->id)
                        ->update([
                            'daily_trend_5d_pct' => $dailyTrend,
                            'range_position_60m' => $rangePosition,
                        ]);
                }

                $updated++;
            } catch (\Exception $e) {
                $errors++;
                $this->error("\n  Error processing trade {$trade->id}: ".$e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("  Updated: {$updated}, Errors: {$errors}");
    }

    private function calculateDailyTrend(string $symbol, string $assetType, string $tradingDate): ?float
    {
        $result = DB::table('daily_prices as dp')
            ->select([
                'dp.price as today_price',
                'dp5.price as price_5d_ago',
            ])
            ->leftJoin('daily_prices as dp5', function ($join) use ($symbol, $assetType) {
                $join->on('dp5.symbol', '=', DB::raw("'{$symbol}'"))
                    ->on('dp5.asset_type', '=', DB::raw("'{$assetType}'"))
                    ->on('dp5.date', '=', DB::raw('DATE_SUB(dp.date, INTERVAL 5 DAY)'));
            })
            ->where('dp.symbol', $symbol)
            ->where('dp.asset_type', $assetType)
            ->where('dp.date', $tradingDate)
            ->first();

        if (! $result || ! $result->price_5d_ago) {
            return null;
        }

        return round((($result->today_price - $result->price_5d_ago) / $result->price_5d_ago) * 100, 2);
    }

    private function calculateRangePosition(string $symbol, string $assetType, string $signalTs, float $entryPrice): ?float
    {
        // Get 60 minutes of 5-minute bars before signal (12 bars * 5 minutes = 60 minutes)
        $bars = DB::table('five_minute_prices')
            ->select(['price', 'high', 'low'])
            ->where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->where('ts_est', '<=', $signalTs)
            ->orderBy('ts_est', 'desc')
            ->limit(12)
            ->get();

        if ($bars->isEmpty()) {
            return null;
        }

        $rangeLow = $bars->min('low');
        $rangeHigh = $bars->max('high');

        if ($rangeHigh <= $rangeLow) {
            return 0.5; // No range, default to middle
        }

        $position = ($entryPrice - $rangeLow) / ($rangeHigh - $rangeLow);

        return round(max(0, min(1, $position)), 6);
    }
}
