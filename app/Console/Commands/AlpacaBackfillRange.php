<?php

namespace App\Console\Commands;

use App\Services\MarketData\AlpacaMarketDataService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AlpacaBackfillRange extends Command
{
    protected $signature = 'alpaca:backfill-range
        {start-date : Start date (YYYY-MM-DD in EST)}
        {end-date : End date (YYYY-MM-DD in EST)}
        {timeframe : Timeframe: 1m, 5m, or both}
        {--chunk=200 : Symbols per request chunk}
        {--feed=sip : iex|sip (sip recommended for historical data)}
        {--symbols= : Comma-separated list of symbols (optional, defaults to all 1_min enabled)}
        {--skip-existing : Skip dates that already have data in the target table}
    ';

    protected $description = 'Backfill a date range of 1-minute and/or 5-minute bars from Alpaca (same format as stream_bars.py)';

    public function handle(AlpacaMarketDataService $alpaca): int
    {
        set_time_limit(0);
        ini_set('memory_limit', '2G');

        $timeframe = $this->argument('timeframe');

        if (! in_array($timeframe, ['1m', '5m', 'both'], true)) {
            $this->error('Timeframe must be "1m", "5m", or "both"');

            return self::FAILURE;
        }

        $timeframes = $timeframe === 'both' ? ['1m', '5m'] : [$timeframe];

        $startDate = $this->parseDate($this->argument('start-date'));
        $endDate = $this->parseDate($this->argument('end-date'));

        if ($startDate === null || $endDate === null) {
            return self::FAILURE;
        }

        if ($startDate->gt($endDate)) {
            $this->error('Start date must be before or equal to end date');

            return self::FAILURE;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $feed = $this->option('feed') ?: 'sip';
        $skipExisting = (bool) $this->option('skip-existing');

        $symbols = $this->resolveSymbols();

        if (empty($symbols)) {
            $this->error('No symbols to process.');

            return self::FAILURE;
        }

        $tables = array_map(fn ($tf) => $tf === '1m' ? 'one_minute_prices' : 'five_minute_prices', $timeframes);
        $tablesStr = implode(' + ', $tables);
        $dateCount = (int) $startDate->diffInDays($endDate) + 1;

        $this->info('╔════════════════════════════════════════════════╗');
        $this->info('║       Alpaca Backfill Range                    ║');
        $this->info('╠════════════════════════════════════════════════╣');
        $this->info('║ Timeframe : '.str_pad($timeframe, 35, ' ').'║');
        $this->info('║ From      : '.str_pad($startDate->toDateString(), 35, ' ').'║');
        $this->info('║ To        : '.str_pad($endDate->toDateString(), 35, ' ').'║');
        $this->info('║ Days      : '.str_pad((string) $dateCount, 35, ' ').'║');
        $this->info('║ Symbols   : '.str_pad((string) count($symbols), 35, ' ').'║');
        $this->info('║ Table(s)  : '.str_pad($tablesStr, 35, ' ').'║');
        $this->info('║ Feed      : '.str_pad($feed, 35, ' ').'║');
        $this->info('╚════════════════════════════════════════════════╝');
        $this->newLine();

        $totalRowsByTable = [];
        foreach ($tables as $t) {
            $totalRowsByTable[$t] = 0;
        }
        $totalErrors = 0;
        $totalDaysProcessed = 0;
        $skippedDays = 0;
        $overallStart = now();

        for ($d = $startDate->copy(); $d->lte($endDate); $d->addDay()) {

            // ── Skip weekends ─────────────────────────────────────────
            if ($d->isWeekend()) {
                $this->line("  <fg=gray>⏭ {$d->toDateString()} — skipping (weekend)</>");

                continue;
            }

            $totalDaysProcessed++;

            // Use the same day-backfill logic as AlpacaBackfillOneDay
            $dateEst = $d->copy()->startOfDay();
            $endEst = $d->copy()->endOfDay();

            $this->info("├─ {$d->toDateString()} [{$totalDaysProcessed}/{$dateCount}]");
            $dayStart = now();
            $dayRows = 0;
            $dayErrors = 0;

            foreach ($timeframes as $tf) {
                $table = $tf === '1m' ? 'one_minute_prices' : 'five_minute_prices';
                $tfLabel = $tf === '1m' ? '1-min' : '5-min';

                // ── Optionally skip dates with existing data ──────────
                if ($skipExisting && $this->dateHasData($table, $d)) {
                    $this->line("  <fg=gray>⏭ {$tfLabel} — {$d->toDateString()} already has data, skipping</>");
                    $skippedDays++;

                    continue;
                }

                $this->line("  <fg=cyan>▸</> {$tfLabel}: fetching {$dateEst->toDateString()} with ".count($symbols).' symbols ('.count(array_chunk($symbols, $chunk)).' chunks)...');

                if ($tf === '1m') {
                    $result = $this->backfill1MinuteBars($alpaca, $symbols, $dateEst, $endEst, $chunk, $feed);
                } else {
                    $result = $this->backfill5MinuteBars($alpaca, $symbols, $dateEst, $endEst, $chunk, $feed);
                }

                $totalRowsByTable[$table] += $result['upserts'];
                $dayRows += $result['upserts'];
                $dayErrors += $result['errors'];
                $totalErrors += $result['errors'];

                $this->line("  <fg=gray>  {$tfLabel}: ".number_format($result['upserts']).' rows upserted</>');
            }

            $dayElapsed = now()->diffInSeconds($dayStart);

            $status = $dayErrors > 0 ? '<fg=yellow>⚠</>' : '<fg=green>✓</>';
            $this->info("  {$status} {$dayRows} rows in {$dayElapsed}s");

            // ── Progress estimate ─────────────────────────────────────
            if ($totalDaysProcessed > 0) {
                $grandTotal = array_sum($totalRowsByTable);
                $avgPerDay = (int) round($grandTotal / $totalDaysProcessed);
                $remainingDays = $dateCount - $totalDaysProcessed;
                $elapsed = now()->diffInSeconds($overallStart);
                $avgSecPerDay = $elapsed / $totalDaysProcessed;
                $etaSec = (int) round($avgSecPerDay * $remainingDays);

                $breakdown = [];
                foreach ($totalRowsByTable as $tbl => $cnt) {
                    $breakdown[] = "{$tbl}: ".number_format($cnt);
                }

                $this->line('  <fg=gray>Total: '.number_format($grandTotal).' rows ('.implode(', ', $breakdown).') | Avg/day: '.number_format($avgPerDay).' | ETA: '.$this->formatSeconds($etaSec).'</>');
            }
        }

        // ── Final summary ─────────────────────────────────────────────
        $totalElapsed = now()->diffInSeconds($overallStart);
        $grandTotal = array_sum($totalRowsByTable);

        $this->newLine();
        $this->info('╔════════════════════════════════════════════════╗');
        $this->info('║       Backfill Complete                        ║');
        $this->info('╠════════════════════════════════════════════════╣');
        $this->info('║ Days processed : '.str_pad((string) $totalDaysProcessed, 30, ' ').'║');
        $this->info('║ Days skipped   : '.str_pad((string) $skippedDays, 30, ' ').'║');
        foreach ($totalRowsByTable as $tbl => $cnt) {
            $this->info('║ '.str_pad($tbl.' : '.number_format($cnt), 46, ' ').'║');
        }
        $this->info('║ Total rows     : '.str_pad(number_format($grandTotal), 30, ' ').'║');
        $this->info('║ Errors         : '.str_pad((string) $totalErrors, 30, ' ').'║');
        $this->info('║ Duration       : '.str_pad($this->formatSeconds((int) $totalElapsed), 30, ' ').'║');
        $this->info('╚════════════════════════════════════════════════╝');

        return $totalErrors > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────
    // 1-minute day backfill (mirrors AlpacaBackfillOneDay logic)
    // ─────────────────────────────────────────────────────────────────

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
            $chunkUpserts = 0;
            $pageNum = 0;
            $firstSymbols = implode(', ', array_slice($symChunk, 0, 3));
            $this->line('    <fg=gray>  chunk '.($idx + 1)."/{$totalChunks}: {$firstSymbols}... (".count($symChunk).' symbols)</>');

            do {
                $pageNum++;

                try {
                    $res = $alpaca->get1mBars($symChunk, $startUtc, $endUtc, $feed, $pageToken);
                    $pageToken = $res['next_page_token'] ?? null;

                    $rows = $this->mapBarsToRows($res['bars'] ?? [], '1m');
                    if (! $rows) {
                        continue;
                    }

                    $rowChunks = array_chunk($rows, 50);
                    foreach ($rowChunks as $rowChunk) {
                        $this->upsertOneMinWithRetry($rowChunk, $idx + 1);
                    }

                    $chunkUpserts += count($rows);
                    $totalUpserts += count($rows);
                    $this->line("    <fg=gray>    page {$pageNum}: ".number_format(count($rows)).' rows ('.number_format($chunkUpserts).' chunk total)</>');
                } catch (\Exception $e) {
                    $totalErrors++;
                    $this->warn('      ✗ Chunk '.($idx + 1)."/{$totalChunks}: ".$e->getMessage());
                }
            } while ($pageToken);

            $this->line('    <fg=gray>  chunk '.($idx + 1)."/{$totalChunks} done: ".number_format($chunkUpserts).' rows</>');
        }

        return ['upserts' => $totalUpserts, 'errors' => $totalErrors];
    }

    // ─────────────────────────────────────────────────────────────────
    // 5-minute day backfill (mirrors AlpacaBackfillOneDay logic)
    // ─────────────────────────────────────────────────────────────────

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
            $chunkUpserts = 0;
            $pageNum = 0;
            $firstSymbols = implode(', ', array_slice($symChunk, 0, 3));
            $this->line('    <fg=gray>  chunk '.($idx + 1)."/{$totalChunks}: {$firstSymbols}... (".count($symChunk).' symbols)</>');

            do {
                $pageNum++;

                try {
                    $res = $alpaca->get5mBars($symChunk, $startUtc, $endUtc, $feed, $pageToken);
                    $pageToken = $res['next_page_token'] ?? null;

                    $rows = $this->mapBarsToRows($res['bars'] ?? [], '5m');
                    if (! $rows) {
                        continue;
                    }

                    $rowChunks = array_chunk($rows, 50);
                    foreach ($rowChunks as $rowChunk) {
                        $this->upsertFiveMinWithRetry($rowChunk, $idx + 1);
                    }

                    $chunkUpserts += count($rows);
                    $totalUpserts += count($rows);
                    $this->line("    <fg=gray>    page {$pageNum}: ".number_format(count($rows)).' rows ('.number_format($chunkUpserts).' chunk total)</>');
                } catch (\Exception $e) {
                    $totalErrors++;
                    $this->warn('      ✗ Chunk '.($idx + 1)."/{$totalChunks}: ".$e->getMessage());
                }
            } while ($pageToken);

            $this->line('    <fg=gray>  chunk '.($idx + 1)."/{$totalChunks} done: ".number_format($chunkUpserts).' rows</>');
        }

        return ['upserts' => $totalUpserts, 'errors' => $totalErrors];
    }

    // ─────────────────────────────────────────────────────────────────
    // Upsert helpers (deadlock retry — matches existing conventions)
    // ─────────────────────────────────────────────────────────────────

    private function upsertOneMinWithRetry(array $rows, int $chunkNum, int $maxRetries = 3): void
    {
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                DB::table('one_minute_prices')->upsert(
                    $rows,
                    ['symbol', 'asset_type', 'ts'],
                    [
                        'source', 'price', 'vwap', 'vwap_dist', 'vwap_dist_pct', 'above_vwap',
                        'ema9', 'ema21', 'ema9_ema21_spread', 'ema9_above_ema21',
                        'atr', 'atr_pct', 'open', 'high', 'low', 'volume',
                        'updated_at',
                    ]
                );

                return;
            } catch (\Illuminate\Database\QueryException $e) {
                $attempt++;

                if (str_contains($e->getMessage(), 'Deadlock')
                    || str_contains($e->getMessage(), '40001')
                    || str_contains($e->getMessage(), '1213')
                    || str_contains($e->getMessage(), '1205')
                ) {
                    if ($attempt < $maxRetries) {
                        usleep($attempt * 100 * 1000); // 100ms, 200ms, 300ms
                        Log::channel('scheduled')->warning('[Alpaca Backfill 1m] Deadlock retry', [
                            'chunk' => $chunkNum,
                            'attempt' => $attempt,
                        ]);

                        continue;
                    }
                }

                Log::channel('scheduled')->error('[Alpaca Backfill 1m] Upsert failed', [
                    'chunk' => $chunkNum,
                    'rows' => count($rows),
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }
    }

    private function upsertFiveMinWithRetry(array $rows, int $chunkNum, int $maxRetries = 3): void
    {
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                DB::table('five_minute_prices')->upsert(
                    $rows,
                    ['symbol', 'asset_type', 'ts'],
                    [
                        'source', 'price', 'vwap', 'vwap_dist', 'vwap_dist_pct', 'above_vwap',
                        'ema9', 'ema21', 'ema9_ema21_spread', 'ema9_above_ema21',
                        'atr', 'atr_pct', 'rsi_14', 'bb_upper', 'bb_middle', 'bb_lower',
                        'open', 'high', 'low', 'volume',
                        'updated_at',
                    ]
                );

                return;
            } catch (\Illuminate\Database\QueryException $e) {
                $attempt++;

                if (str_contains($e->getMessage(), 'Deadlock')
                    || str_contains($e->getMessage(), '40001')
                    || str_contains($e->getMessage(), '1213')
                    || str_contains($e->getMessage(), '1205')
                ) {
                    if ($attempt < $maxRetries) {
                        usleep($attempt * 100 * 1000);
                        Log::channel('scheduled')->warning('[Alpaca Backfill 5m] Deadlock retry', [
                            'chunk' => $chunkNum,
                            'attempt' => $attempt,
                        ]);

                        continue;
                    }
                }

                Log::channel('scheduled')->error('[Alpaca Backfill 5m] Upsert failed', [
                    'chunk' => $chunkNum,
                    'rows' => count($rows),
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Bar mapping — same format as stream_bars.py / AlpacaBackfillOneDay
    // ─────────────────────────────────────────────────────────────────

    private function mapBarsToRows(array $bars, string $timeframe): array
    {
        $now = Carbon::now();
        $rows = [];
        $table = $timeframe === '1m' ? 'one_minute_prices' : 'five_minute_prices';

        foreach ($bars as $symbol => $barList) {
            if (! is_array($barList)) {
                continue;
            }

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

                $vwapDist = null;
                $vwapDistPct = null;
                $aboveVwap = null;

                if ($vwap !== null && $vwap > 0) {
                    $vwapDist = $close - $vwap;
                    $vwapDistPct = ($vwapDist / $vwap) * 100.0;
                    $aboveVwap = $close >= $vwap ? 1 : 0;
                }

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

                    if ($timeframe === '5m') {
                        $rsi14 = $this->calculateRSI($historical, $close, $tsUtcFormatted, 14);
                        $bbData = $this->calculateBollingerBands($historical, $close, $tsUtcFormatted, 20, 2.0);
                        $bbUpper = $bbData['upper'];
                        $bbMiddle = $bbData['middle'];
                        $bbLower = $bbData['lower'];
                    }
                } catch (\Exception $e) {
                    // Indicators are optional
                }

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

    // ─────────────────────────────────────────────────────────────────
    // Indicator calculation helpers (identical to AlpacaBackfillOneDay)
    // ─────────────────────────────────────────────────────────────────

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

    private function calculateEMA(array $historical, float $currentClose, string $currentTs, int $period): ?float
    {
        $closes = [];
        foreach ($historical as $bar) {
            if ($bar->ts >= $currentTs) {
                continue;
            }
            $closes[] = (float) $bar->price;
        }

        $closes[] = $currentClose;

        if (count($closes) < $period) {
            if (count($closes) >= 2) {
                return array_sum($closes) / count($closes);
            }

            return null;
        }

        $initialSma = array_sum(array_slice($closes, 0, $period)) / $period;

        if (count($closes) === $period) {
            return $initialSma;
        }

        $multiplier = 2.0 / ($period + 1);
        $ema = $initialSma;

        for ($i = $period; $i < count($closes); $i++) {
            $ema = ($closes[$i] * $multiplier) + ($ema * (1 - $multiplier));
        }

        return $ema;
    }

    private function calculateATR(array $historical, ?float $open, ?float $high, ?float $low, float $close, string $currentTs, int $period = 14): ?float
    {
        if ($high === null || $low === null) {
            return null;
        }

        $prevBars = [];
        foreach ($historical as $bar) {
            if ($bar->ts >= $currentTs) {
                continue;
            }
            $prevBars[] = $bar;
        }

        if (empty($prevBars)) {
            return $high - $low;
        }

        $prevClose = (float) end($prevBars)->price;
        $tr = max(
            $high - $low,
            abs($high - $prevClose),
            abs($low - $prevClose)
        );

        $lastAtr = null;
        foreach ($prevBars as $bar) {
            if ($bar->atr !== null) {
                $lastAtr = (float) $bar->atr;
            }
        }

        if ($lastAtr !== null) {
            return (($lastAtr * ($period - 1)) + $tr) / $period;
        }

        if (count($prevBars) < $period - 1) {
            return $tr;
        }

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

    private function calculateRSI(array $historical, float $currentClose, string $currentTs, int $period = 14): ?float
    {
        $closes = [];
        foreach ($historical as $bar) {
            if ($bar->ts >= $currentTs) {
                continue;
            }
            $closes[] = (float) $bar->price;
        }

        $closes[] = $currentClose;

        if (count($closes) < $period + 1) {
            return null;
        }

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

        if (count($gains) < $period) {
            return null;
        }

        $avgGain = array_sum(array_slice($gains, -$period)) / $period;
        $avgLoss = array_sum(array_slice($losses, -$period)) / $period;

        if ($avgLoss == 0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;

        return 100 - (100 / (1 + $rs));
    }

    private function calculateBollingerBands(array $historical, float $currentClose, string $currentTs, int $period = 20, float $stdDevMultiplier = 2.0): array
    {
        $closes = [];
        foreach ($historical as $bar) {
            if ($bar->ts >= $currentTs) {
                continue;
            }
            $closes[] = (float) $bar->price;
        }

        $closes[] = $currentClose;

        if (count($closes) < $period) {
            return ['upper' => null, 'middle' => null, 'lower' => null];
        }

        $periodCloses = array_slice($closes, -$period);
        $sma = array_sum($periodCloses) / $period;

        $variance = 0;
        foreach ($periodCloses as $c) {
            $variance += pow($c - $sma, 2);
        }
        $stdDev = sqrt($variance / $period);

        return [
            'upper' => $sma + ($stdDevMultiplier * $stdDev),
            'middle' => $sma,
            'lower' => $sma - ($stdDevMultiplier * $stdDev),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    private function parseDate(string $date): ?Carbon
    {
        try {
            return Carbon::parse($date, 'America/New_York')->startOfDay();
        } catch (\Exception $e) {
            $this->error("Invalid date '{$date}': ".$e->getMessage());

            return null;
        }
    }

    /**
     * @return string[]
     */
    private function resolveSymbols(): array
    {
        $symbolsOpt = trim((string) $this->option('symbols'));

        if ($symbolsOpt !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $symbolsOpt))));
        }

        $symbols = DB::table('asset_info')
            ->where('asset_type', 'stock')
            ->where('1_min', 1)
            ->whereNull('deleted_at')
            ->pluck('symbol')
            ->all();

        return array_values($symbols);
    }

    private function dateHasData(string $table, Carbon $dateEst): bool
    {
        try {
            return DB::table($table)
                ->where('asset_type', 'stock')
                ->whereRaw('CAST((ts - INTERVAL 5 HOUR) AS DATE) = ?', [$dateEst->toDateString()])
                ->exists();
        } catch (\Exception $e) {
            return false;
        }
    }

    private function formatSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        if ($seconds < 3600) {
            $m = intdiv($seconds, 60);
            $s = $seconds % 60;

            return "{$m}m {$s}s";
        }

        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        return "{$h}h {$m}m";
    }
}
