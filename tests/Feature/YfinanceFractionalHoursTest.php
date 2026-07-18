<?php

use Illuminate\Support\Facades\File;

it('uses start/end time approach for fractional hours', function () {
    // This test verifies the Python script logic path for fractional hours
    $pythonScript = File::get(base_path('python/yfinance_stocks_5min_batch.py'));

    // Should have fractional hour support in docstring
    expect($pythonScript)
        ->toContain('supports fractional, e.g., 0.5 for 30 minutes')
        ->and($pythonScript)
        ->toContain('float(sys.argv[1])')
        ->and($pythonScript)
        ->toContain('if hours_back < 1:')
        ->and($pythonScript)
        ->toContain('start=start_time, end=now');
});

it('scheduler uses fractional hours for 5-minute sync commands', function () {
    // Read the console routes file directly
    $consoleRoutesContent = File::get(base_path('routes/console.php'));

    // Should have multiple occurrences of --hours=0.1 (current configuration)
    $occurrences = substr_count($consoleRoutesContent, '--hours=0.1');
    expect($occurrences)->toBeGreaterThanOrEqual(2);

    // Should not have any --hours=1 in the yfinance commands
    expect($consoleRoutesContent)
        ->not->toMatch('/market:yfinance-continuous-sync.*--hours=1/');
});
