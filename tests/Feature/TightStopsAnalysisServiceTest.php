<?php

use App\Services\TightStopsAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can instantiate the tight stops analysis service', function () {
    $service = new TightStopsAnalysisService;

    expect($service)->toBeInstanceOf(TightStopsAnalysisService::class);
});

it('returns array from findBestPicksForTightStops', function () {
    $service = app(TightStopsAnalysisService::class);

    $result = $service->findBestPicksForTightStops(null, 60, 0.01, 0.005, 'stock');

    expect($result)->toBeArray();
});

it('returns proper analysis summary', function () {
    $service = app(TightStopsAnalysisService::class);

    $summary = $service->getAnalysisSummary(null, 120, 0.01, 0.005, 'stock');

    expect($summary)->toBeArray()
        ->and($summary)->toHaveKeys([
            'end_est',
            'lookback_minutes',
            'max_drawdown_pct',
            'min_trend_pct',
            'asset_type',
            'max_drawdown_display',
            'min_trend_display',
        ])
        ->and($summary['lookback_minutes'])->toBe(120)
        ->and($summary['max_drawdown_pct'])->toBe(0.01)
        ->and($summary['min_trend_pct'])->toBe(0.005)
        ->and($summary['asset_type'])->toBe('stock');
});

it('throws exception for invalid lookback minutes', function () {
    $service = app(TightStopsAnalysisService::class);

    expect(function () use ($service) {
        $service->findBestPicksForTightStops(null, 0, 0.01, 0.005, 'stock');
    })->toThrow(InvalidArgumentException::class);
});
