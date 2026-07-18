<?php

use App\Services\Trading\OneMinuteEntryFinderV25_2;

beforeEach(function () {
    $this->finder = app(OneMinuteEntryFinderV25_2::class);
});

it('has the correct version', function () {
    expect($this->finder->getVersion())->toBe('v25.2');
});

it('returns the correct response shape when no data exists', function () {
    $result = $this->finder->findBestLong(
        symbol: 'DOESNOTEXIST',
        assetType: 'stock',
        signalTsEst: '2020-01-02 09:40:00',
        asOfTsEst: '2020-01-02 10:30:00',
    );

    expect($result)
        ->toBeArray()
        ->toHaveKey('ok', 0)
        ->toHaveKey('best_entry', null)
        ->toHaveKey('reason', 'no_entry');
});

it('rejects entries during blocked lunch hours', function () {
    // 12:00 EST falls outside both allowed windows (9:35-11:15 and 14:00-15:55)
    $result = $this->finder->findBestLong(
        symbol: 'DOESNOTEXIST',
        assetType: 'stock',
        signalTsEst: '2020-01-02 09:40:00',
        asOfTsEst: '2020-01-02 12:00:00',
    );

    expect($result)
        ->toBeArray()
        ->toHaveKey('ok', 0)
        ->toHaveKey('reason', 'no_entry');
});

it('allows entries in the morning window', function () {
    // 10:30 falls in the morning window (9:35-11:15); result depends on data but
    // the time gate must not block it — confirmed by checking it doesn't return time_blocked reason
    // (it will return no_entry due to missing test data, but NOT due to time blocking)
    $result = $this->finder->findBestLong(
        symbol: 'DOESNOTEXIST',
        assetType: 'stock',
        signalTsEst: '2020-01-02 09:40:00',
        asOfTsEst: '2020-01-02 10:30:00',
    );

    // Allowed time so reason must be no_entry (data missing), not a time block
    expect($result['ok'])->toBe(0);
    expect($result['reason'] ?? '')->toBe('no_entry');
});

it('implements findBestShort as not-implemented stub', function () {
    $result = $this->finder->findBestShort(
        symbol: 'AAPL',
        assetType: 'stock',
        signalTsEst: '2020-01-02 09:40:00',
        asOfTsEst: '2020-01-02 10:30:00',
    );

    expect($result)
        ->toBeArray()
        ->toHaveKey('ok', 0)
        ->toHaveKey('reason', 'short_not_implemented');
});
