<?php

use App\Models\Sentiment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('displays sentiments page with most recent date when no date specified', function () {
    $user = User::factory()->create();

    // Create some sentiment data for yesterday
    $yesterday = Carbon::yesterday();
    Sentiment::factory()->create([
        'symbol' => 'AAPL',
        'sentiment_date' => $yesterday,
        'sentiment_text' => 'Bullish outlook',
        'sentiment_type' => 'positive',
        'confidence_score' => 0.85,
    ]);

    Sentiment::factory()->create([
        'symbol' => 'TSLA',
        'sentiment_date' => $yesterday,
        'sentiment_text' => 'Bearish sentiment',
        'sentiment_type' => 'negative',
        'confidence_score' => 0.75,
    ]);

    $response = $this->actingAs($user)->get('/sentiments');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('sentiments')
        ->has('sentiments', 2) // Should load the 2 sentiments from yesterday
        ->where('selectedDate', $yesterday->toDateString())
    );
});

it('displays empty state when no sentiment data exists', function () {
    $user = User::factory()->create();

    // Don't create any sentiment data
    $response = $this->actingAs($user)->get('/sentiments');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('sentiments')
        ->has('sentiments', 0) // Should be empty
        ->where('selectedDate', today()->toDateString()) // Should default to today when no data
    );
});

it('displays sentiments for specific date when date parameter provided', function () {
    $user = User::factory()->create();

    // Create sentiment data for different dates
    $specificDate = Carbon::parse('2025-12-05');
    Sentiment::factory()->create([
        'symbol' => 'MSFT',
        'sentiment_date' => $specificDate,
        'sentiment_text' => 'Strong performance',
        'sentiment_type' => 'positive',
        'confidence_score' => 0.90,
    ]);

    $response = $this->actingAs($user)->get('/sentiments?date='.$specificDate->toDateString());

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('sentiments')
        ->has('sentiments', 1) // Should find 1 sentiment for that specific date
        ->where('selectedDate', $specificDate->toDateString())
    );
});
