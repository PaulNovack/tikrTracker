<?php

namespace App\Interfaces;

use App\DTOs\MarketBar;

interface BarRepositoryInterface
{
    /**
     * Get historical bars for a single symbol in the given time range.
     *
     * @return list<MarketBar>
     */
    public function getBars(
        string $timeframe,
        string $symbol,
        string $assetType,
        string $fromTsEst,
        string $asOfTsEst,
        int $limit
    ): array;

    /**
     * Get the latest N bars for multiple symbols at once.
     *
     * @param  list<string>  $symbols
     * @return array<string, list<MarketBar>>
     */
    public function getLatestBars(
        string $timeframe,
        array $symbols,
        string $assetType,
        string $asOfTsEst,
        int $limit
    ): array;

    /**
     * Check whether a set of bars is fresh enough relative to an as-of time.
     */
    public function isFresh(
        array $bars,
        string $asOfTsEst,
        int $maxAgeSeconds
    ): bool;
}
