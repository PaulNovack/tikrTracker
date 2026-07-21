<?php

use App\Console\Commands\MarketYfinance5minContinuousSync;
use Illuminate\Support\Facades\DB;

it('counts only over_1mil enabled stocks for 5m continuous sync', function () {
    DB::table('asset_info')->delete();

    DB::table('asset_info')->insert([
        [
            'symbol' => 'AAA',
            'asset_type' => 'stock',
            'over_1mil' => 1,
            'common_name' => 'AAA',
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'symbol' => 'BBB',
            'asset_type' => 'stock',
            'over_1mil' => 0,
            'common_name' => 'BBB',
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'symbol' => 'CCC',
            'asset_type' => 'stock',
            'over_1mil' => 1,
            'common_name' => 'CCC',
            'deleted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'symbol' => 'DDD',
            'asset_type' => 'crypto',
            'over_1mil' => 1,
            'common_name' => 'DDD',
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $cmd = app(MarketYfinance5minContinuousSync::class);

    expect($cmd->countTargetSymbols())->toBe(1);
});
