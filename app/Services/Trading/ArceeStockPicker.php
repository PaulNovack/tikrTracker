<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

class ArceeStockPicker
{
    public function pickStocks(): array
    {
        // Step 1: Define technical criteria
        $criteria = [
            'above_vwap' => true,
            'ema9_above_ema21' => true,
            'rsi_14' => ['min' => 40, 'max' => 60],
            'volume' => ['min' => 10000], // 10,000 shares
        ];

        // Step 2: Query database with conditions
        $stocks = DB::table('five_minute_prices')
            ->select('symbol', 'asset_type', 'ts', 'price', 'ema9', 'ema21', 'rsi_14', 'volume', 'vwap')
            ->where('asset_type', '=', 'stock')
            ->where('above_vwap', '=', 1)
            ->where('ema9_above_ema21', '=', 1)
            ->whereBetween('rsi_14', [40, 60])
            ->where('volume', '>=', 10000)
            ->orderBy('price', 'desc')
            ->limit(50)
            ->get();

        // Step 3: Process results
        $results = [];
        foreach ($stocks as $stock) {
            $results[] = [
                'symbol' => $stock->symbol,
                'asset_type' => $stock->asset_type,
                'price' => $stock->price,
                'ema9' => $stock->ema9,
                'ema21' => $stock->ema21,
                'rsi_14' => $stock->rsi_14,
                'volume' => $stock->volume,
                'signal_strength' => $this->calculateSignalStrength($stock),
            ];
        }

        return $results;
    }

    private function calculateSignalStrength(object $stock): int
    {
        $strength = 0;

        // EMA crossover strength
        if ($stock->ema9 > $stock->ema21) {
            $strength += 30;
        }

        // RSI strength
        if ($stock->rsi_14 > 50) {
            $strength += 20;
        }

        // Volume strength
        if ($stock->volume > 100000) {
            $strength += 25;
        }

        // VWAP strength
        if (isset($stock->vwap) && $stock->price > $stock->vwap) {
            $strength += 25;
        }

        return $strength;
    }
}
