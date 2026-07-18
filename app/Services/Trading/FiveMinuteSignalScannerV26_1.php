<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\DB;

/**
 * Version 26.0 - Signal Scanner
 * Base: V17.0
 * Purpose: Get stocks with best gains that were positive open-to-close each day for last 5 days
 * Changes:
 * - Filter for stocks that closed above open for each of the last 5 trading days
 * - Return only top 10 stocks (hardcoded limit)
 * - Removed losers from universe
 */
class FiveMinuteSignalScannerV26_1
{
    use HasPriceTables;

    private string $version = 'v26.1';

    private string $name = 'Institutional Fade Detection';

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
    public function scan(string $assetType, string $asOfTsEst, int $lookbackMinutes = 60, float $minMovePct = 0.5, float $volMult = 3.0, int $limit = 10): array
    {
        // V26.0: Get stocks that closed above open at least 4 out of last 5 trading days
        // This filters for consistent daily winners (allows 1 red day)
        $currentDate = substr($asOfTsEst, 0, 10);

        // Get last 5 trading days
        $tradingDays = DB::table($this->fiveMinuteTable)
            ->where('asset_type', $assetType)
            ->where('trading_date_est', '<=', $currentDate)
            ->select('trading_date_est')
            ->distinct()
            ->orderBy('trading_date_est', 'desc')
            ->limit(5)
            ->pluck('trading_date_est')
            ->toArray();

        if (count($tradingDays) < 5) {
            return []; // Not enough trading days
        }

        // Find stocks where close > open for at least 4 of the last 5 days
        // AND get their total performance (last close vs first open across 5 days)
        $sql = "
            WITH daily_moves AS (
                SELECT 
                    symbol,
                    trading_date_est,
                    (SELECT price FROM five_minute_prices fp1 
                     WHERE fp1.symbol = fp.symbol 
                       AND fp1.asset_type = fp.asset_type 
                       AND fp1.trading_date_est = fp.trading_date_est
                       AND TIME(fp1.ts_est) >= '09:30:00'
                     ORDER BY fp1.ts_est ASC LIMIT 1) as day_open,
                    (SELECT price FROM five_minute_prices fp2 
                     WHERE fp2.symbol = fp.symbol 
                       AND fp2.asset_type = fp.asset_type 
                       AND fp2.trading_date_est = fp.trading_date_est
                       AND TIME(fp2.ts_est) <= '16:00:00'
                     ORDER BY fp2.ts_est DESC LIMIT 1) as day_close
                FROM (SELECT DISTINCT symbol, asset_type, trading_date_est 
                      FROM five_minute_prices 
                      WHERE asset_type = ?
                        AND trading_date_est IN (?, ?, ?, ?, ?)) fp
            ),
            positive_days AS (
                SELECT 
                    symbol,
                    COUNT(*) as positive_day_count,
                    SUM(day_close - day_open) / SUM(day_open) * 100 as total_gain_pct
                FROM daily_moves
                WHERE day_open IS NOT NULL 
                  AND day_close IS NOT NULL
                  AND day_close > day_open
                GROUP BY symbol
                HAVING positive_day_count >= 4
            )
            SELECT symbol, total_gain_pct
            FROM positive_days
            ORDER BY total_gain_pct DESC
            LIMIT 500
        ";

        $positiveStocks = $this->dbSelect($sql, array_merge(
            [$assetType],
            $tradingDays
        ));

        $symbols = array_column($positiveStocks, 'symbol');
        $topPerformers = array_map(fn ($s) => [
            'symbol' => $s->symbol,
            'pct_return_pct' => $s->total_gain_pct,
        ], $positiveStocks);

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
  AND last_close >= 2.50
  AND ((last_close - prev_close) / NULLIF(prev_close, 0)) * 100 >= ?
  AND (last_vol / NULLIF(avg_vol, 0)) >= ?
  AND (((last_close - prev_close) / NULLIF(prev_close, 0)) * 100 * (last_vol / NULLIF(avg_vol, 0))) >= 5.2
ORDER BY (((last_close - prev_close) / NULLIF(prev_close, 0)) * 100 * (last_vol / NULLIF(avg_vol, 0))) DESC
LIMIT ?
";

        // In 5-minute bars, N bars back ~ lookbackMinutes/5
        $nback = max(2, (int) floor($lookbackMinutes / 5));

        // Build parameter array: symbols twice (for two IN clauses) + other params
        $params = array_merge(
            [$assetType],           // asset_type (first occurrence)
            $symbols,               // symbols (first IN clause)
            [$asOfTsEst],          // as_of (first occurrence)
            [$asOfTsEst],          // as_of for DATE_SUB
            [15],                  // active_window
            [$assetType],          // asset_type (second occurrence)
            $symbols,              // symbols (second IN clause)
            [$asOfTsEst],          // as_of (second occurrence)
            [$asOfTsEst],          // as_of for DATE_SUB
            [$lookbackMinutes],    // lookback
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
            $perfData = collect($topPerformers)->firstWhere('symbol', $r->symbol);
            $pct7d = $perfData ? $perfData['pct_return_pct'] : null;

            // Calculate ATR from atr_pct and current price
            $atrPct = $r->atr_pct ?? null;
            $currentPrice = (float) $r->last_close;
            $atr = ($atrPct && $currentPrice) ? round(($atrPct / 100) * $currentPrice, 6) : null;

            $out[] = [
                'symbol' => (string) $r->symbol,
                'asset_type' => (string) $r->asset_type,
                'signal_type' => 'MOMO_5M',
                'signal_ts_est' => (string) $r->signal_ts_est,
                'score' => round($score, 3),
                'atr' => $atr,
                'atr_pct' => $atrPct,
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
