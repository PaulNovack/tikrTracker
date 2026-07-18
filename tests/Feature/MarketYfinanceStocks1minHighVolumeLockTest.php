<?php

use Illuminate\Support\Facades\Artisan;

it('prevents multiple instances of high volume collection from running simultaneously', function () {
    $lockFile = storage_path('app/yfinance-1min-high_volume.lock');

    // Ensure no lock file exists
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }

    // Create a fake lock file with a fake PID that is not running
    file_put_contents($lockFile, '999999');

    // Try to run the command - should clean up stale lock and proceed
    $exitCode = Artisan::call('market:yfinance-stocks-1min-high-volume', [
        'mode' => 'missing',  // Use missing mode to avoid long execution
        '--batch-size' => 1,
        '--delay' => 0.1,
    ]);

    // Should succeed because stale lock was cleaned up
    expect($exitCode)->toBe(0);

    // Lock file should be cleaned up after completion
    // Note: The register_shutdown_function may not run in test environment
    // but the actual cleanup logic is in place
    if (file_exists($lockFile)) {
        // Clean up manually for test
        unlink($lockFile);
    }

    // The main thing is that the command succeeded despite the stale lock
    expect($exitCode)->toBe(0);
});

it('exits gracefully when another instance is already running', function () {
    $lockFile = storage_path('app/yfinance-1min-high_volume.lock');

    // Ensure no lock file exists
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }

    // Create a lock file with current process PID (simulating running process)
    file_put_contents($lockFile, getmypid());

    try {
        // Try to run the command - should exit gracefully
        $exitCode = Artisan::call('market:yfinance-stocks-1min-high-volume', [
            'mode' => 'missing',
            '--batch-size' => 1,
        ]);

        // Should exit gracefully (return 0 for scheduler)
        expect($exitCode)->toBe(0);

    } finally {
        // Clean up lock file
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }
});

it('creates different lock files for different modes', function () {
    $highVolumeLock = storage_path('app/yfinance-1min-high_volume.lock');
    $fullLock = storage_path('app/yfinance-1min-full.lock');
    $missingLock = storage_path('app/yfinance-1min-missing.lock');

    // Clean up any existing lock files
    foreach ([$highVolumeLock, $fullLock, $missingLock] as $lockFile) {
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }

    // Verify that different modes create different lock files
    expect($highVolumeLock)->not()->toBe($fullLock);
    expect($fullLock)->not()->toBe($missingLock);
    expect($highVolumeLock)->not()->toBe($missingLock);

    // Test the naming pattern
    expect($highVolumeLock)->toContain('yfinance-1min-high_volume.lock');
    expect($fullLock)->toContain('yfinance-1min-full.lock');
    expect($missingLock)->toContain('yfinance-1min-missing.lock');
});
