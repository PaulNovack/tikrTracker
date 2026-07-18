<?php

use App\Models\AlertLog;
use App\Models\AssetInfo;
use App\Models\PriceAlert;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get(route('alert-logs.index'))->assertRedirect(route('login'));
});

test('authenticated users can view alert logs page', function () {
    $this->actingAs($user = User::factory()->create());

    $response = $this->get(route('alert-logs.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('AlertLogs/Index')
        ->has('logs')
        ->has('logs.data')
        ->has('logs.current_page')
        ->has('logs.total')
        ->has('logs.per_page')
        ->has('logs.last_page')
    );
});

test('alert logs show only current user\'s logs', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $asset = AssetInfo::factory()->create(['symbol' => 'AAPL']);

    $alert1 = PriceAlert::factory()->create(['user_id' => $user1->id, 'asset_info_id' => $asset->id]);
    $alert2 = PriceAlert::factory()->create(['user_id' => $user2->id, 'asset_info_id' => $asset->id]);

    AlertLog::factory()->create([
        'user_id' => $user1->id,
        'price_alert_id' => $alert1->id,
        'symbol' => 'AAPL',
        'direction' => 'up',
    ]);

    AlertLog::factory()->create([
        'user_id' => $user2->id,
        'price_alert_id' => $alert2->id,
        'symbol' => 'AAPL',
        'direction' => 'down',
    ]);

    $this->actingAs($user1);

    $response = $this->get(route('alert-logs.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('logs.total', 1)
        ->where('logs.data.0.user_id', $user1->id)
        ->where('logs.data.0.symbol', 'AAPL')
        ->where('logs.data.0.direction', 'up')
    );
});

test('alert logs display correct alert details', function () {
    $user = User::factory()->create();
    $asset = AssetInfo::factory()->create(['symbol' => 'GOOGL']);

    $alert = PriceAlert::factory()->create(['user_id' => $user->id, 'asset_info_id' => $asset->id]);

    AlertLog::factory()->create([
        'user_id' => $user->id,
        'price_alert_id' => $alert->id,
        'symbol' => 'GOOGL',
        'direction' => 'up',
        'trigger_price' => 150.00,
        'current_price' => 152.50,
        'trigger_percentage' => 1.67,
        'email_status' => 'sent',
        'sent_at' => now(),
    ]);

    $this->actingAs($user);

    $response = $this->get(route('alert-logs.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('logs.data.0.symbol', 'GOOGL')
        ->where('logs.data.0.direction', 'up')
        ->where('logs.data.0.trigger_price', 150)
        ->where('logs.data.0.current_price', 152.5)
        ->where('logs.data.0.trigger_percentage', 1.67)
        ->where('logs.data.0.email_status', 'sent')
    );
});

test('alert logs show email delivery status correctly', function () {
    $user = User::factory()->create();
    $asset = AssetInfo::factory()->create(['symbol' => 'MSFT']);
    $alert = PriceAlert::factory()->create(['user_id' => $user->id, 'asset_info_id' => $asset->id]);

    // Create log with sent status (oldest)
    AlertLog::factory()->create([
        'user_id' => $user->id,
        'price_alert_id' => $alert->id,
        'email_status' => 'sent',
        'sent_at' => now()->subHours(2),
    ]);

    // Create log with failed status (middle)
    AlertLog::factory()->create([
        'user_id' => $user->id,
        'price_alert_id' => $alert->id,
        'email_status' => 'failed',
        'email_error' => '550 User blocked',
        'sent_at' => now()->subHour(),
    ]);

    // Create log with retry status (newest)
    AlertLog::factory()->create([
        'user_id' => $user->id,
        'price_alert_id' => $alert->id,
        'email_status' => 'retry',
        'sent_at' => now(),
    ]);

    $this->actingAs($user);

    $response = $this->get(route('alert-logs.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('logs.data.0.email_status', 'retry')
        ->where('logs.data.1.email_status', 'failed')
        ->where('logs.data.1.email_error', '550 User blocked')
        ->where('logs.data.2.email_status', 'sent')
    );
});

test('alert logs pagination works correctly', function () {
    $user = User::factory()->create();
    $asset = AssetInfo::factory()->create(['symbol' => 'TSLA']);
    $alert = PriceAlert::factory()->create(['user_id' => $user->id, 'asset_info_id' => $asset->id]);

    // Create 75 alert logs (more than default 50 per page)
    AlertLog::factory(75)->create([
        'user_id' => $user->id,
        'price_alert_id' => $alert->id,
    ]);

    $this->actingAs($user);

    // First page
    $response = $this->get(route('alert-logs.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('logs.current_page', 1)
        ->where('logs.total', 75)
        ->where('logs.per_page', 50)
        ->where('logs.last_page', 2)
        ->has('logs.data', 50)
    );

    // Second page
    $response = $this->get(route('alert-logs.index', ['page' => 2]));

    $response->assertInertia(fn ($page) => $page
        ->where('logs.current_page', 2)
        ->where('logs.total', 75)
        ->has('logs.data', 25)
    );
});

test('alert logs display in correct order (newest first)', function () {
    $user = User::factory()->create();
    $asset = AssetInfo::factory()->create(['symbol' => 'NVDA']);
    $alert = PriceAlert::factory()->create(['user_id' => $user->id, 'asset_info_id' => $asset->id]);

    $log1 = AlertLog::factory()->create([
        'user_id' => $user->id,
        'price_alert_id' => $alert->id,
        'sent_at' => now()->subHours(2),
    ]);

    $log2 = AlertLog::factory()->create([
        'user_id' => $user->id,
        'price_alert_id' => $alert->id,
        'sent_at' => now()->subHour(),
    ]);

    $log3 = AlertLog::factory()->create([
        'user_id' => $user->id,
        'price_alert_id' => $alert->id,
        'sent_at' => now(),
    ]);

    $this->actingAs($user);

    $response = $this->get(route('alert-logs.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('logs.data.0.id', $log3->id)
        ->where('logs.data.1.id', $log2->id)
        ->where('logs.data.2.id', $log1->id)
    );
});

test('alert logs show both up and down direction alerts', function () {
    $user = User::factory()->create();
    $asset = AssetInfo::factory()->create(['symbol' => 'META']);
    $alert = PriceAlert::factory()->create(['user_id' => $user->id, 'asset_info_id' => $asset->id]);

    AlertLog::factory()->create([
        'user_id' => $user->id,
        'price_alert_id' => $alert->id,
        'direction' => 'up',
        'sent_at' => now()->subHour(),
    ]);

    AlertLog::factory()->create([
        'user_id' => $user->id,
        'price_alert_id' => $alert->id,
        'direction' => 'down',
        'sent_at' => now(),
    ]);

    $this->actingAs($user);

    $response = $this->get(route('alert-logs.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('logs.total', 2)
        ->where('logs.data.0.direction', 'down')
        ->where('logs.data.1.direction', 'up')
    );
});

test('alert logs handle empty state correctly', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->get(route('alert-logs.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('logs.total', 0)
        ->where('logs.data', [])
        ->where('logs.current_page', 1)
        ->where('logs.last_page', 1)
    );
});

test('alert logs include price alert relationship data', function () {
    $user = User::factory()->create();
    $asset = AssetInfo::factory()->create([
        'symbol' => 'AMZN',
        'asset_type' => 'stock',
        'common_name' => 'Amazon',
    ]);

    $alert = PriceAlert::factory()->create(['user_id' => $user->id, 'asset_info_id' => $asset->id]);

    AlertLog::factory()->create([
        'user_id' => $user->id,
        'price_alert_id' => $alert->id,
        'symbol' => 'AMZN',
    ]);

    $this->actingAs($user);

    $response = $this->get(route('alert-logs.index'));

    $response->assertInertia(fn ($page) => $page
        ->has('logs.data.0')
        ->where('logs.data.0.symbol', 'AMZN')
    );
});

test('alert logs handle decimal price values correctly', function () {
    $user = User::factory()->create();
    $asset = AssetInfo::factory()->create(['symbol' => 'BTC', 'asset_type' => 'crypto']);
    $alert = PriceAlert::factory()->create(['user_id' => $user->id, 'asset_info_id' => $asset->id]);

    AlertLog::factory()->create([
        'user_id' => $user->id,
        'price_alert_id' => $alert->id,
        'trigger_price' => 42567.89,
        'current_price' => 43125.45,
        'trigger_percentage' => 1.31,
    ]);

    $this->actingAs($user);

    $response = $this->get(route('alert-logs.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('logs.data.0.trigger_price', 42567.89)
        ->where('logs.data.0.current_price', 43125.45)
        ->where('logs.data.0.trigger_percentage', 1.31)
    );
});

test('alert logs with error messages display error text', function () {
    $user = User::factory()->create();
    $asset = AssetInfo::factory()->create(['symbol' => 'ETH']);
    $alert = PriceAlert::factory()->create(['user_id' => $user->id, 'asset_info_id' => $asset->id]);

    $errorMessage = 'SMTP Error: 550 User blocked - too many recipients this hour';

    AlertLog::factory()->create([
        'user_id' => $user->id,
        'price_alert_id' => $alert->id,
        'email_status' => 'failed',
        'email_error' => $errorMessage,
    ]);

    $this->actingAs($user);

    $response = $this->get(route('alert-logs.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('logs.data.0.email_error', $errorMessage)
    );
});

test('alert logs with null error message when status is sent', function () {
    $user = User::factory()->create();
    $asset = AssetInfo::factory()->create(['symbol' => 'SOL']);
    $alert = PriceAlert::factory()->create(['user_id' => $user->id, 'asset_info_id' => $asset->id]);

    AlertLog::factory()->create([
        'user_id' => $user->id,
        'price_alert_id' => $alert->id,
        'email_status' => 'sent',
        'email_error' => null,
    ]);

    $this->actingAs($user);

    $response = $this->get(route('alert-logs.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('logs.data.0.email_error', null)
    );
});
