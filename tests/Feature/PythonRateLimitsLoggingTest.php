<?php

use Symfony\Component\Process\Process;

it('python logger writes empty-batch warnings to the rate-limits log file', function () {
    $python = trim((string) shell_exec('command -v python3 || command -v python'));

    if ($python === '') {
        $this->markTestSkipped('python is not available');
    }

    $path = storage_path('logs/laravel-rate-limits-'.now()->format('Y-m-d').'.log');

    $code = <<<'PY'
import sys
sys.path.insert(0, 'python')
import laravel_logger
laravel_logger.log_yfinance_empty_batch('1m', ['AAPL','MSFT'], hours_back=1, period='1d', details={'test': True})
PY;

    $process = new Process([$python, '-c', $code], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue();
    expect(file_exists($path))->toBeTrue();
    expect(file_get_contents($path))->toContain('Empty batch response from yfinance (1m)');
});
