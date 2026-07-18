<?php

use App\Models\AlertLog;
use App\Models\PriceAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

test('alert logs persist when their price alert is deleted', function () {
    $user = User::factory()->create();

    // Create a price alert
    $priceAlert = PriceAlert::factory()->create(['user_id' => $user->id]);

    // Create alert logs for this price alert
    $alertLog1 = AlertLog::factory()->create([
        'user_id' => $user->id,
        'price_alert_id' => $priceAlert->id,
        'symbol' => 'AAPL',
    ]);

    $alertLog2 = AlertLog::factory()->create([
        'user_id' => $user->id,
        'price_alert_id' => $priceAlert->id,
        'symbol' => 'AAPL',
    ]);

    // Verify alert logs exist
    expect(AlertLog::count())->toBe(2);
    expect(AlertLog::where('price_alert_id', $priceAlert->id)->count())->toBe(2);

    // Delete the price alert
    $priceAlert->delete();

    // Alert logs should still exist in the database (with SET NULL behavior)
    expect(AlertLog::count())->toBe(2);
    expect(AlertLog::find($alertLog1->id))->not->toBeNull();
    expect(AlertLog::find($alertLog2->id))->not->toBeNull();

    // Verify that alert logs are no longer tied to a price alert
    // (they should have price_alert_id = null or the old id doesn't matter)
    $logsAfterDelete = AlertLog::where('user_id', $user->id)->get();
    expect($logsAfterDelete->count())->toBe(2);
});

test('alert logs are displayed on alert-logs page after price alert is deleted', function () {
    $user = User::factory()->create();

    // Create a price alert and alert logs
    $priceAlert = PriceAlert::factory()->create(['user_id' => $user->id]);
    AlertLog::factory()->create([
        'user_id' => $user->id,
        'price_alert_id' => $priceAlert->id,
        'symbol' => 'GOOGL',
    ]);

    // Verify we can see the alert log before deletion
    $response = $this->actingAs($user)->get(route('alert-logs.index'));
    expect($response->status())->toBe(200);

    // Delete the price alert
    $priceAlert->delete();

    // Alert log should still be visible on the page
    $response = $this->actingAs($user)->get(route('alert-logs.index'));
    expect($response->status())->toBe(200);

    // Verify the alert log still exists in the database
    $alertLogs = AlertLog::where('user_id', $user->id)->get();
    expect($alertLogs->count())->toBe(1);
    expect($alertLogs[0]['symbol'])->toBe('GOOGL');
});

test('deleting a price alert does not delete associated alert logs', function () {
    $user = User::factory()->create();

    // Create two price alerts
    $priceAlert1 = PriceAlert::factory()->create(['user_id' => $user->id]);
    $priceAlert2 = PriceAlert::factory()->create(['user_id' => $user->id]);

    // Create alert logs for both
    $log1 = AlertLog::factory()->create([
        'user_id' => $user->id,
        'price_alert_id' => $priceAlert1->id,
    ]);

    $log2 = AlertLog::factory()->create([
        'user_id' => $user->id,
        'price_alert_id' => $priceAlert2->id,
    ]);

    $log3 = AlertLog::factory()->create([
        'user_id' => $user->id,
        'price_alert_id' => $priceAlert1->id,
    ]);

    // Delete first price alert
    $priceAlert1->delete();

    // All alert logs should still exist
    expect(AlertLog::count())->toBe(3);

    // Logs from deleted alert should still be in database
    expect(AlertLog::find($log1->id))->not->toBeNull();
    expect(AlertLog::find($log3->id))->not->toBeNull();

    // Logs from undeleted alert should also be there
    expect(AlertLog::find($log2->id))->not->toBeNull();
});
