<?php

use App\Services\ConfirmedMomentumService;
use Carbon\Carbon;

test('confirmed momentum service returns expected structure', function () {
    $service = app(ConfirmedMomentumService::class);

    $time = Carbon::parse('2024-01-15 14:30:00', 'America/New_York');

    $results = $service->scanConfirmedMomentum(
        time: $time,
        assetType: 'stocks',
        lookbackMinutes: 30
    );

    expect($results)
        ->toBeArray()
        ->toHaveKeys(['candidates', 'metadata']);

    expect($results['metadata'])
        ->toBeArray()
        ->toHaveKeys([
            'reference_time_est',
            'window_1m_start',
            'window_1m_end',
            'lookback_minutes',
            'asset_type',
        ]);

    expect($results['candidates'])->toBeArray();

    // If there are candidates, verify structure
    if (! empty($results['candidates'])) {
        $candidate = $results['candidates'][0];
        expect($candidate)->toHaveKeys([
            'symbol',
            'last_price',
            'move_pct',
            'noise_pct',
            'bars_1m',
            'volume_sum_1m',
            'distance_from_high',
            'age_minutes',
            'last_ts_est_1m',
            'recent5_high',
            'last5_open',
            'last5_close',
            'bars_5m',
            'body_pct_5m',
            'last_ts_est_5m',
        ]);
    }
});

test('confirmed momentum service filters require bullish 5m candles', function () {
    $service = app(ConfirmedMomentumService::class);

    $time = Carbon::parse('2024-01-15 14:30:00', 'America/New_York');

    $results = $service->scanConfirmedMomentum(
        time: $time,
        assetType: 'stocks',
        lookbackMinutes: 30,
        strongBodyMinPct: 0.3  // Require at least 0.3% bullish body
    );

    // All candidates should have bullish 5m candles
    foreach ($results['candidates'] as $candidate) {
        expect($candidate['last5_close'])->toBeGreaterThan($candidate['last5_open']);
        expect($candidate['body_pct_5m'])->toBeGreaterThanOrEqual(0.3);
        expect($candidate['last_price'])->toBeGreaterThan($candidate['recent5_high']);
    }
});

test('confirmed momentum service respects minimum move filter', function () {
    $service = app(ConfirmedMomentumService::class);

    $time = Carbon::parse('2024-01-15 14:30:00', 'America/New_York');

    $results = $service->scanConfirmedMomentum(
        time: $time,
        assetType: 'stocks',
        lookbackMinutes: 30,
        minMovePct: 1.0  // Require at least 1% move
    );

    // All candidates should have at least 1% move
    foreach ($results['candidates'] as $candidate) {
        expect($candidate['move_pct'])->toBeGreaterThanOrEqual(1.0);
    }
});

test('confirmed momentum service handles empty results gracefully', function () {
    $service = app(ConfirmedMomentumService::class);

    // Use a very restrictive filter that should return no results
    $time = Carbon::parse('2024-01-15 14:30:00', 'America/New_York');

    $results = $service->scanConfirmedMomentum(
        time: $time,
        assetType: 'stocks',
        lookbackMinutes: 1,  // Very short window
        minMovePct: 50.0     // Unrealistic high move requirement
    );

    expect($results['candidates'])->toBeArray()->toBeEmpty();
    expect($results['metadata'])->toHaveKey('message');
});
