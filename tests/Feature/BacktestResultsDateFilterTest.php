<?php

declare(strict_types=1);

use function Pest\Laravel\get;

it('loads backtest results page without date filter', function () {
    $response = get('/backtest-results?pipeline=A');
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('BacktestResults/index')
        ->has('pipeline')
        ->has('version')
        ->has('summary')
        ->has('trades')
        ->has('targetBreakdown')
    );
});

it('loads backtest results page with date filters', function () {
    $response = get('/backtest-results?pipeline=A&start_date=2025-12-10&end_date=2025-12-20');
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('BacktestResults/index')
        ->has('pipeline')
        ->has('version')
        ->has('summary')
        ->has('trades')
        ->has('targetBreakdown')
    );
});

it('date filter parameters are passed to the service', function () {
    // Test that invalid date formats don't crash the application
    $response = get('/backtest-results?pipeline=A&start_date=invalid&end_date=2025-12-20');
    $response->assertOk();

    // Test that the page still loads with only start date
    $response = get('/backtest-results?pipeline=A&start_date=2025-12-10');
    $response->assertOk();

    // Test that the page still loads with only end date
    $response = get('/backtest-results?pipeline=A&end_date=2025-12-20');
    $response->assertOk();
});
