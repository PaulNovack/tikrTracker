<?php

namespace App\Services\Trading;

/**
 * Version 1.0 - Biased Signal Scanner
 * Base: V17.0
 * Purpose: Scanner for biased pipeline testing
 * Changes: Will be customized for biased analysis
 */
class FiveMinuteBiasedSignalScannerV1_0
{
    use HasPriceTables;

    private string $version = 'v1.0-biased';

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * BIASED LOOK-AHEAD SCANNER - FOR ML TRAINING ONLY
     *
     * Unlike normal scanners that can't look ahead, this scanner analyzes completed trading days
     * to find stocks that actually achieved 10%+ intraday gains. This creates a biased dataset
     * of known winners for ML model training.
     *
     * Process:
     * 1. Extract trading date from asOfTsEst
     * 2. Find all stocks that traded on that day
     * 3. Calculate intraday high vs low (or open) to find 10%+ gainers
     * 4. Generate signals at various points during the day for these known winners
     *
     * Parameters:
     * - $asOfTsEst: Any timestamp during the target trading day
     * - $minMovePct: Minimum intraday gain percentage (default 10%)
     * - $limit: Max number of signals to return
     *
     * Output format:
     * [
     *   ['symbol'=>'TQQQ','asset_type'=>'stock','signal_type'=>'BIASED_10PCT_GAINER','signal_ts_est'=>'YYYY-mm-dd HH:MM:SS', 'score'=>...],
     * ]
     */
    public function scan(string $assetType, string $asOfTsEst, int $lookbackMinutes = 60, float $minMovePct = 10.0, float $volMult = 3.0, int $limit = 60): array
    {
        // Extract trading date from timestamp
        $tradingDate = substr($asOfTsEst, 0, 10);

        // Find all stocks that achieved 10%+ intraday gains on this trading day
        // We look for stocks where the high of the day was 10%+ above the low (or open)
        $sql = "
WITH daily_stats AS (
  SELECT
    symbol,
    asset_type,
    trading_date_est,
    MIN(low) AS day_low,
    MAX(high) AS day_high,
    MIN(CASE WHEN TIME(ts_est) = '09:30:00' THEN open END) AS day_open,
    MIN(ts_est) AS first_ts,
    MAX(ts_est) AS last_ts,
    AVG(volume) AS avg_volume,
    SUM(volume) AS total_volume
  FROM five_minute_prices
  WHERE asset_type = ?
    AND trading_date_est = ?
    AND TIME(ts_est) BETWEEN '09:30:00' AND '16:00:00'
  GROUP BY symbol, asset_type, trading_date_est
),
gainers AS (
  SELECT
    symbol,
    asset_type,
    trading_date_est,
    day_low,
    day_high,
    day_open,
    first_ts,
    last_ts,
    avg_volume,
    total_volume,
    -- Calculate gain from day low to day high
    ((day_high - day_low) / NULLIF(day_low, 0)) * 100 AS low_to_high_pct,
    -- Calculate gain from open to high (if open available)
    ((day_high - COALESCE(day_open, day_low)) / NULLIF(COALESCE(day_open, day_low), 0)) * 100 AS open_to_high_pct,
    day_high AS best_price
  FROM daily_stats
  WHERE 
    -- At least 10% move from low to high
    ((day_high - day_low) / NULLIF(day_low, 0)) * 100 >= ?
    -- Require minimum volume to avoid illiquid stocks
    AND total_volume >= 100000
    -- Require at least 30 bars (2.5 hours of trading)
    AND (SELECT COUNT(*) FROM five_minute_prices 
         WHERE symbol = daily_stats.symbol 
         AND asset_type = daily_stats.asset_type 
         AND trading_date_est = daily_stats.trading_date_est) >= 30
)
SELECT 
  symbol,
  asset_type,
  trading_date_est,
  day_low,
  day_high,
  day_open,
  low_to_high_pct,
  open_to_high_pct,
  total_volume,
  avg_volume,
  best_price,
  -- Find a good entry point: use the timestamp when price was closest to day_low + 2%
  -- This simulates catching the stock early in its move
  -- Require entry within 2 hours of the day low to ensure we capture the move
  (SELECT ts_est 
   FROM five_minute_prices f
   WHERE f.symbol = gainers.symbol
     AND f.asset_type = gainers.asset_type  
     AND f.trading_date_est = gainers.trading_date_est
     AND TIME(f.ts_est) BETWEEN '09:30:00' AND '13:00:00'
     AND f.ts_est <= (
       SELECT ADDTIME(ts_est, '02:00:00')
       FROM five_minute_prices
       WHERE symbol = gainers.symbol
         AND asset_type = gainers.asset_type
         AND trading_date_est = gainers.trading_date_est
         AND low = gainers.day_low
       LIMIT 1
     )
   ORDER BY ABS(f.low - (gainers.day_low * 1.02))
   LIMIT 1
  ) AS signal_ts_est,
  -- Get price at that signal time
  (SELECT price
   FROM five_minute_prices f
   WHERE f.symbol = gainers.symbol
     AND f.asset_type = gainers.asset_type
     AND f.trading_date_est = gainers.trading_date_est
     AND f.ts_est = (
       SELECT ts_est 
       FROM five_minute_prices f2
       WHERE f2.symbol = gainers.symbol
         AND f2.asset_type = gainers.asset_type
         AND f2.trading_date_est = gainers.trading_date_est
         AND TIME(f2.ts_est) BETWEEN '09:30:00' AND '15:30:00'
       ORDER BY ABS(f2.low - (gainers.day_low * 1.02))
       LIMIT 1
     )
  ) AS signal_price
FROM gainers
ORDER BY low_to_high_pct DESC, total_volume DESC
LIMIT ?
";

        $params = [$assetType, $tradingDate, $minMovePct, $limit * 3]; // Fetch 3x more to account for filtering
        $rows = $this->dbSelect($sql, $params);

        $out = [];
        foreach ($rows as $r) {
            if (count($out) >= $limit) {
                break; // We have enough winners
            }

            // Check if a 1% trailing stop would survive from entry to day high
            if (! $this->wouldSurviveTrailingStop($r->symbol, $r->asset_type, $r->trading_date_est, $r->signal_ts_est, 0.01)) {
                continue; // Skip stocks that would get stopped out
            }

            // Calculate potential profit if entered at signal and exited at day high
            $entryPrice = (float) $r->signal_price;
            $exitPrice = (float) $r->day_high;
            $potentialGainPct = (($exitPrice - $entryPrice) / $entryPrice) * 100;

            // Score based on total move and volume
            $score = (float) $r->low_to_high_pct * (float) ($r->total_volume / 1000000); // Volume in millions

            $out[] = [
                'symbol' => (string) $r->symbol,
                'asset_type' => (string) $r->asset_type,
                'signal_type' => 'BIASED_10PCT_GAINER',
                'signal_ts_est' => (string) $r->signal_ts_est,
                'price' => $entryPrice,
                'score' => round($score, 3),
                'meta' => [
                    'trading_date' => (string) $r->trading_date_est,
                    'day_low' => (float) $r->day_low,
                    'day_high' => (float) $r->day_high,
                    'day_open' => $r->day_open ? (float) $r->day_open : null,
                    'low_to_high_pct' => round((float) $r->low_to_high_pct, 2),
                    'open_to_high_pct' => round((float) $r->open_to_high_pct, 2),
                    'potential_gain_pct' => round($potentialGainPct, 2),
                    'signal_price' => $entryPrice,
                    'exit_price' => $exitPrice,
                    'total_volume' => (int) $r->total_volume,
                    'avg_volume' => round((float) $r->avg_volume, 2),
                    'current_price' => $entryPrice, // Used by finder for fallback entries
                    'biased_scanner' => true, // Flag indicating this is look-ahead biased
                ],
            ];
        }

        return $out;
    }

    /**
     * Check if a trailing stop would survive from entry to end of day
     *
     * @param  float  $stopPct  Stop distance as decimal (e.g., 0.01 for 1%)
     */
    private function wouldSurviveTrailingStop(string $symbol, string $assetType, string $tradingDate, string $entryTs, float $stopPct): bool
    {
        // Get all bars from entry time onwards
        $sql = '
            SELECT high, low, ts_est
            FROM five_minute_prices
            WHERE symbol = ?
              AND asset_type = ?
              AND trading_date_est = ?
              AND ts_est >= ?
            ORDER BY ts_est ASC
        ';

        $bars = $this->dbSelect($sql, [$symbol, $assetType, $tradingDate, $entryTs]);

        if (empty($bars)) {
            return false;
        }

        $maxHigh = (float) $bars[0]->high; // Start with entry bar's high

        foreach ($bars as $bar) {
            $currentHigh = (float) $bar->high;
            $currentLow = (float) $bar->low;

            // Update the maximum high reached
            if ($currentHigh > $maxHigh) {
                $maxHigh = $currentHigh;
            }

            // Check if this bar's low violated the trailing stop
            $stopLevel = $maxHigh * (1 - $stopPct);
            if ($currentLow < $stopLevel) {
                return false; // Would have been stopped out
            }
        }

        return true; // Survived the entire move
    }
}
