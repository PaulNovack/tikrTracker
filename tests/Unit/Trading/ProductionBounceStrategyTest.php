<?php

use App\Services\Trading\ProductionBounceStrategy;
use Carbon\Carbon;

class TestableProductionBounceStrategy extends ProductionBounceStrategy
{
    public function analyzeFromBars(string $symbol, Carbon $asOfTime, array $barsDesc): ?array
    {
        return $this->analyzePrices($symbol, $asOfTime, $barsDesc);
    }
}

function make5mBar(string $tsEst, float $open, float $high, float $low, float $close, int $volume): object
{
    return (object) [
        'ts_est' => $tsEst,
        'open' => $open,
        'high' => $high,
        'low' => $low,
        'price' => $close,
        'volume' => $volume,
    ];
}

it('creates a higher-quality pullback-in-uptrend entry with literal 1R/2R targets', function () {
    $barsAsc = [];

    $start = Carbon::create(2024, 1, 2, 10, 0, 0, 'America/New_York');

    // 1) Establish an uptrend for ~54 bars
    $price = 100.00;
    for ($i = 0; $i < 54; $i++) {
        $ts = $start->copy()->addMinutes($i * 5)->format('Y-m-d H:i:s');
        $open = $price;
        $close = $price + 0.06;
        $high = max($open, $close) + 0.10;
        $low = min($open, $close) - 0.10;
        $barsAsc[] = make5mBar($ts, $open, $high, $low, $close, 8000);
        $price = $close;
    }

    // 2) Controlled pullback (5 bars)
    for ($i = 54; $i < 59; $i++) {
        $ts = $start->copy()->addMinutes($i * 5)->format('Y-m-d H:i:s');
        $open = $price;
        $drop = $i === 58 ? 0.55 : 0.28;
        $close = $price - $drop;
        $high = max($open, $close) + 0.05;
        $low = min($open, $close) - 0.12;
        $barsAsc[] = make5mBar($ts, $open, $high, $low, $close, 9000);
        $price = $close;
    }

    // 3) Reversal bar as the LATEST bar (bar 59)
    // Previous bar is the last pullback bar we just added.
    $prevBar = $barsAsc[count($barsAsc) - 1];
    $prevHigh = (float) $prevBar->high;
    $prevClose = (float) $prevBar->price;

    $tsCur = $start->copy()->addMinutes(59 * 5)->format('Y-m-d H:i:s');
    $curOpen = $prevClose - 0.06;
    $curClose = $prevHigh + 0.22; // break prior high
    $curHigh = $curClose + 0.06;
    $curLow = $curOpen - 0.14;
    $barsAsc[] = make5mBar($tsCur, $curOpen, $curHigh, $curLow, $curClose, 24000);

    // Strategy expects bars DESC (latest first)
    $barsDesc = array_reverse($barsAsc);

    $strategy = new TestableProductionBounceStrategy;
    $asOf = Carbon::createFromFormat('Y-m-d H:i:s', $barsDesc[0]->ts_est, 'America/New_York');

    $opp = $strategy->analyzeFromBars('TEST', $asOf, $barsDesc);

    expect($opp)->not()->toBeNull();
    expect($opp['type'])->toBe('production_bounce');
    expect($opp)->toHaveKeys(['entry', 'stop', 'targets', 'risk_pct', 'score']);
    expect($opp['targets'])->toHaveKeys(['1R', '2R']);

    // Stop-entry breakout: planned entry should be at/above the signal bar high.
    expect($opp['entry'])->toBeGreaterThanOrEqual($curHigh);

    $riskPerShare = $opp['entry'] - $opp['stop'];
    expect(abs($opp['targets']['1R'] - ($opp['entry'] + $riskPerShare)))->toBeLessThan(0.0001);
    expect(abs($opp['targets']['2R'] - ($opp['entry'] + ($riskPerShare * 2))))->toBeLessThan(0.0001);

    $riskPct = ($riskPerShare / $opp['entry']) * 100;
    expect(abs($opp['risk_pct'] - $riskPct))->toBeLessThan(0.0001);
});

it('rejects falling-knife bounces in a downtrend', function () {
    $barsAsc = [];

    $start = Carbon::create(2024, 1, 2, 10, 0, 0, 'America/New_York');
    $price = 100.00;

    // Persistent downtrend
    for ($i = 0; $i < 59; $i++) {
        $ts = $start->copy()->addMinutes($i * 5)->format('Y-m-d H:i:s');
        $open = $price;
        $close = $price - 0.20;
        $high = max($open, $close) + 0.08;
        $low = min($open, $close) - 0.10;
        $barsAsc[] = make5mBar($ts, $open, $high, $low, $close, 12000);
        $price = $close;
    }

    // A flashy bounce candle on high volume (should still be rejected by trend filter)
    $ts = $start->copy()->addMinutes(59 * 5)->format('Y-m-d H:i:s');
    $open = $price;
    $close = $price + 0.80;
    $high = $close + 0.10;
    $low = $open - 0.15;
    $barsAsc[] = make5mBar($ts, $open, $high, $low, $close, 40000);

    $barsDesc = array_reverse($barsAsc);

    $strategy = new TestableProductionBounceStrategy;
    $asOf = Carbon::createFromFormat('Y-m-d H:i:s', $barsDesc[0]->ts_est, 'America/New_York');

    $opp = $strategy->analyzeFromBars('TEST', $asOf, $barsDesc);

    expect($opp)->toBeNull();
});
