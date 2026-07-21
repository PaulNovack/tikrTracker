<?php

use App\Services\Trading\FiveMinuteSignalScannerV2000_0;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->scanner = app(FiveMinuteSignalScannerV2000_0::class);

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
});

it('returns a universe-ranked market movers signal list', function () {
    expect($this->scanner->getVersion())->toBe('v2000.0');

    $signals = $this->scanner->scan(
        assetType: 'stock',
        asOfTsEst: '2026-06-11 15:55:00'
    );

    expect($signals)->toBeArray()->not->toBeEmpty();

    $signal = collect($signals)->firstWhere('symbol', 'TESTU2000');

    expect($signal)->not->toBeNull();

    expect($signal)
        ->toHaveKey('asset_type', 'stock')
        ->toHaveKey('signal_type', 'MOMO_5D_UNIVERSE')
        ->toHaveKey('signal_ts_est')
        ->toHaveKey('score')
        ->toHaveKey('atr')
        ->toHaveKey('atr_pct')
        ->toHaveKey('meta');

    expect($signal['signal_ts_est'])->toBeString()->not->toBeEmpty();

    expect($signal['meta'])
        ->toHaveKey('version', 'v2000.0')
        ->toHaveKey('universe_rank')
        ->toHaveKey('universe_size')
        ->toHaveKey('days_appeared', 1)
        ->toHaveKey('max_gain_pct', 5.0);
});
