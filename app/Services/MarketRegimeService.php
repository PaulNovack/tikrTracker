<?php

namespace App\Services;

use App\Models\MarketMover;
use Carbon\Carbon;

class MarketRegimeService
{
    /**
     * Get the market regime for a specific date
     */
    public function getRegimeForDate(string $date): ?array
    {
        $mover = MarketMover::where('trading_date', $date)->first();

        if (! $mover) {
            return null;
        }

        return [
            'date' => $mover->trading_date->format('Y-m-d'),
            'strength' => $mover->strength,
            'label' => $mover->label,
            'bars_4pct_plus' => $mover->bars_4pct_plus,
            'bars_5pct_plus' => $mover->bars_5pct_plus,
            'bars_10pct_plus' => $mover->bars_10pct_plus,
            'max_gain' => $mover->max_gain,
            'mover_count' => count($mover->movers),
        ];
    }

    /**
     * Get the current market regime (today or most recent trading day)
     */
    public function getCurrentRegime(?string $timezone = null): ?array
    {
        $tz = $timezone ?? 'America/New_York';
        $today = Carbon::now($tz)->format('Y-m-d');

        // Try today first
        $regime = $this->getRegimeForDate($today);

        // If not found, get most recent
        if (! $regime) {
            $mover = MarketMover::orderBy('trading_date', 'desc')->first();
            if ($mover) {
                $regime = [
                    'date' => $mover->trading_date->format('Y-m-d'),
                    'strength' => $mover->strength,
                    'label' => $mover->label,
                    'bars_4pct_plus' => $mover->bars_4pct_plus,
                    'bars_5pct_plus' => $mover->bars_5pct_plus,
                    'bars_10pct_plus' => $mover->bars_10pct_plus,
                    'max_gain' => $mover->max_gain,
                    'mover_count' => count($mover->movers),
                ];
            }
        }

        return $regime;
    }

    /**
     * Check if trading should be enabled based on market regime
     */
    public function shouldTrade(?string $date = null, ?string $minLabel = null): bool
    {
        $minLabel = $minLabel ?? config('trading.market_regime.min_label', 'WEAK');

        $regime = $date ? $this->getRegimeForDate($date) : $this->getCurrentRegime();

        if (! $regime) {
            // No data available - default to config setting
            return config('trading.market_regime.trade_without_data', true);
        }

        $labelHierarchy = ['WEAK' => 1, 'MODERATE' => 2, 'STRONG' => 3];
        $minScore = $labelHierarchy[$minLabel] ?? 1;
        $currentScore = $labelHierarchy[$regime['label']] ?? 1;

        return $currentScore >= $minScore;
    }

    /**
     * Get position sizing multiplier based on market regime
     */
    public function getPositionSizeMultiplier(?string $date = null): float
    {
        $regime = $date ? $this->getRegimeForDate($date) : $this->getCurrentRegime();

        if (! $regime) {
            return 1.0; // Default multiplier
        }

        // Adjust position size based on market strength
        return match ($regime['label']) {
            'STRONG' => config('trading.market_regime.strong_multiplier', 1.5),
            'MODERATE' => config('trading.market_regime.moderate_multiplier', 1.0),
            'WEAK' => config('trading.market_regime.weak_multiplier', 0.5),
            default => 1.0,
        };
    }

    /**
     * Get filter strictness based on market regime
     */
    public function getFilterMultiplier(?string $date = null): float
    {
        $regime = $date ? $this->getRegimeForDate($date) : $this->getCurrentRegime();

        if (! $regime) {
            return 1.0;
        }

        // Be more selective on weak days, more permissive on strong days
        return match ($regime['label']) {
            'STRONG' => config('trading.market_regime.strong_filter_multiplier', 0.8), // 80% of normal filters (looser)
            'MODERATE' => config('trading.market_regime.moderate_filter_multiplier', 1.0), // Normal
            'WEAK' => config('trading.market_regime.weak_filter_multiplier', 1.5), // 150% of normal filters (stricter)
            default => 1.0,
        };
    }

    /**
     * Get maximum signals to process based on market regime
     */
    public function getMaxSignals(?string $date = null, int $defaultMax = 25): int
    {
        $regime = $date ? $this->getRegimeForDate($date) : $this->getCurrentRegime();

        if (! $regime) {
            return $defaultMax;
        }

        // Process more signals on strong days, fewer on weak days
        return match ($regime['label']) {
            'STRONG' => (int) ($defaultMax * config('trading.market_regime.strong_signal_multiplier', 1.5)),
            'MODERATE' => $defaultMax,
            'WEAK' => (int) ($defaultMax * config('trading.market_regime.weak_signal_multiplier', 0.5)),
            default => $defaultMax,
        };
    }

    /**
     * Get historical regime statistics
     */
    public function getRegimeStats(int $days = 30): array
    {
        $startDate = Carbon::now('America/New_York')->subDays($days)->format('Y-m-d');

        $stats = MarketMover::where('trading_date', '>=', $startDate)
            ->selectRaw('
                label,
                COUNT(*) as count,
                AVG(strength) as avg_strength,
                AVG(bars_4pct_plus) as avg_bars_4pct,
                AVG(max_gain) as avg_max_gain
            ')
            ->groupBy('label')
            ->get()
            ->keyBy('label')
            ->toArray();

        return $stats;
    }
}
