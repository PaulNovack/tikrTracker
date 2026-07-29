<?php

namespace App\Services\TradingV2\Contracts;

/**
 * Bar data source abstraction — Redis for live, MySQL for backtests.
 */
interface BarSourceInterface
{
    /** @return list<object{tsEst: string, open: float, high: float, low: float, close: float, volume: float, vwap: ?float}> */
    public function getBars(string $timeframe, string $symbol, string $asOfTsEst, int $lookbackMinutes, int $limit): array;

    /** @return list<object{tsEst: string, open: float, high: float, low: float, close: float, volume: float, vwap: ?float}> */
    public function getBarsRange(string $timeframe, string $symbol, string $fromTsEst, string $toTsEst, int $limit): array;
}
