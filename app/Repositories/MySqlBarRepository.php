<?php

namespace App\Repositories;

use App\DTOs\MarketBar;
use App\Interfaces\BarRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * MySqlBarRepository — reads historical bars from one_minute_prices
 * and five_minute_prices tables. Used for backtest, backfill, and
 * fallback when Redis is cold.
 */
class MySqlBarRepository implements BarRepositoryInterface
{
    /**
     * @return list<MarketBar>
     */
    public function getBars(
        string $timeframe,
        string $symbol,
        string $assetType,
        string $fromTsEst,
        string $asOfTsEst,
        int $limit
    ): array {
        $table = $timeframe === '5m' ? 'five_minute_prices' : 'one_minute_prices';
        $select = $timeframe === '5m'
            ? ['symbol', 'ts', 'price', 'volume', 'ts_est']
            : ['symbol', 'ts', 'price', 'open', 'high', 'low', 'volume', 'ts_est'];

        $rows = DB::table($table)
            ->select($select)
            ->where('asset_type', $assetType)
            ->where('symbol', $symbol)
            ->where('ts_est', '>=', $fromTsEst)
            ->where('ts_est', '<=', $asOfTsEst)
            ->orderBy('ts_est', 'asc')
            ->limit($limit)
            ->get();

        return $this->toDto($rows, $symbol, $assetType, $timeframe);
    }

    /**
     * @param  list<string>  $symbols
     * @return array<string, list<MarketBar>>
     */
    public function getLatestBars(
        string $timeframe,
        array $symbols,
        string $assetType,
        string $asOfTsEst,
        int $limit
    ): array {
        $table = $timeframe === '5m' ? 'five_minute_prices' : 'one_minute_prices';
        $select = $timeframe === '5m'
            ? ['symbol', 'ts', 'price', 'volume', 'ts_est']
            : ['symbol', 'ts', 'price', 'open', 'high', 'low', 'volume', 'ts_est'];

        $rows = DB::table($table)
            ->select($select)
            ->where('asset_type', $assetType)
            ->whereIn('symbol', $symbols)
            ->where('ts_est', '<=', $asOfTsEst)
            ->orderBy('symbol')
            ->orderBy('ts_est', 'desc')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[$row->symbol][] = new MarketBar(
                symbol: strtoupper($row->symbol),
                assetType: $assetType,
                timeframe: $timeframe,
                epoch: strtotime((string) $row->ts),
                tsEst: (string) ($row->ts_est ?? ''),
                open: (float) ($row->open ?? $row->price ?? 0),
                high: (float) ($row->high ?? $row->price ?? 0),
                low: (float) ($row->low ?? $row->price ?? 0),
                close: (float) ($row->price ?? 0),
                volume: (float) ($row->volume ?? 0),
                vwap: null,
                isFinal: true,
            );
        }

        // Trim + reverse each symbol's bars to oldest-first
        foreach ($result as $symbol => $bars) {
            $sliced = array_slice($bars, 0, $limit);
            $result[$symbol] = array_reverse($sliced);
        }

        return $result;
    }

    /**
     * @param  list<MarketBar>  $bars
     */
    public function isFresh(
        array $bars,
        string $asOfTsEst,
        int $maxAgeSeconds
    ): bool {
        if ($bars === []) {
            return false;
        }

        $newest = $bars[0];

        foreach ($bars as $bar) {
            if ($bar->epoch > $newest->epoch) {
                $newest = $bar;
            }
        }

        $asOfEpoch = (int) strtotime($asOfTsEst.' EST');

        return ($asOfEpoch - $newest->epoch) <= $maxAgeSeconds;
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return list<MarketBar>
     */
    private function toDto(mixed $rows, string $symbol, string $assetType, string $timeframe): array
    {
        $bars = [];

        foreach ($rows as $row) {
            $bars[] = new MarketBar(
                symbol: strtoupper($symbol),
                assetType: $assetType,
                timeframe: $timeframe,
                epoch: strtotime((string) $row->ts),
                tsEst: (string) ($row->ts_est ?? ''),
                open: (float) ($row->open ?? $row->price ?? 0),
                high: (float) ($row->high ?? $row->price ?? 0),
                low: (float) ($row->low ?? $row->price ?? 0),
                close: (float) ($row->price ?? 0),
                volume: (float) ($row->volume ?? 0),
                vwap: null,
                isFinal: true,
            );
        }

        return $bars;
    }
}
