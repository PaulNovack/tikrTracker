<?php

namespace App\Services\MarketData;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class MarketRegimeService
{
    public function __construct(private AlpacaMarketDataService $alpaca) {}

    /**
     * Check if a symbol has N consecutive closes above its MA.
     *
     * @param  string  $symbol  Stock symbol (e.g., 'ONEQ', 'SPY', 'QQQ')
     * @param  int  $maPeriod  Moving average period in days (e.g., 20, 50, 200)
     * @param  int  $consecutiveCloses  Number of consecutive closes above MA (e.g., 2)
     * @return bool True if last N closes are all above the MA
     */
    public function hasConsecutiveClosesAboveMA(string $symbol, int $maPeriod = 20, int $consecutiveCloses = 2): bool
    {
        $data = $this->getDailyBarsWithMA($symbol, $maPeriod);

        if ($data->count() < $consecutiveCloses) {
            return false;
        }

        // Get last N bars
        $lastN = $data->take(-$consecutiveCloses);

        // Check all closes are above MA
        return $lastN->every(fn ($bar) => $bar['close'] > $bar['ma']);
    }

    /**
     * Check if a symbol has N consecutive closes below its MA.
     */
    public function hasConsecutiveClosesBelowMA(string $symbol, int $maPeriod = 20, int $consecutiveCloses = 2): bool
    {
        $data = $this->getDailyBarsWithMA($symbol, $maPeriod);

        if ($data->count() < $consecutiveCloses) {
            return false;
        }

        $lastN = $data->take(-$consecutiveCloses);

        return $lastN->every(fn ($bar) => $bar['close'] < $bar['ma']);
    }

    /**
     * Get the most recent close and MA values for a symbol.
     *
     * @return array ['close' => float, 'ma' => float, 'above_ma' => bool, 'distance_pct' => float]
     */
    public function getCurrentMAPosition(string $symbol, int $maPeriod = 20): array
    {
        $data = $this->getDailyBarsWithMA($symbol, $maPeriod);

        if ($data->isEmpty()) {
            return [
                'close' => 0,
                'ma' => 0,
                'above_ma' => false,
                'distance_pct' => 0,
            ];
        }

        $latest = $data->last();

        return [
            'close' => $latest['close'],
            'ma' => $latest['ma'],
            'above_ma' => $latest['close'] > $latest['ma'],
            'distance_pct' => (($latest['close'] - $latest['ma']) / $latest['ma']) * 100,
        ];
    }

    /**
     * Get today's P&L for a symbol (close - previous close).
     *
     * @return float P&L in percentage (e.g., 1.5 for +1.5%, -2.3 for -2.3%)
     */
    public function getTodayPnLPct(string $symbol): float
    {
        $bars = $this->getDailyBars($symbol, days: 5); // Get recent bars

        if ($bars->count() < 2) {
            return 0.0;
        }

        $latest = $bars->last();
        $previous = $bars->slice(-2, 1)->first();

        if (! $previous || $previous['close'] <= 0) {
            return 0.0;
        }

        return (($latest['close'] - $previous['close']) / $previous['close']) * 100;
    }

    /**
     * Check multiple market regime conditions at once.
     *
     * @return array [
     *               'spy' => ['above_20ma' => bool, 'pnl_pct' => float, ...],
     *               'qqq' => [...],
     *               'oneq' => [...]
     *               ]
     */
    public function getMarketRegime(): array
    {
        $symbols = ['SPY', 'QQQ', 'ONEQ'];
        $regime = [];

        foreach ($symbols as $symbol) {
            $regime[strtolower($symbol)] = [
                'above_20ma' => $this->hasConsecutiveClosesAboveMA($symbol, 20, 1),
                'two_closes_above_20ma' => $this->hasConsecutiveClosesAboveMA($symbol, 20, 2),
                'above_50ma' => $this->hasConsecutiveClosesAboveMA($symbol, 50, 1),
                'pnl_pct' => $this->getTodayPnLPct($symbol),
                'ma_position' => $this->getCurrentMAPosition($symbol, 20),
            ];
        }

        return $regime;
    }

    /**
     * Fetch daily bars and calculate moving average.
     *
     * @return Collection Collection of bars with 'ma' field added
     */
    private function getDailyBarsWithMA(string $symbol, int $maPeriod): Collection
    {
        // Fetch extra days to ensure we have enough for MA calculation
        $bars = $this->getDailyBars($symbol, days: $maPeriod + 60);

        if ($bars->count() < $maPeriod) {
            return collect();
        }

        // Calculate simple moving average
        $barsWithMA = $bars->map(function ($bar, $index) use ($bars, $maPeriod) {
            // Need at least $maPeriod bars before current to calculate MA
            if ($index < $maPeriod - 1) {
                $bar['ma'] = null;

                return $bar;
            }

            // Get last $maPeriod bars including current
            $slice = $bars->slice($index - $maPeriod + 1, $maPeriod);
            $avg = $slice->avg('close');

            $bar['ma'] = round($avg, 2);

            return $bar;
        });

        // Filter out bars without MA (early bars)
        return $barsWithMA->filter(fn ($bar) => $bar['ma'] !== null)->values();
    }

    /**
     * Fetch daily bars from Alpaca for a symbol.
     *
     * @return Collection Collection of bars with keys: timestamp, open, high, low, close, volume
     */
    private function getDailyBars(string $symbol, int $days = 120): Collection
    {
        $end = Carbon::now('UTC');
        $start = $end->copy()->subDays($days);

        try {
            $response = $this->alpaca->getDailyBars(
                symbols: [$symbol],
                startUtc: $start,
                endUtc: $end
            );

            $bars = $response['bars'][$symbol] ?? [];

            return collect($bars)->map(function ($bar) {
                return [
                    'timestamp' => $bar['t'],
                    'open' => (float) $bar['o'],
                    'high' => (float) $bar['h'],
                    'low' => (float) $bar['l'],
                    'close' => (float) $bar['c'],
                    'volume' => (int) $bar['v'],
                ];
            })->sortBy('timestamp')->values();
        } catch (\Exception $e) {
            report($e);

            return collect();
        }
    }
}
