<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class BackfillFiveMinutePricesFull extends Command
{
    private const MAX_PLACEHOLDERS_PER_INSERT = 65000;

    protected $signature = 'trading:backfill-five-minute-prices-full
        {--symbol= : Restrict to a single symbol}
        {--asset-type= : Restrict to a single asset type (stock|crypto)}
        {--from= : Only include rows with ts >= this UTC datetime}
        {--to= : Only include rows with ts <= this UTC datetime}
        {--chunk=5000 : Number of rows to insert per batch}
        {--limit=0 : Maximum number of missing rows to insert}
        {--dry-run : Show how many rows would be inserted without writing}';

    protected $description = 'Insert missing rows from five_minute_prices into five_minute_prices_full';

    /**
     * @var list<string>
     */
    private array $columns = [
        'symbol',
        'asset_type',
        'source',
        'ts',
        'price',
        'vwap',
        'vwap_dist',
        'vwap_dist_pct',
        'above_vwap',
        'ema9',
        'ema21',
        'ema9_ema21_spread',
        'ema9_above_ema21',
        'atr',
        'atr_pct',
        'rsi_14',
        'bb_upper',
        'bb_middle',
        'bb_lower',
        'open',
        'high',
        'low',
        'volume',
        'change_from_open',
        'relative_volume',
        'created_at',
        'updated_at',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $assetType = $this->option('asset-type');
        if ($assetType !== null && ! in_array($assetType, ['stock', 'crypto'], true)) {
            $this->error('--asset-type must be stock or crypto');

            return self::FAILURE;
        }

        $requestedChunkSize = max(1, (int) $this->option('chunk'));
        $maxSafeChunkSize = max(1, intdiv(self::MAX_PLACEHOLDERS_PER_INSERT, count($this->columns)));
        $chunkSize = min($requestedChunkSize, $maxSafeChunkSize);
        $baseQuery = $this->missingRowsQuery();
        $missingCount = (clone $baseQuery)->count();

        if ($missingCount === 0) {
            $this->info('No missing five-minute rows found in five_minute_prices_full.');

            return self::SUCCESS;
        }

        $limit = max(0, (int) $this->option('limit'));
        $insertCount = min($missingCount, $limit > 0 ? $limit : $missingCount);

        $this->info("Missing five-minute rows found: {$missingCount}");
        $this->line('Source: five_minute_prices');
        $this->line('Target: five_minute_prices_full');
        $this->line('Rows selected for this run: '.$insertCount);
        if ($chunkSize !== $requestedChunkSize) {
            $this->warn("Requested chunk {$requestedChunkSize} is too large for a single insert; using safe chunk {$chunkSize}.");
        }
        $this->line('Chunk size: '.$chunkSize);

        if ($this->option('dry-run')) {
            $this->warn('[DRY RUN] No rows were inserted.');

            return self::SUCCESS;
        }

        $processed = 0;
        $insertedTotal = 0;
        $lastSourceId = 0;
        $batchNumber = 0;

        while ($processed < $insertCount) {
            $remaining = $insertCount - $processed;
            $batchLimit = min($chunkSize, $remaining);

            $batchRows = (clone $baseQuery)
                ->where('src.id', '>', $lastSourceId)
                ->limit($batchLimit)
                ->get(array_merge(
                    ['src.id as source_id'],
                    array_map(fn (string $column): string => "src.{$column}", $this->columns)
                ));

            if ($batchRows->isEmpty()) {
                break;
            }

            $batchNumber++;
            $lastSourceId = (int) $batchRows->last()->source_id;
            $payload = $batchRows
                ->map(function (object $row): array {
                    $values = [];
                    foreach ($this->columns as $column) {
                        $values[$column] = $row->{$column};
                    }

                    return $values;
                })
                ->all();

            DB::table('five_minute_prices_full')->insertOrIgnore($payload);

            $batchInserted = count($payload);
            $processed += $batchInserted;
            $insertedTotal += $batchInserted;

            $this->line("Batch {$batchNumber}: inserted {$batchInserted} rows (processed {$processed}/{$insertCount})");
        }

        $this->info('Insert complete.');
        $this->line('Rows inserted: '.$insertedTotal);

        // Prune source table: delete rows older than 45 days
        $cutoff = now()->subDays(45)->format('Y-m-d H:i:s');
        $deleted = DB::table('five_minute_prices')->where('ts', '<', $cutoff)->delete();
        $this->info("Pruned {$deleted} rows older than 45 days from five_minute_prices.");

        return self::SUCCESS;
    }

    private function missingRowsQuery(): Builder
    {
        $query = DB::table('five_minute_prices as src')
            ->leftJoin('five_minute_prices_full as dest', function ($join): void {
                $join->on('dest.symbol', '=', 'src.symbol')
                    ->on('dest.asset_type', '=', 'src.asset_type')
                    ->on('dest.ts', '=', 'src.ts');
            })
            ->whereNull('dest.id')
            ->orderBy('src.id');

        if ($symbol = $this->option('symbol')) {
            $query->where('src.symbol', strtoupper((string) $symbol));
        }

        if ($assetType = $this->option('asset-type')) {
            $query->where('src.asset_type', $assetType);
        }

        if ($from = $this->option('from')) {
            $query->where('src.ts', '>=', $from);
        }

        if ($to = $this->option('to')) {
            $query->where('src.ts', '<=', $to);
        }

        return $query;
    }
}
