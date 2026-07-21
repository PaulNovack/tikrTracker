<?php

use App\Http\Controllers\Analysis\MlThresholdProfitLossController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

it('defaults to pipeline grouping when no group_by filter is provided', function () {
    $request = Request::create('/analysis/ml-threshold-profit-loss', 'GET');
    $request->headers->set('X-Inertia', 'true');

    $controller = new MlThresholdProfitLossController;
    $response = $controller->index($request);
    $page = json_decode($response->toResponse($request)->getContent(), true, flags: JSON_THROW_ON_ERROR);

    expect(data_get($page, 'props.filters.group_by'))->toBe('pipeline');
});

it('groups closed trades into ml threshold buckets', function () {
    $controller = new MlThresholdProfitLossController;
    $reflection = new ReflectionClass($controller);

    $trades = collect([
        [
            'trade_id' => 'trade-a',
            'pipeline_run' => 'A',
            'ml_win_prob' => 0.73,
            'ml_pct' => 73.0,
            'bucket_start' => 70,
            'bucket_label' => '70-75%',
            'actual_pnl_dollar' => 25.0,
            'symbol' => 'AAPL',
            'buy_filled_at' => '2026-06-01 10:00:00',
            'sell_filled_at' => '2026-06-01 10:30:00',
            'actual_qty' => 10,
            'trade_dollar_amount' => 1000.0,
            'actual_fill' => 100.0,
            'actual_exit_fill' => 102.5,
            'actual_pnl_percent' => 2.5,
            'is_paper' => false,
        ],
        [
            'trade_id' => 'trade-b',
            'pipeline_run' => 'B',
            'ml_win_prob' => 0.18,
            'ml_pct' => 18.0,
            'bucket_start' => 15,
            'bucket_label' => '15-20%',
            'actual_pnl_dollar' => -10.0,
            'symbol' => 'TSLA',
            'buy_filled_at' => '2026-06-01 11:00:00',
            'sell_filled_at' => '2026-06-01 11:20:00',
            'actual_qty' => 5,
            'trade_dollar_amount' => 750.0,
            'actual_fill' => 150.0,
            'actual_exit_fill' => 148.0,
            'actual_pnl_percent' => -1.33,
            'is_paper' => false,
        ],
    ]);

    $summaryMethod = $reflection->getMethod('buildSummary');
    $summaryMethod->setAccessible(true);
    $summary = $summaryMethod->invoke($controller, $trades, 10, '2026-06-01', '2026-06-10');

    $bucketMethod = $reflection->getMethod('buildBucketBreakdown');
    $bucketMethod->setAccessible(true);
    $combinedBreakdown = $bucketMethod->invoke($controller, $trades);

    $pipelineMethod = $reflection->getMethod('buildPipelineBreakdowns');
    $pipelineMethod->setAccessible(true);
    $pipelineBreakdowns = $pipelineMethod->invoke($controller, $trades);

    expect($summary)
        ->toMatchArray([
            'days' => 10,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-10',
            'total_trades' => 2,
            'winning_trades' => 1,
            'losing_trades' => 1,
            'win_rate' => 50.0,
            'net_pnl' => 15.0,
            'avg_pnl' => 7.5,
        ]);

    expect($combinedBreakdown[3])
        ->toMatchArray([
            'bucket_start' => 15,
            'bucket_label' => '15-20%',
            'trade_count' => 1,
            'winning_trades' => 0,
            'losing_trades' => 1,
            'win_rate' => 0.0,
            'total_pnl' => -10.0,
            'avg_pnl' => -10.0,
        ]);

    expect($combinedBreakdown[14])
        ->toMatchArray([
            'bucket_start' => 70,
            'bucket_label' => '70-75%',
            'trade_count' => 1,
            'winning_trades' => 1,
            'losing_trades' => 0,
            'win_rate' => 100.0,
            'total_pnl' => 25.0,
            'avg_pnl' => 25.0,
        ]);

    expect($pipelineBreakdowns)
        ->toHaveCount(2)
        ->and($pipelineBreakdowns[0])
        ->toMatchArray([
            'pipeline_run' => 'A',
            'trade_count' => 1,
            'winning_trades' => 1,
            'win_rate' => 100.0,
            'net_pnl' => 25.0,
            'avg_pnl' => 25.0,
        ])
        ->and($pipelineBreakdowns[1])
        ->toMatchArray([
            'pipeline_run' => 'B',
            'trade_count' => 1,
            'winning_trades' => 0,
            'win_rate' => 0.0,
            'net_pnl' => -10.0,
            'avg_pnl' => -10.0,
        ]);
});
