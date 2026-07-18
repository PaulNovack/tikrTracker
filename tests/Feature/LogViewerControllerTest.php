<?php

use App\Models\User;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::ensureDirectoryExists(storage_path('logs'));
});

afterEach(function () {
    $date = now()->format('Y-m-d');

    File::delete(storage_path("logs/laravel-{$date}.log"));
    File::delete(storage_path("logs/laravel-testing-{$date}.log"));
});

test('authenticated user can fetch default laravel log', function () {
    $date = now()->format('Y-m-d');
    $logPath = storage_path("logs/laravel-{$date}.log");

    File::put($logPath, "[{$date} 10:00:00] local.INFO: app log line");

    $user = User::factory()->create(['email_verified_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/logs/laravel?lines=500');

    $response->assertOk()
        ->assertJsonPath('exists', true)
        ->assertJsonPath('type', 'app')
        ->assertJsonPath('filename', "laravel-{$date}.log");

    expect($response->json('content'))->toContain('app log line');
});

test('authenticated user can fetch testing laravel log with type tab parameter', function () {
    $date = now()->format('Y-m-d');
    $logPath = storage_path("logs/laravel-testing-{$date}.log");

    File::put($logPath, "[{$date} 10:00:00] testing.INFO: testing log line");

    $user = User::factory()->create(['email_verified_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/logs/laravel?lines=500&type=testing');

    $response->assertOk()
        ->assertJsonPath('exists', true)
        ->assertJsonPath('type', 'testing')
        ->assertJsonPath('filename', "laravel-testing-{$date}.log");

    expect($response->json('content'))->toContain('testing log line');
});

test('search endpoint uses selected log type', function () {
    $date = now()->format('Y-m-d');
    $testingLogPath = storage_path("logs/laravel-testing-{$date}.log");

    File::put($testingLogPath, implode(PHP_EOL, [
        "[{$date} 10:00:00] testing.INFO: alpha",
        "[{$date} 10:00:01] testing.INFO: beta match me",
        "[{$date} 10:00:02] testing.INFO: gamma",
    ]));

    $user = User::factory()->create(['email_verified_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/logs/laravel/search?q=match&type=testing&context=0');

    $response->assertOk()
        ->assertJsonPath('total_matches', 1)
        ->assertJsonPath('type', 'testing')
        ->assertJsonPath('filename', "laravel-testing-{$date}.log");

    expect($response->json('blocks.0.lines.0.text'))->toContain('match me');
});

test('testing log endpoint falls back to latest available file when today is missing', function () {
    $fallbackDate = now()->subDay()->format('Y-m-d');
    $fallbackPath = storage_path("logs/laravel-testing-{$fallbackDate}.log");

    File::put($fallbackPath, "[{$fallbackDate} 10:00:00] testing.INFO: fallback log line");

    $user = User::factory()->create(['email_verified_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/logs/laravel?lines=500&type=testing');

    $response->assertOk()
        ->assertJsonPath('exists', true)
        ->assertJsonPath('type', 'testing')
        ->assertJsonPath('filename', "laravel-testing-{$fallbackDate}.log")
        ->assertJsonPath('fallback', true);

    expect($response->json('content'))->toContain('fallback log line');

    File::delete($fallbackPath);
});

test('default laravel log endpoint fills requested lines from prior daily files', function () {
    $today = now()->format('Y-m-d');
    $yesterday = now()->subDay()->format('Y-m-d');

    $todayPath = storage_path("logs/laravel-{$today}.log");
    $yesterdayPath = storage_path("logs/laravel-{$yesterday}.log");

    File::put($todayPath, implode(PHP_EOL, [
        "[{$today} 10:00:00] local.INFO: today line 1",
        "[{$today} 10:00:01] local.INFO: today line 2",
    ]).PHP_EOL);

    File::put($yesterdayPath, implode(PHP_EOL, [
        "[{$yesterday} 10:00:00] local.INFO: yesterday line A",
        "[{$yesterday} 10:00:01] local.INFO: yesterday line B",
        "[{$yesterday} 10:00:02] local.INFO: yesterday line C",
    ]).PHP_EOL);

    $user = User::factory()->create(['email_verified_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/logs/laravel?lines=4&type=app');

    $response->assertOk()
        ->assertJsonPath('exists', true)
        ->assertJsonPath('filename', "laravel-{$today}.log");

    $content = $response->json('content');
    expect($content)->toContain('today line 2')
        ->and($content)->toContain('today line 1')
        ->and($content)->toContain('yesterday line C')
        ->and($content)->toContain('yesterday line B')
        ->and($content)->not->toContain('yesterday line A');

    File::delete($todayPath);
    File::delete($yesterdayPath);
});
