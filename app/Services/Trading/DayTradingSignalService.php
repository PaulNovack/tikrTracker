<?php

namespace App\Services\Trading;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DayTradingSignalService
{
    public function getBuySignals(string $assetType = 'stock', ?Carbon $datetime = null): array
    {
        $datetime = $datetime ?? Carbon::now('America/New_York')->startOfMinute();

        // 5-minute trend check
        $trendCandidates = DB::table('five_minute_prices')
            ->select('symbol', 'ema9', 'ema21', 'rsi_14', 'atr', 'volume', 'price', 'ts_est')
            ->where('asset_type', $assetType)
            ->where('ts_est', $datetime)
            ->where('ema9_above_ema21', 1)
            ->where('rsi_14', '>', 55)
            ->whereNotNull('atr')
            ->get();

        $candidates = [];

        foreach ($trendCandidates as $candidate) {
            $latest1m = DB::table('one_minute_prices')
                ->select('ema9', 'ema21', 'vwap', 'rsi_14', 'macd', 'macd_histogram', 'price', 'volume', 'ts_est')
                ->where('symbol', $candidate->symbol)
                ->where('asset_type', $assetType)
                ->where('ts_est', $datetime)
                ->orderBy('ts_est', 'desc')
                ->first();

            if (! $latest1m) {
                continue;
            }

            if (
                $latest1m->ema9 > $latest1m->ema21 &&
                $latest1m->price > $latest1m->vwap &&
                $latest1m->volume > 2 * $candidate->volume // relative volume approximation
            ) {
                $candidates[] = [
                    'symbol' => $candidate->symbol,
                    '5m_price' => $candidate->price,
                    '1m_price' => $latest1m->price,
                    'ts' => $datetime->toDateTimeString(),
                ];
            }
        }

        return $candidates;
    }
}
