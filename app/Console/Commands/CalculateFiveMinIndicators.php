<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CalculateFiveMinIndicators extends Command
{
    protected $signature = 'indicators:calculate-5m
        {--symbols= : Comma-separated symbols to process (default: all active)}
        {--days=2 : Number of days back to calculate}
        {--chunk=50 : Symbols to process per batch}';

    protected $description = 'Calculate RSI, Bollinger Bands for 5-minute bars';

    public function handle(): int
    {
        // Set unlimited execution time for this command
        set_time_limit(0);
        ini_set('max_execution_time', '0');

        $days = (int) $this->option('days');
        $chunk = (int) $this->option('chunk');
        $symbolsOpt = $this->option('symbols');

        $startTime = microtime(true);
        $this->info("Starting indicator calculation for last {$days} days...");

        // Get symbols to process
        if ($symbolsOpt) {
            $symbols = explode(',', $symbolsOpt);
        } else {
            $symbols = DB::table('asset_info')
                ->whereNull('deleted_at')
                ->where('asset_type', 'stock')
                ->pluck('symbol')
                ->toArray();
        }

        $this->info('Symbols to process: '.count($symbols));

        $symbolChunks = array_chunk($symbols, $chunk);
        $totalUpdated = 0;

        foreach ($symbolChunks as $chunkIdx => $symbolBatch) {
            $this->info('Processing chunk '.($chunkIdx + 1).' of '.count($symbolChunks).'...');

            foreach ($symbolBatch as $symbol) {
                $updated = $this->calculateIndicatorsForSymbol($symbol, $days);
                $totalUpdated += $updated;

                if ($updated > 0) {
                    $this->line("  {$symbol}: Updated {$updated} bars");
                }
            }
        }

        $elapsed = round(microtime(true) - $startTime, 2);
        $this->info("Completed! Updated {$totalUpdated} bars in {$elapsed}s");

        Log::info('[Indicators] Calculation complete', [
            'symbols_processed' => count($symbols),
            'bars_updated' => $totalUpdated,
            'duration_seconds' => $elapsed,
        ]);

        return self::SUCCESS;
    }

    private function calculateIndicatorsForSymbol(string $symbol, int $days): int
    {
        // Calculate cutoff date in EST
        $cutoffDate = now('America/New_York')->subDays($days)->format('Y-m-d');

        // Fetch bars for calculation (need extra for RSI warmup)
        $bars = DB::table('five_minute_prices')
            ->select(['id', 'ts_est', 'price', 'open', 'high', 'low'])
            ->where('symbol', $symbol)
            ->where('asset_type', 'stock')
            ->where('trading_date_est', '>=', $cutoffDate)
            ->orderBy('ts_est', 'asc')
            ->get();

        if ($bars->count() < 50) {
            return 0; // Need minimum bars for indicators
        }

        $ids = [];
        $closes = [];
        $highs = [];
        $lows = [];

        foreach ($bars as $bar) {
            $ids[] = $bar->id;
            $closes[] = (float) $bar->price;
            $highs[] = (float) $bar->high;
            $lows[] = (float) $bar->low;
        }

        // Calculate RSI(14)
        $rsi = $this->calculateRSI($closes, 14);

        // Calculate Bollinger Bands(20, 2)
        [$bbUpper, $bbMiddle, $bbLower] = $this->calculateBollingerBands($closes, 20, 2.0);

        // Batch update database (1000 rows at a time to avoid large transactions)
        $updates = 0;
        $batchSize = 1000;
        $batchUpdates = [];

        foreach ($bars as $idx => $bar) {
            if ($rsi[$idx] !== null && $bbLower[$idx] !== null) {
                $batchUpdates[] = [
                    'id' => $bar->id,
                    'rsi_14' => $rsi[$idx],
                    'bb_upper' => $bbUpper[$idx],
                    'bb_middle' => $bbMiddle[$idx],
                    'bb_lower' => $bbLower[$idx],
                ];

                // Process batch when it reaches the limit
                if (count($batchUpdates) >= $batchSize) {
                    $updates += $this->batchUpdateIndicators($batchUpdates);
                    $batchUpdates = [];
                }
            }
        }

        // Process any remaining updates
        if (! empty($batchUpdates)) {
            $updates += $this->batchUpdateIndicators($batchUpdates);
        }

        return $updates;
    }

    private function calculateRSI(array $closes, int $period): array
    {
        $rsi = array_fill(0, count($closes), null);

        if (count($closes) <= $period) {
            return $rsi;
        }

        $gains = 0.0;
        $losses = 0.0;

        for ($i = 1; $i <= $period; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            if ($change >= 0) {
                $gains += $change;
            } else {
                $losses += abs($change);
            }
        }

        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;
        $rs = ($avgLoss == 0) ? PHP_FLOAT_MAX : ($avgGain / $avgLoss);
        $rsi[$period] = 100.0 - (100.0 / (1.0 + $rs));

        for ($i = $period + 1; $i < count($closes); $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gain = ($change > 0) ? $change : 0.0;
            $loss = ($change < 0) ? abs($change) : 0.0;

            $avgGain = (($avgGain * ($period - 1)) + $gain) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $loss) / $period;

            $rs = ($avgLoss == 0) ? PHP_FLOAT_MAX : ($avgGain / $avgLoss);
            $rsi[$i] = 100.0 - (100.0 / (1.0 + $rs));
        }

        return $rsi;
    }

    private function calculateBollingerBands(array $closes, int $period, float $multiplier): array
    {
        $upper = array_fill(0, count($closes), null);
        $middle = array_fill(0, count($closes), null);
        $lower = array_fill(0, count($closes), null);

        for ($i = $period - 1; $i < count($closes); $i++) {
            $slice = array_slice($closes, $i - $period + 1, $period);
            $sma = array_sum($slice) / $period;

            $variance = 0.0;
            foreach ($slice as $val) {
                $variance += pow($val - $sma, 2);
            }
            $stdDev = sqrt($variance / $period);

            $middle[$i] = $sma;
            $upper[$i] = $sma + ($stdDev * $multiplier);
            $lower[$i] = $sma - ($stdDev * $multiplier);
        }

        return [$upper, $middle, $lower];
    }

    /**
     * Batch update indicators using a single UPDATE with CASE statements
     * This is much more efficient than individual updates
     */
    private function batchUpdateIndicators(array $updates): int
    {
        if (empty($updates)) {
            return 0;
        }

        $ids = array_column($updates, 'id');
        $idList = implode(',', $ids);

        // Build CASE statements for each field
        $rsiCases = [];
        $bbUpperCases = [];
        $bbMiddleCases = [];
        $bbLowerCases = [];

        foreach ($updates as $update) {
            $id = $update['id'];
            $rsiCases[] = "WHEN {$id} THEN {$update['rsi_14']}";
            $bbUpperCases[] = "WHEN {$id} THEN {$update['bb_upper']}";
            $bbMiddleCases[] = "WHEN {$id} THEN {$update['bb_middle']}";
            $bbLowerCases[] = "WHEN {$id} THEN {$update['bb_lower']}";
        }

        $rsiCase = implode(' ', $rsiCases);
        $bbUpperCase = implode(' ', $bbUpperCases);
        $bbMiddleCase = implode(' ', $bbMiddleCases);
        $bbLowerCase = implode(' ', $bbLowerCases);

        $sql = "
            UPDATE five_minute_prices
            SET 
                rsi_14 = CASE id {$rsiCase} END,
                bb_upper = CASE id {$bbUpperCase} END,
                bb_middle = CASE id {$bbMiddleCase} END,
                bb_lower = CASE id {$bbLowerCase} END
            WHERE id IN ({$idList})
        ";

        DB::statement($sql);

        return count($updates);
    }
}
