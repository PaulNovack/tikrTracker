<?php

namespace App\Services\Trading;

use App\Repositories\RedisBarRepository;

/**
 * Trait UsesRedisForEntryFinding - Redis data source for entry finders.
 *
 * Overrides fetchOneMinuteBars() to read from Redis sorted sets
 * instead of MySQL. The parent entry finder's classification and
 * gating logic runs unchanged — only the bar data source differs.
 *
 * This preserves pipeline-specific entry types that ML models depend on.
 */
trait UsesRedisForEntryFinding
{
    private ?RedisBarRepository $_redisRepo = null;

    private function redisRepo(): RedisBarRepository
    {
        return $this->_redisRepo ??= new RedisBarRepository;
    }

    /**
     * Fetch 1-minute bars from Redis instead of MySQL.
     *
     * Called by the parent entry finder's doFindBestLong().
     * Returns stdClass objects matching the MySQL row format so
     * the parent's VWAP/EMA/ATR calculation and classification
     * work identically.
     *
     * @return array<int, object>
     */
    protected function fetchOneMinuteBars(
        string $symbol,
        string $marketOpen,
        string $asOfTsEst,
    ): array {
        return $this->fetchBarsFromRedis('1m', $symbol, $marketOpen, $asOfTsEst, 420);
    }

    /**
     * Fetch 5-minute bars from Redis instead of MySQL.
     *
     * Called by doFindBestLong() for choppiness detection.
     * Overrides the AbstractOneMinuteEntryFinder method that hits
     * the five_minute_prices MySQL table.
     *
     * @return array<int, object>
     */
    protected function fetchFiveMinuteBarsForAnalysis(
        string $symbol,
        string $marketOpen,
        string $asOfTsEst,
    ): array {
        return $this->fetchBarsFromRedis('5m', $symbol, $marketOpen, $asOfTsEst, 100);
    }

    /**
     * Shared Redis bar fetcher — eliminates duplicate RedisBarRepository logic.
     *
     * @return array<int, object>
     */
    private function fetchBarsFromRedis(
        string $timeframe,
        string $symbol,
        string $marketOpen,
        string $asOfTsEst,
        int $limit,
    ): array {
        $bars = $this->redisRepo()->getBars($timeframe, $symbol, 'stock', $marketOpen, $asOfTsEst, $limit);

        if (count($bars) === 0) {
            return [];
        }

        $result = [];
        foreach ($bars as $bar) {
            $result[] = (object) [
                'ts_est' => $bar->tsEst,
                'open' => $bar->open,
                'high' => $bar->high,
                'low' => $bar->low,
                'close' => $bar->close,
                'price' => $bar->close,
                'volume' => $bar->volume,
            ];
        }

        return $result;
    }
}
