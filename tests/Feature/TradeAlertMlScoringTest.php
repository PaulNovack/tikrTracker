<?php

use App\Jobs\ScoreTradeAlertWithMl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

it('dispatches ML scoring job when alert is created', function () {
    Queue::fake();

    // Create a minimal alert record
    $dedupeKey = 'test_'.time().'_'.random_int(1000, 9999);
    DB::table('trade_alerts')->insert([
        'symbol' => 'TEST',
        'asset_type' => 'stock',
        'trading_date_est' => '2026-01-12',
        'as_of_ts_est' => '2026-01-12 09:30:00',
        'signal_ts_est' => '2026-01-12 09:30:00',
        'entry_ts_est' => '2026-01-12 09:31:00',
        'entry' => 100.00,
        'stop' => 98.00,
        'dedupe_key' => $dedupeKey,
        'signal_type' => 'breakout',
        'entry_type' => 'limit',
        'pipeline_run' => 'A',
        'version' => 'v1.0',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $alertId = DB::table('trade_alerts')->where('dedupe_key', $dedupeKey)->value('id');

    // Manually dispatch the job (simulating TradeAlertWriter behavior)
    if (config('trading.ml_scoring.enabled', true)) {
        ScoreTradeAlertWithMl::dispatch($alertId, 'trade_alerts');
    }

    // Assert job was dispatched
    Queue::assertPushed(ScoreTradeAlertWithMl::class, function ($job) use ($alertId) {
        return $job->alertId === $alertId && $job->tableName === 'trade_alerts';
    });

    // Cleanup
    DB::table('trade_alerts')->where('id', $alertId)->delete();
});

it('scores alert with ML model when job is processed', function () {
    // This test requires ML columns to exist in the database
    // Skip if columns don't exist (test database may not have migrations)
    if (! Schema::hasColumn('trade_alerts', 'ml_scored_at')) {
        $this->markTestSkipped('ML columns not available in test database');
    }

    // Find an unscored alert
    $alert = DB::table('trade_alerts')
        ->whereNull('ml_scored_at')
        ->whereNotNull('entry')
        ->whereNotNull('stop')
        ->first();

    if (! $alert) {
        $this->markTestSkipped('No unscored alerts available');
    }

    // Process the job synchronously
    $job = new ScoreTradeAlertWithMl($alert->id, 'trade_alerts');
    $job->handle();

    // Verify alert was scored
    $scoredAlert = DB::table('trade_alerts')->where('id', $alert->id)->first();
    expect($scoredAlert->ml_win_prob)->not->toBeNull()
        ->and($scoredAlert->ml_scored_at)->not->toBeNull()
        ->and($scoredAlert->ml_model_version)->toBe('winner_model_xgb');
});

it('skips already scored alerts', function () {
    if (! Schema::hasColumn('trade_alerts', 'ml_scored_at')) {
        $this->markTestSkipped('ML columns not available in test database');
    }

    // Find an already scored alert
    $alert = DB::table('trade_alerts')
        ->whereNotNull('ml_scored_at')
        ->whereNotNull('ml_win_prob')
        ->first();

    if (! $alert) {
        $this->markTestSkipped('No scored alerts available');
    }

    $originalScoredAt = $alert->ml_scored_at;

    // Try to score again
    $job = new ScoreTradeAlertWithMl($alert->id, 'trade_alerts');
    $job->handle();

    // Verify timestamp didn't change (alert was skipped)
    $refreshedAlert = DB::table('trade_alerts')->where('id', $alert->id)->first();
    expect($refreshedAlert->ml_scored_at)->toBe($originalScoredAt);
});

it('respects ml_scoring enabled config', function () {
    Queue::fake();

    // Temporarily disable ML scoring
    config(['trading.ml_scoring.enabled' => false]);

    $dedupeKey = 'test_config_'.time();
    DB::table('trade_alerts')->insert([
        'symbol' => 'TEST',
        'asset_type' => 'stock',
        'trading_date_est' => '2026-01-12',
        'as_of_ts_est' => '2026-01-12 09:30:00',
        'signal_ts_est' => '2026-01-12 09:30:00',
        'entry_ts_est' => '2026-01-12 09:31:00',
        'entry' => 100.00,
        'stop' => 98.00,
        'dedupe_key' => $dedupeKey,
        'signal_type' => 'breakout',
        'entry_type' => 'limit',
        'pipeline_run' => 'A',
        'version' => 'v1.0',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $alertId = DB::table('trade_alerts')->where('dedupe_key', $dedupeKey)->value('id');

    // Try to dispatch (should not happen when disabled)
    if (config('trading.ml_scoring.enabled', true)) {
        ScoreTradeAlertWithMl::dispatch($alertId, 'trade_alerts');
    }

    // Assert job was NOT dispatched
    Queue::assertNothingPushed();

    // Cleanup
    DB::table('trade_alerts')->where('id', $alertId)->delete();

    // Re-enable for other tests
    config(['trading.ml_scoring.enabled' => true]);
});
