<?php

namespace App\Services\Market;

class MarketData
{
    /**
     * Assets to track (crypto only, fetched from database).
     */
    public static function getTrackedAssets(): array
    {
        return \App\Models\AssetInfo::where('asset_type', 'crypto')
            ->whereNull('deleted_at')
            ->orderBy('symbol')
            ->get(['symbol'])
            ->map(fn ($asset) => [
                'type' => 'crypto',
                'symbol' => $asset->symbol,
            ])
            ->toArray();
    }

    /**
     * Stock symbols to track (fetched from database).
     */
    public static function getTrackedStocks(): array
    {
        return \App\Models\AssetInfo::where('asset_type', 'stock')
            ->whereNull('deleted_at')
            ->orderBy('symbol')
            ->get(['symbol'])
            ->map(fn ($asset) => [
                'type' => 'stock',
                'symbol' => $asset->symbol,
            ])
            ->toArray();
    }

    /**
     * Calculate average of array values.
     */
    public static function avg(array $values): float
    {
        $values = array_values(array_filter($values, static fn ($v) => $v !== null));
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        return array_sum($values) / $count;
    }

    /**
     * Calculate Standard Deviation.
     */
    public static function stddev(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }

        $mean = self::avg($values);
        $variance = array_sum(array_map(fn ($x) => pow($x - $mean, 2), $values)) / $n;

        return sqrt($variance);
    }

    /**
     * Calculate RSI (Relative Strength Index).
     * Returns value between 0-100.
     */
    public static function calculateRSI(array $prices, int $period): ?float
    {
        if (count($prices) < $period + 1) {
            return null;
        }

        $gains = [];
        $losses = [];

        for ($i = 1; $i < count($prices); $i++) {
            $change = $prices[$i] - $prices[$i - 1];
            $gains[] = max($change, 0);
            $losses[] = max(-$change, 0);
        }

        // Use only the most recent $period values
        $gains = array_slice($gains, -$period);
        $losses = array_slice($losses, -$period);

        $avgGain = self::avg($gains);
        $avgLoss = self::avg($losses);

        if ($avgLoss == 0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;
        $rsi = 100 - (100 / (1 + $rs));

        return round($rsi, 2);
    }

    /**
     * Calculate Simple Moving Average.
     */
    public static function calculateSMA(array $prices, int $period): ?float
    {
        if (count($prices) < $period) {
            return null;
        }

        $values = array_slice($prices, -$period);

        return self::avg($values);
    }

    /**
     * Calculate Bollinger Bands.
     * Returns [upper, middle, lower].
     */
    public static function calculateBollingerBands(array $prices, int $period, float $stdDevMultiplier): array
    {
        if (count($prices) < $period) {
            return [null, null, null];
        }

        $values = array_slice($prices, -$period);
        $middle = self::avg($values);
        $stdDev = self::stddev($values);

        $upper = $middle + ($stdDevMultiplier * $stdDev);
        $lower = $middle - ($stdDevMultiplier * $stdDev);

        return [$upper, $middle, $lower];
    }

    /**
     * Calculate Rate of Change (ROC) - Price momentum.
     * Returns percentage change over period.
     */
    public static function calculateROC(array $prices, int $period): ?float
    {
        if (count($prices) < $period + 1) {
            return null;
        }

        $current = end($prices);
        $past = $prices[count($prices) - $period - 1];

        if ($past == 0) {
            return null;
        }

        return (($current - $past) / $past) * 100;
    }
}
