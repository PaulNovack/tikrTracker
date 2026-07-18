<?php

namespace Tests\Unit\Services;

use App\Models\AssetInfo;
use App\Models\FiveMinutePrice;
use App\Services\OHLCService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OHLCServiceTest extends TestCase
{
    use RefreshDatabase;

    private OHLCService $ohlcService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ohlcService = new OHLCService;
    }

    public function test_ohlc_service_returns_empty_array_when_no_data(): void
    {
        $asset = AssetInfo::factory()->create();
        $startTime = now('UTC')->subDays(1);
        $endTime = now('UTC');

        $result = $this->ohlcService->getOHLCData($asset, $startTime, $endTime);

        expect($result)->toBeArray();
        expect($result)->toBeEmpty();
    }

    public function test_ohlc_service_aggregates_prices_into_bars(): void
    {
        $asset = AssetInfo::factory()->create();
        $startTime = now('UTC')->setHour(9)->setMinute(30)->setSecond(0);

        // Create 5 data points at 5-minute intervals
        for ($i = 0; $i < 5; $i++) {
            FiveMinutePrice::create([
                'symbol' => $asset->symbol,
                'asset_type' => $asset->asset_type,
                'ts' => $startTime->clone()->addMinutes($i * 5),
                'price' => 100 + $i,  // Close price
                'open' => 100,
                'high' => 105,
                'low' => 99,
                'volume' => 1000000,
            ]);
        }

        $result = $this->ohlcService->getOHLCData($asset, $startTime, $startTime->clone()->addMinutes(25), '5m');

        expect($result)->not->toBeEmpty();
        expect(count($result))->toBe(5);

        // Verify first bar structure
        $firstBar = $result[0];
        expect($firstBar)->toHaveKeys(['time', 'open', 'high', 'low', 'close', 'volume']);
        expect($firstBar['open'])->toBe(100.0);
        expect($firstBar['high'])->toBe(105.0);
        expect($firstBar['low'])->toBe(99.0);
    }

    public function test_ohlc_service_handles_different_intervals(): void
    {
        $asset = AssetInfo::factory()->create();
        $startTime = now('UTC')->setHour(9)->setMinute(30)->setSecond(0);

        // Create 60 data points (1 per minute)
        for ($i = 0; $i < 60; $i++) {
            FiveMinutePrice::create([
                'symbol' => $asset->symbol,
                'asset_type' => $asset->asset_type,
                'ts' => $startTime->clone()->addMinutes($i),
                'price' => 100 + ($i % 5),
                'open' => 100 + ($i % 5),
                'high' => 102 + ($i % 5),
                'low' => 98 + ($i % 5),
                'volume' => 500000,
            ]);
        }

        // Test 5-minute intervals
        $result5m = $this->ohlcService->getOHLCData($asset, $startTime, $startTime->clone()->addMinutes(60), '5m');
        expect(count($result5m))->toBeGreaterThanOrEqual(12); // 60 minutes / 5 = ~12 bars

        // Test 15-minute intervals
        $result15m = $this->ohlcService->getOHLCData($asset, $startTime, $startTime->clone()->addMinutes(60), '15m');
        expect(count($result15m))->toBeGreaterThanOrEqual(4); // 60 minutes / 15 = ~4 bars

        // Test 30-minute intervals
        $result30m = $this->ohlcService->getOHLCData($asset, $startTime, $startTime->clone()->addMinutes(60), '30m');
        expect(count($result30m))->toBeGreaterThanOrEqual(2); // 60 minutes / 30 = ~2 bars
    }

    public function test_ohlc_service_derives_ohlc_from_prices_when_missing(): void
    {
        $asset = AssetInfo::factory()->create();
        $startTime = now('UTC')->setHour(9)->setMinute(30)->setSecond(0);

        // Create prices without OHLC data (just close prices)
        for ($i = 0; $i < 5; $i++) {
            FiveMinutePrice::create([
                'symbol' => $asset->symbol,
                'asset_type' => $asset->asset_type,
                'ts' => $startTime->clone()->addMinutes($i * 5),
                'price' => 100 + $i,
                'open' => null,
                'high' => null,
                'low' => null,
                'volume' => 1000000,
            ]);
        }

        $result = $this->ohlcService->getOHLCData($asset, $startTime, $startTime->clone()->addMinutes(25), '5m');

        expect($result)->not->toBeEmpty();
        // Should derive OHLC from close prices
        expect($result[0]['close'])->toBe(100.0);
        expect($result[0]['high'])->toBeGreaterThanOrEqual($result[0]['close']);
        expect($result[0]['low'])->toBeLessThanOrEqual($result[0]['close']);
    }

    public function test_get_recommended_interval(): void
    {
        $start = now('UTC')->startOfDay();
        $end = now('UTC')->startOfDay();

        // Test for 1 day range
        expect($this->ohlcService->getRecommendedInterval($start, $end->clone()->addDay()))->toBe('5m');

        // Test for 5 days
        expect($this->ohlcService->getRecommendedInterval($start, $end->clone()->addDays(5)))->toBe('15m');

        // Test for 30 days
        expect($this->ohlcService->getRecommendedInterval($start, $end->clone()->addDays(30)))->toBe('1h');

        // Test for 90 days
        expect($this->ohlcService->getRecommendedInterval($start, $end->clone()->addDays(90)))->toBe('4h');

        // Test for 1 year
        expect($this->ohlcService->getRecommendedInterval($start, $end->clone()->addYear()))->toBe('1d');
    }

    public function test_ohlc_service_aggregates_volume(): void
    {
        $asset = AssetInfo::factory()->create();
        $startTime = now('UTC')->setHour(9)->setMinute(30)->setSecond(0);

        // Create 5 data points with known volumes
        for ($i = 0; $i < 5; $i++) {
            FiveMinutePrice::create([
                'symbol' => $asset->symbol,
                'asset_type' => $asset->asset_type,
                'ts' => $startTime->clone()->addMinutes($i * 5),
                'price' => 100,
                'open' => 100,
                'high' => 100,
                'low' => 100,
                'volume' => 1000 * ($i + 1), // 1000, 2000, 3000, 4000, 5000
            ]);
        }

        $result = $this->ohlcService->getOHLCData($asset, $startTime, $startTime->clone()->addMinutes(25), '5m');

        // Total volume should be sum of all: 1000+2000+3000+4000+5000 = 15000
        $totalVolume = array_sum(array_column($result, 'volume'));
        expect($totalVolume)->toBe(15000);
    }
}
