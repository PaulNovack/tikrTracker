<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\DB;

/**
 * FiveMinuteSignalScannerV80_1 (v80.1)
 *
 * Drop-in compatible with your V60_2 scanner signature + output shape.
 *
 * What changes vs v60.2:
 * - Keeps your exact Universe (BestPerformers + prior-day losers) and SPY RS filter.
 * - Replaces "5m momentum + 1m EntryScore confirm" with THREE strategy detectors that
 *   leverage your existing 1m/5m columns:
 *
 *   A) TD Pullback Continuation      => TD_PULLBACK
 *   B) VWAP Deviation Snapback       => VWAP_SNAPBACK
 *   C) EMA Compression Breakout      => EMA_COMPRESSION_BREAK
 *
 * Each strategy emits a signal row:
 *   ['symbol','asset_type','signal_type','signal_ts_est','score','meta']
 *
 * score:
 * - stays in the same general "0..150-ish" range as your combined score
 *   (EntryScore 0..100 + modest strategy bonus).
 *
 * ENV / config('trading.*'):
 * - ENTRY_SCORE_MIN / ENTRY_SCORE_MAX (same as your V60_2)
 * - ENTRY_SCORE_LIMIT (top N returned)
 *
 * Tunables via config('trading.v80.*') with safe defaults inside.
 */
class FiveMinuteSignalScannerV80_1
{
    use HasPriceTables;

    private string $version = 'v80.1';

    private string $name = 'Multi-Timeframe Confirmation';

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
     * Drop-in compatible signature.
     */
    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 15,
        float $minMovePct = 0.2,
        float $volMult = 2.00,
        int $limit = 60
    ): array {
        // V80-specific configuration (won't affect other versions)
        $minScore = (float) config('trading.v80.entry_score_min', 80);
        $maxScore = (float) config('trading.v80.entry_score_max', 100);
        $topN = (int) config('trading.v80.entry_score_limit', 10);

        if ($topN <= 0) {
            $topN = 10;
        }
        if ($maxScore <= 0) {
            $maxScore = 100.0;
        }
        if ($minScore > $maxScore) {
            [$minScore, $maxScore] = [$maxScore, $minScore];
        }

        $lookbackMinutes = max(5, (int) $lookbackMinutes);
        $limit = max(1, (int) $limit);

        // v80 tunables (config-safe)
        $tdTrendLookbackMin = (int) config('trading.v80.td_trend_lookback_min', 25);
        $tdTrendMinRatio = (float) config('trading.v80.td_trend_min_ratio', 0.80);
        $tdEmaTolPct = (float) config('trading.v80.td_pullback_ema_tol_pct', 0.25);
        $tdPullbackVolMult = (float) config('trading.v80.td_pullback_vol_mult', 0.70);

        $vwapDevMinPct = (float) config('trading.v80.vwap_dev_min_pct', 1.25);
        $vwapDevVolMult = (float) config('trading.v80.vwap_dev_vol_mult', 2.00);

        $compSpreadMax = (float) config('trading.v80.comp_spread_max', 0.030);
        $compVwapNearPct = (float) config('trading.v80.comp_vwap_near_pct', 0.20);
        $compAtrPctMax = (float) config('trading.v80.comp_atr_pct_max', 0.40);
        $compBreakVolMult = (float) config('trading.v80.comp_break_vol_mult', 1.80);

        $avgVolLookbackMin = (int) config('trading.v80.avg_vol_lookback_min', 20);

        // Optional universe throttle (huge speed gain with large universes)
        $universeLimit = (int) config('trading.v80.universe_limit', 0);   // e.g. 600
        $universeLookback = (int) config('trading.v80.universe_lookback_min', 30);

        // -----------------------------
        // 1) Universe (same as v60.2)
        // -----------------------------
        $topPerformers = $this->bestPerformersService->getBestPerformers([
            'assetType' => $assetType,
            'testDateTime' => $asOfTsEst,
            'days' => 5,
            'minBars' => 200,
            'minVol' => 0,
            'rthOnly' => true,
            'limit' => 600,
            'tz' => 'America/New_York',
        ]);

        $symbols = array_values(array_filter(array_unique(array_map(
            fn ($r) => (string) ($r['symbol'] ?? ''),
            $topPerformers
        ))));

        // Add top losers from previous trading day (bounce candidates)
        try {
            $currentDate = substr($asOfTsEst, 0, 10);

            $prevTradingDay = DB::table($this->fiveMinuteTable)
                ->where('asset_type', $assetType)
                ->where('trading_date_est', '<', $currentDate)
                ->orderBy('trading_date_est', 'desc')
                ->value('trading_date_est');

            if ($prevTradingDay) {
                $losersData = $this->gainersLosersService->getGainersAndLosers(
                    $prevTradingDay,
                    $assetType,
                    50
                );

                $loserSymbols = array_values(array_filter(array_unique(array_map(
                    fn ($r) => (string) ($r['symbol'] ?? ''),
                    $losersData['losers'] ?? []
                ))));

                $symbols = array_values(array_unique(array_merge($symbols, $loserSymbols)));
            }
        } catch (\Throwable $e) {
            // fail open
        }

        if (empty($symbols)) {
            return [];
        }

        // -----------------------------
        // 2) 5m Relative strength vs SPY (same as v60.2)
        // -----------------------------
        $spyMovePct = $this->getSpyMovement($asOfTsEst, $lookbackMinutes);

        // We'll apply RS after we compute candidates (so RS can prune).
        // But we still need a stock's 5m move_pct to compute RS gate:
        $moveBySymbol = $this->get5mMovePctBySymbol($assetType, $asOfTsEst, $lookbackMinutes, $symbols);

        // -----------------------------
        // 3) 1m Strategy candidates (NEW)
        //    Scan the last window and find the BEST bar per symbol for each strategy.
        // -----------------------------
        $cands = $this->scanStrategies1m(
            assetType: $assetType,
            asOfTsEst: $asOfTsEst,
            windowMinutes: $lookbackMinutes,
            symbols: $symbols,
            universeLimit: $universeLimit,
            universeLookbackMin: $universeLookback,
            avgVolLookbackMin: $avgVolLookbackMin,

            // TD
            tdTrendLookbackMin: $tdTrendLookbackMin,
            tdTrendMinRatio: $tdTrendMinRatio,
            tdEmaTolPct: $tdEmaTolPct,
            tdPullbackVolMult: $tdPullbackVolMult,

            // VWAP snapback
            vwapDevMinPct: $vwapDevMinPct,
            vwapDevVolMult: $vwapDevVolMult,

            // Compression breakout
            compSpreadMax: $compSpreadMax,
            compVwapNearPct: $compVwapNearPct,
            compAtrPctMax: $compAtrPctMax,
            compBreakVolMult: $compBreakVolMult
        );

        if (empty($cands)) {
            return [];
        }

        // -----------------------------
        // 4) RS filter + EntryScore window + final rank
        // -----------------------------
        $ranked = [];

        foreach ($cands as $c) {
            $sym = $c['symbol'];

            $stockMovePct = (float) ($moveBySymbol[$sym] ?? 0.0);

            // preserve v17 RS logic
            if ($spyMovePct > 0 && $stockMovePct < $spyMovePct * 1.1) {
                continue;
            }

            $entryScore = (float) ($c['entry_score'] ?? 0.0);
            if ($entryScore < $minScore || $entryScore > $maxScore) {
                continue;
            }

            // keep some of your old knobs in play:
            // use minMovePct & volMult as SOFT boosts rather than hard gates
            $moveBoost = ($stockMovePct >= $minMovePct) ? min(20.0, $stockMovePct * 2.5) : 0.0;
            $volBoost = ((float) ($c['meta']['vol_ratio'] ?? 0) >= $volMult) ? 10.0 : 0.0;

            // strategy bonus keeps variety without dominating EntryScore
            $strategyBonus = (float) ($c['strategy_bonus'] ?? 0.0);

            $combined = $entryScore + $strategyBonus + $moveBoost + $volBoost;

            $ranked[] = $c + [
                'move_pct_5m' => $stockMovePct,
                'spy_move_pct' => $spyMovePct,
                'combined_score' => $combined,
            ];
        }

        if (empty($ranked)) {
            return [];
        }

        usort($ranked, fn ($a, $b) => ($b['combined_score'] <=> $a['combined_score']));

        $finalLimit = min($limit, $topN, count($ranked));
        $ranked = array_slice($ranked, 0, $finalLimit);

        $out = [];
        foreach ($ranked as $r) {
            $out[] = [
                'symbol' => (string) $r['symbol'],
                'asset_type' => (string) $r['asset_type'],
                'signal_type' => (string) $r['signal_type'],
                'signal_ts_est' => (string) $r['signal_ts_est'],
                'score' => round((float) $r['combined_score'], 3),
                'meta' => array_merge($r['meta'] ?? [], [
                    'version' => $this->version,
                    'entry_score' => round((float) $r['entry_score'], 2),
                    'entry_score_min' => $minScore,
                    'entry_score_max' => $maxScore,
                    'spy_move_pct' => round((float) $spyMovePct, 3),
                    'move_pct_5m' => round((float) ($r['move_pct_5m'] ?? 0), 3),
                    'window_minutes' => $lookbackMinutes,
                    'strategy_bonus' => round((float) ($r['strategy_bonus'] ?? 0), 2),
                ]),
            ];
        }

        return $out;
    }

    /**
     * Strategy scan on 1m bars:
     * - Finds best matching bar for each strategy per symbol within window.
     * - Computes EntryScore using same v60.2 formula shape (via SQL).
     */
    private function scanStrategies1m(
        string $assetType,
        string $asOfTsEst,
        int $windowMinutes,
        array $symbols,
        int $universeLimit,
        int $universeLookbackMin,
        int $avgVolLookbackMin,

        int $tdTrendLookbackMin,
        float $tdTrendMinRatio,
        float $tdEmaTolPct,
        float $tdPullbackVolMult,

        float $vwapDevMinPct,
        float $vwapDevVolMult,

        float $compSpreadMax,
        float $compVwapNearPct,
        float $compAtrPctMax,
        float $compBreakVolMult
    ): array {
        $symbols = array_values(array_filter(array_unique(array_map('strval', $symbols))));
        if (empty($symbols)) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($symbols), '?'));

        // Optional: throttle universe to top N by recent 1m volume (fast)
        $universeJoinSql = '';
        $universeParams = [];
        if ($universeLimit > 0) {
            $universeJoinSql = "
              JOIN (
                SELECT omp_u.symbol
                FROM one_minute_prices omp_u
                WHERE omp_u.asset_type = ?
                  AND omp_u.ts_est >= DATE_SUB(?, INTERVAL {$universeLookbackMin} MINUTE)
                  AND omp_u.ts_est <= ?
                  AND omp_u.symbol IN ($ph)
                GROUP BY omp_u.symbol
                ORDER BY SUM(COALESCE(omp_u.volume,0)) DESC
                LIMIT {$universeLimit}
              ) uni ON uni.symbol = b.symbol
            ";
            $universeParams = array_merge([$assetType, $asOfTsEst, $asOfTsEst], $symbols);
        }

        // We compute avg_vol_20 as in your v60_2 score CTE, but we do it inside base.
        // Then compute an entry_score with the same weightings as your V60_2 SQL.
        // Then select best per symbol per strategy (max combined).
        $sql = "
WITH params AS (
  SELECT
    ? AS p_asset_type,
    CAST(? AS DATETIME) AS p_asof,
    CAST(? AS DATETIME) - INTERVAL ? MINUTE AS p_from
),
base AS (
  SELECT
    omp.*,
    AVG(COALESCE(omp.volume,0)) OVER (
      PARTITION BY omp.symbol, omp.asset_type
      ORDER BY omp.ts_est
      ROWS BETWEEN 20 PRECEDING AND 1 PRECEDING
    ) AS avg_vol_20
  FROM one_minute_prices omp
  JOIN params p ON omp.asset_type = p.p_asset_type
  WHERE omp.symbol IN ($ph)
    AND omp.ts_est >= p.p_from
    AND omp.ts_est <  p.p_asof
    AND omp.trading_date_est = DATE(p.p_asof)
),
b AS (
  SELECT *
  FROM base
  ".($universeJoinSql ? $universeJoinSql : '')."
),
vol_base AS (
  SELECT
    symbol,
    AVG(COALESCE(volume,0)) AS avg_vol_win,
    AVG(COALESCE(atr_pct,0)) AS avg_atr_pct_win,
    AVG(CASE WHEN above_vwap=1 THEN 1 ELSE 0 END) AS above_vwap_ratio_win,
    AVG(CASE WHEN ema9_above_ema21=1 THEN 1 ELSE 0 END) AS ema_trend_ratio_win
  FROM b
  WHERE ts_est >= DATE_SUB((SELECT p_asof FROM params), INTERVAL ? MINUTE)
  GROUP BY symbol
),
scored AS (
  SELECT
    b.symbol,
    b.asset_type,
    b.ts_est,
    b.price,
    b.open,
    b.high,
    b.low,
    b.volume,
    b.vwap_dist_pct,
    b.above_vwap,
    b.ema9,
    b.ema21,
    b.ema9_ema21_spread,
    b.ema9_above_ema21,
    b.atr_pct,
    vb.avg_vol_win,
    vb.avg_atr_pct_win,
    vb.above_vwap_ratio_win,
    vb.ema_trend_ratio_win,

    -- vol ratio vs avg_vol_20 (your old logic)
    (CASE
      WHEN b.avg_vol_20 IS NULL OR b.avg_vol_20 = 0 OR b.volume IS NULL THEN NULL
      ELSE (b.volume / b.avg_vol_20)
    END) AS vol_ratio_20,

    -- EntryScore (same shape as V60_2 SQL)
    ROUND(
      100 * (
        0.30*(0.70*COALESCE(b.ema9_above_ema21,0) + 0.30*GREATEST(LEAST(((COALESCE(b.ema9_ema21_spread,0) / NULLIF(b.price,0)) - 0.0005) / (0.0030 - 0.0005), 1), 0)) +
        0.25*(COALESCE(b.above_vwap,0) * GREATEST(1 - (ABS(COALESCE(b.vwap_dist_pct,0) - 0.15) / 0.30), 0)) +
        0.10*((GREATEST(LEAST((COALESCE(b.atr_pct,0) - 0.08) / (0.20 - 0.08), 1), 0)) * (1 - GREATEST(LEAST((COALESCE(b.atr_pct,0) - 0.50) / (1.50 - 0.50), 1), 0))) +
        0.20*(CASE
          WHEN b.avg_vol_20 IS NULL OR b.avg_vol_20 = 0 OR b.volume IS NULL THEN 0
          ELSE GREATEST(LEAST(((b.volume / b.avg_vol_20) - 0.8) / (2.5 - 0.8), 1), 0)
        END) +
        0.10*(CASE
          WHEN b.high IS NULL OR b.low IS NULL OR (b.high - b.low) <= 0 THEN 0
          ELSE GREATEST(LEAST(((((b.price - b.low) / (b.high - b.low)) - 0.45) / (0.80 - 0.45)), 1), 0)
        END) +
        0.05*(CASE
          WHEN TIME(b.ts_est) <= '10:30:00' THEN 1.0
          WHEN TIME(b.ts_est) <= '11:00:00' THEN 0.5
          ELSE 0.0
        END)
      ),
      2
    ) AS entry_score
  FROM b
  LEFT JOIN vol_base vb ON vb.symbol = b.symbol
),
flags AS (
  SELECT
    s.*,

    -- Strategy A: TD_PULLBACK
    (CASE
      WHEN s.above_vwap_ratio_win >= ?
       AND s.ema_trend_ratio_win  >= ?
       AND s.above_vwap = 1
       AND s.ema9_above_ema21 = 1
       AND (
         (s.ema9  IS NOT NULL AND ABS((s.price - s.ema9)  / NULLIF(s.ema9,0))  * 100 <= ?)
         OR
         (s.ema21 IS NOT NULL AND ABS((s.price - s.ema21) / NULLIF(s.ema21,0)) * 100 <= ?)
       )
       AND (s.avg_vol_win IS NULL OR COALESCE(s.volume,0) <= s.avg_vol_win * ?)
      THEN 1 ELSE 0 END
    ) AS is_td_pullback,

    -- Strategy B: VWAP_SNAPBACK (long only: below vwap + bullish candle + big deviation + volume)
    (CASE
      WHEN ABS(COALESCE(s.vwap_dist_pct,0)) >= ?
       AND s.above_vwap = 0
       AND s.price > COALESCE(s.open, s.price)
       AND (s.avg_vol_win IS NULL OR COALESCE(s.volume,0) >= s.avg_vol_win * ?)
      THEN 1 ELSE 0 END
    ) AS is_vwap_snapback,

    -- Strategy C: EMA_COMPRESSION_BREAK (long)
    (CASE
      WHEN s.ema9_ema21_spread IS NOT NULL
       AND s.ema9_ema21_spread <= ?
       AND ABS(COALESCE(s.vwap_dist_pct,0)) <= ?
       AND (s.avg_atr_pct_win IS NULL OR s.avg_atr_pct_win <= ?)
       AND s.above_vwap = 1
       AND s.ema9_above_ema21 = 1
       AND s.price > COALESCE(s.open, s.price)
       AND (s.avg_vol_win IS NULL OR COALESCE(s.volume,0) >= s.avg_vol_win * ?)
      THEN 1 ELSE 0 END
    ) AS is_comp_break
  FROM scored s
),
best_td AS (
  SELECT
    symbol,
    MAX(entry_score + 18.0) AS best_score
  FROM flags
  WHERE is_td_pullback = 1
  GROUP BY symbol
),
best_vwap AS (
  SELECT
    symbol,
    MAX(entry_score + 14.0) AS best_score
  FROM flags
  WHERE is_vwap_snapback = 1
  GROUP BY symbol
),
best_comp AS (
  SELECT
    symbol,
    MAX(entry_score + 16.0) AS best_score
  FROM flags
  WHERE is_comp_break = 1
  GROUP BY symbol
),
best_union AS (
  SELECT symbol, 'TD_PULLBACK' AS signal_type, best_score FROM best_td
  UNION ALL
  SELECT symbol, 'VWAP_SNAPBACK' AS signal_type, best_score FROM best_vwap
  UNION ALL
  SELECT symbol, 'EMA_COMPRESSION_BREAK' AS signal_type, best_score FROM best_comp
),
best_per_symbol AS (
  -- choose the best strategy per symbol by best_score
  SELECT
    symbol,
    SUBSTRING_INDEX(GROUP_CONCAT(signal_type ORDER BY best_score DESC), ',', 1) AS best_signal_type,
    MAX(best_score) AS best_score
  FROM best_union
  GROUP BY symbol
)
SELECT
  f.symbol,
  a.id AS asset_id,
  f.asset_type,
  f.ts_est AS signal_ts_est,
  bps.best_signal_type AS signal_type,
  f.entry_score,
  (bps.best_score - f.entry_score) AS strategy_bonus,
  f.vol_ratio_20,
  f.vwap_dist_pct,
  f.ema9_ema21_spread,
  f.atr_pct,
  f.above_vwap,
  f.ema9_above_ema21,
  f.price,
  f.open,
  f.high,
  f.low,
  f.volume
FROM flags f
JOIN asset_info a ON a.symbol = f.symbol AND a.deleted_at IS NULL
JOIN best_per_symbol bps
  ON bps.symbol = f.symbol
WHERE
  -- only rows that match the chosen strategy and achieve that best_score
  (
    (bps.best_signal_type = 'TD_PULLBACK' AND f.is_td_pullback = 1 AND (f.entry_score + 18.0) = bps.best_score)
    OR
    (bps.best_signal_type = 'VWAP_SNAPBACK' AND f.is_vwap_snapback = 1 AND (f.entry_score + 14.0) = bps.best_score)
    OR
    (bps.best_signal_type = 'EMA_COMPRESSION_BREAK' AND f.is_comp_break = 1 AND (f.entry_score + 16.0) = bps.best_score)
  )
ORDER BY bps.best_score DESC
LIMIT ?
";

        $params = [];
        // params CTE
        $params[] = $assetType;
        $params[] = $asOfTsEst;
        $params[] = $asOfTsEst;
        $params[] = $windowMinutes;

        // base symbols
        $params = array_merge($params, $symbols);

        // optional universe join
        if ($universeJoinSql) {
            $params = array_merge($params, $universeParams);
        }

        // vol_base lookback
        $params[] = $avgVolLookbackMin;

        // TD flags params
        $params[] = $tdTrendMinRatio;
        $params[] = $tdTrendMinRatio;
        $params[] = $tdEmaTolPct;
        $params[] = $tdEmaTolPct;
        $params[] = $tdPullbackVolMult;

        // VWAP snapback params
        $params[] = $vwapDevMinPct;
        $params[] = $vwapDevVolMult;

        // Compression params
        $params[] = $compSpreadMax;
        $params[] = $compVwapNearPct;
        $params[] = $compAtrPctMax;
        $params[] = $compBreakVolMult;

        // limit
        $params[] = max(1, (int) config('trading.v80.symbol_limit', 300)); // cap #symbols emitted from SQL
        $rows = $this->dbSelect($sql, $params);

        if (empty($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $volRatio = ($r->vol_ratio_20 !== null) ? (float) $r->vol_ratio_20 : 0.0;
            $atrPct = $r->atr_pct !== null ? round((float) $r->atr_pct, 4) : null;
            $currentPrice = round((float) $r->price, 6);
            $atr = ($atrPct && $currentPrice) ? round(($atrPct / 100) * $currentPrice, 6) : null;

            $out[] = [
                'symbol' => (string) $r->symbol,
                'asset_id' => (int) $r->asset_id,
                'asset_type' => (string) $r->asset_type,
                'signal_type' => (string) $r->signal_type,
                'signal_ts_est' => (string) $r->signal_ts_est,
                'price' => $currentPrice,
                'entry_score' => (float) $r->entry_score,
                'strategy_bonus' => (float) $r->strategy_bonus,
                'score' => (float) $r->entry_score + (float) $r->strategy_bonus,
                'vol_ratio' => round($volRatio, 3),
                'atr' => $atr,
                'atr_pct' => $atrPct,
                'meta' => [
                    'vol_ratio' => round($volRatio, 3),
                    'vwap_dist_pct' => $r->vwap_dist_pct !== null ? round((float) $r->vwap_dist_pct, 4) : null,
                    'ema9_ema21_spread' => $r->ema9_ema21_spread !== null ? round((float) $r->ema9_ema21_spread, 6) : null,
                    'atr_pct' => $atrPct,
                    'above_vwap' => (int) ($r->above_vwap ?? 0),
                    'ema9_above_ema21' => (int) ($r->ema9_above_ema21 ?? 0),
                    'price' => $currentPrice,
                    'current_price' => $currentPrice,
                    'open' => $r->open !== null ? round((float) $r->open, 6) : null,
                    'high' => $r->high !== null ? round((float) $r->high, 6) : null,
                    'low' => $r->low !== null ? round((float) $r->low, 6) : null,
                    'volume' => $r->volume !== null ? (int) $r->volume : null,
                ],
            ];
        }

        return $out;
    }

    private function getSpyMovement(string $asOfTsEst, int $lookbackMinutes): float
    {
        $nback = max(2, (int) floor($lookbackMinutes / 5));

        $sql = "
            SELECT
                price AS last_close,
                LAG(price, ?) OVER (ORDER BY ts_est) AS prev_close
            FROM five_minute_prices
            WHERE symbol = 'SPYG'
              AND asset_type = 'stock'
              AND ts_est <= ?
              AND ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
            ORDER BY ts_est DESC
            LIMIT 1
        ";

        $result = DB::selectOne($sql, [$nback, $asOfTsEst, $asOfTsEst, $lookbackMinutes]);

        if (! $result || ! $result->prev_close) {
            return 0.0;
        }

        return (($result->last_close - $result->prev_close) / $result->prev_close) * 100.0;
    }

    /**
     * Used only to preserve your RS filter; computes 5m move_pct per symbol over window.
     */
    private function get5mMovePctBySymbol(string $assetType, string $asOfTsEst, int $lookbackMinutes, array $symbols): array
    {
        $symbols = array_values(array_filter(array_unique(array_map('strval', $symbols))));
        if (empty($symbols)) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($symbols), '?'));
        $nback = max(2, (int) floor($lookbackMinutes / 5));

        $sql = "
WITH last_bar AS (
  SELECT symbol, asset_type, MAX(ts_est) AS last_ts_est
  FROM five_minute_prices
  WHERE asset_type = ?
    AND symbol IN ($ph)
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
    ROW_NUMBER() OVER (PARTITION BY f.symbol, f.asset_type ORDER BY f.ts_est DESC) AS rn
  FROM five_minute_prices f
  JOIN last_bar lb
    ON lb.symbol = f.symbol AND lb.asset_type = f.asset_type
  WHERE f.asset_type = ?
    AND f.symbol IN ($ph)
    AND f.ts_est <= ?
    AND f.ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
),
agg AS (
  SELECT
    symbol,
    MAX(CASE WHEN rn = 1 THEN close END) AS last_close,
    MAX(CASE WHEN rn = 1 + ? THEN close END) AS prev_close
  FROM bars
  GROUP BY symbol
)
SELECT symbol, ((last_close - prev_close) / NULLIF(prev_close,0)) * 100 AS move_pct
FROM agg
WHERE prev_close IS NOT NULL
";

        $params = array_merge(
            [$assetType],
            $symbols,
            [$asOfTsEst, $asOfTsEst, max(15, $lookbackMinutes)],
            [$assetType],
            $symbols,
            [$asOfTsEst, $asOfTsEst, max(15, $lookbackMinutes)],
            [$nback]
        );

        $rows = $this->dbSelect($sql, $params);

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->symbol] = (float) $r->move_pct;
        }

        return $out;
    }
}
