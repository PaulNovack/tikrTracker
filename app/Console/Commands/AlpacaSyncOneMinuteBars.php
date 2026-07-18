<?php

namespace App\Console\Commands;

use App\Services\MarketData\AlpacaMarketDataService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AlpacaSyncOneMinuteBars extends Command
{
    protected $signature = 'alpaca:sync-1m
        {--minutes=3 : How many minutes back to fetch (use small buffer like 2-5)}
        {--chunk=150 : Symbols per request chunk (reduced from 200 to avoid connection resets)}
        {--retry=2 : Number of retry attempts for failed chunks}
        {--feed= : iex|sip (defaults to config)}
        {--symbols= : Comma-separated list of symbols (optional)}
    ';

    protected $description = 'Fetch Alpaca 1-minute bars and upsert into one_minute_prices';

    public function handle(AlpacaMarketDataService $alpaca): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $chunk = max(1, (int) $this->option('chunk'));
        $maxRetries = max(0, (int) $this->option('retry'));
        $feed = $this->option('feed') ?: null;

        // Provide symbols either via --symbols or from your DB universe table.
        $symbolsOpt = trim((string) $this->option('symbols'));
        if ($symbolsOpt !== '') {
            $symbols = array_values(array_filter(array_map('trim', explode(',', $symbolsOpt))));
        } else {
            // Get all 1-minute enabled symbols from asset_info (same as yfinance sync)
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

            $this->info('Fetching 1-minute data for '.count($symbols).' symbols from asset_info');
        }

        // Always fetch in UTC (Alpaca timestamps are UTC)
        $endUtc = Carbon::now('UTC')->addSeconds(5); // tiny cushion
        $startUtc = $endUtc->copy()->subMinutes($minutes);

        $totalUpserts = 0;
        $totalErrors = 0;
        $chunks = array_chunk($symbols, $chunk);

        foreach ($chunks as $idx => $symChunk) {
            $pageToken = null;
            $retryCount = 0;
            $chunkSuccess = false;

            // Retry loop for this chunk
            while ($retryCount <= $maxRetries && ! $chunkSuccess) {
                try {
                    do {
                        $res = $alpaca->get1mBars($symChunk, $startUtc, $endUtc, $feed, $pageToken);
                        $pageToken = $res['next_page_token'] ?? null;

                        $rows = $this->mapBarsToRows($res['bars'] ?? []);
                        if (! $rows) {
                            continue;
                        }

                        // Upsert in smaller chunks to reduce deadlock probability
                        $rowChunks = array_chunk($rows, 50);
                        foreach ($rowChunks as $rowChunk) {
                            $this->upsertOneMinWithRetry($rowChunk, $idx + 1);
                        }

                        $totalUpserts += count($rows);
                    } while ($pageToken);

                    $chunkSuccess = true; // Mark successful completion
                } catch (\GuzzleHttp\Exception\ClientException $e) {
                    $retryCount++;
                    $totalErrors++;
                    $this->error('API error for chunk '.($idx + 1).' (attempt '.$retryCount.'/'.($maxRetries + 1).'): '.$e->getMessage());
                    Log::channel('scheduled')->error('[Alpaca Sync] API request failed', [
                        'chunk' => $idx + 1,
                        'attempt' => $retryCount,
                        'symbols' => implode(',', $symChunk),
                        'error' => $e->getMessage(),
                        'code' => $e->getCode(),
                    ]);

                    if ($retryCount <= $maxRetries) {
                        $backoffSeconds = pow(2, $retryCount); // Exponential backoff: 2, 4, 8 seconds
                        $this->warn("Retrying after {$backoffSeconds}s...");
                        sleep($backoffSeconds);
                    }
                } catch (\Illuminate\Database\QueryException $e) {
                    $totalErrors++;
                    $this->error('Database error for chunk '.($idx + 1).': '.$e->getMessage());
                    Log::channel('scheduled')->error('[Alpaca Sync] Database upsert failed', [
                        'chunk' => $idx + 1,
                        'rows_attempted' => count($rows ?? []),
                        'error' => $e->getMessage(),
                    ]);
                    break; // Don't retry database errors
                } catch (\Exception $e) {
                    $retryCount++;
                    $totalErrors++;
                    $this->error('Unexpected error for chunk '.($idx + 1).' (attempt '.$retryCount.'/'.($maxRetries + 1).'): '.$e->getMessage());
                    Log::channel('scheduled')->error('[Alpaca Sync] Unexpected error', [
                        'chunk' => $idx + 1,
                        'attempt' => $retryCount,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    if ($retryCount <= $maxRetries) {
                        $backoffSeconds = pow(2, $retryCount);
                        $this->warn("Retrying after {$backoffSeconds}s...");
                        sleep($backoffSeconds);
                    }
                }
            }

            if ($chunkSuccess) {
                $this->line('Chunk '.($idx + 1).'/'.count($chunks).' done.');
            } else {
                $this->warn('Chunk '.($idx + 1).'/'.count($chunks).' failed after '.($maxRetries + 1).' attempts.');
            }
        }

        if ($totalErrors > 0) {
            $this->warn("Completed with {$totalErrors} error(s)");
        }

        $this->info("Upserted/updated rows: {$totalUpserts}");

        return $totalErrors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Upsert with retry logic for deadlocks
     */
    private function upsertOneMinWithRetry(array $rows, int $chunkNum, int $maxRetries = 3): void
    {
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                DB::table('one_minute_prices')->upsert(
                    $rows,
                    ['symbol', 'asset_type', 'ts'],
                    [
                        'source',
                        'price', 'vwap', 'vwap_dist', 'vwap_dist_pct', 'above_vwap',
                        'ema9', 'ema21', 'ema9_ema21_spread', 'ema9_above_ema21',
                        'atr', 'atr_pct',
                        'open', 'high', 'low', 'volume',
                        'updated_at',
                    ]
                );

                return; // Success
            } catch (\Illuminate\Database\QueryException $e) {
                $attempt++;

                // Check if it's a deadlock error (code 40001 or 1213) or lock timeout (1205)
                if (str_contains($e->getMessage(), 'Deadlock') || str_contains($e->getMessage(), '40001') || str_contains($e->getMessage(), '1213') || str_contains($e->getMessage(), '1205')) {
                    if ($attempt < $maxRetries) {
                        $waitMs = $attempt * 100; // 100ms, 200ms, 300ms
                        usleep($waitMs * 1000);
                        Log::channel('scheduled')->warning('[Alpaca 1m Sync] Deadlock/Lock timeout detected, retrying', [
                            'chunk' => $chunkNum,
                            'attempt' => $attempt,
                            'wait_ms' => $waitMs,
                        ]);

                        continue;
                    }
                }

                // Not a deadlock or max retries reached
                Log::channel('scheduled')->error('[Alpaca 1m Sync] Database upsert failed', [
                    'chunk' => $chunkNum,
                    'rows_attempted' => count($rows),
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }
    }

    /**
     * Alpaca bar fields typically:
     *  t=timestamp, o,h,l,c, v=volume, vw=vwap, n=trade_count
     *
     * We store:
     *  price = c
     *  ts = t (UTC) formatted
     *  Note: ts_est, trading_date_est, trading_time_est are generated columns (auto-calculated from ts)
     *  Note: EMA9, EMA21, ATR are calculated from recent historical bars
     */
    private function mapBarsToRows(array $barsBySymbol): array
    {
        $now = Carbon::now();
        $out = [];

        foreach ($barsBySymbol as $symbol => $bars) {
            // Get recent historical bars for this symbol to calculate indicators
            $historical = $this->getHistoricalBars($symbol, 50); // Get 50 bars for EMA/ATR calculation

            foreach ($bars as $b) {
                $tsUtc = Carbon::parse($b['t'])->timezone('UTC');
                $tsUtcFormatted = $tsUtc->format('Y-m-d H:i:s');

                $close = isset($b['c']) ? (float) $b['c'] : null;
                if ($close === null) {
                    continue;
                }

                $open = isset($b['o']) ? (float) $b['o'] : null;
                $high = isset($b['h']) ? (float) $b['h'] : null;
                $low = isset($b['l']) ? (float) $b['l'] : null;

                $vwap = isset($b['vw']) ? (float) $b['vw'] : null;
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

                try {
                    $ema9 = $this->calculateEMA($historical, $close, $tsUtcFormatted, 9);
                    $ema21 = $this->calculateEMA($historical, $close, $tsUtcFormatted, 21);
                    $atr = $this->calculateATR($historical, $open, $high, $low, $close, $tsUtcFormatted, 14);
                } catch (\Exception $e) {
                    // Log but don't fail - indicators are optional
                    Log::channel('scheduled')->warning('[Alpaca Sync] Indicator calculation failed', [
                        'symbol' => $symbol,
                        'ts' => $tsUtcFormatted,
                        'error' => $e->getMessage(),
                    ]);
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

                $out[] = [
                    'symbol' => $symbol,
                    'source' => 'alpaca',
                    'asset_type' => 'stock',
                    'ts' => $tsUtcFormatted,

                    'price' => $close,
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

                    'open' => $open,
                    'high' => $high,
                    'low' => $low,
                    'volume' => $b['v'] ?? null,

                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return $out;
    }

    /**
     * Get recent historical bars for a symbol
     */
    private function getHistoricalBars(string $symbol, int $limit = 50): array
    {
        try {
            return DB::table('one_minute_prices')
                ->where('symbol', $symbol)
                ->where('asset_type', 'stock')
                ->orderBy('ts', 'desc')
                ->limit($limit)
                ->get(['ts', 'price', 'open', 'high', 'low', 'ema9', 'ema21', 'atr'])
                ->reverse()
                ->values()
                ->all();
        } catch (\Exception $e) {
            Log::channel('scheduled')->warning('[Alpaca Sync] Failed to fetch historical bars', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Calculate EMA for current bar using historical data
     */
    private function calculateEMA(array $historical, float $currentClose, string $currentTs, int $period): ?float
    {
        $multiplier = 2.0 / ($period + 1);

        // Find last EMA value before current timestamp
        $lastEma = null;
        foreach ($historical as $bar) {
            if ($bar->ts >= $currentTs) {
                continue;
            }
            if ($period === 9 && $bar->ema9 !== null) {
                $lastEma = (float) $bar->ema9;
            } elseif ($period === 21 && $bar->ema21 !== null) {
                $lastEma = (float) $bar->ema21;
            }
        }

        // If we have previous EMA, use it
        if ($lastEma !== null) {
            return ($currentClose * $multiplier) + ($lastEma * (1 - $multiplier));
        }

        // Otherwise need enough bars for initial SMA
        $closes = [];
        foreach ($historical as $bar) {
            if ($bar->ts >= $currentTs) {
                continue;
            }
            $closes[] = (float) $bar->price;
        }

        if (count($closes) < $period - 1) {
            return null; // Not enough data yet
        }

        // Use last N-1 bars + current close for initial EMA
        $closes = array_slice($closes, -($period - 1));
        $closes[] = $currentClose;

        return array_sum($closes) / count($closes);
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
}
