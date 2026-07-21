<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * Version 21.0 - Alligator WAKE_UP Signal Scanner
 * Base: Fresh implementation
 * Purpose: Generate signals for all symbols that pass basic filters
 *
 * v21.0 features:
 * - Simple universe selection based on price and volume
 * - All filtering logic delegated to OneMinuteEntryFinderV21_0 (Alligator WAKE_UP)
 * - No 5-minute signal patterns - just pass through candidates
 */
class FiveMinuteSignalScannerV21_0
{
    use HasPriceTables;

    private string $version = 'v21.0';

    private string $name = 'Alligator Wake-Up';

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Scan 5-minute bars for potential signal candidates
     *
     * Returns all symbols that meet basic criteria:
     * - Have recent 5-minute data
     * - Meet minimum price threshold ($1.00)
     * - Have reasonable volume
     *
     * Actual Alligator WAKE_UP filtering happens in OneMinuteEntryFinderV21_0
     *
     * @param  string  $assetType  'stock' or 'crypto'
     * @param  string  $asOfTsEst  EST timestamp
     * @return array Signal candidates for entry finder
     */
    public function scan(string $assetType, string $asOfTsEst): array
    {
        $minPrice = 1.0;
        $minVolume5m = 10000; // Basic liquidity filter

        // Get symbols with recent 5-minute data that pass basic filters
        $symbols = DB::table($this->fiveMinuteTable)
            ->select('symbol', 'price', 'volume')
            ->where('asset_type', $assetType)
            ->where('ts_est', $asOfTsEst)
            ->where('price', '>=', $minPrice)
            ->where('volume', '>=', $minVolume5m)
            ->get();

        $signals = [];

        foreach ($symbols as $row) {
            $signals[] = [
                'symbol' => $row->symbol,
                'signal_type' => 'ALLIGATOR_SCAN',
                'signal_ts_est' => $asOfTsEst,
                'asset_type' => $assetType,
                'scan_ts_est' => $asOfTsEst,
                'price_5m' => (float) $row->price,
                'volume_5m' => (int) $row->volume,
            ];
        }

        return $signals;
    }
}
