<?php

use App\Models\User;
use App\Services\ConfirmedMomentumService;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

test('breakout confirmed page loads successfully', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);
    $user->markEmailAsVerified();

    $response = $this->actingAs($user)->get('/analysis/breakout-confirmed');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->component('analysis/BreakoutConfirmed')
            ->has('title')
            ->has('time')
            ->has('assetType')
            ->has('lookback')
            ->has('minMove')
            ->has('results')
            ->has('metadata');
    });
});

test('breakout confirmed page accepts time parameter', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);
    $user->markEmailAsVerified();
    $testTime = '2024-01-15 14:30:00';

    $response = $this->actingAs($user)->get("/analysis/breakout-confirmed?time={$testTime}");

    $response->assertSuccessful();
    $response->assertInertia(function ($page) use ($testTime) {
        $page->where('time', $testTime);
    });
});

test('breakout confirmed page accepts filter parameters', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);
    $user->markEmailAsVerified();
    $params = [
        'time' => '2024-01-15 14:30:00',
        'asset_type' => 'crypto',
        'lookback' => '60',
        'min_move' => '1.5',
    ];

    $response = $this->actingAs($user)->get('/analysis/breakout-confirmed?'.http_build_query($params));

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->where('assetType', 'crypto')
            ->where('lookback', 60)
            ->where('minMove', 1.5);
    });
});

test('breakout confirmed controller uses confirmed momentum service', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);
    $user->markEmailAsVerified();
    $mockService = mock(ConfirmedMomentumService::class);

    $expectedResults = [
        'candidates' => [],
        'metadata' => [
            'reference_time_est' => '2024-01-15 14:30:00',
            'window_1m_start' => '2024-01-15 14:00:00',
            'window_1m_end' => '2024-01-15 14:30:00',
            'lookback_minutes' => 30,
            'asset_type' => 'stocks',
        ],
    ];

    $mockService->shouldReceive('scanConfirmedMomentum')
        ->once()
        ->andReturn($expectedResults);

    $response = $this->actingAs($user)->get('/analysis/breakout-confirmed?time=2024-01-15 14:30:00');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) use ($expectedResults) {
        $page->has('results', count($expectedResults['candidates']))
            ->has('metadata');
    });
});

test('breakout confirmed controller defaults parameters correctly', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);
    $user->markEmailAsVerified();

    $response = $this->actingAs($user)->get('/analysis/breakout-confirmed');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->where('assetType', 'stocks')
            ->where('lookback', 15)  // Updated to correct default
            ->where('minMove', 0.75);  // Updated to correct default
    });
});
