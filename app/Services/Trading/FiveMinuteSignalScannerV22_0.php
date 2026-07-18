<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * Version 22.1 - Alligator WAKE_UP Signal Scanner
 *
 * Changes from v21.0:
 * - Slightly stronger candidate universe:
 *   - minPrice
 *   - minVolume5m
 *   - optional minNotional5m (helps reduce thin junk before 1m work)
 *
 * Alligator logic remains in OneMinuteEntryFinderV22_0.
 */
class FiveMinuteSignalScannerV22_0
{
    use HasPriceTables;

    private string $version = 'v22.0';

    private string $name = 'Alligator Wake-Up';

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function scan(string $assetType, string $asOfTsEst): array
    {
        $minPrice = 1.0;
        $minVolume5m = 10000;

        // Optional: helps reduce thin symbols before 1m scan
        $minNotional5m = 25000.0;

        $rows = DB::table($this->fiveMinuteTable)
            ->select('symbol', 'price', 'volume')
            ->where('asset_type', $assetType)
            ->where('ts_est', $asOfTsEst)
            ->where('price', '>=', $minPrice)
            ->where('volume', '>=', $minVolume5m)
            ->get();

        $signals = [];

        foreach ($rows as $row) {
            $price = (float) $row->price;
            $vol = (int) $row->volume;
            $notional = $price * $vol;

            if ($notional < $minNotional5m) {
                continue;
            }

            $signals[] = [
                'symbol' => $row->symbol,
                'signal_type' => 'ALLIGATOR_SCAN',
                'signal_ts_est' => $asOfTsEst,
                'asset_type' => $assetType,
                'scan_ts_est' => $asOfTsEst,
                'price_5m' => $price,
                'volume_5m' => $vol,
                'notional_5m' => round($notional, 2),
                'version' => $this->version,
            ];
        }

        return $signals;
    }
}
