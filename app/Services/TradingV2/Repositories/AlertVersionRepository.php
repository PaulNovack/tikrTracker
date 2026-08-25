<?php

namespace App\Services\TradingV2\Repositories;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Loads active alert versions + their gate thresholds from the DB.
 * Results cached in Redis for 1 hour.
 *
 * The ONLY MySQL query in the hot path — and it runs at most once per hour.
 */
class AlertVersionRepository
{
    private const CACHE_KEY = 'rt:config:tradingv2:versions';

    private const CACHE_TTL = 3600;

    /**
     * @var list<array{id: int, pipeline_letter: string, version_string: string,
     *                 signal_type: string, scanner_score_formula: ?string,
     *                 gates_5m: array, gates_1m: array}>
     */
    private array $lastKnownGoodActive = [];

    /**
     * Returns all enabled alert versions with their 5m and 1m gate configs.
     *
     * @return list<array{id: int, pipeline_letter: string, version_string: string,
     *                    signal_type: string,
     *                    scanner_score_formula: ?string,
     *                    gates_5m: array, gates_1m: array}>
     */
    public function getActive(): array
    {
        return $this->getActiveRaw();
    }

    /**
     * Get active versions directly from DB, bypassing Redis cache.
     * Used for backtests where Redis may not be available.
     */
    public function getActiveDb(): array
    {
        return $this->queryDb();
    }

    /**
     * @return list<array{id: int, pipeline_letter: string, version_string: string,
     *                    signal_type: string, scanner_score_formula: ?string,
     *                    gates_5m: array, gates_1m: array}>
     */
    private function getActiveRaw(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->queryDbWithRetry());
        } catch (\Throwable $e) {
            if ($this->lastKnownGoodActive !== []) {
                Log::channel('bar-events')->warning('[AlertVersionRepository] Returning last known good active versions after cache failure', [
                    'error' => $e->getMessage(),
                    'cached_versions' => count($this->lastKnownGoodActive),
                ]);

                return $this->lastKnownGoodActive;
            }

            return $this->queryDbWithRetry();
        }
    }

    /**
     * Retry transient DB failures a few times before giving up.
     */
    private function queryDbWithRetry(): array
    {
        $attempt = 0;
        $maxAttempts = 3;

        while (true) {
            try {
                return $this->queryDb();
            } catch (\Throwable $e) {
                $attempt++;

                if ($attempt < $maxAttempts && $this->isTransientDatabaseFailure($e)) {
                    $waitMs = $attempt * 100;

                    Log::channel('bar-events')->warning('[AlertVersionRepository] Transient DB failure, retrying', [
                        'attempt' => $attempt,
                        'wait_ms' => $waitMs,
                        'error' => $e->getMessage(),
                    ]);

                    usleep($waitMs * 1000);

                    continue;
                }

                throw $e;
            }
        }
    }

    private function queryDb(): array
    {
        $versions = DB::table('alert_versions')
            ->where('enabled', true)
            ->orderBy('pipeline_letter')
            ->get();

        if ($versions->isEmpty()) {
            return [];
        }

        $ids = $versions->pluck('id')->all();

        $allGates = DB::table('alert_version_gates')
            ->whereIn('alert_version_id', $ids)
            ->where('enabled', true)
            ->get()
            ->groupBy('alert_version_id');

        return $this->lastKnownGoodActive = $versions->map(function ($v) use ($allGates) {
            $gates = $allGates->get($v->id, collect());

            $gates5m = [];
            $gates1m = [];
            foreach ($gates as $g) {
                $entry = [
                    'threshold_min' => $g->threshold_min !== null ? (float) $g->threshold_min : null,
                    'threshold_max' => $g->threshold_max !== null ? (float) $g->threshold_max : null,
                ];
                if ($g->timeframe === '5m') {
                    $gates5m[$g->gate_name] = $entry;
                } else {
                    $gates1m[$g->gate_name] = $entry;
                }
            }

            return [
                'id' => (int) $v->id,
                'pipeline_letter' => $v->pipeline_letter,
                'version_string' => $v->version_string,
                'signal_type' => $v->signal_type,
                'scanner_score_formula' => $v->scanner_score_formula,
                'gates_5m' => $gates5m,
                'gates_1m' => $gates1m,
            ];
        })->all();
    }

    private function isTransientDatabaseFailure(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        $code = (string) $e->getCode();

        return str_contains($message, 'deadlock')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'server has gone away')
            || str_contains($message, 'lost connection')
            || str_contains($message, 'too many connections')
            || in_array($code, ['1205', '1213', '40001', '2002', '2006', '2013'], true);
    }
}
