<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Version 17.0 - Signal Scanner (Experimental)
 * Base: V13.0
 * Purpose: Experiment with loosened filters to find more signals without sacrificing quality
 * Changes TBD
 */
class FiveMinuteSignalScannerV17_0
{
    use HasPriceTables;

    private string $version = 'v17.0';

    private string $name = 'Base Pattern';

    // ── Scanner Configuration (public so entry finders can read) ──
    public int $activeWindowMinutes = 30;

    public string $benchmarkSymbol = 'QQQM';

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
            'active_window_minutes' => $this->activeWindowMinutes,
            'benchmark_symbol' => $this->benchmarkSymbol,
        ];
    }

    public function __construct(
        private readonly BestPerformers5mService $bestPerformersService,
        private readonly GainersLosersAnalysisService $gainersLosersService
    ) {}

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setFullTable(bool $full): void
    {
        $this->fiveMinuteTable = $full ? 'five_minute_prices_full' : 'five_minute_prices';
        $this->oneMinuteTable = $full ? 'one_minute_prices_full' : 'one_minute_prices';
        $this->bestPerformersService->setFullTable($full);
        $this->gainersLosersService->setFullTable($full);
    }

    /**
     * Returns candidate signals using five_minute_prices.
     * V13.0 adds: Expanded universe (500 stocks + 50 losers), relaxed RS filter (1.2x SPY)
     * V12.7 adds: Top 25 losers from previous trading day to universe (bounce opportunities)
     * V12.0 adds relative strength filter (vs SPY) - stocks must outperform market.
     *
     * Process:
     * 1. Get top 500 performers from last 5 days (BestPerformers5mService)
     * 2. Add top 50 losers from previous trading day (potential bounce plays)
     * 3. Scan combined universe for momentum/volume signals
     * 4. v12.0: Filter by relative strength - require stock_move > spy_move * 1.2
     *
     * This is intentionally "simple but solid" and fast:
     * - Universe filter: top performers from last 5 days + top 50 losers from previous day
     * - Activity filter: must have bars in last N minutes
     * - Momentum-ish filter: last close > close N bars ago by minPct
     * - Volume filter: last vol > avg vol * volMult (default 2.2x based on backtest data)
     * - v12.0: Relative strength filter vs SPY
     *
     * Output format:
     * [
     *   ['symbol'=>'TQQQ','asset_type'=>'stock','signal_type'=>'MOMO_5M','signal_ts_est'=>'YYYY-mm-dd HH:MM:SS', 'score'=>...],
     * ]
     */
    public function scan(string $assetType, string $asOfTsEst, int $lookbackMinutes = 60, float $minMovePct = 1.2, float $volMult = 3.5, int $limit = 60): array
    {
        // Step 1: Universe from intraday_universe (pre-built, fast, cached 8h)
        $universeCacheKey = "scan_v17_0:universe_symbols:{$assetType}";
        $symbols = Cache::get($universeCacheKey);
        if ($symbols === null) {
            $symbols = DB::table('intraday_universe')
                ->select('symbol')
                ->where('asset_type', $assetType)
                ->orderBy('symbol')
                ->pluck('symbol')
                ->all();

            // Add market movers to universe if enabled
            $moversLimit = (int) config('trading.market_movers.pipeline_i', 0);
            if ($moversLimit > 0) {
                $movers = app(\App\Services\MarketMoversService::class)->getTodaysTopMoversFromCache(null, $moversLimit);
                $symbols = array_values(array_unique(array_merge($symbols, $movers)));
            }

            // Add 4-bar 1-min up streak symbols from Redis
            $redisSymbols = \Illuminate\Support\Facades\Redis::get('last_4_1min_up:symbols');
            if ($redisSymbols) {
                $streakSymbols = json_decode($redisSymbols, true);
                if (is_array($streakSymbols) && $streakSymbols !== []) {
                    $symbols = array_values(array_unique(array_merge($symbols, $streakSymbols)));
                }
            }

            // Cache for 8 hours — universe only needs to be refreshed once per trading day
            Cache::put($universeCacheKey, $symbols, 28800);
        }

        // If no symbols found, return empty
        if (empty($symbols)) {
            return [];
        }

        // Build IN clause for SQL filtering
        $placeholders = implode(',', array_fill(0, count($symbols), '?'));

        // Step 2: Scan only the filtered universe for momentum signals
        $sql = "
WITH last_bar AS (
  SELECT
    symbol,
    asset_type,
    MAX(ts_est) AS last_ts_est
  FROM five_minute_prices
  WHERE asset_type = ?
    AND symbol IN ($placeholders)
    AND ts_est <= ?
    AND ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
  GROUP BY symbol, asset_type
),
bars AS (
  SELECT
    f.symbol,
    f.asset_type,
    f.ts_est,
    f.price AS close,
    f.volume,
    ROW_NUMBER() OVER (PARTITION BY f.symbol, f.asset_type ORDER BY f.ts_est DESC) AS rn
  FROM five_minute_prices f
  INNER JOIN last_bar lb
    ON lb.symbol = f.symbol AND lb.asset_type = f.asset_type
  WHERE f.asset_type = ?
    AND f.symbol IN ($placeholders)
    AND f.ts_est <= ?
    AND f.ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
),
agg AS (
  SELECT
    symbol,
    asset_type,
    MAX(CASE WHEN rn = 1 THEN ts_est END) AS signal_ts_est,
    MAX(CASE WHEN rn = 1 THEN close END)  AS last_close,
    MAX(CASE WHEN rn = 1 THEN volume END) AS last_vol,
    MAX(CASE WHEN rn = 1 + ? THEN close END) AS prev_close,
    AVG(volume) AS avg_vol
  FROM bars
  GROUP BY symbol, asset_type
)
SELECT
  symbol,
  asset_type,
  signal_ts_est,
  last_close,
  prev_close,
  last_vol,
  avg_vol,
  ((last_close - prev_close) / NULLIF(prev_close, 0)) * 100 AS move_pct,
  (last_vol / NULLIF(avg_vol, 0)) AS vol_ratio
FROM agg
WHERE prev_close IS NOT NULL
  AND ((last_close - prev_close) / NULLIF(prev_close, 0)) * 100 >= ?
  AND (last_vol / NULLIF(avg_vol, 0)) >= ?
ORDER BY (((last_close - prev_close) / NULLIF(prev_close, 0)) * 100 * (last_vol / NULLIF(avg_vol, 0))) DESC
LIMIT ?
";

        // In 5-minute bars, N bars back ~ lookbackMinutes/5
        $nback = max(2, (int) floor($lookbackMinutes / 5));

        // Make active window configurable (not hardcoded to 15)
        $activeWindow = $this->activeWindowMinutes;

        // Build parameter array: symbols twice (for two IN clauses) + other params
        $params = array_merge(
            [$assetType],           // asset_type (first occurrence)
            $symbols,               // symbols (first IN clause)
            [$asOfTsEst],          // as_of (first occurrence)
            [$asOfTsEst],          // as_of for DATE_SUB
            [$activeWindow],       // active_window (now configurable)
            [$assetType],          // asset_type (second occurrence)
            $symbols,              // symbols (second IN clause)
            [$asOfTsEst],          // as_of (second occurrence)
            [$asOfTsEst],          // as_of for DATE_SUB
            [$lookbackMinutes + 10], // lookback + 10 min buffer so prev_close (rn=nback+1) is always within window
            [$nback],              // nback
            [$minMovePct],         // min_move_pct
            [$volMult],            // vol_mult
            [$limit],              // lim
        );

        $rows = $this->dbSelect($sql, $params);

        // v12.0: Get SPY movement for relative strength comparison
        $spyMovePct = $this->getSpyMovement($asOfTsEst, $lookbackMinutes);

        $out = [];
        foreach ($rows as $r) {
            $stockMovePct = (float) $r->move_pct;

            // v17.0: Further relaxed RS filter to 1.1x (was 1.2x in v13.0) to find more signals
            // If SPY is flat or down, allow any positive stock move
            // If SPY is up, require stock move > SPY move * 1.1
            if ($spyMovePct > 0 && $stockMovePct < $spyMovePct * 1.1) {
                continue; // Skip stocks not showing relative strength
            }

            $score = $stockMovePct * (float) $r->vol_ratio;

            // Find the 7d performance for this symbol
            $perfData = null;  // Universe comes from intraday_universe — no per-performer enrichment data
            $pct7d = $perfData ? $perfData['pct_return_pct'] : null;

            $out[] = [
                'symbol' => (string) $r->symbol,
                'asset_type' => (string) $r->asset_type,
                'signal_type' => 'MOMO_5M',
                'signal_ts_est' => (string) $r->signal_ts_est,
                'score' => round($score, 3),
                'meta' => [
                    'move_pct' => round($stockMovePct, 3),
                    'vol_ratio' => round((float) $r->vol_ratio, 3),
                    'last_close' => (float) $r->last_close,
                    'prev_close' => (float) $r->prev_close,
                    'pct_7d' => $pct7d !== null ? round($pct7d, 2) : null, // 7-day performance
                    'spy_move_pct' => round($spyMovePct, 3), // v12.0: SPY comparison
                    'relative_strength' => $spyMovePct > 0 ? round($stockMovePct / $spyMovePct, 2) : null, // v12.0: RS ratio
                    'universe_filtered' => true, // Indicates top performer filtering was used
                    'universe_size' => count($symbols), // How many symbols in filtered universe
                ],
            ];
        }

        return $out;
    }

    /**
     * v12.0: Get SPY movement percentage over lookback period
     */
    private function getSpyMovement(string $asOfTsEst, int $lookbackMinutes): float
    {
        $benchmarkSymbol = config('trading.market_benchmark_symbol', 'QQQM');
        $nback = max(2, (int) floor($lookbackMinutes / 5));

        $sql = "
            SELECT 
                price AS last_close,
                LAG(price, ?) OVER (ORDER BY ts_est) AS prev_close
            FROM five_minute_prices
            WHERE symbol = ?
                AND asset_type = 'stock'
                AND ts_est <= ?
                AND ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
            ORDER BY ts_est DESC
            LIMIT 1
        ";

        $result = DB::selectOne($sql, [$nback, $benchmarkSymbol, $asOfTsEst, $asOfTsEst, $lookbackMinutes]);

        if (! $result || ! $result->prev_close) {
            return 0.0; // If no SPY data, allow all trades (don't filter)
        }

        return (($result->last_close - $result->prev_close) / $result->prev_close) * 100.0;
    }
}
