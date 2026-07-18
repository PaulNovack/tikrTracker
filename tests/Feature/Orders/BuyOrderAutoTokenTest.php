<?php

use App\Models\User;
use App\Models\WebullToken;
use App\Services\Webull\WebullAuthClient;
use App\Services\Webull\WebullOrderService;
use App\UserRole;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\mock;

uses()->group('orders');

beforeEach(function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);
    actingAs($user);

    config(['webull.mode' => 'DEV']);
    config(['webull.account_id' => 'test_account_123']);
});

it('automatically creates a token when none exists and places order', function () {
    // No token exists initially
    expect(WebullToken::where('environment', 'DEV')->count())->toBe(0);

    // Mock the auth client to return a NORMAL token
    $authClient = mock(WebullAuthClient::class);
    $authClient->shouldReceive('createToken')
        ->once()
        ->andReturn([
            'token' => 'test_auto_token_123',
            'status' => 'NORMAL',
            'expires' => now()->addDays(15)->getPreciseTimestamp(3),
        ]);

    // Mock the order service to successfully place order
    $orderService = mock(WebullOrderService::class);
    $orderService->shouldReceive('placeMarket')
        ->once()
        ->with('test_account_123', 'AAPL', 'BUY', 10, 'DAY')
        ->andReturn([
            'order_id' => 'order_123',
            'status' => 'ACCEPTED',
        ]);

    $response = $this->postJson('/orders/place', [
        'symbol' => 'AAPL',
        'shares' => 10,
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'success' => true,
        'message' => 'Market order placed for 10 shares of AAPL',
    ]);

    // Verify token was saved to database
    $token = WebullToken::where('environment', 'DEV')->first();
    expect($token)->not->toBeNull();
    expect($token->token)->toBe('test_auto_token_123');
    expect($token->status)->toBe('NORMAL');
})->uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('uses existing valid token without creating a new one', function () {
    // Create an existing valid token
    WebullToken::create([
        'environment' => 'DEV',
        'token' => 'existing_token_456',
        'status' => 'NORMAL',
        'expires_at' => now()->addDays(10),
    ]);

    // Auth client should NOT be called
    $authClient = mock(WebullAuthClient::class);
    $authClient->shouldNotReceive('createToken');

    // Mock the order service
    $orderService = mock(WebullOrderService::class);
    $orderService->shouldReceive('placeMarket')
        ->once()
        ->andReturn([
            'order_id' => 'order_456',
            'status' => 'ACCEPTED',
        ]);

    $response = $this->postJson('/orders/place', [
        'symbol' => 'TSLA',
        'shares' => 5,
    ]);

    $response->assertSuccessful();

    // Token should still be the original one
    $token = WebullToken::where('environment', 'DEV')->first();
    expect($token->token)->toBe('existing_token_456');
})->uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('recreates token when existing token is expired', function () {
    // Create an expired token
    WebullToken::create([
        'environment' => 'DEV',
        'token' => 'expired_token_789',
        'status' => 'NORMAL',
        'expires_at' => now()->subDay(),
    ]);

    // Mock auth client to return new token
    $authClient = mock(WebullAuthClient::class);
    $authClient->shouldReceive('createToken')
        ->once()
        ->andReturn([
            'token' => 'new_token_789',
            'status' => 'NORMAL',
            'expires' => now()->addDays(15)->getPreciseTimestamp(3),
        ]);

    // Mock order service
    $orderService = mock(WebullOrderService::class);
    $orderService->shouldReceive('placeMarket')
        ->once()
        ->andReturn([
            'order_id' => 'order_789',
            'status' => 'ACCEPTED',
        ]);

    $response = $this->postJson('/orders/place', [
        'symbol' => 'MSFT',
        'shares' => 3,
    ]);

    $response->assertSuccessful();

    // Verify new token was saved
    $token = WebullToken::where('environment', 'DEV')->first();
    expect($token->token)->toBe('new_token_789');
})->uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('returns helpful error when token requires SMS verification', function () {
    // No token exists
    expect(WebullToken::where('environment', 'DEV')->count())->toBe(0);

    // Mock auth client to return PENDING token
    $authClient = mock(WebullAuthClient::class);
    $authClient->shouldReceive('createToken')
        ->once()
        ->andReturn([
            'token' => 'pending_token_999',
            'status' => 'PENDING',
            'expires' => now()->addDays(15)->getPreciseTimestamp(3),
        ]);

    $response = $this->postJson('/orders/place', [
        'symbol' => 'NVDA',
        'shares' => 2,
    ]);

    $response->assertStatus(400);
    $response->assertJsonPath('error', fn ($error) => str_contains($error, 'UAT token created but requires SMS verification') &&
        str_contains($error, 'UAT test accounts cannot verify via mobile app')
    );

    // Token should still be saved to database
    $token = WebullToken::where('environment', 'DEV')->first();
    expect($token)->not->toBeNull();
    expect($token->status)->toBe('PENDING');
})->uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('handles token creation failure gracefully', function () {
    // Mock auth client to throw error
    $authClient = mock(WebullAuthClient::class);
    $authClient->shouldReceive('createToken')
        ->once()
        ->andThrow(new \RuntimeException('Invalid credentials'));

    $response = $this->postJson('/orders/place', [
        'symbol' => 'GOOGL',
        'shares' => 1,
    ]);

    $response->assertStatus(400);
    $response->assertJsonFragment([
        'error' => 'Order failed: Failed to create Webull token: Invalid credentials',
    ]);
})->uses(Illuminate\Foundation\Testing\RefreshDatabase::class);
