<?php

namespace App\Services\TradingV2\Repositories;

use App\Services\TradingV2\Contracts\BarSourceInterface;

/**
 * Reads bars from rt:bars:* sorted sets (Redis).
 * Used for live/realtime path — zero MySQL.
 */
class RedisBarSource implements BarSourceInterface
{
    /**
     * {@inheritDoc}
     */
    public function getBars(string $timeframe, string $symbol, string $asOfTsEst, int $lookbackMinutes, int $limit): array
    {
        $ny = new \DateTimeZone('America/New_York');
        $asOf = new \DateTime($asOfTsEst, $ny);
        $from = (clone $asOf)->modify("-{$lookbackMinutes} minutes");

        return $this->getBarsRange($timeframe, $symbol, $from->format('Y-m-d H:i:s'), $asOf->format('Y-m-d H:i:s'), $limit);
    }

    /**
     * {@inheritDoc}
     */
    public function getBarsRange(string $timeframe, string $symbol, string $fromTsEst, string $toTsEst, int $limit): array
    {
        $minEpoch = strtotime($fromTsEst.' America/New_York');
        $maxEpoch = strtotime($toTsEst.' America/New_York');

        if ($minEpoch > $maxEpoch) {
            [$minEpoch, $maxEpoch] = [$maxEpoch, $minEpoch];
        }

        $dateStr = str_replace('-', '', substr($fromTsEst, 0, 10));
        $key = sprintf('rt:bars:%s:%s:stock:%s', $timeframe, $dateStr, strtoupper($symbol));

        $raw = \Illuminate\Support\Facades\Redis::connection('rt')->zrangebyscore($key, $minEpoch, $maxEpoch, ['limit' => [0, $limit]]);

        if (empty($raw)) {
            return [];
        }

        return array_map(function ($json) {
            $data = json_decode($json, true);

            return (object) [
                'tsEst' => $data['ts'] ?? '',
                'open' => (float) ($data['open'] ?? $data['o'] ?? 0),
                'high' => (float) ($data['high'] ?? $data['h'] ?? 0),
                'low' => (float) ($data['low'] ?? $data['l'] ?? 0),
                'close' => (float) ($data['close'] ?? $data['c'] ?? 0),
                'volume' => (float) ($data['volume'] ?? $data['v'] ?? 0),
                'vwap' => isset($data['vwap']) ? (float) $data['vwap'] : null,
            ];
        }, $raw);
    }
}
