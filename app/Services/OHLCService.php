<?php

namespace App\Services;

use App\Models\AssetInfo;
use App\Models\FiveMinutePrice;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class OHLCService
{
    /**
     * Get aggregated OHLC data for a given time range and interval.
     *
     * Supported intervals: '1m', '5m', '15m', '30m', '1h', '4h', '1d'
     */
    public function getOHLCData(
        AssetInfo $asset,
        Carbon $startTime,
        Carbon $endTime,
        string $interval = '5m'
    ): array {
        $cacheKey = sprintf(
            'ohlc-data:%s:%s:%s:%s:%s',
            $asset->symbol,
            $asset->asset_type,
            $startTime->timestamp,
            $endTime->timestamp,
            $interval
        );

        return Cache::remember($cacheKey, 3600, function () use ($asset, $startTime, $endTime, $interval) {
            return $this->computeOHLCData($asset, $startTime, $endTime, $interval);
        });
    }

    /**
     * Compute OHLC data from raw 5-minute prices.
     */
    private function computeOHLCData(
        AssetInfo $asset,
        Carbon $startTime,
        Carbon $endTime,
        string $interval
    ): array {
        // Fetch raw 5-minute prices from database
        $prices = FiveMinutePrice::where('symbol', $asset->symbol)
            ->where('asset_type', $asset->asset_type)
            ->whereBetween('ts', [$startTime, $endTime])
            ->orderBy('ts', 'asc')
            ->get();

        if ($prices->isEmpty()) {
            return [];
        }

        // Group prices by interval
        $groupedByInterval = $this->groupByInterval($prices, $interval, $startTime);

        // Convert groups to OHLC bars
        return $this->barsFromGroups($groupedByInterval);
    }

    /**
     * Group prices by time interval.
     */
    private function groupByInterval(Collection $prices, string $interval, Carbon $startTime): array
    {
        $groups = [];
        $intervalSeconds = $this->intervalToSeconds($interval);

        foreach ($prices as $price) {
            // Calculate the bucket start time for this price
            $bucketStart = clone $startTime;
            $secondsFromStart = $startTime->diffInSeconds($price->ts);
            $bucketIndex = (int) floor($secondsFromStart / $intervalSeconds);
            $bucketStart->addSeconds($bucketIndex * $intervalSeconds);

            $bucketKey = $bucketStart->toDateTimeString();

            if (! isset($groups[$bucketKey])) {
                $groups[$bucketKey] = [
                    'timestamp' => $bucketStart,
                    'prices' => [],
                ];
            }

            $groups[$bucketKey]['prices'][] = $price;
        }

        return $groups;
    }

    /**
     * Convert interval string to seconds.
     */
    private function intervalToSeconds(string $interval): int
    {
        return match ($interval) {
            '1m' => 60,
            '5m' => 300,
            '15m' => 900,
            '30m' => 1800,
            '1h' => 3600,
            '4h' => 14400,
            '1d' => 86400,
            default => 300, // default to 5m
        };
    }

    /**
     * Convert grouped prices to OHLC bars.
     */
    private function barsFromGroups(array $groups): array
    {
        $bars = [];

        foreach ($groups as $group) {
            $prices = collect($group['prices']);

            // Use existing OHLC data if available, otherwise derive from close price
            $opens = $prices
                ->filter(fn ($p) => $p->open !== null)
                ->map(fn ($p) => (float) $p->open)
                ->values();

            $highs = $prices
                ->filter(fn ($p) => $p->high !== null)
                ->map(fn ($p) => (float) $p->high)
                ->values();

            $lows = $prices
                ->filter(fn ($p) => $p->low !== null)
                ->map(fn ($p) => (float) $p->low)
                ->values();

            $closes = $prices->map(fn ($p) => (float) $p->price)->values();

            // Determine OHLC values
            $open = $opens->isNotEmpty()
                ? $opens->first()
                : $closes->first();

            $high = $highs->isNotEmpty()
                ? $highs->max()
                : $closes->max();

            $low = $lows->isNotEmpty()
                ? $lows->min()
                : $closes->min();

            $close = $closes->last();

            // Calculate volume
            $volume = $prices->sum('volume') ?? 0;

            $bars[] = [
                'time' => $group['timestamp']->toDateTimeString(),
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => (int) $volume,
            ];
        }

        return $bars;
    }

    /**
     * Get recommended interval for a time range.
     */
    public function getRecommendedInterval(Carbon $startTime, Carbon $endTime): string
    {
        $days = $startTime->diffInDays($endTime);

        return match (true) {
            $days <= 1 => '5m',
            $days <= 5 => '15m',
            $days <= 30 => '1h',
            $days <= 90 => '4h',
            default => '1d',
        };
    }
}
