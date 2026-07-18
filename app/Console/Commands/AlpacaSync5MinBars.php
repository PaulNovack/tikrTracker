<?php

namespace App\Console\Commands;

use App\Services\MarketData\AlpacaMarketDataService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AlpacaSync5MinBars extends Command
{
    protected $signature = 'alpaca:sync-5m
        {--hours=1 : How many hours back to fetch}
        {--chunk=200 : Symbols per request chunk}
        {--feed= : iex|sip (defaults to config)}
        {--symbols= : Comma-separated list of symbols (optional)}
    ';

    protected $description = 'Fetch Alpaca 5-minute bars and upsert into five_minute_prices';

    public function handle(AlpacaMarketDataService $alpaca): int
    {
        $hours = max(0.1, (float) $this->option('hours'));
        $chunk = max(1, (int) $this->option('chunk'));
        $feed = $this->option('feed') ?: null;

        // Get symbols either via --symbols or from asset_info table
        $symbolsOpt = trim((string) $this->option('symbols'));
        if ($symbolsOpt !== '') {
            $symbols = array_values(array_filter(array_map('trim', explode(',', $symbolsOpt))));
        } else {
            // Get all 1-minute enabled symbols from asset_info (we sync 5-minute data for the same symbols)
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

            $this->info('Fetching 5-minute data for '.count($symbols).' symbols from asset_info');
        }

        // Fetch in UTC (Alpaca timestamps are UTC)
        $endUtc = Carbon::now('UTC')->addSeconds(5); // tiny cushion
        $startUtc = $endUtc->copy()->subHours($hours);

        $totalUpserts = 0;
        $totalErrors = 0;
        $chunks = array_chunk($symbols, $chunk);

        $this->info("Fetching 5-minute bars from {$startUtc->toIso8601String()} to {$endUtc->toIso8601String()}");

        foreach ($chunks as $idx => $symChunk) {
            $pageToken = null;
            $chunkUpserts = 0;

            do {
                try {
                    $res = $alpaca->get5mBars($symChunk, $startUtc, $endUtc, $feed, $pageToken);
                    $pageToken = $res['next_page_token'] ?? null;

                    $rows = $this->mapBarsToRows($res['bars'] ?? []);
                    if (! $rows) {
                        continue;
                    }

                    // Upsert in smaller chunks to reduce deadlock probability
                    $rowChunks = array_chunk($rows, 50);
                    foreach ($rowChunks as $rowChunk) {
                        $this->upsertWithRetry($rowChunk, $idx + 1);
                    }

                    $chunkUpserts += count($rows);
                    $totalUpserts += count($rows);
                } catch (\GuzzleHttp\Exception\ClientException $e) {
                    $totalErrors++;
                    $this->error('API error for chunk '.($idx + 1).': '.$e->getMessage());
                    Log::channel('scheduled')->error('[Alpaca 5m Sync] API request failed', [
                        'chunk' => $idx + 1,
                        'symbols' => implode(',', $symChunk),
                        'error' => $e->getMessage(),
                        'code' => $e->getCode(),
                    ]);
                    break; // Stop pagination for this chunk
                } catch (\Illuminate\Database\QueryException $e) {
                    $totalErrors++;
                    $this->error('Database error for chunk '.($idx + 1).': '.$e->getMessage());
                    Log::channel('scheduled')->error('[Alpaca 5m Sync] Database upsert failed', [
                        'chunk' => $idx + 1,
                        'rows_attempted' => count($rows ?? []),
                        'error' => $e->getMessage(),
                    ]);
                    break; // Stop pagination for this chunk
                } catch (\Exception $e) {
                    $totalErrors++;
                    $this->error('Unexpected error for chunk '.($idx + 1).': '.$e->getMessage());
                    Log::channel('scheduled')->error('[Alpaca 5m Sync] Unexpected error', [
                        'chunk' => $idx + 1,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    break; // Stop pagination for this chunk
                }
            } while ($pageToken);

            $this->line('Chunk '.($idx + 1).'/'.count($chunks).' done ('.$chunkUpserts.' rows)');
        }

        if ($totalErrors > 0) {
            $this->warn("Completed with {$totalErrors} error(s)");
        }

        $this->info("Total rows upserted: {$totalUpserts}");

        return $totalErrors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Upsert with retry logic for deadlocks
     */
    private function upsertWithRetry(array $rows, int $chunkNum, int $maxRetries = 3): void
    {
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                DB::table('five_minute_prices')->upsert(
                    $rows,
                    ['symbol', 'asset_type', 'ts'],
                    [
                        'source',
                        'price', 'vwap', 'vwap_dist', 'vwap_dist_pct', 'above_vwap',
                        'ema9', 'ema21', 'ema9_ema21_spread', 'ema9_above_ema21',
                        'atr', 'atr_pct', 'rsi_14', 'bb_upper', 'bb_middle', 'bb_lower',
                        'open', 'high', 'low', 'volume',
                        'updated_at',
                    ]
                );

                return; // Success
            } catch (\Illuminate\Database\QueryException $e) {
                $attempt++;

                // Check if it's a deadlock error (code 40001 or 1213)
                if (str_contains($e->getMessage(), 'Deadlock') || str_contains($e->getMessage(), '40001') || str_contains($e->getMessage(), '1213')) {
                    if ($attempt < $maxRetries) {
                        $waitMs = $attempt * 100; // 100ms, 200ms, 300ms
                        usleep($waitMs * 1000);
                        Log::channel('scheduled')->warning('[Alpaca 5m Sync] Deadlock detected, retrying', [
                            'chunk' => $chunkNum,
                            'attempt' => $attempt,
                            'wait_ms' => $waitMs,
                        ]);

                        continue;
                    }
                }

                // Not a deadlock or max retries reached
                Log::channel('scheduled')->error('[Alpaca 5m Sync] Database upsert failed', [
                    'chunk' => $chunkNum,
                    'rows_attempted' => count($rows),
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }
    }

    /**
     * Map Alpaca 5-minute bars to database rows
     * Alpaca bar fields: t=timestamp, o,h,l,c, v=volume, vw=vwap, n=trade_count
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

                $close = (float) ($b['c'] ?? 0);
                $open = (float) ($b['o'] ?? $close);
                $high = (float) ($b['h'] ?? $close);
                $low = (float) ($b['l'] ?? $close);
                $volume = (int) ($b['v'] ?? 0);
                $vwap = isset($b['vw']) ? (float) $b['vw'] : null;

                // Add current bar to historical for indicator calculation
                $allBars = array_merge($historical, [[
                    'close' => $close,
                    'high' => $high,
                    'low' => $low,
                    'ts' => $tsUtcFormatted,
                ]]);

                // Calculate indicators
                $indicators = $this->calculateIndicators($allBars);

                // Calculate VWAP distance
                $vwapDist = null;
                $vwapDistPct = null;
                $aboveVwap = null;
                if ($vwap !== null && $vwap > 0) {
                    $vwapDist = $close - $vwap;
                    $vwapDistPct = ($vwapDist / $vwap) * 100;
                    $aboveVwap = $close > $vwap ? 1 : 0;
                }

                $out[] = [
                    'symbol' => strtoupper($symbol),
                    'asset_type' => 'stock',
                    'source' => 'alpaca',
                    'ts' => $tsUtcFormatted,
                    'price' => $close,
                    'open' => $open,
                    'high' => $high,
                    'low' => $low,
                    'volume' => $volume,
                    'vwap' => $vwap,
                    'vwap_dist' => $vwapDist,
                    'vwap_dist_pct' => $vwapDistPct,
                    'above_vwap' => $aboveVwap,
                    'ema9' => $indicators['ema9'],
                    'ema21' => $indicators['ema21'],
                    'ema9_ema21_spread' => $indicators['ema9_ema21_spread'],
                    'ema9_above_ema21' => $indicators['ema9_above_ema21'],
                    'atr' => $indicators['atr'],
                    'atr_pct' => $indicators['atr_pct'],
                    'rsi_14' => $indicators['rsi_14'],
                    'bb_upper' => $indicators['bb_upper'],
                    'bb_middle' => $indicators['bb_middle'],
                    'bb_lower' => $indicators['bb_lower'],
                    'updated_at' => $now,
                ];
            }
        }

        return $out;
    }

    /**
     * Get recent historical bars for indicator calculation
     */
    private function getHistoricalBars(string $symbol, int $limit): array
    {
        return DB::table('five_minute_prices')
            ->where('symbol', $symbol)
            ->where('asset_type', 'stock')
            ->orderBy('ts', 'desc')
            ->limit($limit)
            ->get(['price as close', 'high', 'low', 'ts'])
            ->reverse()
            ->values()
            ->map(fn ($row) => [
                'close' => (float) $row->close,
                'high' => (float) $row->high,
                'low' => (float) $row->low,
                'ts' => $row->ts,
            ])
            ->toArray();
    }

    /**
     * Calculate EMA9, EMA21, and ATR
     */
    private function calculateIndicators(array $bars): array
    {
        if (empty($bars)) {
            return [
                'ema9' => null,
                'ema21' => null,
                'ema9_ema21_spread' => null,
                'ema9_above_ema21' => null,
                'atr' => null,
                'atr_pct' => null,
            ];
        }

        $closes = array_column($bars, 'close');
        $highs = array_column($bars, 'high');
        $lows = array_column($bars, 'low');

        // Calculate EMA9
        $ema9 = $this->calculateEMA($closes, 9);

        // Calculate EMA21
        $ema21 = $this->calculateEMA($closes, 21);

        // Calculate ATR(14)
        $atr = $this->calculateATR($highs, $lows, $closes, 14);
        $currentClose = end($closes);
        $atrPct = ($currentClose > 0 && $atr !== null) ? ($atr / $currentClose) * 100 : null;

        // EMA spread
        $ema9Ema21Spread = null;
        $ema9AboveEma21 = null;
        if ($ema9 !== null && $ema21 !== null) {
            $ema9Ema21Spread = $ema9 - $ema21;
            $ema9AboveEma21 = $ema9 > $ema21 ? 1 : 0;
        }

        // Calculate RSI (14 period)
        $rsi14 = $this->calculateRSI($closes, 14);

        // Calculate Bollinger Bands (20 period, 2 std dev)
        $bbData = $this->calculateBollingerBands($closes, 20, 2.0);

        return [
            'ema9' => $ema9,
            'ema21' => $ema21,
            'ema9_ema21_spread' => $ema9Ema21Spread,
            'ema9_above_ema21' => $ema9AboveEma21,
            'atr' => $atr,
            'atr_pct' => $atrPct,
            'rsi_14' => $rsi14,
            'bb_upper' => $bbData['upper'],
            'bb_middle' => $bbData['middle'],
            'bb_lower' => $bbData['lower'],
        ];
    }

    /**
     * Calculate Exponential Moving Average
     */
    private function calculateEMA(array $values, int $period): ?float
    {
        if (count($values) < $period) {
            return null;
        }

        $multiplier = 2 / ($period + 1);
        $ema = array_sum(array_slice($values, 0, $period)) / $period;

        for ($i = $period; $i < count($values); $i++) {
            $ema = ($values[$i] * $multiplier) + ($ema * (1 - $multiplier));
        }

        return $ema;
    }

    /**
     * Calculate Average True Range
     */
    private function calculateATR(array $highs, array $lows, array $closes, int $period): ?float
    {
        if (count($highs) < $period + 1) {
            return null;
        }

        $trs = [];
        for ($i = 1; $i < count($highs); $i++) {
            $tr = max(
                $highs[$i] - $lows[$i],
                abs($highs[$i] - $closes[$i - 1]),
                abs($lows[$i] - $closes[$i - 1])
            );
            $trs[] = $tr;
        }

        if (count($trs) < $period) {
            return null;
        }

        // Simple moving average for first ATR
        $atr = array_sum(array_slice($trs, 0, $period)) / $period;

        // Smooth subsequent values
        for ($i = $period; $i < count($trs); $i++) {
            $atr = (($atr * ($period - 1)) + $trs[$i]) / $period;
        }

        return $atr;
    }

    /**
     * Calculate RSI (Relative Strength Index)
     */
    private function calculateRSI(array $closes, int $period = 14): ?float
    {
        // Need at least period + 1 values to calculate RSI (need differences)
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
     * Calculate Bollinger Bands
     */
    private function calculateBollingerBands(array $closes, int $period = 20, float $stdDevMultiplier = 2.0): array
    {
        // Need at least $period values to calculate Bollinger Bands
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
