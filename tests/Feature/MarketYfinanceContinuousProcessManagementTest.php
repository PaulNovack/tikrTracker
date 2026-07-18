<?php

test('continuous sync command handles process cleanup properly', function () {
    // Test that the command can run and exit cleanly
    $result = $this->artisan('market:yfinance-continuous-sync', [
        '--hours' => 0.5,
        '--parallel-jobs' => 1,
        '--batch-size' => 10,
        '--max-symbols' => 5,
    ]);

    $result->assertSuccessful();

    // Verify no lock file remains
    $lockFile = storage_path('app/yfinance-continuous-sync.lock');
    expect(file_exists($lockFile))->toBeFalse('Lock file should be cleaned up after command completion');
});

test('continuous sync prevents multiple instances', function () {
    // Create a fake lock file
    $lockFile = storage_path('app/yfinance-continuous-sync.lock');
    file_put_contents($lockFile, getmypid());

    try {
        $result = $this->artisan('market:yfinance-continuous-sync', [
            '--hours' => 0.5,
            '--max-symbols' => 1,
        ]);

        $result->assertFailed();
    } finally {
        // Clean up
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }
});

test('continuous sync removes stale lock files', function () {
    // Create a fake lock file with a non-existent PID
    $lockFile = storage_path('app/yfinance-continuous-sync.lock');
    file_put_contents($lockFile, '999999'); // Non-existent PID

    $result = $this->artisan('market:yfinance-continuous-sync', [
        '--hours' => 0.5,
        '--parallel-jobs' => 1,
        '--batch-size' => 5,
        '--max-symbols' => 2,
    ]);

    $result->assertSuccessful();

    // Verify the stale lock file was removed and cleaned up properly
    expect(file_exists($lockFile))->toBeFalse('Stale lock file should be removed and cleaned up');
});

test('continuous sync processes expected number of symbols', function () {
    $result = $this->artisan('market:yfinance-continuous-sync', [
        '--hours' => 0.5,
        '--parallel-jobs' => 1,
        '--batch-size' => 3,
        '--max-symbols' => 5,
    ]);

    $result->assertSuccessful();
    $result->expectsOutput('   Total symbols processed: 5');
});
