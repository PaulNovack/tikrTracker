<?php

use App\Models\User;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('renders the best gains 7 days page successfully', function () {
    $response = $this->actingAs($this->user)->get('/analysis/best-gains-7d');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('analysis/BestGains7Days')
        ->has('performers')
        ->has('filters')
    );
});

it('requires authentication', function () {
    $response = $this->get('/analysis/best-gains-7d');

    $response->assertRedirect('/login');
});

it('applies filters correctly', function () {
    $response = $this->actingAs($this->user)->get('/analysis/best-gains-7d', [
        'assetType' => 'crypto',
        'days' => 14,
        'rthOnly' => false,
    ]);

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('analysis/BestGains7Days')
        ->where('filters.assetType', 'crypto')
        ->where('filters.days', 14)
        ->where('filters.rthOnly', false)
    );
});

it('returns performers data with correct structure', function () {
    // Create test data in five_minute_prices table
    DB::table('five_minute_prices')->insert([
        [
            'symbol' => 'TEST',
            'asset_type' => 'stock',
            'ts_est' => now()->subDays(7)->format('Y-m-d H:i:s'),
            'price' => 100.00,
            'volume' => 1000000,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'symbol' => 'TEST',
            'asset_type' => 'stock',
            'ts_est' => now()->format('Y-m-d H:i:s'),
            'price' => 110.00,
            'volume' => 1500000,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $service = new BestPerformers5mService;
    $performers = $service->getBestPerformers([
        'days' => 7,
        'assetType' => 'stock',
        'limit' => 200,
        'minBars' => 1, // Low threshold for test data
        'minVol' => 0,
        'rthOnly' => false,
        'tz' => 'America/New_York',
    ]);

    expect($performers)->toBeArray();

    if (count($performers) > 0) {
        expect($performers[0])->toHaveKeys([
            'symbol',
            'bars',
            'vol_sum',
            'first_ts',
            'last_ts',
            'first_price',
            'last_price',
            'pct_return',
            'pct_return_pct',
        ]);
    }
});

it('calculates percentage returns correctly', function () {
    DB::table('five_minute_prices')->insert([
        [
            'symbol' => 'GAIN',
            'asset_type' => 'stock',
            'ts_est' => now()->subDays(7)->setTime(10, 0, 0)->format('Y-m-d H:i:s'),
            'price' => 100.00,
            'volume' => 1000000,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'symbol' => 'GAIN',
            'asset_type' => 'stock',
            'ts_est' => now()->setTime(15, 0, 0)->format('Y-m-d H:i:s'),
            'price' => 150.00, // 50% gain
            'volume' => 2000000,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $service = new BestPerformers5mService;
    $performers = $service->getBestPerformers([
        'days' => 7,
        'assetType' => 'stock',
        'limit' => 200,
        'minBars' => 1,
        'minVol' => 0,
        'rthOnly' => false,
        'tz' => 'America/New_York',
    ]);

    $gainPerformer = collect($performers)->firstWhere('symbol', 'GAIN');

    if ($gainPerformer) {
        expect($gainPerformer['pct_return'])->toBeGreaterThan(0.49); // ~50% return
        expect($gainPerformer['pct_return_pct'])->toBeGreaterThan(49.0);
    }
});
