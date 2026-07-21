<?php

declare(strict_types=1);

namespace App\Contracts\Trading;

interface OneMinuteEntryFinderContract
{
    /**
     * Find the best long entry for a symbol at the given timestamp.
     *
     * Called by the realtime pipeline's RealtimeEntryTriggerService.
     *
     * @return array|null Entry array with keys like entry_ts_est, entry_price, score, reason
     *                    or null if no valid entry exists.
     */
    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
    ): ?array;
}
