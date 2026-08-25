<?php

use App\Services\TradingV2\Contracts\BarSourceInterface;
use App\Services\TradingV2\GateEvaluator;

it('calculates distance to hod using close as the denominator', function () {
    $bars = [
        ['high' => 9.90, 'close' => 9.55],
        ['high' => 10.00, 'close' => 9.50],
    ];

    $evaluator = new GateEvaluator(new class implements BarSourceInterface
    {
        public function getBars(string $timeframe, string $symbol, string $asOfTsEst, int $lookbackMinutes, int $limit): array
        {
            return [];
        }

        public function getBarsRange(string $timeframe, string $symbol, string $fromTsEst, string $toTsEst, int $limit): array
        {
            return [];
        }
    });

    $value = $evaluator->computeDistToHod($bars);

    expect($value)->toBeFloat()->toBeGreaterThan(0);
    expect(abs($value - 5.2631578947))->toBeLessThan(0.0001);
});
