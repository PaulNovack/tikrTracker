<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;
use Inertia\Response;

class RedisKeysController extends Controller
{
    /**
     * Render the Redis keys explorer page.
     */
    public function index(): Response
    {
        $summary = $this->getKeySummary();

        return Inertia::render('system/RedisKeys', [
            'summary' => $summary,
            'lastUpdated' => now('America/New_York')->format('Y-m-d H:i:s T'),
        ]);
    }

    /**
     * AJAX: Return key summary grouped by prefix.
     */
    public function summary(): JsonResponse
    {
        return response()->json([
            'summary' => $this->getKeySummary(),
            'lastUpdated' => now('America/New_York')->format('Y-m-d H:i:s T'),
        ]);
    }

    /**
     * AJAX: Get the value of a specific Redis key.
     */
    public function show(Request $request): JsonResponse
    {
        $key = $request->query('key');

        if (! $key || ! is_string($key)) {
            return response()->json(['error' => 'Missing key parameter.'], 422);
        }

        try {
            $redis = Redis::connection('noprefix');
            $type = $redis->type($key);
            $ttl = $redis->ttl($key);

            $value = match ($type) {
                'string' => $redis->get($key),
                'hash' => $redis->hgetall($key),
                'list' => $this->getListSample($key),
                'set' => $this->getSetSample($key),
                'zset' => $this->getZsetSample($key),
                'stream' => $this->getStreamSample($key),
                'none' => null,
                default => null,
            };

            return response()->json([
                'key' => $key,
                'type' => $type,
                'ttl' => $ttl,
                'value' => $value,
                'size' => $this->getKeySize($key, $type),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * AJAX: Delete a Redis key.
     */
    public function destroy(Request $request): JsonResponse
    {
        $key = $request->input('key');

        if (! $key || ! is_string($key)) {
            return response()->json(['error' => 'Missing key parameter.'], 422);
        }

        try {
            $deleted = Redis::connection('noprefix')->del($key);

            return response()->json([
                'deleted' => $deleted > 0,
                'key' => $key,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * AJAX: Search for keys matching a pattern.
     */
    public function search(Request $request): JsonResponse
    {
        $pattern = $request->query('pattern');

        if (! $pattern || ! is_string($pattern)) {
            return response()->json(['error' => 'Missing pattern parameter.'], 422);
        }

        try {
            $keys = $this->scanKeys($pattern);

            $results = [];
            foreach ($keys as $key) {
                $type = Redis::connection('noprefix')->type($key);
                $ttl = Redis::connection('noprefix')->ttl($key);

                $results[] = [
                    'key' => $key,
                    'type' => $type,
                    'ttl' => $ttl,
                    'size' => $this->getKeySize($key, $type),
                ];
            }

            return response()->json([
                'keys' => $results,
                'count' => count($results),
                'pattern' => $pattern,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Scan all keys grouped by prefix with type and size counts.
     */
    private function getKeySummary(): array
    {
        try {
            $allKeys = $this->scanKeys('*');
            $grouped = [];

            foreach ($allKeys as $key) {
                // Extract prefix (first segment or up to first colon)
                $prefix = $this->keyPrefix($key);
                $type = Redis::connection('noprefix')->type($key);

                if (! isset($grouped[$prefix])) {
                    $grouped[$prefix] = [
                        'prefix' => $prefix,
                        'total' => 0,
                        'types' => [],
                        'sample_keys' => [],
                    ];
                }

                $grouped[$prefix]['total']++;
                $grouped[$prefix]['types'][$type] = ($grouped[$prefix]['types'][$type] ?? 0) + 1;

                // Keep up to 5 sample keys per prefix
                if (count($grouped[$prefix]['sample_keys']) < 5) {
                    $grouped[$prefix]['sample_keys'][] = $key;
                }
            }

            $totalKeys = count($allKeys);

            // Sort by count descending
            usort($grouped, fn ($a, $b) => $b['total'] <=> $a['total']);

            return [
                'total_keys' => $totalKeys,
                'groups' => array_values($grouped),
            ];
        } catch (\Throwable $e) {
            return [
                'total_keys' => 0,
                'groups' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Scan Redis keys matching a pattern.
     *
     * @return string[]
     */
    private function scanKeys(string $pattern): array
    {
        $keys = [];
        $cursor = null;
        $redis = Redis::connection('noprefix');

        do {
            $result = $redis->scan($cursor ?? 0, ['match' => $pattern, 'count' => 200]);
            $cursor = (int) ($result[0] ?? 0);
            foreach ($result[1] ?? [] as $k) {
                $keys[] = $k;
            }
        } while ($cursor !== 0);

        sort($keys);

        return $keys;
    }

    /**
     * Extract a readable prefix from a Redis key.
     */
    private function keyPrefix(string $key): string
    {
        // Laravel cache keys: laravel_cache:tag:key...
        if (preg_match('/^laravel_cache:([^:]+)/', $key, $m)) {
            return 'cache:'.$m[1];
        }

        // Laravel horizon / queue keys
        if (str_starts_with($key, 'horizon:')) {
            return 'horizon';
        }

        // Generic colon-delimited prefix
        $parts = explode(':', $key, 2);

        return $parts[0] !== '' ? $parts[0] : '(root)';
    }

    /**
     * Get the size of a key based on its type.
     */
    private function getKeySize(string $key, string $type): int
    {
        try {
            $redis = Redis::connection('noprefix');

            return match ($type) {
                'string' => strlen((string) $redis->get($key)),
                'hash' => (int) $redis->hlen($key),
                'list' => (int) $redis->llen($key),
                'set' => (int) $redis->scard($key),
                'zset' => (int) $redis->zcard($key),
                'stream' => (int) $redis->executeRaw(['XLEN', $key]),
                default => 0,
            };
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Get a sample of list elements.
     */
    private function getListSample(string $key): array
    {
        try {
            $redis = Redis::connection('noprefix');
            $len = (int) $redis->llen($key);
            $sample = $redis->lrange($key, 0, min(19, $len - 1));

            return [
                'length' => $len,
                'sample' => $sample,
            ];
        } catch (\Throwable) {
            return ['length' => 0, 'sample' => []];
        }
    }

    /**
     * Get a sample of set members.
     */
    private function getSetSample(string $key): array
    {
        try {
            $redis = Redis::connection('noprefix');
            $count = (int) $redis->scard($key);
            $sample = $redis->srandmember($key, min(20, $count));

            return [
                'count' => $count,
                'sample' => $sample ?: [],
            ];
        } catch (\Throwable) {
            return ['count' => 0, 'sample' => []];
        }
    }

    /**
     * Get a sample of sorted set members with scores.
     */
    private function getZsetSample(string $key): array
    {
        try {
            $redis = Redis::connection('noprefix');
            $count = (int) $redis->zcard($key);
            $sample = $redis->zrange($key, 0, 19, 'WITHSCORES');

            return [
                'count' => $count,
                'sample' => $sample ?: [],
            ];
        } catch (\Throwable) {
            return ['count' => 0, 'sample' => []];
        }
    }

    /**
     * Get a sample of stream entries (newest first).
     */
    private function getStreamSample(string $key): array
    {
        try {
            // Use XLEN for count, XREVRANGE for newest entries
            $redis = Redis::connection('noprefix');
            $count = (int) $redis->executeRaw(['XLEN', $key]);
            // XREVRANGE key + - COUNT 20 — newest to oldest
            $raw = $redis->executeRaw(['XREVRANGE', $key, '+', '-', 'COUNT', '20']);
            $entries = [];

            if (is_array($raw)) {
                foreach ($raw as $item) {
                    // $item is [entryId, [field, val, field, val, ...]]
                    $entryId = $item[0];
                    $fields = $item[1] ?? [];
                    $data = [];
                    for ($i = 0; $i + 1 < count($fields); $i += 2) {
                        $data[$fields[$i]] = $fields[$i + 1];
                    }
                    $entries[] = ['id' => $entryId, 'data' => $data];
                }
            }

            return [
                'count' => $count,
                'sample' => $entries,
            ];
        } catch (\Throwable $e) {
            return ['count' => 0, 'sample' => [], 'error' => $e->getMessage()];
        }
    }
}
