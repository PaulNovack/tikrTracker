<?php

use App\Console\Commands\Market\MarketYfinanceCrypto1MinContinuousSync;

it('crypto 1-minute continuous sync command class exists', function () {
    expect(class_exists(MarketYfinanceCrypto1MinContinuousSync::class))->toBeTrue();
});

it('can instantiate crypto 1-minute command', function () {
    $command = new MarketYfinanceCrypto1MinContinuousSync;
    expect($command)->toBeInstanceOf(MarketYfinanceCrypto1MinContinuousSync::class);
});
