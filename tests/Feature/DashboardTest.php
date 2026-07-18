<?php

use App\Models\DailyPrice;
use App\Models\FiveMinutePrice;
use App\Models\HourlyPrice;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('dashboard'))->assertOk();
});

test('dashboard includes data freshness information', function () {
    $this->actingAs($user = User::factory()->create());

    // Create some test data
    FiveMinutePrice::factory()->create(['ts' => now()->subMinutes(5)]);
    HourlyPrice::factory()->create(['ts' => now()->subHour()]);
    DailyPrice::factory()->create(['date' => now()->subDay()]);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('dashboard')
        ->has('dataFreshness')
        ->has('dataFreshness.five_minute')
        ->has('dataFreshness.hourly')
        ->has('dataFreshness.daily')
        ->where('dataFreshness.five_minute.minutes_ago', fn ($value) => $value !== null)
        ->where('dataFreshness.hourly.hours_ago', fn ($value) => $value !== null)
        ->where('dataFreshness.daily.days_ago', fn ($value) => $value !== null)
    );
});
