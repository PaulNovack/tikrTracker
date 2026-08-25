<?php

use App\Models\DisclaimerAcceptance;
use App\Models\User;
use App\UserRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-21 09:00:00', 'America/New_York'));

    insertTradeAlert([
        'symbol' => 'OLD',
        'trading_date_est' => '2026-07-01',
        'as_of_ts_est' => '2026-07-01 09:30:00',
        'signal_ts_est' => '2026-07-01 09:30:00',
        'entry_ts_est' => '2026-07-01 09:31:00',
        'ml_win_prob' => 0.60,
        'pnl_percent' => 1.25,
        'pipeline_run' => 'A',
    ]);

    insertTradeAlert([
        'symbol' => 'AAA',
        'trading_date_est' => '2026-08-05',
        'as_of_ts_est' => '2026-08-05 09:30:00',
        'signal_ts_est' => '2026-08-05 09:30:00',
        'entry_ts_est' => '2026-08-05 09:31:00',
        'ml_win_prob' => 0.50,
        'pnl_percent' => 2.50,
        'pipeline_run' => 'A',
    ]);

    insertTradeAlert([
        'symbol' => 'AAB',
        'trading_date_est' => '2026-08-05',
        'as_of_ts_est' => '2026-08-05 10:00:00',
        'signal_ts_est' => '2026-08-05 10:00:00',
        'entry_ts_est' => '2026-08-05 10:01:00',
        'ml_win_prob' => 0.40,
        'pnl_percent' => -1.00,
        'pipeline_run' => 'B',
    ]);

    insertTradeAlert([
        'symbol' => 'BBC',
        'trading_date_est' => '2026-08-05',
        'as_of_ts_est' => '2026-08-05 10:30:00',
        'signal_ts_est' => '2026-08-05 10:30:00',
        'entry_ts_est' => '2026-08-05 10:31:00',
        'ml_win_prob' => 0.45,
        'pnl_percent' => 0.75,
        'pipeline_run' => 'C',
    ]);

    insertTradeAlert([
        'symbol' => 'BBB',
        'trading_date_est' => '2026-08-20',
        'as_of_ts_est' => '2026-08-20 09:30:00',
        'signal_ts_est' => '2026-08-20 09:30:00',
        'entry_ts_est' => '2026-08-20 09:31:00',
        'ml_win_prob' => 0.55,
        'pnl_percent' => 3.00,
        'pipeline_run' => 'B',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function insertTradeAlert(array $attributes): void
{
    DB::table('trade_alerts')->insert(array_merge([
        'symbol' => 'TEST',
        'asset_type' => 'stock',
        'trading_date_est' => '2026-08-01',
        'as_of_ts_est' => '2026-08-01 09:30:00',
        'signal_type' => 'TEST_SIGNAL',
        'signal_ts_est' => '2026-08-01 09:30:00',
        'entry_type' => 'TEST_ENTRY',
        'entry_ts_est' => '2026-08-01 09:31:00',
        'entry' => 100,
        'stop' => 95,
        'risk_pct' => 5,
        'score' => 1,
        'ml_win_prob' => 0.5,
        'dedupe_key' => 'trades-per-day-'.uniqid('', true),
        'pipeline_run' => 'A',
    ], $attributes));
}

it('shows daily trade counts for the last 30 days using ml thresholds', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);
    DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test');

    $response = $this->actingAs($user)->get('/analysis/trades-per-day');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('analysis/TradesPerDay')
        ->where('summary.start_date', '2026-07-23')
        ->where('summary.end_date', '2026-08-21')
        ->where('summary.total_trades', 3)
        ->where('summary.total_invested_10k', 30000.0)
        ->where('summary.total_profit_10k', 625.0)
        ->where('summary.active_days', 2)
        ->where('summary.avg_trades_per_day', 1.5)
        ->where('summary.peak_day', '2026-08-05')
        ->where('summary.peak_day_trades', 2)
        ->where('dailyBreakdowns.0.date', '2026-08-05')
        ->where('dailyBreakdowns.0.trade_count', 2)
        ->where('dailyBreakdowns.0.invested_10k', 20000)
        ->where('dailyBreakdowns.0.total_profit_10k', 325.0)
        ->where('dailyBreakdowns.0.pnl_percent', 1.63)
        ->where('dailyBreakdowns.0.trades.0.symbol', 'AAA')
        ->where('dailyBreakdowns.0.trades.0.pipeline_run', 'A')
        ->where('dailyBreakdowns.0.trades.0.ml_threshold', 0.45)
        ->where('dailyBreakdowns.0.trades.0.profit_10k', 250.0)
        ->where('dailyBreakdowns.1.date', '2026-08-20')
        ->where('dailyBreakdowns.1.trade_count', 1)
        ->where('dailyBreakdowns.1.invested_10k', 10000)
        ->where('dailyBreakdowns.1.total_profit_10k', 300.0)
        ->where('dailyBreakdowns.1.pnl_percent', 3.0)
        ->where('pipelineThresholds.A', 0.45)
    );
});

it('respects a custom date range', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);
    DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test');

    $response = $this->actingAs($user)->get('/analysis/trades-per-day?start_date=2026-07-01&end_date=2026-08-21');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('analysis/TradesPerDay')
        ->where('summary.start_date', '2026-07-01')
        ->where('summary.end_date', '2026-08-21')
        ->where('summary.total_trades', 4)
        ->where('summary.total_invested_10k', 40000.0)
        ->where('summary.total_profit_10k', 750.0)
        ->where('summary.active_days', 3)
        ->where('dailyBreakdowns.0.date', '2026-07-01')
        ->where('dailyBreakdowns.0.trade_count', 1)
        ->where('dailyBreakdowns.0.invested_10k', 10000)
        ->where('dailyBreakdowns.0.total_profit_10k', 125.0)
        ->where('dailyBreakdowns.0.pnl_percent', 1.25)
        ->where('dailyBreakdowns.0.trades.0.symbol', 'OLD')
        ->where('dailyBreakdowns.1.date', '2026-08-05')
        ->where('dailyBreakdowns.1.trade_count', 2)
        ->where('dailyBreakdowns.1.invested_10k', 20000)
        ->where('dailyBreakdowns.1.total_profit_10k', 325.0)
        ->where('dailyBreakdowns.1.pnl_percent', 1.63)
        ->where('dailyBreakdowns.1.trades.1.symbol', 'BBC')
        ->where('dailyBreakdowns.1.trades.1.profit_10k', 75.0)
        ->where('dailyBreakdowns.2.date', '2026-08-20')
        ->where('dailyBreakdowns.2.trade_count', 1)
        ->where('dailyBreakdowns.2.invested_10k', 10000)
        ->where('dailyBreakdowns.2.total_profit_10k', 300.0)
        ->where('dailyBreakdowns.2.pnl_percent', 3.0)
    );
});
