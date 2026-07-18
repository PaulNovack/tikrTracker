<?php

use App\Models\Sentiment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('can display sentiments page with table layout', function () {
    $user = User::factory()->create();

    // Create test sentiment data
    $sentiment = Sentiment::create([
        'symbol' => 'AAPL',
        'sentiment_text' => 'Strong earnings beat drives positive sentiment',
        'sentiment_type' => 'positive',
        'sentiment_date' => now()->toDateString(),
        'confidence_score' => 0.85,
    ]);

    $response = $this->actingAs($user)->get('/sentiments');

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sentiments')
            ->has('sentiments', 1)
            ->where('sentiments.0.symbol', 'AAPL')
            ->where('sentiments.0.sentiment_type', 'positive')
            ->has('stats')
            ->has('availableDates')
        );
});

it('requires authentication to access sentiments page', function () {
    $response = $this->get('/sentiments');

    $response->assertRedirect('/login');
});
