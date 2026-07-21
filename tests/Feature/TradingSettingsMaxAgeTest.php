<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\TradingSettingService;
use App\UserRole;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('renders the max age settings in trading settings', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);

    Setting::updateOrCreate(['name' => 'trading.pipeline_j.max_age_minutes'], ['value' => '10']);

    $response = $this->actingAs($user)->get('/trading-settings');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->where('maxAgeSettings.j', 10));
});

it('renders pipeline x in the trading settings pipelines and ml thresholds tabs', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);

    Setting::updateOrCreate(['name' => 'trading.pipeline_x.run_cron'], ['value' => '1']);
    Setting::updateOrCreate(['name' => 'trading.pipeline_x.ml_threshold'], ['value' => '0.73']);

    $response = $this->actingAs($user)->get('/trading-settings');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->where('pipelines.x.run_cron', true)
        ->where('mlThresholds.x', 0.73)
    );
});

it('updates pipeline max age settings through the trading settings tab', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);

    Setting::updateOrCreate(['name' => 'trading.pipeline_j.max_age_minutes'], ['value' => '10']);

    expect(TradingSettingService::getPipelineMaxAgeMinutes('j'))->toBe(10);

    $response = $this->actingAs($user)->patch('/trading-settings/max-age', [
        'max_age_minutes' => [
            'a' => 10,
            'b' => 10,
            'c' => 10,
            'd' => 10,
            'f' => 10,
            'g' => 10,
            'h' => 10,
            'i' => 10,
            'j' => 12,
            'l' => 10,
        ],
    ]);

    $response->assertStatus(302);

    expect(TradingSettingService::getPipelineMaxAgeMinutes('j'))->toBe(12);
    $this->actingAs($user)->get('/trading-settings')->assertInertia(fn ($page) => $page->where('maxAgeSettings.j', 12));
});
