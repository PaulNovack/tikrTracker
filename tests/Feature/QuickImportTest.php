<?php

use App\Models\User;

it('can render quick import page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/quick-import');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->component('investments/quick-import/index'));
});

it('can parse trade notification text', function () {
    $user = User::factory()->create();

    $tradeText = <<<'TEXT'
    Your position was opened
    We filled your buy order for a new AXS position on 11/18/2025. Here are the details:
    AXS Units: 227.876946
    AXS Unit Price: $1.3165
    Invested Amount: $300.00
    TEXT;

    $response = $this->actingAs($user)->postJson('/quick-import/parse', [
        'text' => $tradeText,
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'symbol' => 'AXS',
        'quantity' => '227.876946',
        'price_per_share' => '1.3165',
        'total_amount' => '300.00',
        'transaction_date' => '2025-11-18T00:00',
    ]);
});

it('handles missing data gracefully', function () {
    $user = User::factory()->create();

    $incompleteText = <<<'TEXT'
    Some random text without trade details
    TEXT;

    $response = $this->actingAs($user)->postJson('/quick-import/parse', [
        'text' => $incompleteText,
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'symbol' => null,
        'quantity' => null,
        'price_per_share' => null,
        'total_amount' => null,
        'transaction_date' => null,
    ]);
});

it('requires authentication to access quick import page', function () {
    $response = $this->get('/quick-import');

    $response->assertRedirect('/login');
});

it('requires authentication to parse trade text', function () {
    $response = $this->postJson('/quick-import/parse', [
        'text' => 'some text',
    ]);

    $response->assertUnauthorized();
});
