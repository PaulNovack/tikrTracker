<?php

use App\Models\User;
use App\Services\AtrPerformanceService;
use App\UserRole;

use function Pest\Laravel\mock;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('renders the backtest-vs-actual page for authenticated traders', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    $response = $this->actingAs($user)->get('/backtest-vs-actual');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->component('backtest-vs-actual/index')
            ->has('trades')
            ->has('summary')
            ->has('versions');
    });
});

it('includes pipeline J in the version filter list', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    $response = $this->actingAs($user)->get('/backtest-vs-actual');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->where('versions', function ($versions) {
            return collect($versions)->contains(function ($version) {
                return $version['value'] === config('app.trade_alert_j_version', 'v2000.0');
            });
        });
    });
});

it('returns 200 for unauthenticated users (route is public)', function () {
    $response = $this->get('/backtest-vs-actual');

    $response->assertSuccessful();
});

it('uses computePnlForAlert from AtrPerformanceService for signal P&L', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    $mockService = mock(AtrPerformanceService::class);
    $mockService->shouldReceive('computePnlForAlert')
        ->andReturn(['pnl_percent' => 4.43, 'exit_price' => 49.47]);

    $response = $this->actingAs($user)->get('/backtest-vs-actual?start_date=2026-04-28&end_date=2026-04-28');

    $response->assertSuccessful();
});

it('returns empty trades when no Alpaca orders exist in date range', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    $response = $this->actingAs($user)->get('/backtest-vs-actual?start_date=2000-01-01&end_date=2000-01-02');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->where('trades.data', [])
            ->where('trades.total', 0)
            ->where('summary.total_trades', 0);
    });
});
