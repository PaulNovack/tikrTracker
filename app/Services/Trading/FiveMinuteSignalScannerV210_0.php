<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * FiveMinuteSignalScannerV210_0 - Oversold Bounce Scanner
 *
 * Scans for oversold bounce opportunities:
 * 1. Stock down 1.5%+ from recent high
 * 2. Volume spike on selling (1.3x+)
 * 3. Reversal signal forming
 * 4. Quick bounce setup with tight stops
 *
 * Target: High win rate on short-term bounces in choppy/down markets
 */
class FiveMinuteSignalScannerV210_0
{
    use HasPriceTables;

    private string $version = 'v210.0';

    private string $name = 'Oversold Bounce';

    // ── Scanner Configuration (public so entry finders can read) ──
    /** @var float Minimum share price to scan */
    public float $minPrice = 10.0;

    /** @var float Maximum share price to scan */
    public float $maxPrice = 300.0;

    /** @var float Minimum ATR% (14-period 5m) for volatility */
    public float $minAtrPct5m = 0.10;

    /** @var int Minimum $ notional per 5m bar for liquidity */
    public int $minNotional5m = 100000;

    /** @var float Reversal confirmation threshold */
    public float $reversalThreshold = 0.3;

    /** @var float Minimum composite signal score to return */
    public float $minSignalScore = 2.0;

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
            'min_price' => $this->minPrice,
            'max_price' => $this->maxPrice,
            'min_atr_pct_5m' => $this->minAtrPct5m,
            'min_notional_5m' => $this->minNotional5m,
            'reversal_threshold' => $this->reversalThreshold,
            'min_signal_score' => $this->minSignalScore,
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

    /**
     * Scan for oversold bounce signals
     *
     * @param  string  $assetType  'stock' or 'crypto'
     * @param  string  $asOfTsEst  EST timestamp 'YYYY-mm-dd HH:MM:SS'
     * @param  int  $lookbackMinutes  How many 5m bars to look back
     * @param  float  $minDeclinePct  Minimum decline from recent high (default 1.5%)
     * @param  float  $minVolumeMult  Minimum volume multiple (default 1.3x)
     * @param  int  $limit  Max signals to return
     * @return array Array of signal arrays
     */
    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minDeclinePct = 1.2,
        float $minVolumeMult = 3.5,
        int $limit = 25
    ): array {
        $minPrice = $this->minPrice;
        $maxPrice = $this->maxPrice;
        $minAtrPct = $this->minAtrPct5m;
        $minNotional = $this->minNotional5m;
        $reversalThreshold = $this->reversalThreshold;
        $minSignalScore = $this->minSignalScore;

        // Get active symbols for scanning
        $symbols = $this->getActiveSymbols($assetType);

        // Add market movers to universe if enabled (top explosive movers from recent days)
        $moversLimit = (int) config('trading.market_movers.pipeline_g', 0);
        if ($moversLimit > 0) {
            $movers = app(\App\Services\MarketMoversService::class)->getTodaysTopMoversFromCache(null, $moversLimit);
            $symbols = array_values(array_unique(array_merge($symbols, $movers)));
        }

        if (empty($symbols)) {
            return [];
        }

        // Single query for all symbols instead of N+1 per-symbol queries.
        $symbolBars = $this->batchGet5mBars($symbols, $assetType, $asOfTsEst, $lookbackMinutes + 15);

        $signals = [];

        foreach ($symbols as $symbol) {
            $signal = $this->analyzeSymbol(
                $symbolBars[$symbol] ?? [],
                $symbol,
                $assetType,
                $asOfTsEst,
                $minDeclinePct,
                $minVolumeMult,
                $minPrice,
                $maxPrice,
                $minAtrPct,
                $minNotional,
                $reversalThreshold,
                $minSignalScore
            );

            if ($signal) {
                $signals[] = $signal;
            }
        }

        // Sort by score descending
        usort($signals, fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        return array_slice($signals, 0, $limit);
    }

    private function analyzeSymbol(
        array $bars,
        string $symbol,
        string $assetType,
        string $asOfTsEst,
        float $minDeclinePct,
        float $minVolumeMult,
        float $minPrice,
        float $maxPrice,
        float $minAtrPct,
        int $minNotional,
        float $reversalThreshold,
        float $minSignalScore
    ): ?array {
        if (count($bars) < 12) {
            return null;
        }

        $latest = $bars[0];
        $currentPrice = (float) $latest->price;
        $currentVolume = (int) $latest->volume;
        $currentHigh = (float) $latest->high;
        $currentLow = (float) $latest->low;

        // Use pre-calculated ATR from database
        $atr = (float) ($latest->atr ?? 0);
        $atrPct = (float) ($latest->atr_pct ?? 0);

        // Basic filters
        if ($currentPrice < $minPrice || $currentPrice > $maxPrice) {
            return null;
        }

        if ($currentVolume < 1000) {
            return null;
        }

        // Check ATR filter (using pre-calculated value from database)

        if ($atrPct < $minAtrPct) {
            return null;
        }

        // Check notional
        $notional = $currentPrice * $currentVolume;
        if ($notional < $minNotional) {
            return null;
        }

        // Detect decline from recent high
        $declineInfo = $this->detectDecline($bars, 15, $minDeclinePct);
        if (! $declineInfo) {
            return null;
        }

        // Calculate volume score
        $volumeScore = $this->calculateVolumeScore($bars, $minVolumeMult);
        if ($volumeScore < $minVolumeMult) {
            return null;
        }

        // Calculate reversal score
        $reversalScore = $this->calculateReversalScore($bars, $reversalThreshold);
        if ($reversalScore < 1.0) {
            return null;
        }

        // Calculate overall score
        $overallScore = $this->calculateOverallScore($declineInfo, $volumeScore, $reversalScore);

        // Filter by minimum signal score (reduces noise at source)
        if ($overallScore < $minSignalScore) {
            return null;
        }

        return [
            'symbol' => $symbol,
            'asset_type' => $assetType,
            'signal_type' => 'OVERSOLD_BOUNCE',
            'signal_ts_est' => $asOfTsEst,
            'price' => $currentPrice,
            'volume' => $currentVolume,
            'notional' => $notional,
            'atr' => $atr,
            'atr_pct' => round($atrPct, 4),
            'score' => round($overallScore, 2),
            'meta' => [
                'pattern' => 'OVERSOLD_BOUNCE',
                'decline_pct' => round($declineInfo['decline_pct'], 2),
                'recent_high' => $declineInfo['recent_high'],
                'pullback_low' => $currentLow,
                'current_price' => $currentPrice,
                'volume_multiple' => round($volumeScore, 2),
                'reversal_score' => round($reversalScore, 2),
                'bounce_from_low_pct' => round((($currentPrice - $currentLow) / $currentLow) * 100, 2),
            ],
        ];
    }

    private function getActiveSymbols(string $assetType): array
    {
        return DB::table('asset_info')
            ->where('asset_type', $assetType)
            ->whereNull('deleted_at')
            ->pluck('symbol')
            ->toArray();
    }

    /**
     * Load 5m bars for all symbols in a single query, grouped by symbol.
     *
     * @param  array<int, string>  $symbols
     * @return array<string, list<object>>
     */
    private function batchGet5mBars(array $symbols, string $assetType, string $asOfTsEst, int $lookbackMinutes): array
    {
        if (empty($symbols)) {
            return [];
        }

        $startTime = date('Y-m-d H:i:s', strtotime($asOfTsEst." -{$lookbackMinutes} minutes"));

        // Cache key based only on asset type, 5-minute bucket, and lookback window.
        // Symbol list is intentionally excluded so all callers share the same cache entry
        // regardless of which subset of symbols they request — filtered in PHP below.
        $bucketTs = date('Y-m-d H:i', strtotime(floor(strtotime($asOfTsEst) / 300) * 300));
        $cacheKey = "5m_bars:{$assetType}:{$bucketTs}:{$lookbackMinutes}";

        $allGrouped = Cache::get($cacheKey);
        if ($allGrouped === null) {
            $lock = Cache::lock("lock:{$cacheKey}", 60);
            if ($lock->get()) {
                try {
                    $rows = $this->dbSelect('
                    SELECT *
                    FROM five_minute_prices
                    WHERE asset_type = ?
                      AND ts <= ?
                      AND ts >= ?
                    ORDER BY symbol ASC, ts DESC
                ', [$assetType, $asOfTsEst, $startTime]);

                    $allGrouped = [];
                    foreach ($rows as $row) {
                        $allGrouped[$row->symbol][] = $row;
                    }

                    Cache::put($cacheKey, $allGrouped, 270);
                } finally {
                    $lock->release();
                }
            } else {
                // Another process (e.g. backtest) holds the lock — don't block the live pipeline.
                // Use cached result if available, otherwise fall back to empty.
                $allGrouped = Cache::get($cacheKey) ?? [];
            }
        }

        // Filter the cached universe down to the requested symbols
        $symbolSet = array_flip($symbols);

        return array_filter($allGrouped, fn ($_, $sym) => isset($symbolSet[$sym]), ARRAY_FILTER_USE_BOTH);
    }

    private function calculateAtr(array $bars, int $period = 14): float
    {
        if (count($bars) < $period + 1) {
            return 0.0;
        }

        $trs = [];
        for ($i = 0; $i < min($period + 1, count($bars) - 1); $i++) {
            $high = (float) $bars[$i]->high;
            $low = (float) $bars[$i]->low;
            $prevClose = (float) $bars[$i + 1]->price;

            $tr = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose)
            );

            $trs[] = $tr;
        }

        return count($trs) > 0 ? array_sum($trs) / count($trs) : 0.0;
    }

    private function detectDecline(array $bars, int $lookbackPeriods, float $minDeclinePct): ?array
    {
        if (count($bars) < $lookbackPeriods + 1) {
            return null;
        }

        $currentPrice = (float) $bars[0]->price;

        // Look at previous bars to find recent high
        $lookbackBars = array_slice($bars, 1, $lookbackPeriods);

        if (count($lookbackBars) < 10) {
            return null;
        }

        // Find highest high in lookback period
        $maxHigh = max(array_map(fn ($bar) => (float) $bar->high, $lookbackBars));

        // Calculate decline percentage
        $declinePct = (($maxHigh - $currentPrice) / $maxHigh) * 100;

        if ($declinePct < $minDeclinePct) {
            return null;
        }

        return [
            'recent_high' => $maxHigh,
            'decline_pct' => $declinePct,
            'current_price' => $currentPrice,
        ];
    }

    private function calculateVolumeScore(array $bars, float $minMult): float
    {
        if (count($bars) < 4) {
            return 0.0;
        }

        // Recent 3 bars average volume
        $recentVolumes = array_slice($bars, 0, 3);
        $avgRecentVolume = array_sum(array_map(fn ($bar) => (int) $bar->volume, $recentVolumes)) / 3;

        if ($avgRecentVolume === 0) {
            return 0.0;
        }

        // Compare to average of previous 10 bars
        $lookbackVolumes = array_slice($bars, 3, 10);
        if (count($lookbackVolumes) < 5) {
            return 0.0;
        }

        $avgLookbackVolume = array_sum(array_map(fn ($bar) => (int) $bar->volume, $lookbackVolumes)) / count($lookbackVolumes);

        if ($avgLookbackVolume === 0) {
            return 0.0;
        }

        return $avgRecentVolume / $avgLookbackVolume;
    }

    private function calculateReversalScore(array $bars, float $reversalThreshold): float
    {
        if (count($bars) < 5) {
            return 0.0;
        }

        $latest = $bars[0];
        $current = (float) $latest->price;
        $high = (float) $latest->high;
        $low = (float) $latest->low;

        $score = 0.0;

        // Current bar bouncing from low
        if ($low > 0) {
            $bounceFromLow = (($current - $low) / $low) * 100;
            if ($bounceFromLow > $reversalThreshold) {
                $score += $bounceFromLow * 2;
            }
        }

        // Recent bars showing support
        $recentLows = array_slice(array_map(fn ($bar) => (float) $bar->low, $bars), 0, 3);
        $minRecentLow = min($recentLows);

        if ($current > $minRecentLow) {
            $score += 1.0;
        }

        // Volume confirmation on bounce
        if (count($bars) > 1) {
            $currentVolume = (int) $latest->volume;
            $prevVolume = (int) $bars[1]->volume;

            if ($currentVolume > $prevVolume) {
                $score += 0.5;
            }
        }

        return $score;
    }

    private function calculateOverallScore(array $declineInfo, float $volumeScore, float $reversalScore): float
    {
        $score = 0.0;

        // Decline magnitude (higher decline = higher potential bounce)
        $score += $declineInfo['decline_pct'] * 0.5;

        // Volume score
        $score += ($volumeScore - 1) * 2.0;

        // Reversal strength
        $score += $reversalScore;

        return max(0, $score);
    }
}
