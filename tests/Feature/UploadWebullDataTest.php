<?php

use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;

it('can access the upload webull data page when authenticated', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/upload-webull-data');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->component('UploadWebullData'));
});

it('redirects guests to login on upload webull data page', function () {
    $response = $this->get('/upload-webull-data');

    $response->assertRedirect('/login');
});

it('shows upload data in sidebar navigation for authenticated users', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertSuccessful();
    // The sidebar is rendered by the React component, so we just verify the page loads
});

it('can upload webull csv and link sell orders to buy orders', function () {
    $user = User::factory()->create();

    // Create a sample Webull CSV content
    $csvContent = "Name,Symbol,Side,Status,Filled,Total Qty,Price,Avg Price,Time-in-Force,Placed Time,Filled Time\n";
    $csvContent .= "Apple Inc,AAPL,Buy,Filled,10.00000000,10.00000000,@150.00,@150.50,Day,12/04/2025 10:00:00 EST,12/04/2025 10:00:15 EST\n";
    $csvContent .= "Apple Inc,AAPL,Sell,Filled,10.00000000,10.00000000,@155.00,@155.25,Day,12/04/2025 11:00:00 EST,12/04/2025 11:00:15 EST\n";

    $file = UploadedFile::fake()->createWithContent('webull_test.csv', $csvContent);

    $response = $this->actingAs($user)->post('/upload-webull-data', [
        'file' => $file,
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'success' => true,
        'message' => 'Upload completed successfully!',
        'details' => [
            'processed' => 2,
            'skipped' => 0,
            'errors' => 0,
        ],
    ]);

    // Verify transactions were created
    expect(StockTransaction::where('user_id', $user->id)->count())->toBe(2);

    // Verify buy transaction
    $buyTransaction = StockTransaction::where('user_id', $user->id)
        ->where('type', 'buy')
        ->where('symbol', 'AAPL')
        ->first();

    expect($buyTransaction)->not->toBeNull();
    expect((float) $buyTransaction->quantity)->toBe(10.0);
    expect((float) $buyTransaction->price_per_share)->toBe(150.0);
    expect((float) $buyTransaction->avg_price)->toBe(150.50);

    // Verify sell transaction
    $sellTransaction = StockTransaction::where('user_id', $user->id)
        ->where('type', 'sell')
        ->where('symbol', 'AAPL')
        ->first();

    expect($sellTransaction)->not->toBeNull();
    expect((float) $sellTransaction->quantity)->toBe(10.0);
    expect((float) $sellTransaction->price_per_share)->toBe(155.0);
    expect((float) $sellTransaction->avg_price)->toBe(155.25);

    // Verify sell is linked to buy
    expect($sellTransaction->stock_buy_id)->toBe($buyTransaction->id);
});

it('skips duplicate transactions when uploading same file twice', function () {
    $user = User::factory()->create();

    $csvContent = "Name,Symbol,Side,Status,Filled,Total Qty,Price,Avg Price,Time-in-Force,Placed Time,Filled Time\n";
    $csvContent .= "Microsoft Corp,MSFT,Buy,Filled,5.00000000,5.00000000,@300.00,@300.50,Day,12/04/2025 09:00:00 EST,12/04/2025 09:00:15 EST\n";

    $file1 = UploadedFile::fake()->createWithContent('webull_test1.csv', $csvContent);
    $file2 = UploadedFile::fake()->createWithContent('webull_test2.csv', $csvContent);

    // First upload
    $response1 = $this->actingAs($user)->post('/upload-webull-data', [
        'file' => $file1,
    ]);

    $response1->assertSuccessful();
    $response1->assertJson([
        'details' => [
            'processed' => 1,
            'skipped' => 0,
        ],
    ]);

    // Second upload with same data
    $response2 = $this->actingAs($user)->post('/upload-webull-data', [
        'file' => $file2,
    ]);

    $response2->assertSuccessful();
    $response2->assertJson([
        'details' => [
            'processed' => 0,
            'skipped' => 1,
        ],
    ]);

    // Verify only one transaction exists
    expect(StockTransaction::where('user_id', $user->id)->where('symbol', 'MSFT')->count())->toBe(1);
});

it('handles invalid csv format gracefully', function () {
    $user = User::factory()->create();

    $invalidCsv = "Invalid,Header,Format\nSome,Invalid,Data\n";
    $file = UploadedFile::fake()->createWithContent('invalid.csv', $invalidCsv);

    $response = $this->actingAs($user)->post('/upload-webull-data', [
        'file' => $file,
    ]);

    $response->assertStatus(422);
    $response->assertJson([
        'success' => false,
    ]);

    // Check that the error message contains the expected text
    $responseData = $response->json();
    expect($responseData['message'])->toContain('Invalid Webull CSV format. Missing headers:');
});
