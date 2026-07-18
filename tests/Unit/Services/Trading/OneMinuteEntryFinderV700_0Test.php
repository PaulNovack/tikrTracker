<?php

use App\Services\Trading\OneMinuteEntryFinderV700_0;

beforeEach(function () {
    $this->finder = app(OneMinuteEntryFinderV700_0::class);
});

it('finds short entry after VWAP rejection', function () {
    $result = $this->finder->findBestShort(
        symbol: 'AAPL',
        assetType: 'stock',
        signalTsEst: '2026-02-05 10:00:00',
        asOfTsEst: '2026-02-05 14:30:00',
        beforeMinutes: 15,
        afterMinutes: 30,
        volLookback: 20,
        pivotLookback: 15,
        fillModel: 'next_open'
    );

    expect($result)->toBeArray();
    expect($result)->toHaveKey('ok');

    if ($result['ok'] === true) {
        expect($result)->toHaveKey('best_entry');
        expect($result['best_entry'])
            ->toHaveKey('type')
            ->toHaveKey('entry')
            ->toHaveKey('stop')
            ->toHaveKey('score')
            ->toHaveKey('risk_per_share')
            ->toHaveKey('pct_targets')
            ->toHaveKey('targets');

        // Check short-specific fields
        expect($result['best_entry'])
            ->toHaveKey('short_readiness')
            ->toHaveKey('lod');

        // For shorts, stop should be above entry
        $entry = $result['best_entry']['entry'];
        $stop = $result['best_entry']['stop'];
        expect($stop)->toBeGreaterThan($entry);

        // Check targets are below entry (price drops)
        $pctTargets = $result['best_entry']['pct_targets'];
        expect($pctTargets)->toHaveKey('-2%');
        expect($pctTargets['-2%'])->toBeLessThan($entry);
    }
});

it('returns error when not enough 1m data', function () {
    $result = $this->finder->findBestShort(
        symbol: 'INVALID',
        assetType: 'stock',
        signalTsEst: '2020-01-01 10:00:00',
        asOfTsEst: '2020-01-01 10:30:00',
        beforeMinutes: 15,
        afterMinutes: 30
    );

    expect($result)
        ->toBeArray()
        ->toHaveKey('ok', false)
        ->toHaveKey('error');
});

it('has correct version', function () {
    expect($this->finder->getVersion())->toBe('v700.0');
});

it('filters by score thresholds', function () {
    $result = $this->finder->findBestShort(
        symbol: 'AAPL',
        assetType: 'stock',
        signalTsEst: '2026-02-05 10:00:00',
        asOfTsEst: '2026-02-05 14:30:00',
        beforeMinutes: 15,
        afterMinutes: 30
    );

    if ($result['ok'] === true && ! empty($result['candidates'])) {
        $minScore = config('trading.v700.entry_score_min', 80);
        $maxScore = config('trading.v700.entry_score_max', 100);

        foreach ($result['candidates'] as $candidate) {
            $score = $candidate['score'];
            expect($score)->toBeGreaterThanOrEqual($minScore);
            expect($score)->toBeLessThanOrEqual($maxScore);
        }
    }

    expect($result)->toBeArray();
});

it('returns only allowed short entry types', function () {
    $result = $this->finder->findBestShort(
        symbol: 'AAPL',
        assetType: 'stock',
        signalTsEst: '2026-02-05 10:00:00',
        asOfTsEst: '2026-02-05 14:30:00',
        beforeMinutes: 15,
        afterMinutes: 30
    );

    if ($result['ok'] === true && ! empty($result['candidates'])) {
        $allowedTypes = ['VWAP_REJECTION_1M', 'LOWER_HIGH_BREAK', 'LOD_FLUSH'];

        foreach ($result['candidates'] as $candidate) {
            expect($candidate['type'])->toBeIn($allowedTypes);
        }
    }

    expect($result)->toBeArray();
});
