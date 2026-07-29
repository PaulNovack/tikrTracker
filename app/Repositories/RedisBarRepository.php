<?php

namespace App\Repositories;

use App\DTOs\MarketBar;
use App\Interfaces\BarRepositoryInterface;
use Illuminate\Support\Facades\Redis as RedisFacade;

/**
 * RedisBarRepository — reads completed bars from rt:bars sorted sets
 * and partial bars from rt:partial hashes.
 *
 * Key patterns (from stream_bars.py):
 *   rt:bars:1m:{date}:{asset}:{symbol}   Sorted set   epoch → JSON
 *   rt:bars:5m:{date}:{asset}:{symbol}   Sorted set   epoch → JSON
 *   rt:partial:1m:{asset}:{symbol}        Hash        bar fields
 *
 * Zero MySQL queries — designed for the realtime scanner path.
 */
class RedisBarRepository implements BarRepositoryInterface
{
    private const RT_CONNECTION = 'rt';

    private const RT_PREFIX = 'rt:';

    private const PARTIAL_TTL_CACHE = 30;

    /** @var array<string, MarketBar|null> cache for partial bars */
    private array $partialCache = [];

    /**
     * @param  string  $timeframe  "1m" or "5m"
     * @param  string  $assetType  "stock" or "crypto"
     * @param  string  $fromTsEst  EST datetime string (lower bound)
     * @param  string  $asOfTsEst  EST datetime string (upper bound, exclusive)
     * @param  int  $limit  Max bars to return
     * @return list<MarketBar>
     */
    public function getBars(
        string $timeframe,
        string $symbol,
        string $assetType,
        string $fromTsEst,
        string $asOfTsEst,
        int $limit
    ): array {
        $minEpoch = strtotime($fromTsEst.' America/New_York');
        $maxEpoch = strtotime($asOfTsEst.' America/New_York');

        if ($minEpoch > $maxEpoch) {
            [$minEpoch, $maxEpoch] = [$maxEpoch, $minEpoch];
        }

        $dateStr = str_replace('-', '', substr($fromTsEst, 0, 10));
        $key = sprintf('%sbars:%s:%s:%s:%s', self::RT_PREFIX, $timeframe, $dateStr, $assetType, strtoupper($symbol));

        if ($minEpoch <= 0 || $maxEpoch <= 0) {
            \Log::channel('redis-scan')->warning('[RedisBarRepo] Invalid epoch', [
                'symbol' => $symbol, 'tf' => $timeframe,
                'from' => $fromTsEst, 'to' => $asOfTsEst,
                'minEpoch' => $minEpoch, 'maxEpoch' => $maxEpoch,
            ]);

            return [];
        }

        $raw = RedisFacade::connection(self::RT_CONNECTION)->zrangebyscore($key, $minEpoch, $maxEpoch, ['limit' => [0, $limit]]);

        if (empty($raw)) {
            \Log::channel('redis-scan')->warning('[RedisBarRepo] No bars in Redis', [
                'symbol' => $symbol, 'tf' => $timeframe,
                'key' => $key, 'fromEpoch' => $minEpoch, 'toEpoch' => $maxEpoch,
                'fromEst' => $fromTsEst, 'toEst' => $asOfTsEst,
            ]);
        }

        return $this->parseBars($raw, $symbol, $assetType, $timeframe);
    }

    /**
     * @param  list<string>  $symbols
     * @return array<string, list<MarketBar>>
     */
    public function getLatestBars(
        string $timeframe,
        array $symbols,
        string $assetType,
        string $asOfTsEst,
        int $limit
    ): array {
        $result = [];

        foreach ($symbols as $symbol) {
            $asOfEpoch = strtotime($asOfTsEst.' America/New_York');
            $dateStr = str_replace('-', '', substr($asOfTsEst, 0, 10));
            $key = sprintf('%sbars:%s:%s:%s:%s', self::RT_PREFIX, $timeframe, $dateStr, $assetType, strtoupper($symbol));

            $raw = RedisFacade::connection(self::RT_CONNECTION)->zrevrangebyscore($key, $asOfEpoch, 0, ['limit' => [0, $limit]]);

            $bars = $this->parseBars($raw, $symbol, $assetType, $timeframe);
            $result[$symbol] = array_reverse($bars); // oldest-first
        }

        return $result;
    }

    /**
     * Check whether the newest bar in the set is within maxAgeSeconds of asOfTsEst.
     *
     * @param  list<MarketBar>  $bars
     */
    public function isFresh(
        array $bars,
        string $asOfTsEst,
        int $maxAgeSeconds
    ): bool {
        if ($bars === []) {
            return false;
        }

        $newest = $bars[0];

        foreach ($bars as $bar) {
            if ($bar->epoch > $newest->epoch) {
                $newest = $bar;
            }
        }

        $asOfEpoch = (int) strtotime($asOfTsEst.' America/New_York');

        return ($asOfEpoch - $newest->epoch) <= $maxAgeSeconds;
    }

    /**
     * Read the in-progress partial bar from Redis, if available.
     * Cached for 30 seconds to avoid hammering Redis on every scanner tick.
     */
    public function getPartialBar(string $symbol, string $assetType = 'stock'): ?MarketBar
    {
        $cacheKey = $symbol.'|'.$assetType;

        if (array_key_exists($cacheKey, $this->partialCache)) {
            return $this->partialCache[$cacheKey];
        }

        $key = self::RT_PREFIX.'partial:1m:'.$assetType.':'.strtoupper($symbol);
        $data = RedisFacade::connection(self::RT_CONNECTION)->hgetall($key);

        if (! $data || empty($data['symbol'])) {
            $this->partialCache[$cacheKey] = null;

            return null;
        }

        $bar = new MarketBar(
            symbol: (string) $data['symbol'],
            assetType: $assetType,
            timeframe: '1m',
            epoch: (int) ($data['ts'] ?? 0),
            tsEst: gmdate('Y-m-d H:i:s', (int) ($data['ts'] ?? 0)),
            open: (float) ($data['open'] ?? 0),
            high: (float) ($data['high'] ?? 0),
            low: (float) ($data['low'] ?? 0),
            close: (float) ($data['close'] ?? 0),
            volume: (float) ($data['volume'] ?? 0),
            vwap: null, // partial bars don't carry vwap
            isFinal: false,
        );

        $this->partialCache[$cacheKey] = $bar;

        return $bar;
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Parse raw JSON strings from a sorted-set range into MarketBar DTOs.
     *
     * @param  list<string>  $raw
     * @return list<MarketBar>
     */
    private function parseBars(array $raw, string $symbol, string $assetType, string $timeframe): array
    {
        $bars = [];

        foreach ($raw as $json) {
            $b = json_decode($json, true);

            if (! is_array($b) || ! isset($b['ts'])) {
                continue; // Skip score entries (WITHSCORES interspersed integers)
            }

            $bars[] = new MarketBar(
                symbol: strtoupper($symbol),
                assetType: $assetType,
                timeframe: $timeframe,
                epoch: (int) $b['ts'],
                tsEst: (string) ($b['ts_est'] ?? ''),
                open: (float) ($b['open'] ?? 0),
                high: (float) ($b['high'] ?? 0),
                low: (float) ($b['low'] ?? 0),
                close: (float) ($b['close'] ?? 0),
                volume: (float) ($b['volume'] ?? 0),
                vwap: isset($b['vwap']) ? (float) $b['vwap'] : null,
                isFinal: (bool) ($b['is_final'] ?? true),
                ema9: isset($b['ema9']) ? (float) $b['ema9'] : null,
                ema21: isset($b['ema21']) ? (float) $b['ema21'] : null,
                aboveVwap: isset($b['above_vwap']) ? (int) $b['above_vwap'] : null,
                rsi14: isset($b['rsi_14']) ? (float) $b['rsi_14'] : null,
                bbPosition: isset($b['bb_position']) ? (float) $b['bb_position'] : null,
            );
        }

        return $bars;
    }
}
