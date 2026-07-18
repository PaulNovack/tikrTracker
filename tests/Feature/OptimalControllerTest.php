<?php

it('can access optimal strategy analysis page', function () {
    $user = \App\Models\User::factory()->create();

    $response = $this->actingAs($user)->get('/analysis/optimal');

    $response->assertSuccessful()
        ->assertInertia(function ($page) {
            $page->component('analysis/Optimal')
                ->has('strategyData', function ($strategyData) {
                    $strategyData->has('monthly_return')
                        ->has('annualized_return')
                        ->has('optimal_entry_time')
                        ->has('trades_per_month')
                        ->has('win_rate')
                        ->has('avg_win')
                        ->has('avg_loss')
                        ->has('max_drawdown')
                        ->has('sharpe_ratio');
                })
                ->has('optimizations', 4)
                ->has('topTrades', 4)
                ->has('validation');
        });
});

it('returns correct strategy performance data', function () {
    $user = \App\Models\User::factory()->create();

    $response = $this->actingAs($user)->get('/analysis/optimal');

    $response->assertSuccessful();

    $page = $response->viewData('page');
    $props = $page['props'];

    // Verify breakthrough performance metrics
    expect($props['strategyData']['monthly_return'])->toBe(11.533);
    expect($props['strategyData']['annualized_return'])->toBe(138.4);
    expect($props['strategyData']['optimal_entry_time'])->toBe('10:15 AM ET');
    expect($props['strategyData']['win_rate'])->toBe(62.5);

    // Verify optimizations are present
    expect($props['optimizations'])->toHaveCount(4);
    expect($props['optimizations'][0]['factor'])->toBe('Entry Timing');
    expect($props['optimizations'][0]['improvement'])->toBe('+59.2%');

    // Verify top trades data
    expect($props['topTrades'])->toHaveCount(4);
    expect($props['topTrades'][0]['return'])->toBe('+1.80%');

    // Verify validation metrics
    expect($props['validation']['forward_bias'])->toContain('None - all signals at 10:15 AM');
});

it('shows optimal strategy breakthrough achievements', function () {
    $user = \App\Models\User::factory()->create();

    $response = $this->actingAs($user)->get('/analysis/optimal');

    $response->assertSuccessful()
        ->assertInertia(function ($page) {
            $page->component('analysis/Optimal')
                ->where('strategyData.monthly_return', 11.533)
                ->where('strategyData.optimal_entry_time', '10:15 AM ET')
                ->has('optimizations.0', function ($optimization) {
                    $optimization->has('factor')
                        ->has('improvement')
                        ->has('original')
                        ->has('optimized')
                        ->has('reason');
                });
        });
});
