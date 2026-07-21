<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\DB;

/**
 * Version 60.1 - Hybrid Scanner
 *
 * "Best of both worlds":
 * - v17 edge: universe (top performers + losers) + SPY relative strength filter
 * - v50 edge: EntryScore confirmation from 1m bars in the scan window
 *
 * ENV / config('trading.*'):
 * - ENTRY_SCORE_MIN / ENTRY_SCORE_MAX: score window
 * - ENTRY_SCORE_LIMIT: max picks returned (defaults to 10)
 */
class FiveMinuteSignalScannerV60_3
{
    use HasPriceTables;

    private string $version = 'v60.3';

    private string $name = 'Hybrid Breakout';

    // ── Scanner Configuration (public so entry finders can read) ──
    public float $entryScoreMin = 80;

    public float $entryScoreMax = 98;

    public int $entryScoreLimit = 60;

    public float $minComposite = 2.0;

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
            'entry_score_min' => $this->entryScoreMin,
            'entry_score_max' => $this->entryScoreMax,
            'entry_score_limit' => $this->entryScoreLimit,
            'min_composite' => $this->minComposite,
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
     * Drop-in compatible signature.
     *
     * Recommended live settings:
     * - $lookbackMinutes: 10–20
     * - $minMovePct: 0.9+ (loosened from 1.2 to capture more setups)
     * - $volMult: 2.5+ (loosened from 3.5 to capture more setups)
     */
    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 15,
        float $minMovePct = 0.9,
        float $volMult = 2.5,
        int $limit = 60
    ): array {
        $minScore = $this->entryScoreMin;
        $maxScore = $this->entryScoreMax;
        $topN = $this->entryScoreLimit;

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

        // -----------------------------
        // 1) Universe (v17)
        // -----------------------------
        $topPerformers = $this->bestPerformersService->getBestPerformers([
            'assetType' => $assetType,
            'testDateTime' => $asOfTsEst,
            'days' => 5,
            'minBars' => 200,
            'minVol' => 500000,
            'rthOnly' => true,
            'limit' => 100,
            'tz' => 'America/New_York',
        ]);

        $symbols = array_values(array_filter(array_unique(array_map(
            fn ($r) => (string) ($r['symbol'] ?? ''),
            $topPerformers
        ))));

        // REMOVED: Losers from previous day - they don't bounce reliably in live trading

        // Add market movers to universe if enabled (top explosive movers from recent days)
        $moversLimit = (int) config('trading.market_movers.pipeline_d', 0);
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

        if (empty($symbols)) {
            return [];
        }

        // -----------------------------
        // 2) 5m momentum candidates (v17 core)
        // -----------------------------
        $placeholders = implode(',', array_fill(0, count($symbols), '?'));

        // In 5-minute bars, N bars back ~ lookbackMinutes/5
        $nback = max(2, (int) floor($lookbackMinutes / 5));

        $sql5m = "
WITH bars AS (
  SELECT
    symbol,
    asset_type,
    ts_est,
    price AS close,
    volume,
    atr_pct,
    ROW_NUMBER() OVER (PARTITION BY symbol, asset_type ORDER BY ts_est DESC) AS rn
  FROM five_minute_prices
  WHERE asset_type = ?
    AND symbol IN ($placeholders)
    AND ts_est <= ?
    AND ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
),
agg AS (
  SELECT
    symbol,
    asset_type,
    MAX(CASE WHEN rn = 1 THEN ts_est END) AS signal_ts_est,
    MAX(CASE WHEN rn = 1 THEN close END)  AS last_close,
    MAX(CASE WHEN rn = 1 THEN volume END) AS last_vol,
    MAX(CASE WHEN rn = 1 THEN atr_pct END) AS atr_pct,
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
  atr_pct,
  ((last_close - prev_close) / NULLIF(prev_close, 0)) * 100 AS move_pct,
  (last_vol / NULLIF(avg_vol, 0)) AS vol_ratio
FROM agg
WHERE prev_close IS NOT NULL
  AND ((last_close - prev_close) / NULLIF(prev_close, 0)) * 100 >= ?
  AND (last_vol / NULLIF(avg_vol, 0)) >= ?
  AND (((last_close - prev_close) / NULLIF(prev_close, 0)) * 100 * (last_vol / NULLIF(avg_vol, 0))) >= ?
ORDER BY (((last_close - prev_close) / NULLIF(prev_close, 0)) * 100 * (last_vol / NULLIF(avg_vol, 0))) DESC
LIMIT ?
";

        $minComposite = $this->minComposite;

        $params5m = array_merge(
            [$assetType],
            $symbols,
            [$asOfTsEst],
            [$asOfTsEst],
            [$lookbackMinutes + 10], // +10 min buffer so prev_close (rn=nback+1) is always within window
            [$nback],
            [$minMovePct],
            [$volMult],
            [$minComposite],
            [$limit]
        );

        $rows5m = $this->dbSelect($sql5m, $params5m);

        if (empty($rows5m)) {
            return [];
        }

        // -----------------------------
        // 3) Relative strength vs SPY (v17 edge)
        // -----------------------------
        $spyMovePct = $this->getSpyMovement($asOfTsEst, $lookbackMinutes);

        $cands = [];
        foreach ($rows5m as $r) {
            $stockMovePct = (float) $r->move_pct;

            // RS filter: allow slightly negative SPY but still require stock outperformance
            if ($spyMovePct <= -0.3) {
                continue; // Skip only if SPY clearly bearish
            }
            if ($stockMovePct < $spyMovePct * 1.15) {
                continue; // Stock must be 115%+ of SPY move (loosened from 150%)
            }

            $momoScore = $stockMovePct * (float) $r->vol_ratio;

            $cands[] = [
                'symbol' => (string) $r->symbol,
                'asset_type' => (string) $r->asset_type,
                'signal_ts_est' => (string) $r->signal_ts_est,
                'move_pct' => $stockMovePct,
                'vol_ratio' => (float) $r->vol_ratio,
                'momo_score' => $momoScore,
                'last_close' => (float) $r->last_close,
                'prev_close' => (float) $r->prev_close,
                'atr_pct' => $r->atr_pct ?? null,
            ];
        }

        if (empty($cands)) {
            return [];
        }

        // -----------------------------
        // 4) EntryScore confirmation from 1m bars (v50 edge)
        //    Compute BEST (max) EntryScore per symbol inside the scan window.
        // -----------------------------
        $candSymbols = array_values(array_unique(array_map(fn ($c) => $c['symbol'], $cands)));
        $ph = implode(',', array_fill(0, count($candSymbols), '?'));

        $sql1mScore = "
WITH base AS (
  SELECT
    omp.*,
    AVG(omp.volume) OVER (
      PARTITION BY omp.symbol, omp.asset_type
      ORDER BY omp.ts_est
      ROWS BETWEEN 20 PRECEDING AND 1 PRECEDING
    ) AS avg_vol_20
  FROM one_minute_prices omp
  WHERE omp.asset_type = ?
    AND omp.symbol IN ($ph)
    AND omp.ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
    AND omp.ts_est <  ?
    AND omp.trading_date_est = DATE(?)
),
scored AS (
  SELECT
    b.symbol,
    b.asset_type,
    b.ts_est,
    ROUND(
      100 * (
        0.30*(0.70*COALESCE(b.ema9_above_ema21,0) + 0.30*GREATEST(LEAST(((COALESCE(b.ema9_ema21_spread,0) / NULLIF(b.price,0)) - 0.0005) / (0.0030 - 0.0005), 1), 0)) +
        0.25*(COALESCE(b.above_vwap,0) * GREATEST(1 - (ABS(COALESCE(b.vwap_dist_pct,0) - 0.15) / 0.30), 0)) +
        0.10*((GREATEST(LEAST((COALESCE(b.atr_pct,0) - 0.08) / (0.20 - 0.08), 1), 0)) * (1 - GREATEST(LEAST((COALESCE(b.atr_pct,0) - 0.50) / (1.50 - 0.50), 1), 0))) +
        0.20*(CASE
          WHEN AVG_VOL_20 IS NULL OR AVG_VOL_20 = 0 OR b.volume IS NULL THEN 0
          ELSE GREATEST(LEAST(((b.volume / AVG_VOL_20) - 0.8) / (2.5 - 0.8), 1), 0)
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
  FROM base b
  WHERE COALESCE(b.ema9_above_ema21,0)=1
    AND COALESCE(b.above_vwap,0)=1
)
SELECT symbol, MAX(entry_score) AS best_entry_score
FROM scored
GROUP BY symbol
";

        $params1mScore = array_merge(
            [$assetType],
            $candSymbols,
            [$asOfTsEst, $lookbackMinutes, $asOfTsEst, $asOfTsEst]
        );

        $scoreRows = $this->dbSelect($sql1mScore, $params1mScore);
        $bestScoreBySymbol = [];
        foreach ($scoreRows as $sr) {
            $bestScoreBySymbol[(string) $sr->symbol] = (float) $sr->best_entry_score;
        }

        // -----------------------------
        // 5) Filter by EntryScore window + rank by combined score
        // -----------------------------
        $ranked = [];
        foreach ($cands as $c) {
            $entryScore = $bestScoreBySymbol[$c['symbol']] ?? null;
            if ($entryScore === null) {
                continue;
            }
            if ($entryScore < $minScore || $entryScore > $maxScore) {
                continue;
            }

            // Combined score: keep leader/momentum first, then structure confirmation
            // - entryScore: 0..100
            // - momoScore: usually 2..30 (but can spike)
            $momoBoost = min(50.0, (float) $c['momo_score'] * 5.0);
            $combined = $entryScore + $momoBoost;

            $ranked[] = $c + [
                'entry_score' => $entryScore,
                'combined_score' => $combined,
            ];
        }

        if (empty($ranked)) {
            return [];
        }

        usort($ranked, fn ($a, $b) => ($b['combined_score'] <=> $a['combined_score']));

        $finalLimit = min($limit, $topN, count($ranked));
        $ranked = array_slice($ranked, 0, $finalLimit);

        // Output signals
        $out = [];
        foreach ($ranked as $r) {
            // Calculate ATR from atr_pct
            $atrPct = $r['atr_pct'] ?? null;
            $currentPrice = (float) ($r['last_close'] ?? 0);
            $atr = ($atrPct && $currentPrice) ? round(($atrPct / 100) * $currentPrice, 6) : null;

            $out[] = [
                'symbol' => (string) $r['symbol'],
                'asset_type' => (string) $r['asset_type'],
                'signal_type' => 'HYBRID_MOMO_ENTRY_SCORE',
                'signal_ts_est' => (string) $r['signal_ts_est'],
                'score' => round((float) $r['combined_score'], 3),
                'atr' => $atr,
                'atr_pct' => $atrPct,
                'meta' => [
                    'version' => $this->version,
                    'current_price' => $currentPrice,
                    'move_pct' => round((float) $r['move_pct'], 3),
                    'vol_ratio' => round((float) $r['vol_ratio'], 3),
                    'momo_score' => round((float) $r['momo_score'], 3),
                    'entry_score' => round((float) $r['entry_score'], 2),
                    'entry_score_min' => $minScore,
                    'entry_score_max' => $maxScore,
                    'spy_move_pct' => round((float) $spyMovePct, 3),
                    'window_minutes' => $lookbackMinutes,
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
            WHERE symbol = 'QQQM'
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
}
