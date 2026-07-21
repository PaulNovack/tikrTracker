<?php

use App\Console\Commands\AnalyzeMlThresholds;

it('only applies lower-only threshold updates when the suggestion is below a stored threshold', function () {
    $command = new AnalyzeMlThresholds;

    $shouldApply = new ReflectionMethod($command, 'shouldApplyThreshold');
    $shouldApply->setAccessible(true);

    $shouldClearOverride = new ReflectionMethod($command, 'shouldClearOverride');
    $shouldClearOverride->setAccessible(true);

    expect($shouldApply->invoke($command, 0.55, 0.50, true))->toBeTrue();
    expect($shouldApply->invoke($command, 0.55, 0.60, true))->toBeFalse();
    expect($shouldApply->invoke($command, null, 0.50, true))->toBeFalse();
    expect($shouldApply->invoke($command, null, 0.50, false))->toBeTrue();

    expect($shouldClearOverride->invoke($command, true))->toBeFalse();
    expect($shouldClearOverride->invoke($command, false))->toBeTrue();
});
