<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;

/**
 * Version 120.0 - Elite Multi-Day Momentum Continuation Scanner
 *
 * Enhanced v90.1 with 5 key improvements:
 * 1. Multi-day win streak detection (2+ consecutive days up)
 * 2. Gap-up continuation filter (holding pre-market gap)
 * 3. Volume pattern analysis (increasing volume trend)
 * 4. Score range optimization (focus on 40-59 sweet spot)
 * 5. Catalyst awareness metadata
 *
 * ENV / config('trading.*'):
 * - V120_ENTRY_SCORE_MIN / V120_ENTRY_SCORE_MAX: score window
 * - V120_ENTRY_SCORE_LIMIT: max picks returned
 * - V120_MIN_CONSECUTIVE_DAYS: minimum consecutive up days (default 2)
 * - V120_MIN_GAP_PCT: minimum gap-up % (default 2.0)
 * - V120_REQUIRE_VOL_INCREASE: require volume increasing (default true)
 */
class FiveMinuteSignalScannerV120_0
{
    use HasPriceTables;

    private string $version = 'v120.0';

    private string $name = 'Elite Multi-Day Momentum';

    // ── Scanner Configuration (public so entry finders can read) ──
    /** @var float Minimum entry score (0-100) */
    public float $entryScoreMin = 70;

    /** @var float Maximum entry score (0-100) */
    public float $entryScoreMax = 95;

    /** @var int Maximum number of top-scored signals to return */
    public int $entryScoreLimit = 80;

    /** @var int Minimum consecutive days for multi-day pattern */
    public int $minConsecutiveDays = 2;

    /** @var float Minimum gap pct between days */
    public float $minGapPct = 2.0;

    /** @var bool Require increasing volume across consecutive days */
    public bool $requireVolIncrease = false;

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
            'entry_score_min' => $this->entryScoreMin,
            'entry_score_max' => $this->entryScoreMax,
            'entry_score_limit' => $this->entryScoreLimit,
            'min_consecutive_days' => $this->minConsecutiveDays,
            'min_gap_pct' => $this->minGapPct,
            'require_vol_increase' => $this->requireVolIncrease,
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

    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 15,
        float $minMovePct = 1.2,
        float $volMult = 3.5,
        int $limit = 60
    ): array {
        // Score range: 70-95 provides good funnel for ML filtering
        // 85-100 for "elite only" mode, 70-95 for workable candidate flow
        $minScore = $this->entryScoreMin;
        $maxScore = $this->entryScoreMax;
        $topN = $this->entryScoreLimit;
        $minConsecutiveDays = $this->minConsecutiveDays;
        $minGapPct = $this->minGapPct;
        $requireVolIncrease = $this->requireVolIncrease;

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
        $tradeDate = substr($asOfTsEst, 0, 10);

        // -----------------------------
        // 1) Get Elite Multi-Day Winners (2+ days up with increasing volume)
        // -----------------------------
        $eliteMovers = $this->getEliteMultiDayMovers(
            $assetType,
            $tradeDate,
            $minConsecutiveDays,
            $requireVolIncrease
        );

        if (empty($eliteMovers)) {
            return [];
        }

        $symbols = array_column($eliteMovers, 'symbol');

        // Add market movers to universe if enabled (top explosive movers from recent days)
        $moversLimit = (int) config('trading.market_movers.pipeline_b', 0);
        if ($moversLimit > 0) {
            $movers = app(\App\Services\MarketMoversService::class)->getTodaysTopMoversFromCache(null, $moversLimit);
            $symbols = array_values(array_unique(array_merge($symbols, $movers)));
        }

        if (empty($symbols)) {
            return [];
        }

        // Build metadata about each stock's history
        $stockMeta = [];
        foreach ($eliteMovers as $mover) {
            $stockMeta[$mover['symbol']] = [
                'consecutive_days' => $mover['consecutive_days'],
                'avg_daily_move' => $mover['avg_daily_move'],
                'vol_trend' => $mover['vol_trend'],
                'yesterday_high' => $mover['yesterday_high'],
                'day_before_high' => $mover['day_before_high'] ?? null,
            ];
        }

        // -----------------------------
        // 2) Check for gap-up continuation patterns
        // -----------------------------
        $gapData = $this->getGapUpData($assetType, $symbols, $tradeDate, $minGapPct);

        // -----------------------------
        // 3) Get 5m momentum breakout candidates
        // -----------------------------
        $placeholders = implode(',', array_fill(0, count($symbols), '?'));

        $sql5m = "
WITH today_data AS (
  SELECT
    f.symbol,
    f.asset_type,
    f.ts_est,
    f.price AS close,
    f.high,
    f.low,
    f.open,
    f.volume,
    f.vwap,
    f.atr_pct
  FROM five_minute_prices f
  WHERE f.asset_type = ?
    AND f.symbol IN ($placeholders)
    AND f.ts_est <= ?
    AND f.trading_date_est = DATE(?)
),
latest_bar AS (
  SELECT
    symbol,
    asset_type,
    MAX(ts_est) AS last_ts_est
  FROM today_data
  GROUP BY symbol, asset_type
),
current_state AS (
  SELECT
    td.symbol,
    td.asset_type,
    td.ts_est AS signal_ts_est,
    td.close,
    td.high,
    td.low,
    td.open,
    td.volume,
    td.vwap,
    td.atr_pct,
    FIRST_VALUE(td.open) OVER (PARTITION BY td.symbol ORDER BY td.ts_est) AS day_open,
    AVG(td.volume) OVER (
      PARTITION BY td.symbol
      ORDER BY td.ts_est
      ROWS BETWEEN 20 PRECEDING AND 1 PRECEDING
    ) AS avg_vol_20bars
  FROM today_data td
  INNER JOIN latest_bar lb ON td.symbol = lb.symbol AND td.ts_est = lb.last_ts_est
),
with_metrics AS (
  SELECT
    cs.*,
    ((cs.close - cs.day_open) / NULLIF(cs.day_open, 0)) * 100 AS move_from_open_pct,
    (cs.volume / NULLIF(cs.avg_vol_20bars, 0)) AS vol_ratio,
    ((cs.close - cs.vwap) / NULLIF(cs.vwap, 0)) * 100 AS vwap_dist_pct
  FROM current_state cs
)
SELECT
  symbol,
  asset_type,
  signal_ts_est,
  close,
  high,
  day_open,
  vwap,
  volume,
  avg_vol_20bars as avg_vol,
  move_from_open_pct,
  vol_ratio,
  vwap_dist_pct,
  atr_pct
FROM with_metrics
WHERE move_from_open_pct >= 0.5
  AND vol_ratio >= 1.2
  AND close >= vwap * 0.995
ORDER BY (move_from_open_pct * COALESCE(vol_ratio, 1.0)) DESC
LIMIT ?
";

        $params5m = array_merge(
            [$assetType],
            $symbols,
            [$asOfTsEst],
            [$asOfTsEst],
            [$limit]
        );

        $rows5m = $this->dbSelect($sql5m, $params5m);

        if (empty($rows5m)) {
            return [];
        }

        // -----------------------------
        // 4) Enhanced momentum scoring with all 6 features
        // -----------------------------

        $cands = [];
        foreach ($rows5m as $r) {
            $symbol = (string) $r->symbol;
            $meta = $stockMeta[$symbol] ?? null;

            if (! $meta) {
                continue; // Skip if we don't have historical data
            }

            $moveFromOpen = (float) $r->move_from_open_pct;
            $volRatio = (float) $r->vol_ratio;
            $vwapDist = (float) $r->vwap_dist_pct;
            $currentPrice = (float) $r->close;
            $dayOpen = (float) $r->day_open;

            // Calculate gap-up (if exists)
            $gapInfo = $gapData[$symbol] ?? null;
            $gapPct = $gapInfo['gap_pct'] ?? 0;
            $holdingGap = $gapInfo['holding_gap'] ?? false;

            // FILTER: If gap exists, must be holding it
            if ($gapPct >= $minGapPct && ! $holdingGap) {
                continue;
            }

            // Enhanced momentum score (0-100)
            $score = 0.0;

            // 1) Multi-day momentum = 35 points (2 days = 20pts, 3+ days = 35pts)
            $consecutiveDays = $meta['consecutive_days'];
            $score += min(35.0, 15.0 * $consecutiveDays);

            // 2) Intraday move = 30 points (1% = 6pts, 5% = 30pts)
            $score += min(30.0, $moveFromOpen * 6);

            // 3) Volume confirmation = 20 points (1.5x = 10pts, 3x+ = 20pts)
            if ($volRatio > 0) {
                $score += min(20.0, max(0, ($volRatio - 1.0) * 15));
            }

            // 4) Gap-up continuation bonus = 15 points (holding 3%+ gap)
            if ($gapPct >= $minGapPct && $holdingGap) {
                $score += min(15.0, 10.0 + ($gapPct * 1.0));
            }

            // Round to nearest integer
            $score = round($score);

            // Determine catalyst likelihood based on move pattern
            $catalystLikelihood = 'unknown';
            if ($gapPct >= 5.0 && $consecutiveDays >= 2) {
                $catalystLikelihood = 'high'; // Big gap + multi-day = likely news/catalyst
            } elseif ($gapPct >= 3.0 || $consecutiveDays >= 3) {
                $catalystLikelihood = 'medium';
            } else {
                $catalystLikelihood = 'low';
            }

            $atrPct = $r->atr_pct ?? null;
            $atr = ($atrPct && $currentPrice) ? round(($atrPct / 100) * $currentPrice, 6) : null;

            $cands[] = [
                'symbol' => $symbol,
                'asset_type' => (string) $r->asset_type,
                'signal_ts_est' => (string) $r->signal_ts_est,
                'score' => $score,
                'move_pct' => $moveFromOpen,
                'vol_ratio' => $volRatio,
                'vwap_dist_pct' => $vwapDist,
                'current_price' => $currentPrice,
                'consecutive_days' => $consecutiveDays,
                'avg_daily_move' => $meta['avg_daily_move'],
                'vol_trend' => $meta['vol_trend'],
                'gap_pct' => $gapPct,
                'holding_gap' => $holdingGap,
                'catalyst_likelihood' => $catalystLikelihood,
                'yesterday_high' => $meta['yesterday_high'],
                'breaking_multi_day_high' => $currentPrice > max($meta['yesterday_high'], $meta['day_before_high'] ?? 0),
                'atr' => $atr,
                'atr_pct' => $atrPct,
            ];
        }

        if (empty($cands)) {
            return [];
        }

        // -----------------------------
        // 5) Filter by score threshold (40-59 sweet spot) and rank
        // -----------------------------
        $ranked = [];
        foreach ($cands as $c) {
            $score = (float) $c['score'];

            // Apply score filter (focus on 40-59 sweet spot)
            if ($score < $minScore || $score > $maxScore) {
                continue;
            }

            $ranked[] = $c;
        }

        if (empty($ranked)) {
            return [];
        }

        // Sort by composite ranking: score * consecutive_days * volume
        usort($ranked, function ($a, $b) {
            $aRank = $a['score'] * $a['consecutive_days'] * min(3, $a['vol_ratio']);
            $bRank = $b['score'] * $b['consecutive_days'] * min(3, $b['vol_ratio']);

            return $bRank <=> $aRank;
        });

        $finalLimit = min($limit, $topN, count($ranked));
        $ranked = array_slice($ranked, 0, $finalLimit);

        // Output signals with enhanced metadata
        $out = [];
        foreach ($ranked as $r) {
            $out[] = [
                'symbol' => (string) $r['symbol'],
                'asset_type' => (string) $r['asset_type'],
                'signal_type' => 'ELITE_MOMENTUM_CONTINUATION',
                'signal_ts_est' => (string) $r['signal_ts_est'],
                'score' => (int) $r['score'],
                'atr' => $r['atr'] ?? null,
                'atr_pct' => $r['atr_pct'] ?? null,
                'meta' => [
                    'version' => $this->version,
                    'move_from_open_pct' => round((float) $r['move_pct'], 2),
                    'vol_ratio' => round((float) $r['vol_ratio'], 2),
                    'vwap_dist_pct' => round((float) $r['vwap_dist_pct'], 2),
                    'current_price' => round((float) $r['current_price'], 2),
                    // Multi-day momentum features
                    'consecutive_up_days' => $r['consecutive_days'],
                    'avg_daily_move_pct' => round((float) $r['avg_daily_move'], 2),
                    'volume_trend' => $r['vol_trend'], // 'increasing', 'decreasing', 'stable'
                    // Gap-up features
                    'gap_pct' => round((float) $r['gap_pct'], 2),
                    'holding_gap' => $r['holding_gap'],
                    // Breakout status
                    'yesterday_high' => round((float) $r['yesterday_high'], 2),
                    'breaking_multi_day_high' => $r['breaking_multi_day_high'],
                    // Catalyst metadata
                    'catalyst_likelihood' => $r['catalyst_likelihood'],
                    'score_min' => $minScore,
                    'score_max' => $maxScore,
                ],
            ];
        }

        return $out;
    }

    /**
     * Get stocks with 2+ consecutive up days with increasing volume
     */
    private function getEliteMultiDayMovers(
        string $assetType,
        string $tradeDate,
        int $minConsecutiveDays,
        bool $requireVolIncrease
    ): array {
        // Get last 5 trading days
        $sql = "
WITH recent_days AS (
  SELECT DISTINCT date
  FROM daily_prices
  WHERE date < ?
    AND asset_type = ?
  ORDER BY date DESC
  LIMIT 5
),
consecutive_movers AS (
  SELECT
    dp.symbol,
    dp.date,
    dp.open,
    dp.price as close,
    dp.high,
    dp.low,
    dp.volume,
    ((dp.price - dp.open) / dp.open * 100) as day_move_pct,
    dp.volume / NULLIF(
      (SELECT AVG(d2.volume)
       FROM daily_prices d2
       WHERE d2.symbol = dp.symbol
         AND d2.asset_type = dp.asset_type
         AND d2.date < dp.date
         AND d2.date >= DATE_SUB(dp.date, INTERVAL 10 DAY)
      ), 0
    ) as vol_ratio,
    ROW_NUMBER() OVER (PARTITION BY dp.symbol ORDER BY dp.date DESC) as day_rank
  FROM daily_prices dp
  INNER JOIN recent_days rd ON dp.date = rd.date
  WHERE dp.asset_type = ?
    AND (dp.price - dp.open) / dp.open >= 0.025
  HAVING vol_ratio >= 1.2
),
streak_analysis AS (
  SELECT
    symbol,
    COUNT(*) as consecutive_days,
    AVG(day_move_pct) as avg_daily_move,
    MIN(day_rank) as min_rank,
    MAX(day_rank) as max_rank,
    MAX(CASE WHEN day_rank = 1 THEN high END) as yesterday_high,
    MAX(CASE WHEN day_rank = 2 THEN high END) as day_before_high,
    -- Check volume trend
    CASE
      WHEN MAX(CASE WHEN day_rank = 1 THEN volume END) > 
           MAX(CASE WHEN day_rank = 2 THEN volume END) * 1.1 THEN 'increasing'
      WHEN MAX(CASE WHEN day_rank = 1 THEN volume END) < 
           MAX(CASE WHEN day_rank = 2 THEN volume END) * 0.9 THEN 'decreasing'
      ELSE 'stable'
    END as vol_trend
  FROM consecutive_movers
  GROUP BY symbol
  HAVING consecutive_days >= ?
    AND (max_rank - min_rank + 1) = consecutive_days
)
SELECT *
FROM streak_analysis
";

        if ($requireVolIncrease) {
            $sql .= " WHERE vol_trend = 'increasing'";
        }

        $sql .= ' ORDER BY consecutive_days DESC, avg_daily_move DESC LIMIT 150';

        $movers = $this->dbSelect($sql, [
            $tradeDate,
            $assetType,
            $assetType,
            $minConsecutiveDays,
        ]);

        return array_map(fn ($m) => (array) $m, $movers);
    }

    /**
     * Get gap-up data and check if stocks are holding the gap
     */
    private function getGapUpData(
        string $assetType,
        array $symbols,
        string $tradeDate,
        float $minGapPct
    ): array {
        if (empty($symbols)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($symbols), '?'));

        $sql = "
        
SELECT
  f.symbol,
  (SELECT f2.open FROM five_minute_prices f2 
   WHERE f2.symbol = f.symbol 
     AND f2.asset_type = f.asset_type 
     AND f2.trading_date_est = ?
   ORDER BY f2.ts_est ASC LIMIT 1) as first_open,
  prev.close as yesterday_close,
  ((SELECT f2.open FROM five_minute_prices f2 
    WHERE f2.symbol = f.symbol 
      AND f2.asset_type = f.asset_type 
      AND f2.trading_date_est = ?
    ORDER BY f2.ts_est ASC LIMIT 1) - prev.close) / prev.close * 100 as gap_pct,
  MIN(f.price) as intraday_low,
  (MIN(f.price) >= prev.close) as holding_gap
FROM five_minute_prices f
INNER JOIN (
  SELECT symbol, price as close
  FROM daily_prices
  WHERE date = (
    SELECT MAX(date) FROM daily_prices 
    WHERE date < ? AND asset_type = ?
  )
  AND asset_type = ?
  AND symbol IN ($placeholders)
) prev ON f.symbol = prev.symbol
WHERE f.asset_type = ?
  AND f.symbol IN ($placeholders)
  AND f.trading_date_est = ?
GROUP BY f.symbol, f.asset_type, prev.close
HAVING gap_pct >= ?
";

        $params = array_merge(
            [$tradeDate, $tradeDate, $tradeDate, $assetType, $assetType],
            $symbols,
            [$assetType],
            $symbols,
            [$tradeDate, $minGapPct]
        );

        $gaps = $this->dbSelect($sql, $params);

        $result = [];
        foreach ($gaps as $gap) {
            $result[$gap->symbol] = [
                'gap_pct' => (float) $gap->gap_pct,
                'holding_gap' => (bool) $gap->holding_gap,
                'intraday_low' => (float) $gap->intraday_low,
                'yesterday_close' => (float) $gap->yesterday_close,
            ];
        }

        return $result;
    }
}
