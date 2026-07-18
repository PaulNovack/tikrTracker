<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MarketMoversService
{
    private const MAX_BARS = 200;

    public function calculateForDateRange(string $startDate, string $endDate, bool $useFullTables = false): array
    {
        $fiveMinuteTable = $useFullTables ? 'five_minute_prices_full' : 'five_minute_prices';

        // Query market movers data with symbols
        $results = DB::select("
            SELECT 
                trading_date_est,
                COUNT(CASE WHEN ((price - open) / open) * 100 >= 10 THEN 1 END) as bars_10pct_plus,
                COUNT(CASE WHEN ((price - open) / open) * 100 >= 5 THEN 1 END) as bars_5pct_plus,
                COUNT(CASE WHEN ((price - open) / open) * 100 >= 4 THEN 1 END) as bars_4pct_plus,
                ROUND(MAX(((price - open) / open) * 100), 2) as max_gain
            FROM {$fiveMinuteTable}
            WHERE open > 0 AND trading_date_est >= ? AND trading_date_est <= ?
            GROUP BY trading_date_est
            ORDER BY trading_date_est DESC
        ", [$startDate, $endDate]);

        // Calculate strength scores and fetch movers for each date
        return collect($results)->map(function ($row) use ($useFullTables) {
            $strength = min(100, round(($row->bars_4pct_plus / self::MAX_BARS) * 100));
            $label = $strength >= 70 ? 'STRONG' : ($strength >= 40 ? 'MODERATE' : 'WEAK');

            // Get all movers for this date
            $movers = $this->getMoversForDate($row->trading_date_est, $useFullTables);

            return [
                'date' => $row->trading_date_est,
                'bars_4pct_plus' => $row->bars_4pct_plus,
                'bars_5pct_plus' => $row->bars_5pct_plus,
                'bars_10pct_plus' => $row->bars_10pct_plus,
                'max_gain' => $row->max_gain,
                'strength' => $strength,
                'label' => $label,
                'movers' => $movers,
            ];
        })->toArray();
    }

    public function calculateForDate(string $date, bool $useFullTables = false): array
    {
        $fiveMinuteTable = $useFullTables ? 'five_minute_prices_full' : 'five_minute_prices';

        // Query market movers data for a single date
        $result = DB::selectOne("
            SELECT 
                trading_date_est,
                COUNT(CASE WHEN ((price - open) / open) * 100 >= 10 THEN 1 END) as bars_10pct_plus,
                COUNT(CASE WHEN ((price - open) / open) * 100 >= 5 THEN 1 END) as bars_5pct_plus,
                COUNT(CASE WHEN ((price - open) / open) * 100 >= 4 THEN 1 END) as bars_4pct_plus,
                ROUND(MAX(((price - open) / open) * 100), 2) as max_gain
            FROM {$fiveMinuteTable}
            WHERE open > 0 AND trading_date_est = ?
            GROUP BY trading_date_est
        ", [$date]);

        if (! $result) {
            return [];
        }

        $strength = min(100, round(($result->bars_4pct_plus / self::MAX_BARS) * 100));
        $label = $strength >= 70 ? 'STRONG' : ($strength >= 40 ? 'MODERATE' : 'WEAK');

        // Get all movers for this date
        $movers = $this->getMoversForDate($date, $useFullTables);

        return [
            'date' => $date,
            'bars_4pct_plus' => $result->bars_4pct_plus,
            'bars_5pct_plus' => $result->bars_5pct_plus,
            'bars_10pct_plus' => $result->bars_10pct_plus,
            'max_gain' => $result->max_gain,
            'strength' => $strength,
            'label' => $label,
            'movers' => $movers,
        ];
    }

    private function getMoversForDate(string $date, bool $useFullTables = false): array
    {
        $fiveMinuteTable = $useFullTables ? 'five_minute_prices_full' : 'five_minute_prices';

        // Get all movers for a specific date (symbols that had 4%+ gains)
        $movers = DB::select("
            SELECT 
                symbol,
                ROUND(MAX(((price - open) / open) * 100), 2) as max_gain_pct
            FROM {$fiveMinuteTable}
            WHERE open > 0 
                AND trading_date_est = ?
                AND ((price - open) / open) * 100 >= 4
            GROUP BY symbol
            ORDER BY max_gain_pct DESC
        ", [$date]);

        return collect($movers)->map(function ($mover) {
            return [
                'symbol' => $mover->symbol,
                'gain_pct' => $mover->max_gain_pct,
            ];
        })->toArray();
    }

    /**
     * Get top N movers for a specific date (for universe expansion)
     */
    public function getTopMoversSymbols(string $date, int $limit = 100): array
    {
        $movers = DB::select('
            SELECT 
                symbol,
                ROUND(MAX(((price - open) / open) * 100), 2) as max_gain_pct
            FROM five_minute_prices
            WHERE open > 0 
                AND trading_date_est = ?
                AND ((price - open) / open) * 100 >= 4
            GROUP BY symbol
            ORDER BY max_gain_pct DESC
            LIMIT ?
        ', [$date, $limit]);

        return collect($movers)->pluck('symbol')->toArray();
    }

    /**
     * Get today's top movers from database (fast lookup).
     * Falls back to most recent trading day if exact date not found.
     * Aggregates from multiple recent days if needed to fill requested count.
     *
     * Cached per (date, limit) for 60 minutes — market movers data is immutable during the trading day.
     */
    public function getTodaysTopMoversFromCache(?string $date = null, int $limit = 100): array
    {
        $date = $date ?? now('America/New_York')->format('Y-m-d');

        $cacheKey = "market_movers_top:{$date}:{$limit}";
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $lock = Cache::lock("lock:{$cacheKey}", 60);

        if ($lock->get()) {
            try {
                $result = $this->computeTopMovers($date, $limit);
                Cache::put($cacheKey, $result, 3600); // 60 minutes

                return $result;
            } finally {
                $lock->release();
            }
        }

        return Cache::get($cacheKey) ?? $this->computeTopMovers($date, $limit);
    }

    private function computeTopMovers(string $date, int $limit): array
    {
        $date = $date ?? now('America/New_York')->format('Y-m-d');

        // Get movers from recent trading days (up to 10 days back to fill count)
        $movers = \App\Models\MarketMover::where('trading_date', '<=', $date)
            ->orderBy('trading_date', 'desc')
            ->limit(10)
            ->get();

        if ($movers->isEmpty()) {
            return [];
        }

        // Aggregate unique symbols from multiple days until we have enough
        $symbols = [];
        foreach ($movers as $mover) {
            if (empty($mover->movers)) {
                continue;
            }

            foreach ($mover->movers as $moverData) {
                if (! isset($symbols[$moverData['symbol']])) {
                    $symbols[$moverData['symbol']] = $moverData['gain_pct'];

                    if (count($symbols) >= $limit) {
                        break 2;
                    }
                }
            }
        }

        // Return symbols in order of gain_pct (already sorted in each day's data)
        return array_keys($symbols);
    }
}
