<?php

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

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
        'pnl_percent' => 1,
        'dedupe_key' => 'test-'.uniqid('', true),
        'pipeline_run' => 'A',
    ], $attributes));
}

beforeEach(function () {
    insertTradeAlert([
        'symbol' => 'AAA',
        'trading_date_est' => '2026-07-01',
        'as_of_ts_est' => '2026-07-01 09:30:00',
        'signal_ts_est' => '2026-07-01 09:30:00',
        'entry_ts_est' => '2026-07-01 09:31:00',
        'ml_win_prob' => 0.02,
        'pnl_percent' => -2.0,
        'pipeline_run' => 'A',
    ]);

    insertTradeAlert([
        'symbol' => 'AAB',
        'trading_date_est' => '2026-08-05',
        'as_of_ts_est' => '2026-08-05 09:30:00',
        'signal_ts_est' => '2026-08-05 09:30:00',
        'entry_ts_est' => '2026-08-05 09:31:00',
        'ml_win_prob' => 0.04,
        'pnl_percent' => 4.0,
        'pipeline_run' => 'A',
    ]);

    insertTradeAlert([
        'symbol' => 'BBB',
        'trading_date_est' => '2026-08-10',
        'as_of_ts_est' => '2026-08-10 09:30:00',
        'signal_ts_est' => '2026-08-10 09:30:00',
        'entry_ts_est' => '2026-08-10 09:31:00',
        'ml_win_prob' => 0.11,
        'pnl_percent' => 1.0,
        'pipeline_run' => 'B',
    ]);

    insertTradeAlert([
        'symbol' => 'BBC',
        'trading_date_est' => '2026-08-15',
        'as_of_ts_est' => '2026-08-15 09:30:00',
        'signal_ts_est' => '2026-08-15 09:30:00',
        'entry_ts_est' => '2026-08-15 09:31:00',
        'ml_win_prob' => 0.11,
        'pnl_percent' => -1.0,
        'pipeline_run' => 'B',
    ]);
});

it('shows ML buckets grouped by pipeline', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    $response = $this->actingAs($user)->get('/analysis/ml-buckets');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('analysis/MlBuckets')
        ->where('summary.total_alerts', 4)
        ->where('summary.total_pipelines', 2)
        ->where('summary.win_rate', 50.0)
        ->where('pipelineBreakdowns.0.pipeline_run', 'A')
        ->where('pipelineBreakdowns.0.buckets.0.bucket_label', '0-5%')
        ->where('pipelineBreakdowns.0.buckets.0.trade_count', 2)
        ->where('pipelineBreakdowns.1.pipeline_run', 'B')
        ->where('pipelineBreakdowns.1.buckets.2.bucket_label', '10-15%')
        ->where('pipelineBreakdowns.1.buckets.2.trade_count', 2)
    );
});

it('filters ML buckets by start date', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    $response = $this->actingAs($user)->get('/analysis/ml-buckets?start_date=2026-08-01');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('analysis/MlBuckets')
        ->where('summary.total_alerts', 3)
        ->where('summary.first_date', '2026-08-05')
        ->where('summary.last_date', '2026-08-15')
        ->where('pipelineBreakdowns.0.pipeline_run', 'A')
        ->where('pipelineBreakdowns.0.buckets.0.trade_count', 1)
        ->where('pipelineBreakdowns.1.pipeline_run', 'B')
        ->where('pipelineBreakdowns.1.buckets.2.trade_count', 2)
    );
});
