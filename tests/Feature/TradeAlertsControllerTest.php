<?php

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create test trade alert data using the correct schema
    DB::table('trade_alerts')->insert([
        [
            'symbol' => 'AAPL',
            'asset_type' => 'stock',
            'trading_date_est' => now()->format('Y-m-d'),
            'as_of_ts_est' => now(),
            'signal_type' => 'MOMENTUM_BUY',
            'signal_ts_est' => now(),
            'entry_type' => 'BREAKOUT_1M',
            'entry_ts_est' => now(),
            'entry' => 150.25,
            'stop' => 148.50,
            'risk_pct' => 1.16,
            'score' => 4.5,
            'dedupe_key' => 'AAPL_MOMENTUM_BUY_'.now()->format('Y-m-d_H:i'),
            'meta' => json_encode([
                'change_percent' => 2.5,
                'confidence' => 0.85,
                'position_size_usd' => 1000,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'symbol' => 'TSLA',
            'asset_type' => 'stock',
            'trading_date_est' => now()->subHours(2)->format('Y-m-d'),
            'as_of_ts_est' => now()->subHours(2),
            'signal_type' => 'UNIVERSE_1M',
            'signal_ts_est' => now()->subHours(2),
            'entry_type' => 'VWAP_RECLAIM_1M',
            'entry_ts_est' => now()->subHours(2),
            'entry' => 245.80,
            'stop' => 242.00,
            'risk_pct' => 1.55,
            'score' => 3.2,
            'dedupe_key' => 'TSLA_UNIVERSE_1M_'.now()->subHours(2)->format('Y-m-d_H:i'),
            'meta' => json_encode([
                'change_percent' => -1.2,
                'confidence' => 0.72,
                'position_size_usd' => 2000,
            ]),
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ],
    ]);
});

it('can access trade alerts page when authenticated', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    $response = $this->actingAs($user)->get('/trade-alerts');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->component('trade-alerts/index'));
});

it('redirects to disclaimer when not authenticated', function () {
    $response = $this->get('/trade-alerts');

    // Should redirect through disclaimer middleware
    $response->assertRedirect();
});

it('returns paginated alerts data', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    $response = $this->actingAs($user)->get('/trade-alerts');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->has('alerts')
        ->has('pagination')
        ->has('pagination.current_page')
        ->has('pagination.total')
        ->where('alerts.0.symbol', 'AAPL') // Most recent first
    );
});

it('formats alert data correctly', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    $response = $this->actingAs($user)->get('/trade-alerts');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->where('alerts.0', function ($alert) {
        return isset($alert['formatted_time'])
            && isset($alert['time_ago'])
            && isset($alert['meta'])
            && $alert['symbol'] === 'AAPL';
    })
    );
});

it('only shows alerts from last 24 hours', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    // Add an old alert that should not appear
    DB::table('trade_alerts')->insert([
        'symbol' => 'OLD',
        'asset_type' => 'stock',
        'trading_date_est' => now()->subDays(2)->format('Y-m-d'),
        'as_of_ts_est' => now()->subDays(2),
        'signal_type' => 'TEST',
        'signal_ts_est' => now()->subDays(2),
        'entry_type' => 'TEST_ENTRY',
        'entry_ts_est' => now()->subDays(2),
        'entry' => 100.00,
        'stop' => 95.00,
        'risk_pct' => 5.0,
        'score' => 1.0,
        'dedupe_key' => 'OLD_TEST_'.now()->subDays(2)->format('Y-m-d_H:i'),
        'meta' => json_encode([]),
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);

    $response = $this->actingAs($user)->get('/trade-alerts');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->where('alerts', function ($alerts) {
        // Should only have the 2 recent alerts, not the old one
        return count($alerts) === 2
            && collect($alerts)->every(fn ($alert) => $alert['symbol'] !== 'OLD');
    })
    );
});

it('filters alerts by ML minimum threshold', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    // Add alerts with different ML probabilities
    DB::table('trade_alerts')->insert([
        'symbol' => 'NVDA',
        'asset_type' => 'stock',
        'trading_date_est' => now()->format('Y-m-d'),
        'as_of_ts_est' => now(),
        'signal_type' => 'MOMENTUM_BUY',
        'signal_ts_est' => now(),
        'entry_type' => 'BREAKOUT_1M',
        'entry_ts_est' => now(),
        'entry' => 500.00,
        'stop' => 495.00,
        'risk_pct' => 1.0,
        'score' => 5.0,
        'ml_win_prob' => 0.75, // 75%
        'dedupe_key' => 'NVDA_MOMENTUM_BUY_'.now()->format('Y-m-d_H:i'),
        'meta' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('trade_alerts')->insert([
        'symbol' => 'AMD',
        'asset_type' => 'stock',
        'trading_date_est' => now()->format('Y-m-d'),
        'as_of_ts_est' => now(),
        'signal_type' => 'MOMENTUM_BUY',
        'signal_ts_est' => now(),
        'entry_type' => 'BREAKOUT_1M',
        'entry_ts_est' => now(),
        'entry' => 150.00,
        'stop' => 148.00,
        'risk_pct' => 1.3,
        'score' => 4.0,
        'ml_win_prob' => 0.55, // 55%
        'dedupe_key' => 'AMD_MOMENTUM_BUY_'.now()->format('Y-m-d_H:i'),
        'meta' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Filter with ml_min=60 should only show NVDA (75%)
    $response = $this->actingAs($user)->get('/trade-alerts?ml_min=60');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->where('mlMinThreshold', 60)
        ->where('alerts', function ($alerts) {
            // Should only include NVDA (75%), not AMD (55%) or AAPL/TSLA (null)
            return count($alerts) === 1
                && $alerts[0]['symbol'] === 'NVDA';
        })
    );
});

it('shows all alerts when ML filter is not applied', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    // Add alert with ML probability
    DB::table('trade_alerts')->insert([
        'symbol' => 'NVDA',
        'asset_type' => 'stock',
        'trading_date_est' => now()->format('Y-m-d'),
        'as_of_ts_est' => now(),
        'signal_type' => 'MOMENTUM_BUY',
        'signal_ts_est' => now(),
        'entry_type' => 'BREAKOUT_1M',
        'entry_ts_est' => now(),
        'entry' => 500.00,
        'stop' => 495.00,
        'risk_pct' => 1.0,
        'score' => 5.0,
        'ml_win_prob' => 0.75,
        'dedupe_key' => 'NVDA_MOMENTUM_BUY_'.now()->format('Y-m-d_H:i'),
        'meta' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->get('/trade-alerts');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->where('mlMinThreshold', 0)
        ->where('alerts', function ($alerts) {
            // Should include all 3 alerts (AAPL, TSLA, NVDA)
            return count($alerts) === 3;
        })
    );
});
