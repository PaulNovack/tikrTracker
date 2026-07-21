<?php

use App\Services\Trading\FiveMinuteSignalScannerV2000_0;
use App\Services\Trading\OneMinuteEntryFinderV2000_0;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->scanner = app(FiveMinuteSignalScannerV2000_0::class);
    $this->finder = app(OneMinuteEntryFinderV2000_0::class);

    DB::table('market_movers')->updateOrInsert(
        ['trading_date' => '2026-06-11'],
        [
            'bars_4pct_plus' => 1,
            'bars_5pct_plus' => 1,
            'bars_10pct_plus' => 0,
            'max_gain' => 5.00,
            'strength' => 1,
            'label' => 'WEAK',
            'movers' => json_encode([
                ['symbol' => 'TESTU2000', 'gain_pct' => 5.0],
            ]),
            'updated_at' => now(),
            'created_at' => now(),
        ]
    );

    DB::table('five_minute_prices')->insert([
        'symbol' => 'TESTU2000',
        'source' => 'yfinance',
        'asset_type' => 'stock',
        'ts' => '2026-06-11 15:55:00',
        'price' => 105.00,
        'open' => 100.00,
        'high' => 106.00,
        'low' => 99.50,
        'volume' => 100000,
        'atr' => 2.50,
        'atr_pct' => 2.50,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('one_minute_prices')->insert([
        'symbol' => 'TESTU2000',
        'source' => 'yfinance',
        'asset_type' => 'stock',
        'ts' => '2026-06-11 15:55:00',
        'price' => 105.00,
        'open' => 104.00,
        'high' => 105.50,
        'low' => 103.75,
        'volume' => 1000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('returns a pipeline-safe long entry for a universe signal', function () {
    expect($this->finder->getVersion())->toBe('v2000.0');

    $signals = $this->scanner->scan(
        assetType: 'stock',
        asOfTsEst: '2026-06-11 15:55:00'
    );

    expect($signals)->toBeArray()->not->toBeEmpty();

    $signal = collect($signals)->firstWhere('symbol', 'TESTU2000');

    expect($signal)->not->toBeNull();

    $result = $this->finder->findBestLong(
        $signal['symbol'],
        $signal['asset_type'],
        $signal['signal_ts_est'],
        '2026-06-11 15:55:00'
    );

    expect($result)->toBeArray()
        ->toHaveKey('ok', 1)
        ->toHaveKey('best_entry');

    expect($result['best_entry'])
        ->toHaveKey('type', 'UNIVERSE_ALERT_1M')
        ->toHaveKey('entry_ts_est')
        ->toHaveKey('entry')
        ->toHaveKey('stop')
        ->toHaveKey('risk_pct')
        ->toHaveKey('risk_per_share')
        ->toHaveKey('score')
        ->toHaveKey('targets');
});
