<?php

// Test database connectivity without DatabaseTransactions to avoid migration issues

it('can connect to mysql test database', function () {
    // This should connect to laravelInvestTest database
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');

    // Check we can query the database
    $result = DB::select('SELECT DATABASE() as db_name');
    expect($result[0]->db_name)->toBe('laravelInvestTest');
});

it('has crypto assets in test database', function () {
    // Check that we have crypto assets from the production data we copied
    $cryptoCount = DB::table('asset_info')
        ->where('asset_type', 'crypto')
        ->whereNull('deleted_at')
        ->count();

    expect($cryptoCount)->toBeGreaterThan(0);

    // Check for specific cryptos we know should exist
    $xrp = DB::table('asset_info')
        ->where('asset_type', 'crypto')
        ->where('symbol', 'XRP')
        ->first();

    expect($xrp)->not->toBeNull();
    expect($xrp->common_name)->toContain('Ripple');
});

it('test environment is properly configured', function () {
    expect(config('app.env'))->toBe('testing');
    expect(config('database.default'))->toBe('mysql');
    expect(config('database.connections.mysql.database'))->toBe('laravelInvestTest');
});
