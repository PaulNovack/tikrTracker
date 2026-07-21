<?php

namespace App\Services\Trading\Realtime;

use App\Services\TradingSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RealtimeMarketDataService
{
    protected int $maxQuoteAge;

    protected string $oneMinTable;

    public function __construct()
    {
        $this->maxQuoteAge = TradingSettingService::getMaxQuoteAgeSeconds();
        $this->oneMinTable = (string) config('trading_realtime.tables.one_minute_prices', 'one_minute_prices');
    }

    /** @var array<string, array|null> */
    protected array $quoteCache = [];

    /** @var array<string, array|null> */
    protected array $barCache = [];

    /**
     * Per-loop cache of last N bars per symbol, populated by warmUpBars().
     * Keyed by symbol => array of bar arrays (most recent first).
     *
     * @var array<string, array<int, array>>
     */
    protected array $recentBarCache = [];

    /** Clear caches between loops. */
    public function clearCache(): void
    {
        $this->quoteCache = [];
        $this->barCache = [];
        $this->recentBarCache = [];
    }

    /**
     * Bulk preload quotes from MySQL (fast — small PK table).
     * Bars are loaded lazily from Redis via latestPartialOneMinuteBar().
     *
     * @param  string[]  $symbols
     */
    public function warmUpBars(array $symbols): void
    {
        if (empty($symbols)) {
            return;
        }

        $upperSymbols = array_map('strtoupper', $symbols);

        // --- Bulk load latest quotes ---
        $quotes = DB::table('latest_stock_quotes')
            ->whereIn('symbol', $upperSymbols)
            ->get();

        foreach ($quotes as $row) {
            $sym = strtoupper((string) $row->symbol);
            if (empty($row->ask_price) || empty($row->bid_price) || (float) $row->ask_price <= 0) {
                $this->quoteCache[$sym] = null;

                continue;
            }
            $this->quoteCache[$sym] = [
                'symbol' => $sym,
                'bid' => (float) $row->bid_price,
                'ask' => (float) $row->ask_price,
                'bid_qty' => (int) ($row->bid_size ?? 0),
                'ask_qty' => (int) ($row->ask_size ?? 0),
                'ts_est' => $this->utcToEst($row->quote_ts_utc ?? $row->received_at_utc ?? ''),
            ];
        }

        // Mark symbols with no quote as null
        foreach ($upperSymbols as $sym) {
            if (! array_key_exists($sym, $this->quoteCache)) {
                $this->quoteCache[$sym] = null;
            }
        }
    }

    /**
     * Read the latest quote from MySQL latest_stock_quotes.
     * The Python stream_bars.py writes quotes here reliably via REPLACE INTO.
     * Results cached per loop for instant repeat lookups.
     */
    public function latestQuote(string $symbol): ?array
    {
        $sym = strtoupper($symbol);

        if (array_key_exists($sym, $this->quoteCache)) {
            return $this->quoteCache[$sym];
        }

        $row = DB::table('latest_stock_quotes')
            ->where('symbol', $sym)
            ->first();

        if (! $row || empty($row->ask_price) || empty($row->bid_price) || (float) $row->ask_price <= 0) {
            $this->quoteCache[$sym] = null;

            return null;
        }

        return $this->quoteCache[$sym] = [
            'symbol' => $sym,
            'bid' => (float) $row->bid_price,
            'ask' => (float) $row->ask_price,
            'bid_qty' => (int) ($row->bid_size ?? 0),
            'ask_qty' => (int) ($row->ask_size ?? 0),
            'ts_est' => $this->utcToEst($row->quote_ts_utc ?? $row->received_at_utc ?? ''),
        ];
    }

    /**
     * Get the latest 1-minute bar.
     * Tries Redis first (written by stream_bars.py on every bar flush),
     * falls back to MySQL for symbols not yet cached.
     */
    public function latestPartialOneMinuteBar(string $symbol): ?array
    {
        $sym = strtoupper($symbol);

        if (array_key_exists($sym, $this->barCache)) {
            return $this->barCache[$sym];
        }

        $bar = $this->latestBarFromRedis($sym) ?? $this->latestBarFromMysql($sym);

        if ($bar !== null && empty($bar['vwap'])) {
            $bar['vwap'] = round(($bar['high'] + $bar['low'] + $bar['close']) / 3, 4);
        }

        $this->barCache[$sym] = $bar;

        return $bar;
    }

    private function latestBarFromRedis(string $symbol): ?array
    {
        try {
            // Do NOT add the prefix manually — Laravel's Redis client auto-prepends
            // config('database.redis.options.prefix') to every command.
            $key = 'stream:bar:'.$symbol;
            $data = \Illuminate\Support\Facades\Redis::connection()->hgetall($key);

            if (empty($data) || empty($data['ts'])) {
                return null;
            }

            return [
                'symbol' => $symbol,
                'start_ts_est' => (string) ($data['ts_est'] ?? $data['ts'] ?? ''),
                'updated_ts_est' => (string) ($data['ts_est'] ?? $data['ts'] ?? ''),
                'open' => (float) ($data['open'] ?? 0),
                'high' => (float) ($data['high'] ?? 0),
                'low' => (float) ($data['low'] ?? 0),
                'close' => (float) ($data['price'] ?? 0),
                'volume' => (int) ($data['volume'] ?? 0),
                // Computed indicators — populated once Python warm-up + stream run
                'vwap' => isset($data['vwap']) && $data['vwap'] !== '' ? (float) $data['vwap'] : null,
                'vwap_dist_pct' => isset($data['vwap_dist_pct']) && $data['vwap_dist_pct'] !== '' ? (float) $data['vwap_dist_pct'] : null,
                'above_vwap' => isset($data['above_vwap']) && $data['above_vwap'] !== '' ? (int) $data['above_vwap'] : null,
                'ema9' => isset($data['ema9']) && $data['ema9'] !== '' ? (float) $data['ema9'] : null,
                'ema21' => isset($data['ema21']) && $data['ema21'] !== '' ? (float) $data['ema21'] : null,
                'ema9_above_ema21' => isset($data['ema9_above_ema21']) && $data['ema9_above_ema21'] !== '' ? (int) $data['ema9_above_ema21'] : null,
                'atr_pct' => isset($data['atr_pct']) && $data['atr_pct'] !== '' ? (float) $data['atr_pct'] : null,
                'rvol' => isset($data['rvol']) && $data['rvol'] !== '' ? (float) $data['rvol'] : null,
                'avg_vol_20' => isset($data['avg_vol_20']) && $data['avg_vol_20'] !== '' ? (float) $data['avg_vol_20'] : null,
                'move_30m_pct' => isset($data['move_30m_pct']) && $data['move_30m_pct'] !== '' ? (float) $data['move_30m_pct'] : null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function latestBarFromMysql(string $symbol): ?array
    {
        $sym = strtoupper($symbol);

        $row = DB::table($this->oneMinTable)
            ->where('symbol', $sym)
            ->orderByDesc('ts_est')
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'symbol' => $sym,
            'start_ts_est' => (string) $row->ts_est,
            'updated_ts_est' => (string) $row->ts_est,
            'open' => (float) $row->open,
            'high' => (float) $row->high,
            'low' => (float) $row->low,
            'close' => (float) $row->price,
            'volume' => (int) $row->volume,
            'vwap' => isset($row->vwap) ? (float) $row->vwap : null,
            'vwap_dist_pct' => isset($row->vwap_dist_pct) ? (float) $row->vwap_dist_pct : null,
            'above_vwap' => isset($row->above_vwap) ? (int) $row->above_vwap : null,
            'ema9' => isset($row->ema9) ? (float) $row->ema9 : null,
            'ema21' => isset($row->ema21) ? (float) $row->ema21 : null,
            'ema9_above_ema21' => isset($row->ema9_above_ema21) ? (int) $row->ema9_above_ema21 : null,
            'atr_pct' => isset($row->atr_pct) ? (float) $row->atr_pct : null,
            'rvol' => null,      // not stored in DB; Redis only
            'avg_vol_20' => null,
            'move_30m_pct' => null,
        ];
    }

    private function utcToEst(string $utcTs): string
    {
        if ($utcTs === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse($utcTs, 'UTC')
                ->setTimezone('America/New_York')
                ->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $utcTs;
        }
    }

    public function putQuote(array $quote): void
    {
        $this->putLegacyCachedQuote($quote);
    }

    public function putPartialOneMinuteBar(array $bar): void
    {
        $sym = strtoupper((string) ($bar['symbol'] ?? ''));
        if ($sym === '') {
            return;
        }
        if (! isset($this->recentBarCache[$sym])) {
            $this->recentBarCache[$sym] = [];
        }
        array_unshift($this->recentBarCache[$sym], $bar);
        if (count($this->recentBarCache[$sym]) > 5) {
            $this->recentBarCache[$sym] = array_slice($this->recentBarCache[$sym], 0, 5);
        }
    }

    public function quoteAgeSeconds(array $quote): ?int
    {
        if (empty($quote['ts_est'])) {
            return null;
        }

        return CarbonImmutable::parse((string) $quote['ts_est'], 'America/New_York')
            ->diffInSeconds(now('America/New_York'), false);
    }

    public function recentOneMinuteBars(string $symbol, int $limit = 5): array
    {
        $sym = strtoupper($symbol);

        if (array_key_exists($sym, $this->recentBarCache)) {
            $cached = $this->recentBarCache[$sym];
            $sliced = array_slice($cached, 0, $limit);

            return array_reverse($sliced);
        }

        $rows = DB::table($this->oneMinTable)
            ->where('symbol', $sym)
            ->orderByDesc('ts_est')
            ->limit($limit)
            ->get()
            ->map(static fn ($row) => (array) $row)
            ->all();

        $this->recentBarCache[$sym] = $rows;

        return array_reverse($rows);
    }

    public function quoteKey(string $symbol): string
    {
        $prefix = (string) env('REDIS_PREFIX', 'tikrtracker-database-');

        return $prefix.'stream:quote:'.strtoupper($symbol);
    }

    public function partialBarKey(string $symbol): string
    {
        return 'market:partial_1m:'.strtoupper($symbol);
    }

    private function putLegacyCachedQuote(array $quote): void
    {
        $symbol = strtoupper((string) $quote['symbol']);
        \Illuminate\Support\Facades\Cache::put('market:quote:'.$symbol, [
            'symbol' => $symbol,
            'bid' => (float) $quote['bid'],
            'ask' => (float) $quote['ask'],
            'bid_qty' => (int) ($quote['bid_qty'] ?? 0),
            'ask_qty' => (int) ($quote['ask_qty'] ?? 0),
            'ts_est' => (string) $quote['ts_est'],
        ], now()->addSeconds((int) config('trading_realtime.quote_cache_ttl_seconds', 15)));
    }
}
