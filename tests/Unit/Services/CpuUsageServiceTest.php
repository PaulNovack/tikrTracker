<?php

use App\Services\CpuUsageService;

it('returns a valid cpu usage percentage', function () {
    $service = new CpuUsageService;
    $usage = $service->getCurrentUsage();

    expect($usage)->toBeFloat()
        ->toBeGreaterThanOrEqual(0.0)
        ->toBeLessThanOrEqual(100.0);
});

it('returns load average information', function () {
    $service = new CpuUsageService;
    $load = $service->getLoadAverage();

    expect($load)->toBeFloat()
        ->toBeGreaterThanOrEqual(0.0);
});

it('returns cpu core count', function () {
    $service = new CpuUsageService;
    $cores = $service->getCpuCoreCount();

    expect($cores)->toBeInt()
        ->toBeGreaterThan(0);
});

it('returns detailed cpu information', function () {
    $service = new CpuUsageService;
    $info = $service->getDetailedInfo();

    expect($info)->toBeArray()
        ->toHaveKeys(['usage_percent', 'load_average_1min', 'load_average_5min', 'load_average_15min', 'cpu_cores', 'timestamp'])
        ->and($info['usage_percent'])->toBeFloat()
        ->and($info['cpu_cores'])->toBeInt();
});

it('checks if usage is above threshold', function () {
    $service = new CpuUsageService;
    $result = $service->isHighUsage(80.0);

    expect($result)->toBeBool();
});

it('returns instant cpu usage', function () {
    $service = new CpuUsageService;
    $usage = $service->getInstantUsage();

    expect($usage)->toBeFloat()
        ->toBeGreaterThanOrEqual(0.0)
        ->toBeLessThanOrEqual(100.0);
});
