<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class RedisBarHydrator extends Command
{
    protected $signature = 'redis:hydrate-bars
        {--asset=stock : Asset type (stock|crypto)}
        {--symbols= : Comma-separated symbol list (default: all 1_min=1 symbols)}
        {--minutes-1m=390 : Minutes of 1m bars to load (default: full session)}
        {--minutes-5m=390 : Minutes of 5m bars to load (default: full session)}
        {--skip-5m : Skip 5m bar hydration}
        {--skip-events : Skip event-stream emission}
    ';

    protected $description = 'Hydrate Redis rt: bar keys from MySQL for warm-starting the realtime pipeline.';

    private const RT_PREFIX = 'rt:';

    private const BARS_1M_RETENTION = 420;

    private const BARS_5M_RETENTION = 100;

    private const BARS_TTL_SECONDS = 172_800;

    private const EVENTS_MAXLEN = 100_000;

    private const HYDRATION_TTL_SECONDS = 26 * 3600;

    public function handle(): int
    {
        $assetType = $this->option('asset');
        $skip5m = (bool) $this->option('skip-5m');
        $skipEvents = (bool) $this->option('skip-events');
        $minutes1m = max(1, (int) $this->option('minutes-1m'));
        $minutes5m = max(1, (int) $this->option('minutes-5m'));

        $symbols = $this->resolveSymbols();
        if ($symbols === []) {
            $this->error('No symbols found.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Hydrating Redis bars for %d %s symbols (1m: %dm, 5m: %s)...',
            count($symbols),
            $assetType,
            $minutes1m,
            $skip5m ? 'skipped' : "{$minutes5m}m"
        ));

        // Use the latest bar timestamp in the database as the anchor point,
        // not "now" — data may be hours or days old (e.g. running on Sunday
        // when the last bars are from Friday).
        $latest1m = $this->latestBarTimestamp('one_minute_prices', $assetType, $symbols);

        if ($latest1m === null) {
            $this->warn('No 1-minute bars found in the database. Is the table populated?');

            return self::FAILURE;
        }

        // Clamp to 4:00 PM EST (20:00 UTC) — bars past market close are
        // after-hours and not useful for session-wide indicator computation.
        $sessionCloseUtc = $latest1m->copy()->setTime(20, 0, 0);
        if ($latest1m->gt($sessionCloseUtc)) {
            $latest1m = $sessionCloseUtc;
        }

        $this->info(sprintf(
            '  Latest 1m bar in DB: %s UTC (clamped to 4pm EST)',
            $latest1m->toDateTimeString()
        ));

        $start1m = $latest1m->copy()->subMinutes($minutes1m);

        // ── Hydrate 1-minute bars ──
        $count1m = $this->hydrate1mBars($symbols, $assetType, $start1m, $latest1m, $skipEvents);
        $this->info("  1m bars written: {$count1m}");

        // ── Hydrate 5-minute bars (aggregated from 1m OHLCV since
        //     five_minute_prices lacks open/high/low columns) ──
        if (! $skip5m) {
            // Use the same anchor — aggregate 1m → 5m from the same time window
            $start5m = $latest1m->copy()->subMinutes($minutes5m + 10);
            $count5m = $this->hydrate5mBars($symbols, $assetType, $start5m, $latest1m, $skipEvents);
            $this->info("  5m bars written: {$count5m}");
        }

        // ── Hydration marker ──
        $this->setHydrationMarker($assetType);

        $this->info('Redis bar hydration complete.');

        return self::SUCCESS;
    }

    /**
     * Find the most recent bar timestamp for the given symbols, capped at
     * 4:00 PM EST (20:00 UTC) on the day the bar falls, so we anchor on
     * the actual trading session, not after-hours bars at midnight.
     */
    private function latestBarTimestamp(string $table, string $assetType, array $symbols): ?Carbon
    {
        $ts = DB::table($table)
            ->where('asset_type', $assetType)
            ->whereIn('symbol', $symbols)
            ->max('ts');

        if (! $ts) {
            return null;
        }

        $latest = Carbon::parse($ts);
        $sessionClose = $latest->copy()->startOfDay()->setTime(20, 0, 0);

        if ($latest->gt($sessionClose)) {
            $latest = $sessionClose;
        }

        return $latest->addMinute();
    }

    /** @return list<string> */
    private function resolveSymbols(): array
    {
        $symbolsOpt = trim((string) $this->option('symbols'));
        if ($symbolsOpt !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $symbolsOpt))));
        }

        return DB::table('asset_info')
            ->where('asset_type', 'stock')
            ->where('1_min', 1)
            ->whereNull('deleted_at')
            ->orderBy('symbol')
            ->pluck('symbol')
            ->all();
    }

    /**
     * Pull 1-minute bars from MySQL and upsert into rt:bars:1m:{date}:stock:{symbol}.
     */
    private function hydrate1mBars(
        array $symbols,
        string $assetType,
        Carbon $start,
        Carbon $end,
        bool $skipEvents,
    ): int {
        $totalWritten = 0;

        foreach (array_chunk($symbols, 250) as $chunk) {
            $rows = DB::table('one_minute_prices')
                ->select(['symbol', 'ts', 'price', 'open', 'high', 'low', 'volume', 'ts_est'])
                ->where('asset_type', $assetType)
                ->whereIn('symbol', $chunk)
                ->whereBetween('ts', [$start, $end])
                ->orderBy('symbol')
                ->orderBy('ts')
                ->get();

            $barsBySymbol = [];
            foreach ($rows as $row) {
                $barsBySymbol[$row->symbol][] = $row;
            }

            foreach ($barsBySymbol as $symbol => $bars) {
                $written = $this->write1mBarsToRedis($symbol, $assetType, $bars, $skipEvents);
                $totalWritten += $written;
            }

            $this->line("    Chunk processed: {$totalWritten} 1m bars so far...");
        }

        return $totalWritten;
    }

    /**
     * Build 5-minute bars by aggregating 1-minute data from one_minute_prices,
     * then upsert into rt:bars:5m:{date}:stock:{symbol}.
     *
     * This matches the Python daemon's _maybe_aggregate_5m_bar: open=first open,
     * high=max, low=min, close=last close, volume=sum from five 1-minute bars.
     */
    private function hydrate5mBars(
        array $symbols,
        string $assetType,
        Carbon $start,
        Carbon $end,
        bool $skipEvents,
    ): int {
        $totalWritten = 0;

        foreach (array_chunk($symbols, 250) as $chunk) {
            // Pull 1-minute bars with full OHLCV so we can aggregate properly
            $rows = DB::table('one_minute_prices')
                ->select(['symbol', 'ts', 'open', 'high', 'low', 'price', 'volume', 'ts_est'])
                ->where('asset_type', $assetType)
                ->whereIn('symbol', $chunk)
                ->whereBetween('ts', [$start, $end])
                ->orderBy('symbol')
                ->orderBy('ts')
                ->get();

            // Group into 5-minute buckets
            $buckets = [];
            foreach ($rows as $row) {
                $ts = Carbon::parse($row->ts);
                $bucketEpoch = $ts->timestamp - ($ts->timestamp % 300);
                $key = $row->symbol.'|'.$bucketEpoch;
                $buckets[$key][] = $row;
            }

            // Aggregate each bucket into a 5-minute bar
            $aggregated = [];
            foreach ($buckets as $key => $bars) {
                if (count($bars) === 0) {
                    continue;
                }

                $bar0 = $bars[0];
                $barLast = $bars[count($bars) - 1];
                $bucketEpoch = (int) (Carbon::parse($bar0->ts)->timestamp / 300) * 300;

                $high = (float) $bar0->high;
                $low = (float) $bar0->low;
                $volume = 0;
                $vwapNum = 0.0;

                foreach ($bars as $b) {
                    $p = (float) $b->price;
                    $h = (float) $b->high;
                    $l = (float) $b->low;
                    $v = (int) ($b->volume ?? 0);

                    if ($h > $high) {
                        $high = $h;
                    }
                    if ($l < $low) {
                        $low = $l;
                    }
                    $volume += $v;
                    $vwapNum += $p * $v;
                }

                $aggregated[] = (object) [
                    'symbol' => $bar0->symbol,
                    'bucket_epoch' => $bucketEpoch,
                    'ts' => Carbon::createFromTimestamp($bucketEpoch, 'UTC'),
                    'open' => (float) $bar0->open,
                    'high' => $high,
                    'low' => $low,
                    'close' => (float) $barLast->price,
                    'volume' => $volume,
                    'vwap' => $volume > 0 ? round($vwapNum / $volume, 4) : round((float) $barLast->price, 4),
                ];
            }

            // Group by symbol and write
            $aggBySymbol = [];
            foreach ($aggregated as $agg) {
                $aggBySymbol[$agg->symbol][] = $agg;
            }

            foreach ($aggBySymbol as $symbol => $bars) {
                $written = $this->write5mBarsToRedis($symbol, $assetType, $bars, $skipEvents);
                $totalWritten += $written;
            }

            $this->line("    Chunk processed: {$totalWritten} 5m bars so far...");
        }

        return $totalWritten;
    }

    // ─────────────────────────────────────────────────────────────────
    // Redis writers — mirror the Python rt: key patterns from stream_bars.py
    // ─────────────────────────────────────────────────────────────────

    /**
     * Atomic upsert of 1-minute bars into the rt:bars:1m sorted set.
     * Uses the same MULTI/EXEC pattern as the Python daemon:
     *   ZREMRANGEBYSCORE → ZADD → ZREMRANGEBYRANK → EXPIRE → (XADD)
     *
     * @param  array<int, object>  $bars  Row objects with symbol|ts|price|open|high|low|volume|ts_est
     * @return int Number of bars written
     */
    private function write1mBarsToRedis(string $symbol, string $assetType, array $bars, bool $skipEvents): int
    {
        $written = 0;
        $eventsKey = self::RT_PREFIX.'events:bars';

        foreach ($bars as $bar) {
            $epoch = Carbon::parse($bar->ts)->timestamp;
            $dateStr = Carbon::parse($bar->ts)->format('Ymd');
            $barKey = sprintf('%sbars:1m:%s:%s:%s', self::RT_PREFIX, $dateStr, $assetType, strtoupper($symbol));

            $payload = $this->build1mPayload($symbol, $bar, $epoch);
            $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

            $pipe = Redis::pipeline();
            $pipe->zremrangebyscore($barKey, $epoch, $epoch);
            $pipe->zadd($barKey, $epoch, $payloadJson);
            $pipe->zremrangebyrank($barKey, 0, -(self::BARS_1M_RETENTION + 1));
            $pipe->expire($barKey, self::BARS_TTL_SECONDS);
            $pipe->execute();
            $written++;
        }

        return $written;
    }

    /**
     * Atomic upsert of 5-minute bars into the rt:bars:5m sorted set.
     *
     * @param  array<int, object>  $bars  Aggregated bars with open|high|low|close|volume|vwap|bucket_epoch
     * @return int Number of bars written
     */
    private function write5mBarsToRedis(string $symbol, string $assetType, array $bars, bool $skipEvents): int
    {
        $written = 0;

        foreach ($bars as $bar) {
            $epoch = (int) ($bar->bucket_epoch ?? Carbon::parse($bar->ts)->timestamp);
            $dateStr = Carbon::createFromTimestamp($epoch, 'UTC')->format('Ymd');
            $barKey = sprintf('%sbars:5m:%s:%s:%s', self::RT_PREFIX, $dateStr, $assetType, strtoupper($symbol));

            $tsEst = Carbon::createFromTimestamp($epoch, 'UTC')->subHours(4)->format('Y-m-d H:i:s');

            $payload = [
                'ts' => $epoch,
                'ts_est' => $tsEst,
                'symbol' => strtoupper($symbol),
                'open' => round((float) ($bar->open ?? $bar->close ?? 0), 4),
                'high' => round((float) ($bar->high ?? $bar->close ?? 0), 4),
                'low' => round((float) ($bar->low ?? $bar->close ?? 0), 4),
                'close' => round((float) ($bar->close ?? $bar->price ?? 0), 4),
                'volume' => (int) ($bar->volume ?? 0),
                'vwap' => round((float) ($bar->vwap ?? $bar->close ?? 0), 4),
                'is_final' => true,
                'source' => 'mysql_hydration',
            ];
            $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

            $pipe = Redis::pipeline();
            $pipe->zremrangebyscore($barKey, $epoch, $epoch);
            $pipe->zadd($barKey, $epoch, $payloadJson);
            $pipe->zremrangebyrank($barKey, 0, -(self::BARS_5M_RETENTION + 1));
            $pipe->expire($barKey, self::BARS_TTL_SECONDS);
            $pipe->execute();
            $written++;
        }

        return $written;
    }

    /**
     * Build the canonical 1-minute bar JSON payload matching the Python format.
     *
     * @param  object  $bar  Row with price|open|high|low|volume|ts_est fields
     * @return array<string, mixed>
     */
    private function build1mPayload(string $symbol, object $bar, int $epoch): array
    {
        $close = (float) $bar->price;
        $open = isset($bar->open) ? (float) $bar->open : $close;
        $high = isset($bar->high) ? (float) $bar->high : $close;
        $low = isset($bar->low) ? (float) $bar->low : $close;
        $volume = (int) ($bar->volume ?? 0);
        $tsEst = $bar->ts_est ?? Carbon::createFromTimestamp($epoch, 'UTC')->subHours(4)->format('Y-m-d H:i:s');

        return [
            'ts' => $epoch,
            'ts_est' => $tsEst,
            'symbol' => strtoupper($symbol),
            'open' => round($open, 4),
            'high' => round($high, 4),
            'low' => round($low, 4),
            'close' => round($close, 4),
            'volume' => $volume,
            'vwap' => round(($high + $low + $close) / 3, 4),
            'is_final' => true,
            'source' => 'mysql_hydration',
        ];
    }

    /**
     * Set the rt:hydrated:{date}:{asset} marker so the system knows bars are warm.
     */
    private function setHydrationMarker(string $assetType): void
    {
        $dateStr = Carbon::now('UTC')->format('Ymd');
        $key = sprintf('%shydrated:%s:%s', self::RT_PREFIX, $dateStr, $assetType);
        Redis::set($key, '1', 'EX', self::HYDRATION_TTL_SECONDS);
        $this->info("  Hydration marker set: {$key}");
    }
}
