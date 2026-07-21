<?php

use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('deduplicates identical Webull rows on upload', function () {
    $user = User::factory()->create();

    $symbol = strtoupper(Str::random(4));
    $seconds = str_pad((string) random_int(0, 59), 2, '0', STR_PAD_LEFT);
    $placedTime = "12/19/2025 09:33:{$seconds} EST";
    $filledTime = "12/19/2025 09:38:{$seconds} EST";

    $row = sprintf(
        'Plus Therapeutics Inc,%s,Sell,Filled,"5,439","5,439","$0.54","$0.54",DAY,%s,%s',
        $symbol,
        $placedTime,
        $filledTime,
    );

    $csv = implode("\n", [
        'Name,Symbol,Side,Status,Filled,Total Qty,Price,Avg Price,Time-in-Force,Placed Time,Filled Time',
        $row,
        $row,
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('webull.csv', $csv);

    $response = $this->actingAs($user)->post('/upload-webull-data', [
        'file' => $file,
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('details.processed', 1)
        ->assertJsonPath('details.skipped', 1)
        ->assertJsonPath('details.errors', 0);

    expect(StockTransaction::where('user_id', $user->id)->count())->toBe(1);

    $transaction = StockTransaction::where('user_id', $user->id)->first();
    expect($transaction->symbol)->toBe($symbol);
    expect($transaction->type)->toBe('sell');
    expect($transaction->broker_order_id)->toStartWith('webull:');
    expect((float) $transaction->quantity)->toBe(5439.0);
    expect((float) $transaction->price_per_share)->toBe(0.54);
});
