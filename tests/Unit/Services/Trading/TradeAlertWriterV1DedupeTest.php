<?php

use App\Services\Trading\TradeAlertWriterV1;

it('dedupes alerts to one row per symbol per minute', function () {
    $writer = app(TradeAlertWriterV1::class);
    $reflector = new ReflectionMethod($writer, 'buildDedupeKey');
    $reflector->setAccessible(true);

    $signal = [
        'symbol' => 'ZZZTEST1',
        'asset_type' => 'stock',
        'signal_type' => 'MOMO_5D_UNIVERSE',
        'signal_ts_est' => '2026-06-11 11:00:05',
        'meta' => [],
    ];

    $firstEntry = [
        'type' => 'UNIVERSE_ALERT_1M',
        'entry_ts_est' => '2026-06-11 11:00:12',
        'entry' => 45.48,
        'stop' => 45.00,
        'risk_pct' => 0.85,
        'risk_per_share' => 0.48,
        'score' => 74.5,
    ];

    $secondEntry = [
        'type' => 'VWAP_RECLAIM_1M',
        'entry_ts_est' => '2026-06-11 11:00:48',
        'entry' => 45.62,
        'stop' => 45.10,
        'risk_pct' => 0.95,
        'risk_per_share' => 0.52,
        'score' => 72.5,
    ];

    $firstKey = $reflector->invoke($writer, $signal, $firstEntry, '2026-06-11', 'v2000.0', 'J');
    $secondKey = $reflector->invoke($writer, $signal, $secondEntry, '2026-06-11', 'v2000.0', 'J');
    $thirdKey = $reflector->invoke($writer, $signal, [
        ...$secondEntry,
        'entry_ts_est' => '2026-06-11 11:01:02',
    ], '2026-06-11', 'v2000.0', 'J');
    $pipelineMKey = $reflector->invoke($writer, $signal, $secondEntry, '2026-06-11', 'v2000.0', 'M');

    expect($firstKey)->toBe('stock|ZZZTEST1|2026-06-11 11:00:00');
    expect($secondKey)->toBe($firstKey);
    expect($thirdKey)->toBe('stock|ZZZTEST1|2026-06-11 11:01:00');
    expect($pipelineMKey)->toBe('stock|ZZZTEST1|2026-06-11|v2000.0|M');
});
