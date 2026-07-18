<?php

namespace App\Services\Webull;

use Illuminate\Support\Facades\Cache;

class WebullInstrumentClient
{
    public function __construct(private readonly WebullSignedHttp $http) {}

    /**
     * GET /instrument/list
     *
     * @param  string[]  $symbols
     */
    public function getInstruments(array $symbols, string $category = 'US_STOCK'): array
    {
        $symbols = array_values(array_unique(array_map('strtoupper', $symbols)));

        return $this->http->request('GET', '/instrument/list', [
            'symbols' => implode(',', $symbols),
            'category' => $category,
        ], [], null);
    }

    /**
     * Resolve instrument_id for a single symbol.
     */
    public function resolveInstrumentId(string $symbol, string $category = 'US_STOCK', int $cacheSeconds = 86400): ?string
    {
        $symbol = strtoupper(trim($symbol));
        $cacheKey = "webull:instrument_id:{$category}:{$symbol}";

        return Cache::remember($cacheKey, $cacheSeconds, function () use ($symbol, $category) {
            $rows = $this->getInstruments([$symbol], $category);

            // Webull returns a list of instruments (per docs)
            if (! is_array($rows) || empty($rows[0])) {
                return null;
            }

            // Find exact symbol match just in case
            foreach ($rows as $r) {
                if (($r['symbol'] ?? null) === $symbol) {
                    return $r['instrument_id'] ?? null;
                }
            }

            return $rows[0]['instrument_id'] ?? null;
        });
    }
}
