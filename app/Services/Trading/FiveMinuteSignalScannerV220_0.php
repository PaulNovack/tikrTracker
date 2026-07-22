<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Version 220.0 - Three Advancing White Soldiers (CDL3WHITESOLDIERS) Signal Scanner
 *
 * Detects the bullish Three Advancing White Soldiers candlestick pattern
 * on 5-minute intraday bars using the TA-Lib Flask screener.
 *
 * Only bullish signals are emitted. The scanner calls the Flask API
 * to detect the pattern at the as-of timestamp and returns the results
 * as standard signal rows. No additional gating or enrichment is applied —
 * the Flask API / TA-Lib handles all detection logic.
 */
class FiveMinuteSignalScannerV220_0 extends AbstractSignalScanner
{
    private string $version = 'v220.0';

    private string $name = 'Three White Soldiers';

    private string $flaskBaseUrl = 'http://127.0.0.1:5000';

    private int $symbolLimit = 750;

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
            'symbol_limit' => $this->symbolLimit,
            'pattern' => 'CDL3WHITESOLDIERS',
            'pattern_name' => 'Three Advancing White Soldiers',
        ];
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    protected function doScan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes,
        float $minMovePct,
        float $volMult,
        int $limit,
        bool $skipCache
    ): array {
        // Convert EST as-of time to UTC for the Flask API
        $asOfUtc = (new \DateTime($asOfTsEst, new \DateTimeZone('America/New_York')))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        // Call Flask API to get CDL3WHITESOLDIERS hits at this time
        $patternHits = $this->fetchPatternHits($asOfUtc);

        $out = [];

        foreach ($patternHits as $hit) {
            $out[] = [
                'symbol' => (string) $hit['symbol'],
                'asset_type' => $assetType,
                'signal_type' => 'CDL3WHITESOLDIERS',
                'signal_ts_est' => $asOfTsEst,
                'score' => (float) ($hit['signal_value'] ?? 100),
                'atr' => null,
                'atr_pct' => 0.0,
                'meta' => [
                    'move_30m_pct' => 0.0,
                    'rvol_5m' => 0.0,
                    'atr_pct_5m' => 0.0,
                    'notional_last5m' => 0.0,
                    'pct_nd' => null,
                    'spy_move_30m_pct' => 0.0,
                    'universe_size' => count($patternHits),
                    'signal_age_seconds' => 0,
                    'version' => $this->version,
                    'current_price' => null,
                ],
            ];
        }

        return array_slice($out, 0, max(1, $limit));
    }

    /**
     * Fetch CDL3WHITESOLDIERS pattern hits from the Flask TA-Lib screener.
     *
     * @return array<int, array{symbol: string, signal: string, signal_value: int, last_date: string}>
     */
    private function fetchPatternHits(string $asOfUtc): array
    {
        try {
            $response = Http::timeout(120)->get("{$this->flaskBaseUrl}/api/scan-intraday-at", [
                'pattern' => 'CDL3WHITESOLDIERS',
                'limit' => $this->symbolLimit,
                'before' => $asOfUtc,
            ]);

            if (! $response->successful()) {
                Log::warning('[ThreeWhiteSoldiers] Flask API returned non-success', [
                    'status' => $response->status(),
                    'as_of_utc' => $asOfUtc,
                ]);

                return [];
            }

            $data = $response->json();
            $results = $data['results'] ?? [];

            // Only keep bullish signals
            $bullish = array_filter($results, fn ($r) => ($r['signal'] ?? '') === 'bullish');

            return array_values($bullish);
        } catch (\Exception $e) {
            Log::warning('[ThreeWhiteSoldiers] Flask API call failed: '.$e->getMessage());

            return [];
        }
    }
}
