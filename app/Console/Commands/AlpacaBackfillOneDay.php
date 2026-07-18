<?php

namespace App\Console\Commands;

use App\Services\MarketData\AlpacaMarketDataService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AlpacaBackfillOneDay extends Command
{
    protected $signature = 'alpaca:backfill-day
        {date : Date to backfill (YYYY-MM-DD in EST)}
        {timeframe : Timeframe: 1m or 5m}
        {--chunk=200 : Symbols per request chunk}
        {--feed=sip : iex|sip (sip recommended for historical data)}
        {--symbols= : Comma-separated list of symbols (optional, defaults to all 1_min enabled)}
    ';

    protected $description = 'Backfill one day of 1-minute or 5-minute bars from Alpaca';

    public function handle(AlpacaMarketDataService $alpaca): int
    {
        set_time_limit(0);
        ini_set('memory_limit', '2G');

        $date = $this->argument('date');
        $timeframe = $this->argument('timeframe');
        $chunk = max(1, (int) $this->option('chunk'));
        $feed = $this->option('feed') ?: 'sip';

        if (! in_array($timeframe, ['1m', '5m'])) {
            $this->error('Timeframe must be either "1m" or "5m"');

            return self::FAILURE;
        }

        try {
            $dateEst = Carbon::parse($date, 'America/New_York')->startOfDay();
            $endEst = $dateEst->copy()->endOfDay();
        } catch (\Exception $e) {
            $this->error('Invalid date format: '.$e->getMessage());

            return self::FAILURE;
        }

        // Get symbols
        $symbolsOpt = trim((string) $this->option('symbols'));
        if ($symbolsOpt !== '') {
            $symbols = array_values(array_filter(array_map('trim', explode(',', $symbolsOpt))));
        } else {
            $symbols = DB::table('asset_info')
                ->where('asset_type', 'stock')
                ->where('1_min', 1)
                ->whereNull('deleted_at')
                ->pluck('symbol')
                ->all();

            if (empty($symbols)) {
                $this->error('No 1-minute enabled symbols found in asset_info table.');

                return self::FAILURE;
            }
        }

        $this->info('=== Alpaca Backfill Single Day ===');
        $this->info("Date: {$dateEst->toDateString()} EST");
        $this->info("Timeframe: {$timeframe}");
        $this->info('Symbols: '.count($symbols));
        $this->info("Feed: {$feed}");
        $this->newLine();

        $startTime = now();

        if ($timeframe === '1m') {
            $result = $this->backfill1MinuteBars($alpaca, $symbols, $dateEst, $endEst, $chunk, $feed);
            $this->info("✓ Upserted {$result['upserts']} 1-minute bars, {$result['errors']} errors");
        } else {
            $result = $this->backfill5MinuteBars($alpaca, $symbols, $dateEst, $endEst, $chunk, $feed);
            $this->info("✓ Upserted {$result['upserts']} 5-minute bars, {$result['errors']} errors");
        }

        $elapsed = now()->diffInSeconds($startTime);
        $this->info("✅ Complete! Time: {$elapsed}s");

        return self::SUCCESS;
    }

    private function backfill1MinuteBars(
        AlpacaMarketDataService $alpaca,
        array $symbols,
        Carbon $startEst,
        Carbon $endEst,
        int $chunk,
        string $feed
    ): array {
        $startUtc = $startEst->copy()->setTimezone('UTC');
        $endUtc = $endEst->copy()->setTimezone('UTC');

        $totalUpserts = 0;
        $totalErrors = 0;
        $chunks = array_chunk($symbols, $chunk);
        $totalChunks = count($chunks);

        foreach ($chunks as $idx => $symChunk) {
            $pageToken = null;

            do {
                try {
                    $this->line('  → Fetching chunk '.($idx + 1)."/{$totalChunks} (".count($symChunk).' symbols)...');
                    $res = $alpaca->get1mBars($symChunk, $startUtc, $endUtc, $feed, $pageToken);
                    $pageToken = $res['next_page_token'] ?? null;

                    $rows = $this->mapBarsToRows($res['bars'] ?? [], '1m');
                    if (! $rows) {
                        continue;
                    }

                    // Insert in chunks of 50 to avoid memory/SQL issues
                    $rowChunks = array_chunk($rows, 50);
                    foreach ($rowChunks as $rowChunk) {
                        $this->upsertOneMinWithRetry($rowChunk, $idx + 1);
                    }

                    $totalUpserts += count($rows);
                    $this->line('  ✓ Upserted '.count($rows)." bars (Total: {$totalUpserts})");
                } catch (\Exception $e) {
                    $totalErrors++;
                    $this->error('  ✗ Error chunk '.($idx + 1)."/{$totalChunks}: ".$e->getMessage());
                }
            } while ($pageToken);
        }

        return ['upserts' => $totalUpserts, 'errors' => $totalErrors];
    }

    /**
     * Upsert one minute data with retry logic for deadlocks
     */
    private function upsertOneMinWithRetry(array $rows, int $chunkNum, int $maxRetries = 3): void
    {
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                DB::table('one_minute_prices')->upsert(
                    $rows,
                    ['symbol', 'asset_type', 'ts'],
                    ['source', 'price', 'vwap', 'vwap_dist', 'vwap_dist_pct', 'above_vwap',
                        'ema9', 'ema21', 'ema9_ema21_spread', 'ema9_above_ema21',
                        'atr', 'atr_pct', 'open', 'high', 'low', 'volume',
                        'updated_at']
                );

                return; // Success
            } catch (\Illuminate\Database\QueryException $e) {
                $attempt++;

                // Check if it's a deadlock error (code 40001 or 1213) or lock timeout (1205)
                if (str_contains($e->getMessage(), 'Deadlock') || str_contains($e->getMessage(), '40001') || str_contains($e->getMessage(), '1213') || str_contains($e->getMessage(), '1205')) {
                    if ($attempt < $maxRetries) {
                        $waitMs = $attempt * 100; // 100ms, 200ms, 300ms
                        usleep($waitMs * 1000);
                        Log::channel('scheduled')->warning('[Alpaca Backfill 1m] Deadlock detected, retrying', [
                            'chunk' => $chunkNum,
                            'attempt' => $attempt,
                            'wait_ms' => $waitMs,
                        ]);

                        continue;
                    }
                }

                // Not a deadlock or max retries reached
                Log::channel('scheduled')->error('[Alpaca Backfill 1m] Database upsert failed', [
                    'chunk' => $chunkNum,
                    'rows_attempted' => count($rows),
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }
    }

    private function backfill5MinuteBars(
        AlpacaMarketDataService $alpaca,
        array $symbols,
        Carbon $startEst,
        Carbon $endEst,
        int $chunk,
        string $feed
    ): array {
        $startUtc = $startEst->copy()->setTimezone('UTC');
        $endUtc = $endEst->copy()->setTimezone('UTC');

        $totalUpserts = 0;
        $totalErrors = 0;
        $chunks = array_chunk($symbols, $chunk);
        $totalChunks = count($chunks);

        foreach ($chunks as $idx => $symChunk) {
            $pageToken = null;

            do {
                try {
                    $this->line('  → Fetching chunk '.($idx + 1)."/{$totalChunks} (".count($symChunk).' symbols)...');
                    $res = $alpaca->get5mBars($symChunk, $startUtc, $endUtc, $feed, $pageToken);
                    $pageToken = $res['next_page_token'] ?? null;

                    $rows = $this->mapBarsToRows($res['bars'] ?? [], '5m');
                    if (! $rows) {
                        continue;
                    }

                    // Insert in chunks of 50 to avoid memory/SQL issues
                    $rowChunks = array_chunk($rows, 50);
                    foreach ($rowChunks as $rowChunk) {
                        $this->upsertFiveMinWithRetry($rowChunk, $idx + 1);
                    }

                    $totalUpserts += count($rows);
                    $this->line('  ✓ Upserted '.count($rows)." bars (Total: {$totalUpserts})");
                } catch (\Exception $e) {
                    $totalErrors++;
                    $this->error('  ✗ Error chunk '.($idx + 1)."/{$totalChunks}: ".$e->getMessage());
                }
            } while ($pageToken);
        }

        return ['upserts' => $totalUpserts, 'errors' => $totalErrors];
    }

    /**
     * Upsert five minute data with retry logic for deadlocks
     */
    private function upsertFiveMinWithRetry(array $rows, int $chunkNum, int $maxRetries = 3): void
    {
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                DB::table('five_minute_prices')->upsert(
                    $rows,
                    ['symbol', 'asset_type', 'ts'],
                    ['source', 'price', 'vwap', 'vwap_dist', 'vwap_dist_pct', 'above_vwap',
                        'ema9', 'ema21', 'ema9_ema21_spread', 'ema9_above_ema21',
                        'atr', 'atr_pct', 'rsi_14', 'bb_upper', 'bb_middle', 'bb_lower',
                        'open', 'high', 'low', 'volume',
                        'updated_at']
                );

                return; // Success
            } catch (\Illuminate\Database\QueryException $e) {
                $attempt++;

                // Check if it's a deadlock error (code 40001 or 1213)
                if (str_contains($e->getMessage(), 'Deadlock') || str_contains($e->getMessage(), '40001') || str_contains($e->getMessage(), '1213')) {
                    if ($attempt < $maxRetries) {
                        $waitMs = $attempt * 100; // 100ms, 200ms, 300ms
                        usleep($waitMs * 1000);
                        Log::channel('scheduled')->warning('[Alpaca Backfill] Deadlock detected, retrying', [
                            'chunk' => $chunkNum,
                            'attempt' => $attempt,
                            'wait_ms' => $waitMs,
                        ]);

                        continue;
                    }
                }

                // Not a deadlock or max retries reached
                Log::channel('scheduled')->error('[Alpaca Backfill] Database upsert failed', [
                    'chunk' => $chunkNum,
                    'rows_attempted' => count($rows),
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }
    }

    private function mapBarsToRows(array $bars, string $timeframe): array
    {
        $now = Carbon::now();
        $rows = [];
        $table = $timeframe === '1m' ? 'one_minute_prices' : 'five_minute_prices';

        foreach ($bars as $symbol => $barList) {
            if (! is_array($barList)) {
                continue;
            }

            // Get historical bars for this symbol to calculate indicators
            $historical = $this->getHistoricalBars($symbol, $table, 50);

            foreach ($barList as $bar) {
                $tsUtc = Carbon::parse($bar['t'], 'UTC');
                $tsUtcFormatted = $tsUtc->format('Y-m-d H:i:s');

                $close = isset($bar['c']) ? (float) $bar['c'] : null;
                if ($close === null) {
                    continue;
                }

                $open = isset($bar['o']) ? (float) $bar['o'] : null;
                $high = isset($bar['h']) ? (float) $bar['h'] : null;
                $low = isset($bar['l']) ? (float) $bar['l'] : null;
                $vwap = isset($bar['vw']) ? (float) $bar['vw'] : null;

                // Calculate VWAP distance
                $vwapDist = null;
                $vwapDistPct = null;
                $aboveVwap = null;

                if ($vwap !== null && $vwap > 0) {
                    $vwapDist = $close - $vwap;
                    $vwapDistPct = ($vwapDist / $vwap) * 100.0;
                    $aboveVwap = $close >= $vwap ? 1 : 0;
                }

                // Calculate indicators using historical data + current bar
                $ema9 = null;
                $ema21 = null;
                $atr = null;
                $rsi14 = null;
                $bbUpper = null;
                $bbMiddle = null;
                $bbLower = null;

                try {
                    $ema9 = $this->calculateEMA($historical, $close, $tsUtcFormatted, 9);
                    $ema21 = $this->calculateEMA($historical, $close, $tsUtcFormatted, 21);
                    $atr = $this->calculateATR($historical, $open, $high, $low, $close, $tsUtcFormatted, 14);

                    // Only calculate RSI and BB for 5-minute data
                    if ($timeframe === '5m') {
                        $rsi14 = $this->calculateRSI($historical, $close, $tsUtcFormatted, 14);
                        $bbData = $this->calculateBollingerBands($historical, $close, $tsUtcFormatted, 20, 2.0);
                        $bbUpper = $bbData['upper'];
                        $bbMiddle = $bbData['middle'];
                        $bbLower = $bbData['lower'];
                    }
                } catch (\Exception $e) {
                    // Indicators are optional, continue without them
                }

                // Add current bar to historical array so next bars can use it
                // Keep only last 50 bars to avoid memory issues
                $historical[] = (object) [
                    'ts' => $tsUtcFormatted,
                    'price' => $close,
                    'open' => $open,
                    'high' => $high,
                    'low' => $low,
                    'ema9' => $ema9,
                    'ema21' => $ema21,
                    'atr' => $atr,
                ];

                if (count($historical) > 50) {
                    array_shift($historical);
                }

                $ema9Ema21Spread = null;
                $ema9AboveEma21 = null;
                if ($ema9 !== null && $ema21 !== null) {
                    $ema9Ema21Spread = $ema9 - $ema21;
                    $ema9AboveEma21 = $ema9 >= $ema21 ? 1 : 0;
                }

                $atrPct = null;
                if ($atr !== null && $close > 0) {
                    $atrPct = ($atr / $close) * 100.0;
                }

                $row = [
                    'symbol' => strtoupper($symbol),
                    'asset_type' => 'stock',
                    'source' => 'alpaca',
                    'ts' => $tsUtcFormatted,
                    'open' => $open,
                    'high' => $high,
                    'low' => $low,
                    'price' => $close,
                    'volume' => $bar['v'] ?? 0,
                    'vwap' => $vwap,
                    'vwap_dist' => $vwapDist,
                    'vwap_dist_pct' => $vwapDistPct,
                    'above_vwap' => $aboveVwap,
                    'ema9' => $ema9,
                    'ema21' => $ema21,
                    'ema9_ema21_spread' => $ema9Ema21Spread,
                    'ema9_above_ema21' => $ema9AboveEma21,
                    'atr' => $atr,
                    'atr_pct' => $atrPct,
                    'updated_at' => $now,
                ];

                // Add 5-minute specific fields
                if ($timeframe === '5m') {
                    $row['rsi_14'] = $rsi14;
                    $row['bb_upper'] = $bbUpper;
                    $row['bb_middle'] = $bbMiddle;
                    $row['bb_lower'] = $bbLower;
                }

                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Get recent historical bars for a symbol
     */
    private function getHistoricalBars(string $symbol, string $table, int $limit = 50): array
    {
        try {
            return DB::table($table)
                ->where('symbol', $symbol)
                ->where('asset_type', 'stock')
                ->orderBy('ts', 'desc')
                ->limit($limit)
                ->get(['ts', 'price', 'open', 'high', 'low', 'ema9', 'ema21', 'atr'])
                ->reverse()
                ->values()
                ->all();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Calculate EMA for current bar using historical data
     * This version calculates EMA from scratch using price history, not relying on pre-calculated EMAs
     */
    private function calculateEMA(array $historical, float $currentClose, string $currentTs, int $period): ?float
    {
        // Collect all closes before current timestamp
        $closes = [];
        foreach ($historical as $bar) {
            if ($bar->ts >= $currentTs) {
                continue;
            }
            $closes[] = (float) $bar->price;
        }

        // Add current close
        $closes[] = $currentClose;

        // Need at least $period bars to calculate EMA
        if (count($closes) < $period) {
            // Not enough data - return simple average of what we have if possible
            if (count($closes) >= 2) {
                return array_sum($closes) / count($closes);
            }

            return null;
        }

        // Calculate initial SMA for first $period values
        $initialSma = array_sum(array_slice($closes, 0, $period)) / $period;

        // If we only have exactly $period bars, return the SMA
        if (count($closes) === $period) {
            return $initialSma;
        }

        // Calculate EMA from the SMA forward
        $multiplier = 2.0 / ($period + 1);
        $ema = $initialSma;

        for ($i = $period; $i < count($closes); $i++) {
            $ema = ($closes[$i] * $multiplier) + ($ema * (1 - $multiplier));
        }

        return $ema;
    }

    /**
     * Calculate ATR for current bar using historical data
     */
    private function calculateATR(array $historical, ?float $open, ?float $high, ?float $low, float $close, string $currentTs, int $period = 14): ?float
    {
        if ($high === null || $low === null) {
            return null;
        }

        // Get previous bars for TR calculation
        $prevBars = [];
        foreach ($historical as $bar) {
            if ($bar->ts >= $currentTs) {
                continue;
            }
            $prevBars[] = $bar;
        }

        if (empty($prevBars)) {
            // First bar - just use high - low
            return $high - $low;
        }

        // Calculate True Range for current bar
        $prevClose = (float) end($prevBars)->price;
        $tr = max(
            $high - $low,
            abs($high - $prevClose),
            abs($low - $prevClose)
        );

        // Find last ATR value
        $lastAtr = null;
        foreach ($prevBars as $bar) {
            if ($bar->atr !== null) {
                $lastAtr = (float) $bar->atr;
            }
        }

        // If we have previous ATR, use smoothed ATR formula
        if ($lastAtr !== null) {
            return (($lastAtr * ($period - 1)) + $tr) / $period;
        }

        // Otherwise need enough bars for initial ATR
        if (count($prevBars) < $period - 1) {
            return $tr; // Not enough data, return current TR
        }

        // Calculate initial ATR as average of last N TRs
        $trs = [$tr];
        for ($i = count($prevBars) - 1; $i >= 0 && count($trs) < $period; $i--) {
            $bar = $prevBars[$i];
            $prevBar = $i > 0 ? $prevBars[$i - 1] : null;

            if ($prevBar) {
                $barTr = max(
                    (float) $bar->high - (float) $bar->low,
                    abs((float) $bar->high - (float) $prevBar->price),
                    abs((float) $bar->low - (float) $prevBar->price)
                );
                $trs[] = $barTr;
            }
        }

        return array_sum($trs) / count($trs);
    }

    /**
     * Calculate RSI (Relative Strength Index) for current bar using historical data
     */
    private function calculateRSI(array $historical, float $currentClose, string $currentTs, int $period = 14): ?float
    {
        // Collect all closes before current timestamp
        $closes = [];
        foreach ($historical as $bar) {
            if ($bar->ts >= $currentTs) {
                continue;
            }
            $closes[] = (float) $bar->price;
        }

        // Add current close
        $closes[] = $currentClose;

        // Need at least period + 1 bars to calculate RSI (need differences)
        if (count($closes) < $period + 1) {
            return null;
        }

        // Calculate price changes
        $gains = [];
        $losses = [];

        for ($i = 1; $i < count($closes); $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            if ($change > 0) {
                $gains[] = $change;
                $losses[] = 0;
            } else {
                $gains[] = 0;
                $losses[] = abs($change);
            }
        }

        // Need at least $period changes
        if (count($gains) < $period) {
            return null;
        }

        // Calculate average gain and loss for the period
        $avgGain = array_sum(array_slice($gains, -$period)) / $period;
        $avgLoss = array_sum(array_slice($losses, -$period)) / $period;

        // Avoid division by zero
        if ($avgLoss == 0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;
        $rsi = 100 - (100 / (1 + $rs));

        return $rsi;
    }

    /**
     * Calculate Bollinger Bands for current bar using historical data
     */
    private function calculateBollingerBands(array $historical, float $currentClose, string $currentTs, int $period = 20, float $stdDevMultiplier = 2.0): array
    {
        // Collect all closes before current timestamp
        $closes = [];
        foreach ($historical as $bar) {
            if ($bar->ts >= $currentTs) {
                continue;
            }
            $closes[] = (float) $bar->price;
        }

        // Add current close
        $closes[] = $currentClose;

        // Need at least $period bars to calculate Bollinger Bands
        if (count($closes) < $period) {
            return ['upper' => null, 'middle' => null, 'lower' => null];
        }

        // Get last $period closes
        $periodCloses = array_slice($closes, -$period);

        // Calculate SMA (middle band)
        $sma = array_sum($periodCloses) / $period;

        // Calculate standard deviation
        $variance = 0;
        foreach ($periodCloses as $close) {
            $variance += pow($close - $sma, 2);
        }
        $stdDev = sqrt($variance / $period);

        // Calculate upper and lower bands
        $upper = $sma + ($stdDevMultiplier * $stdDev);
        $lower = $sma - ($stdDevMultiplier * $stdDev);

        return [
            'upper' => $upper,
            'middle' => $sma,
            'lower' => $lower,
        ];
    }
}
