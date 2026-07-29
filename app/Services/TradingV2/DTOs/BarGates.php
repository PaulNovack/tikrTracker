<?php

namespace App\Services\TradingV2\DTOs;

/**
 * Holds ALL computed gate values for one bar event (5m or 1m).
 *
 * Bar data is read from Redis by GateEvaluator, all gates computed once,
 * then this DTO is passed to EvaluateBarJob for per-version threshold checking.
 */
class BarGates
{
    /**
     * @param  string  $timeframe  '5m' or '1m'
     * @param  array<string, float|int|null>  $values  Raw computed gate values
     */
    public function __construct(
        public readonly string $timeframe,
        public readonly array $values,
    ) {}

    /**
     * Return an empty gate set when bar data is insufficient.
     */
    public static function empty(string $timeframe): self
    {
        return new self($timeframe, ['min_bars' => 0]);
    }

    public function get(string $gate): float|int|null
    {
        return $this->values[$gate] ?? null;
    }

    public function toArray(): array
    {
        return $this->values;
    }

    public function isEmpty(): bool
    {
        return ($this->values['min_bars'] ?? 0) === 0;
    }
}
