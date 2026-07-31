<?php

namespace App\Services\TradingV2\Repositories;

use App\Services\TradingV2\Contracts\BarSourceInterface;
use Illuminate\Support\Facades\DB;

/**
 * Reads bars from five_minute_prices / one_minute_prices MySQL tables.
 * Used for backtest/offline path — allows full historical scanning.
 *
 * Bars are cached per (table, symbol, trading_date_est) in memory. The backtest
 * scans the same symbol across many 5-minute steps with overlapping lookback
 * windows, so a single query per symbol per day avoids thousands of redundant
 * MySQL round-trips.
 */
class MySqlBarSource implements BarSourceInterface
{
    /**
     * @var array<string, array<string, array<string, list<object>>>> barCache[table][symbol][tradeDate]
     */
    private static array $barCache = [];

    public function __construct(
        private readonly bool $fullTable = false,
    ) {}

    /**
     * Clear the in-memory bar cache. Call between independent backtest runs
     * to avoid stale data being served across runs.
     */
    public static function clearCache(): void
    {
        self::$barCache = [];
    }

    /**
     * {@inheritDoc}
     */
    public function getBars(string $timeframe, string $symbol, string $asOfTsEst, int $lookbackMinutes, int $limit): array
    {
        $ny = new \DateTimeZone('America/New_York');
        $asOf = new \DateTime($asOfTsEst, $ny);
        $from = (clone $asOf)->modify("-{$lookbackMinutes} minutes");

        return $this->getBarsRange($timeframe, $symbol, $from->format('Y-m-d H:i:s'), $asOf->format('Y-m-d H:i:s'), $limit);
    }

    /**
     * {@inheritDoc}
     */
    public function getBarsRange(string $timeframe, string $symbol, string $fromTsEst, string $toTsEst, int $limit): array
    {
        $table = $this->resolveTable($timeframe);
        $tradeDate = substr($fromTsEst, 0, 10);

        // Serve from in-memory cache when available (backtest-friendly).
        $cacheKey = "{$table}:{$tradeDate}";
        if (! isset(self::$barCache[$cacheKey][$symbol])) {
            self::$barCache[$cacheKey][$symbol] = DB::table($table)
                ->select(['ts_est as tsEst', 'open', 'high', 'low', 'price as close', 'volume', 'vwap'])
                ->where('symbol', $symbol)
                ->where('trading_date_est', $tradeDate)
                ->orderBy('ts_est')
                ->get()
                ->map(fn ($row) => (object) [
                    'tsEst' => $row->tsEst,
                    'open' => (float) $row->open,
                    'high' => (float) $row->high,
                    'low' => (float) $row->low,
                    'close' => (float) $row->close,
                    'volume' => (float) $row->volume,
                    'vwap' => isset($row->vwap) ? (float) $row->vwap : null,
                ])
                ->all();
        }

        $bars = self::$barCache[$cacheKey][$symbol];

        // Slice the cached full-day bars to the requested window.
        $from = $fromTsEst;
        $to = $toTsEst;

        return array_values(array_filter($bars, fn ($b) => $b->tsEst >= $from && $b->tsEst <= $to));
    }

    private function resolveTable(string $timeframe): string
    {
        $base = $timeframe === '5m' ? 'five_minute_prices' : 'one_minute_prices';

        return $this->fullTable ? "{$base}_full" : $base;
    }
}
