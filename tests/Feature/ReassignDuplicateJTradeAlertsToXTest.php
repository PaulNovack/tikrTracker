<?php

use Illuminate\Support\Facades\DB;

it('moves duplicate J alerts without alpaca orders to X while preserving ordered alerts in J', function () {
    DB::statement("ALTER TABLE trade_alerts MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','X','BIASED1') NOT NULL DEFAULT 'A'");

    $suffix = (string) now()->timestamp;

    DB::table('alpaca_orders')->where('symbol', 'ZZZTEST1')->delete();
    DB::table('trade_alerts')
        ->where('symbol', 'ZZZTEST1')
        ->where('trading_date_est', '2026-06-11')
        ->delete();

    $baseAlert = [
        'symbol' => 'ZZZTEST1',
        'asset_type' => 'stock',
        'trading_date_est' => '2026-06-11',
        'as_of_ts_est' => '2026-06-11 11:00:00',
        'signal_type' => 'MOMO_5D_UNIVERSE',
        'signal_ts_est' => '2026-06-11 11:00:00',
        'entry_type' => 'UNIVERSE_ALERT_1M',
        'entry' => 45.48,
        'stop' => 45.00,
        'dedupe_key' => 'test-1',
        'version' => 'v2000.0',
        'pipeline_run' => 'J',
        'created_at' => '2026-06-11 11:00:00',
        'updated_at' => '2026-06-11 11:00:00',
    ];

    $firstId = DB::table('trade_alerts')->insertGetId(array_merge($baseAlert, [
        'signal_ts_est' => '2026-06-11 11:00:05',
        'entry_ts_est' => '2026-06-11 11:00:12',
        'dedupe_key' => 'test-gels-1100-a-'.$suffix,
    ]));

    $secondId = DB::table('trade_alerts')->insertGetId(array_merge($baseAlert, [
        'signal_ts_est' => '2026-06-11 11:00:48',
        'entry_ts_est' => '2026-06-11 11:00:48',
        'dedupe_key' => 'test-gels-1100-b-'.$suffix,
    ]));

    $protectedId = DB::table('trade_alerts')->insertGetId(array_merge($baseAlert, [
        'signal_ts_est' => '2026-06-11 11:01:05',
        'entry_ts_est' => '2026-06-11 11:01:05',
        'dedupe_key' => 'test-gels-1101-a-'.$suffix,
        'entry_type' => 'VWAP_RECLAIM_1M',
    ]));

    $unprotectedWithProtectedId = DB::table('trade_alerts')->insertGetId(array_merge($baseAlert, [
        'signal_ts_est' => '2026-06-11 11:01:40',
        'entry_ts_est' => '2026-06-11 11:01:40',
        'dedupe_key' => 'test-gels-1101-b-'.$suffix,
        'entry_type' => 'VWAP_RECLAIM_1M',
    ]));

    DB::table('alpaca_orders')->insert([
        'alpaca_order_id' => '11111111-1111-1111-1111-'.substr(md5($suffix), 0, 12),
        'trade_alert_id' => $protectedId,
        'symbol' => 'GELS',
        'side' => 'buy',
        'status' => 'filled',
        'created_at' => '2026-06-11 11:02:00',
        'updated_at' => '2026-06-11 11:02:00',
    ]);

    $this->artisan('trade:reassign-duplicate-j-alerts-to-x', [
        '--date' => '2026-06-11',
    ])->assertExitCode(0);

    expect(DB::table('trade_alerts')->where('id', $firstId)->value('pipeline_run'))->toBe('J');
    expect(DB::table('trade_alerts')->where('id', $secondId)->value('pipeline_run'))->toBe('X');
    expect(DB::table('trade_alerts')->where('id', $protectedId)->value('pipeline_run'))->toBe('J');
    expect(DB::table('trade_alerts')->where('id', $unprotectedWithProtectedId)->value('pipeline_run'))->toBe('X');

    expect(DB::table('trade_alerts')->where('pipeline_run', 'J')->count())->toBe(2);
    expect(DB::table('trade_alerts')->where('pipeline_run', 'X')->count())->toBe(2);
});
