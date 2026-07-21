<?php

use App\Models\AssetInfo;
use App\Models\User;
use App\Models\Watch;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    // Create some test assets
    $this->asset1 = AssetInfo::factory()->create(['symbol' => 'AAPL']);
    $this->asset2 = AssetInfo::factory()->create(['symbol' => 'MSFT']);
    $this->asset3 = AssetInfo::factory()->create(['symbol' => 'GOOGL']);
    $this->invalidAsset = 'INVALID123'; // This symbol doesn't exist
});

it('displays the CSV set watches page', function () {
    $response = $this->get('/watches/csv');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('csv-set-watches')
        ->has('maxWatches')
        ->has('currentWatchCount')
    );
});

it('adds valid symbols to watch list', function () {
    $symbols = 'AAPL, MSFT, GOOGL';

    $response = $this->post('/watches/csv', [
        'symbols' => $symbols,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('message');

    // Verify watches were created
    expect(Watch::where('user_id', $this->user->id)->count())->toBe(3);
    expect(Watch::where('user_id', $this->user->id)
        ->whereHas('asset', fn ($q) => $q->whereIn('symbol', ['AAPL', 'MSFT', 'GOOGL']))
        ->count()
    )->toBe(3);
});

it('handles duplicates correctly', function () {
    // First, create an existing watch
    Watch::create([
        'user_id' => $this->user->id,
        'asset_info_id' => $this->asset1->id, // AAPL
    ]);

    $symbols = 'AAPL, MSFT, AAPL'; // AAPL appears twice, one already exists

    $response = $this->post('/watches/csv', [
        'symbols' => $symbols,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('message');

    // Should have 2 total watches (1 existing AAPL + 1 new MSFT)
    expect(Watch::where('user_id', $this->user->id)->count())->toBe(2);

    $message = session('message');
    expect($message)->toContain('Added 1 new watch');
    expect($message)->toContain('Skipped 1 duplicate');
});

it('ignores invalid symbols', function () {
    $symbols = 'AAPL, INVALID123, MSFT';

    $response = $this->post('/watches/csv', [
        'symbols' => $symbols,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('message');

    // Should only create 2 watches (AAPL and MSFT)
    expect(Watch::where('user_id', $this->user->id)->count())->toBe(2);

    $message = session('message');
    expect($message)->toContain('Added 2 new watches');
    expect($message)->toContain('Ignored 1 invalid symbol');
});

it('respects watch limits', function () {
    // Set max watches to 2 for this test
    config(['app.max_watches' => 2]);

    // Create one existing watch
    Watch::create([
        'user_id' => $this->user->id,
        'asset_info_id' => $this->asset1->id,
    ]);

    $symbols = 'MSFT, GOOGL, TSLA'; // Try to add 3 more, but TSLA won't exist in test

    $response = $this->post('/watches/csv', [
        'symbols' => $symbols,
    ]);

    $response->assertRedirect();

    // Should only have 2 total (1 existing + 1 new)
    expect(Watch::where('user_id', $this->user->id)->count())->toBe(2);

    $message = session('message');
    expect($message)->toContain('Watch limit of 2 reached');
});

it('validates required symbols field', function () {
    $response = $this->post('/watches/csv', [
        'symbols' => '',
    ]);

    $response->assertSessionHasErrors(['symbols']);
});

it('handles mixed case symbols correctly', function () {
    $symbols = 'aapl, MsfT, googL';

    $response = $this->post('/watches/csv', [
        'symbols' => $symbols,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('message');

    // Verify watches were created (symbols should be converted to uppercase)
    expect(Watch::where('user_id', $this->user->id)->count())->toBe(3);
});

it('handles empty and whitespace in symbol list', function () {
    $symbols = ' AAPL , , MSFT  ,   , GOOGL ';

    $response = $this->post('/watches/csv', [
        'symbols' => $symbols,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('message');

    // Should create 3 watches, empty entries should be filtered out
    expect(Watch::where('user_id', $this->user->id)->count())->toBe(3);
});
