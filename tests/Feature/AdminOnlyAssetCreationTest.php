<?php

use App\Jobs\Market\UpdateStockDataJob;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // Fake the queue to prevent jobs from running during tests
    Queue::fake();
});

it('shows add new symbol button only to admin users', function () {
    $adminUser = \App\Models\User::factory()->create(['role' => \App\UserRole::Admin]);
    $traderUser = \App\Models\User::factory()->create(['role' => \App\UserRole::Trader]);

    // Admin should see the add button (checking that page loads)
    $response = $this->actingAs($adminUser)
        ->get('/market-data/assets/add');

    $response->assertSuccessful();

    // Trader should not be able to access the add page
    $response = $this->actingAs($traderUser)
        ->get('/market-data/assets/add');

    $response->assertForbidden();
});

it('allows only admin users to create new assets', function () {
    $adminUser = \App\Models\User::factory()->create(['role' => \App\UserRole::Admin]);
    $traderUser = \App\Models\User::factory()->create(['role' => \App\UserRole::Trader]);

    $assetData = [
        'symbol' => 'TESTSTOCK',
        'asset_type' => 'stock',
        'common_name' => 'Test Stock Company',
        'sector' => 'Technology',
    ];

    // Admin should be able to create
    $response = $this->actingAs($adminUser)
        ->post('/market-data/assets', $assetData);

    $response->assertRedirect();
    $this->assertDatabaseHas('asset_info', ['symbol' => 'TESTSTOCK']);

    // Verify the job was dispatched
    Queue::assertPushed(UpdateStockDataJob::class);

    // Trader should not be able to create
    $assetData['symbol'] = 'TESTSTOCK2'; // Different symbol to avoid duplicate
    $response = $this->actingAs($traderUser)
        ->post('/market-data/assets', $assetData);

    $response->assertForbidden();
    $this->assertDatabaseMissing('asset_info', ['symbol' => 'TESTSTOCK2']);
});
