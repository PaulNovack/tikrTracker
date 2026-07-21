<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Version 20.0 - RSI Filtering for EMA Crossover Pattern
 *
 * Changes from v19.0:
 * - Added RSI filter for EMA_CROSS_BULL: 50 < RSI < 70
 * - Avoids overbought conditions (RSI >= 70)
 * - Ensures bullish momentum (RSI > 50)
 * - Scanner unchanged, filtering happens in OneMinuteEntryFinderV20_0
 */
class FiveMinuteSignalScannerV20_0
{
    use HasPriceTables;

    private string $version = 'v20.0';

    private string $name = 'Alligator Wake-Up';

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
     * v20.0: Scan with RSI filtering (in OneMinuteEntryFinder)
     *
     * Process:
     * 1. Get all symbols with 1-minute data
     * 2. Scan 5-minute bars for momentum signals
     * 3. Pass to OneMinuteEntryFinderV20_0
     * 4. v20.0 NEW: RSI filter in entry finder (50 < RSI < 70)
     */
    public function scan(string $assetType, string $asOfTsEst, int $lookbackMinutes = 60, float $minMovePct = 0.6, float $volMult = 3.0, int $limit = 100): array
    {
        // v20.0: Get ALL symbols with 1-minute data (no pre-filtering)
        // RSI filtering happens in OneMinuteEntryFinderV20_0
        $currentDate = substr($asOfTsEst, 0, 10);

        $symbols = DB::table($this->oneMinuteTable)
            ->where('asset_type', $assetType)
            ->where('trading_date_est', $currentDate)
            ->where('ts_est', '<=', $asOfTsEst)
            ->distinct()
            ->pluck('symbol')
            ->toArray();

        if (empty($symbols)) {
            return [];
        }

        Log::info('V20.0 Scanning ALL symbols', [
            'total_symbols' => count($symbols),
            'date' => $currentDate,
            'time' => $asOfTsEst,
        ]);

        // Step 2: Get 5-minute signals - pass all symbols through with high limit
        $fiveMinSignals = $this->scanFiveMinute($symbols, $assetType, $asOfTsEst, $lookbackMinutes, $minMovePct, $volMult, 5000);

        if (empty($fiveMinSignals)) {
            return [];
        }

        // v20.0: Pass all 5-minute signals through to 1-minute scanner
        // RSI filtering happens in OneMinuteEntryFinderV20_0
        $confirmedSignals = $fiveMinSignals;

        Log::info('V20.0 5-Min Scanner Results', [
            'signals_passed' => count($confirmedSignals),
            'sample_symbols' => array_column(array_slice($fiveMinSignals, 0, 10), 'symbol'),
        ]);

        // V20.0: Skip RS filter and performance data - process all signals equally
        $spyMovePct = $this->getSpyMovement($asOfTsEst, $lookbackMinutes);

        $out = [];
        foreach ($confirmedSignals as $signal) {
            $stockMovePct = $signal['move_pct'];
            $score = $stockMovePct * $signal['vol_ratio'];

            $out[] = [
                'symbol' => $signal['symbol'],
                'asset_type' => $signal['asset_type'],
                'signal_type' => 'MOMO_5M',
                'signal_ts_est' => $signal['signal_ts_est'],
                'score' => round($score, 3),
                'meta' => [
                    'move_pct' => round($stockMovePct, 3),
                    'vol_ratio' => round($signal['vol_ratio'], 3),
                    'last_close' => $signal['last_close'],
                    'prev_close' => $signal['prev_close'],
                    'pct_7d' => null,
                    'spy_move_pct' => round($spyMovePct, 3),
                    'relative_strength' => $spyMovePct > 0 ? round($stockMovePct / $spyMovePct, 2) : null,
                    'universe_filtered' => true,
                    'universe_size' => count($symbols),
                    // v19.0 NEW: Alligator WAKE_UP metadata
                    'alligator_wake_up' => $signal['alligator_wake_up'] ?? false,
                    'alligator_consecutive' => $signal['alligator_consecutive'] ?? 0,
                    'alligator_lips' => isset($signal['alligator_lips']) ? round($signal['alligator_lips'], 2) : null,
                    'alligator_teeth' => isset($signal['alligator_teeth']) ? round($signal['alligator_teeth'], 2) : null,
                    'alligator_jaw' => isset($signal['alligator_jaw']) ? round($signal['alligator_jaw'], 2) : null,
                ],
            ];
        }

        return $out;
    }

    /**
     * v18.0: Scan 5-minute bars for momentum (same as v17.0)
     */
    private function scanFiveMinute(array $symbols, string $assetType, string $asOfTsEst, int $lookbackMinutes, float $minMovePct, float $volMult, int $limit): array
    {
        $placeholders = implode(',', array_fill(0, count($symbols), '?'));
        $nback = max(2, (int) floor($lookbackMinutes / 5));

        $sql = "
WITH last_bar AS (
  SELECT symbol, asset_type, MAX(ts_est) AS last_ts_est
  FROM five_minute_prices
  WHERE asset_type = ? AND symbol IN ($placeholders)
    AND ts_est <= ? AND ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
  GROUP BY symbol, asset_type
),
bars AS (
  SELECT f.symbol, f.asset_type, f.ts_est, f.price AS close, f.volume,
    ROW_NUMBER() OVER (PARTITION BY f.symbol, f.asset_type ORDER BY f.ts_est DESC) AS rn
  FROM five_minute_prices f
  INNER JOIN last_bar lb ON lb.symbol = f.symbol AND lb.asset_type = f.asset_type
  WHERE f.asset_type = ? AND f.symbol IN ($placeholders)
    AND f.ts_est <= ? AND ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
),
agg AS (
  SELECT symbol, asset_type,
    MAX(CASE WHEN rn = 1 THEN ts_est END) AS signal_ts_est,
    MAX(CASE WHEN rn = 1 THEN close END) AS last_close,
    MAX(CASE WHEN rn = 1 THEN volume END) AS last_vol,
    MAX(CASE WHEN rn = 1 + ? THEN close END) AS prev_close,
    AVG(volume) AS avg_vol
  FROM bars
  GROUP BY symbol, asset_type
)
SELECT symbol, asset_type, signal_ts_est, last_close, prev_close, last_vol, avg_vol,
  ((last_close - prev_close) / NULLIF(prev_close, 0)) * 100 AS move_pct,
  (last_vol / NULLIF(avg_vol, 0)) AS vol_ratio
FROM agg
WHERE prev_close IS NOT NULL
ORDER BY last_vol DESC
LIMIT ?
";

        $params = array_merge(
            [$assetType], $symbols, [$asOfTsEst], [$asOfTsEst], [15],
            [$assetType], $symbols, [$asOfTsEst], [$asOfTsEst], [$lookbackMinutes],
            [$nback], [$limit]
        );

        $rows = $this->dbSelect($sql, $params);

        $signals = [];
        foreach ($rows as $r) {
            $signals[] = [
                'symbol' => (string) $r->symbol,
                'asset_type' => (string) $r->asset_type,
                'signal_ts_est' => (string) $r->signal_ts_est,
                'last_close' => (float) $r->last_close,
                'prev_close' => (float) $r->prev_close,
                'last_vol' => (float) $r->last_vol,
                'avg_vol' => (float) $r->avg_vol,
                'move_pct' => (float) $r->move_pct,
                'vol_ratio' => (float) $r->vol_ratio,
            ];
        }

        return $signals;
    }

    /**
     * v18.0 NEW: Confirm breakouts using 1-minute data
     *
     * Filters:
     * 1. Noise filter: move must be > 1.5x standard deviation
     * 2. Near high: 1m price within 0.25% of window high
     * 3. Breakout: 1m price above recent 5m high
     * 4. Strong body: Last 5m candle has bullish body > 0.3%
     */
    private function confirmBreakoutsWithOneMinute(array $fiveMinSignals, string $assetType, string $asOfTsEst): array
    {
        if (empty($fiveMinSignals)) {
            return [];
        }

        $symbols = array_column($fiveMinSignals, 'symbol');
        $symbolPlaceholders = implode(',', array_fill(0, count($symbols), '?'));
        $lookbackMinutes = 15; // Check last 15 minutes of 1m data

        // Get 1-minute momentum data
        $oneMinSql = "
SELECT symbol,
    STDDEV_POP(price) AS price_stddev,
    MAX(high) AS window_high,
    SUBSTRING_INDEX(GROUP_CONCAT(price ORDER BY ts_est DESC), ',', 1) AS last_price,
    SUBSTRING_INDEX(GROUP_CONCAT(price ORDER BY ts_est ASC), ',', 1) AS first_price
FROM one_minute_prices
WHERE asset_type = ? AND symbol IN ($symbolPlaceholders)
  AND ts_est <= ? AND ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
GROUP BY symbol
";

        $oneMinParams = array_merge([$assetType], $symbols, [$asOfTsEst], [$asOfTsEst], [$lookbackMinutes]);
        $oneMinData = $this->dbSelect($oneMinSql, $oneMinParams);

        // Get 5-minute high and body data
        $fiveMinSql = "
SELECT symbol,
    MAX(high) AS recent5_high,
    SUBSTRING_INDEX(GROUP_CONCAT(open ORDER BY ts_est DESC), ',', 1) AS last5_open,
    SUBSTRING_INDEX(GROUP_CONCAT(price ORDER BY ts_est DESC), ',', 1) AS last5_close
FROM five_minute_prices
WHERE asset_type = ? AND symbol IN ($symbolPlaceholders)
  AND ts_est <= ? AND ts_est >= DATE_SUB(?, INTERVAL 25 MINUTE)
GROUP BY symbol
";

        $fiveMinParams = array_merge([$assetType], $symbols, [$asOfTsEst], [$asOfTsEst]);
        $fiveMinData = $this->dbSelect($fiveMinSql, $fiveMinParams);

        // Index data by symbol
        $oneMinIndex = [];
        foreach ($oneMinData as $row) {
            $oneMinIndex[$row->symbol] = $row;
        }

        $fiveMinIndex = [];
        foreach ($fiveMinData as $row) {
            $fiveMinIndex[$row->symbol] = $row;
        }

        // Apply breakout confirmation filters
        $confirmed = [];
        foreach ($fiveMinSignals as $signal) {
            $symbol = $signal['symbol'];

            if (! isset($oneMinIndex[$symbol]) || ! isset($fiveMinIndex[$symbol])) {
                continue; // Skip if missing data
            }

            $oneMin = $oneMinIndex[$symbol];
            $fiveMin = $fiveMinIndex[$symbol];

            $lastPrice = (float) $oneMin->last_price;
            $firstPrice = (float) $oneMin->first_price;
            $priceStddev = (float) $oneMin->price_stddev;
            $windowHigh = (float) $oneMin->window_high;
            $recent5High = (float) $fiveMin->recent5_high;
            $last5Open = (float) $fiveMin->last5_open;
            $last5Close = (float) $fiveMin->last5_close;

            // Filter 1: Noise check - move must be > 1.5x standard deviation
            $movePct = $firstPrice > 0 ? (($lastPrice - $firstPrice) / $firstPrice) * 100.0 : 0;
            $noisePct = $firstPrice > 0 && $priceStddev > 0 ? ($priceStddev / $firstPrice) * 100.0 : 0;

            $noiseFiltered = false;
            if ($noisePct > 0 && $movePct < 1.5 * $noisePct) {
                continue; // Too noisy, skip
            } else {
                $noiseFiltered = true;
            }

            // Filter 2: Near high - within 0.25% of window high
            if ($windowHigh > 0 && $lastPrice < $windowHigh) {
                $distanceFromHighPct = (($windowHigh - $lastPrice) / $windowHigh) * 100.0;
                if ($distanceFromHighPct > 0.25) {
                    continue; // Too far from high
                }
            }

            // Filter 3: Breakout - 1m price must be ABOVE recent 5m high
            $above5mHigh = $lastPrice > $recent5High;
            if (! $above5mHigh) {
                continue; // Not breaking out
            }

            // Filter 4: Strong bullish body on last 5m candle
            $bodyPct = $last5Open > 0 ? (($last5Close - $last5Open) / $last5Open) * 100.0 : 0;
            $strongBody5m = $bodyPct >= 0.3;

            if (! $strongBody5m) {
                continue; // Weak 5m body
            }

            // Passed all filters - this is a confirmed breakout
            $signal['breakout_confirmed'] = true;
            $signal['noise_filtered'] = $noiseFiltered;
            $signal['above_5m_high'] = $above5mHigh;
            $signal['strong_body_5m'] = $strongBody5m;

            $confirmed[] = $signal;
        }

        return $confirmed;
    }

    /**
     * v19.0 NEW: Filter signals by Alligator WAKE_UP pattern
     *
     * Requires 3 consecutive WAKE_UP states where:
     * - lips > teeth > jaw (lines properly aligned)
     * - price between lips and teeth (early trend formation)
     *
     * @param  array  $signals  5-minute signals to filter
     * @param  string  $assetType  'stock' or 'crypto'
     * @param  string  $asOfTsEst  Current timestamp (EST)
     * @return array Filtered signals with Alligator confirmation
     */
    private function filterByAlligatorWakeUp(array $signals, string $assetType, string $asOfTsEst): array
    {
        if (empty($signals)) {
            return [];
        }

        $symbols = array_column($signals, 'symbol');
        $symbolPlaceholders = implode(',', array_fill(0, count($symbols), '?'));

        // Calculate Alligator WAKE_UP states in SQL and find symbols with 3 consecutive WAKE_UP
        $sql = "
WITH RECURSIVE
params AS (
  SELECT
    ? AS atype,
    CAST(? AS DATETIME) AS end_ts,
    200 AS warmup_mins
),

bars AS (
  SELECT
    omp.symbol,
    omp.ts_est,
    omp.price,
    ROW_NUMBER() OVER (PARTITION BY omp.symbol ORDER BY omp.ts_est) AS rn
  FROM one_minute_prices omp
  CROSS JOIN params p
  WHERE omp.asset_type = p.atype
    AND omp.symbol IN ($symbolPlaceholders)
    AND omp.ts_est >= DATE_SUB(p.end_ts, INTERVAL p.warmup_mins MINUTE)
    AND omp.ts_est <= p.end_ts
),

-- SMMA(5) calculation per symbol
seed5 AS (
  SELECT
    b.symbol,
    b.rn,
    b.ts_est,
    b.price,
    (SELECT AVG(b2.price) FROM bars b2 WHERE b2.symbol = b.symbol AND b2.rn BETWEEN 1 AND 5) AS smma5
  FROM bars b
  WHERE b.rn = 5
),
smma5 AS (
  SELECT symbol, rn, ts_est, price, smma5 FROM seed5
  UNION ALL
  SELECT
    b.symbol,
    b.rn,
    b.ts_est,
    b.price,
    ((s.smma5 * 4) + b.price) / 5 AS smma5
  FROM smma5 s
  JOIN bars b ON b.symbol = s.symbol AND b.rn = s.rn + 1
),

-- SMMA(8) calculation per symbol
seed8 AS (
  SELECT
    b.symbol,
    b.rn,
    b.ts_est,
    b.price,
    (SELECT AVG(b2.price) FROM bars b2 WHERE b2.symbol = b.symbol AND b2.rn BETWEEN 1 AND 8) AS smma8
  FROM bars b
  WHERE b.rn = 8
),
smma8 AS (
  SELECT symbol, rn, ts_est, price, smma8 FROM seed8
  UNION ALL
  SELECT
    b.symbol,
    b.rn,
    b.ts_est,
    b.price,
    ((s.smma8 * 7) + b.price) / 8 AS smma8
  FROM smma8 s
  JOIN bars b ON b.symbol = s.symbol AND b.rn = s.rn + 1
),

-- SMMA(13) calculation per symbol
seed13 AS (
  SELECT
    b.symbol,
    b.rn,
    b.ts_est,
    b.price,
    (SELECT AVG(b2.price) FROM bars b2 WHERE b2.symbol = b.symbol AND b2.rn BETWEEN 1 AND 13) AS smma13
  FROM bars b
  WHERE b.rn = 13
),
smma13 AS (
  SELECT symbol, rn, ts_est, price, smma13 FROM seed13
  UNION ALL
  SELECT
    b.symbol,
    b.rn,
    b.ts_est,
    b.price,
    ((s.smma13 * 12) + b.price) / 13 AS smma13
  FROM smma13 s
  JOIN bars b ON b.symbol = s.symbol AND b.rn = s.rn + 1
),

-- Combine into one series with shifts
alligator_raw AS (
  SELECT
    b.symbol,
    b.ts_est,
    b.price,
    s5.smma5 AS lips_raw,
    s8.smma8 AS teeth_raw,
    s13.smma13 AS jaw_raw
  FROM bars b
  LEFT JOIN smma5 s5 ON s5.symbol = b.symbol AND s5.rn = b.rn
  LEFT JOIN smma8 s8 ON s8.symbol = b.symbol AND s8.rn = b.rn
  LEFT JOIN smma13 s13 ON s13.symbol = b.symbol AND s13.rn = b.rn
),

-- Apply backward shifts (use historical SMMA values at current bar for signal detection)
alligator_shifted AS (
  SELECT
    symbol,
    ts_est,
    price,
    DATE_SUB(ts_est, INTERVAL 3 MINUTE) AS lips_ts,
    lips_raw AS lips,
    DATE_SUB(ts_est, INTERVAL 5 MINUTE) AS teeth_ts,
    teeth_raw AS teeth,
    DATE_SUB(ts_est, INTERVAL 8 MINUTE) AS jaw_ts,
    jaw_raw AS jaw
  FROM alligator_raw
),

-- Join shifted values to current bars
alligator_states AS (
  SELECT
    b.symbol,
    b.ts_est,
    b.price,
    l.lips,
    t.teeth,
    j.jaw,
    CASE
      WHEN l.lips IS NOT NULL AND t.teeth IS NOT NULL AND j.jaw IS NOT NULL
       AND ABS(l.lips - t.teeth) / b.price < 0.0015
       AND ABS(t.teeth - j.jaw) / b.price < 0.0015
      THEN 'SLEEPING'

      WHEN b.price > l.lips AND l.lips > t.teeth AND t.teeth > j.jaw
      THEN 'EATING'

      WHEN l.lips > t.teeth AND t.teeth > j.jaw
       AND b.price <= l.lips AND b.price >= t.teeth
      THEN 'WAKE_UP'

      WHEN l.lips < t.teeth
       AND LAG(l.lips) OVER (PARTITION BY b.symbol ORDER BY b.ts_est) >= LAG(t.teeth) OVER (PARTITION BY b.symbol ORDER BY b.ts_est)
      THEN 'SATED'

      WHEN b.price < l.lips AND l.lips < t.teeth AND t.teeth < j.jaw
      THEN 'EATING_BEAR'

      WHEN l.lips < t.teeth AND t.teeth < j.jaw
       AND b.price >= l.lips AND b.price <= t.teeth
      THEN 'WAKE_UP_BEAR'

      ELSE 'UNDEFINED'
    END AS alligator_state
  FROM bars b
  LEFT JOIN (SELECT lips_ts AS ts_est, symbol, lips FROM alligator_shifted) l 
    ON l.symbol = b.symbol AND l.ts_est = b.ts_est
  LEFT JOIN (SELECT teeth_ts AS ts_est, symbol, teeth FROM alligator_shifted) t 
    ON t.symbol = b.symbol AND t.ts_est = b.ts_est
  LEFT JOIN (SELECT jaw_ts AS ts_est, symbol, jaw FROM alligator_shifted) j 
    ON j.symbol = b.symbol AND j.ts_est = b.ts_est
  CROSS JOIN params p
  WHERE b.ts_est >= DATE_SUB(p.end_ts, INTERVAL 10 MINUTE)
),

-- Find symbols with 3 consecutive WAKE_UP or WAKE_UP_BEAR states (both indicate early trend formation)
consecutive_wakeup AS (
  SELECT
    symbol,
    ts_est,
    alligator_state,
    lips,
    teeth,
    jaw,
    CASE
      WHEN alligator_state IN ('WAKE_UP', 'WAKE_UP_BEAR')
       AND LAG(alligator_state, 1) OVER (PARTITION BY symbol ORDER BY ts_est) IN ('WAKE_UP', 'WAKE_UP_BEAR')
       AND LAG(alligator_state, 2) OVER (PARTITION BY symbol ORDER BY ts_est) IN ('WAKE_UP', 'WAKE_UP_BEAR')
      THEN 3
      ELSE 0
    END AS consecutive_count
  FROM alligator_states
)

SELECT
  symbol,
  MAX(consecutive_count) AS max_consecutive,
  MAX(lips) AS lips,
  MAX(teeth) AS teeth,
  MAX(jaw) AS jaw
FROM consecutive_wakeup
WHERE consecutive_count >= 3
GROUP BY symbol
";

        $params = array_merge([$assetType, $asOfTsEst], $symbols);
        $rows = $this->dbSelect($sql, $params);

        // Build lookup of symbols with 3+ consecutive WAKE_UP
        $wakeUpSymbols = [];
        foreach ($rows as $row) {
            $wakeUpSymbols[$row->symbol] = [
                'consecutive' => (int) $row->max_consecutive,
                'lips' => (float) $row->lips,
                'teeth' => (float) $row->teeth,
                'jaw' => (float) $row->jaw,
            ];
        }

        // Filter signals to only those with Alligator confirmation
        $confirmed = [];
        foreach ($signals as $signal) {
            $symbol = $signal['symbol'];

            if (isset($wakeUpSymbols[$symbol])) {
                $data = $wakeUpSymbols[$symbol];

                // Add Alligator metadata to signal
                $signal['alligator_wake_up'] = true;
                $signal['alligator_consecutive'] = $data['consecutive'];
                $signal['alligator_lips'] = $data['lips'];
                $signal['alligator_teeth'] = $data['teeth'];
                $signal['alligator_jaw'] = $data['jaw'];

                $confirmed[] = $signal;
            }
        }

        return $confirmed;
    }

    /**
     * Get SPY movement percentage (same as v17.0)
     */
    private function getSpyMovement(string $asOfTsEst, int $lookbackMinutes): float
    {
        $benchmarkSymbol = config('trading.market_benchmark_symbol', 'QQQM');
        $nback = max(2, (int) floor($lookbackMinutes / 5));

        $sql = "
            SELECT price AS last_close,
                LAG(price, ?) OVER (ORDER BY ts_est) AS prev_close
            FROM five_minute_prices
            WHERE symbol = ? AND asset_type = 'stock'
                AND ts_est <= ? AND ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
            ORDER BY ts_est DESC
            LIMIT 1
        ";

        $result = DB::selectOne($sql, [$nback, $benchmarkSymbol, $asOfTsEst, $asOfTsEst, $lookbackMinutes]);

        if (! $result || ! $result->prev_close) {
            return 0.0;
        }

        return (($result->last_close - $result->prev_close) / $result->prev_close) * 100.0;
    }
}
