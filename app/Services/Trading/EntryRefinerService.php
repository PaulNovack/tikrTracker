<?php

declare(strict_types=1);

namespace App\Services\Trading;

use App\Models\OneMinutePrice;

/**
 * Service to slide pipeline entries to the most recent 1-minute bar.
 *
 * After a finder identifies a pattern match on an older bar, this service
 * moves the entry to the latest available bar so the order reflect the
 * freshest price rather than a stale entry point.
 */
class EntryRefinerService
{
    /**
     * Slide an entry to the latest 1-minute bar for the given symbol.
     *
     * Queries the most recent one-minute price bar (up to the given cutoff)
     * and updates entry_ts_est, entry price, and stop loss proportionally.
     *
     * @param  array{symbol:string,asset_type:string,entry_ts_est:string,entry:float,stop:?float,stop_pct:?float,...}  $entry
     * @param  string  $cutoffTs  Latest allowed timestamp (typically current time EST)
     * @return array Updated entry with slid prices, or original if no newer bar found
     */
    public static function slideToLatest(array $entry, string $cutoffTs): array
    {
        $symbol = $entry['symbol'] ?? '';
        $assetType = $entry['asset_type'] ?? 'stock';

        if (empty($symbol)) {
            return $entry;
        }

        // Find the most recent 1-minute bar at or before cutoff
        $latestBar = OneMinutePrice::where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->where('ts_est', '<=', $cutoffTs)
            ->orderByDesc('ts_est')
            ->first(['ts_est', 'open', 'high', 'low', 'price']);

        if (! $latestBar) {
            return $entry;
        }

        $latestTs = $latestBar->ts_est instanceof \Carbon\Carbon
            ? $latestBar->ts_est->format('Y-m-d H:i:s')
            : (string) $latestBar->ts_est;

        // Don't slide if the latest bar is not actually newer
        if ($latestTs <= $entry['entry_ts_est']) {
            return $entry;
        }

        // Calculate original stop percent if we have stop price
        $originalEntry = (float) ($entry['entry'] ?? 0);
        $originalStop = isset($entry['stop']) ? (float) $entry['stop'] : null;
        $stopPct = null;
        if ($originalEntry > 0 && $originalStop !== null) {
            $stopPct = ($originalEntry - $originalStop) / $originalEntry;
        }

        // Use the latest bar's price (close) as the new entry price
        $newEntry = (float) $latestBar->price;

        // Recalculate stop proportionally
        $newStop = null;
        if ($stopPct !== null && $stopPct > 0) {
            $newStop = round($newEntry * (1 - $stopPct), 4);
        } elseif ($originalStop !== null && $originalEntry > 0) {
            // Fallback: apply same dollar risk
            $riskDollar = $originalEntry - $originalStop;
            $newStop = round($newEntry - $riskDollar, 4);
        }

        // Update entry
        $entry['entry_ts_est'] = $latestTs;
        $entry['entry'] = $newEntry;
        if ($newStop !== null) {
            $entry['stop'] = $newStop;
        }
        if (isset($entry['risk_pct'])) {
            $entry['risk_pct'] = $newStop !== null && $newEntry > 0
                ? round(($newEntry - $newStop) / $newEntry * 100, 2)
                : $entry['risk_pct'];
        }

        return $entry;
    }

    /**
     * Determine if sliding is applicable for the current mode.
     */
    public static function shouldSlide(bool $isBacktest, bool $isRollingWindow): bool
    {
        return ! $isBacktest && ! $isRollingWindow;
    }
}
