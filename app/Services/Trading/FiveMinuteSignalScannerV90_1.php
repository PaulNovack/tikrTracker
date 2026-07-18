<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\DB;

/**
 * Version 90.0 - Momentum Continuation Scanner
 *
 * Catches multi-day runners by identifying:
 * - Stocks that moved 5%+ yesterday with volume
 * - Continuing momentum today (breaking yesterday's high)
 * - Volume confirmation on current bars
 *
 * ENV / config('trading.*'):
 * - V90_ENTRY_SCORE_MIN / V90_ENTRY_SCORE_MAX: score window
 * - V90_ENTRY_SCORE_LIMIT: max picks returned
 * - V90_MIN_YESTERDAY_MOVE: minimum yesterday's move % (default 5.0)
 * - V90_MIN_VOL_MULT: minimum volume multiplier vs 5-day avg (default 1.5)
 */
class FiveMinuteSignalScannerV90_1
{
    use HasPriceTables;

    private string $version = 'v90.1';

    private string $name = 'Momentum Continuation';

    // ── Scanner Configuration (public so entry finders can read) ──
    public float $entryScoreMin = 93;

    public float $entryScoreMax = 100;

    public int $entryScoreLimit = 80;

    public float $minYesterdayMove = 5.0;

    public float $minVolMult = 1.5;

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
            'entry_score_min' => $this->entryScoreMin,
            'entry_score_max' => $this->entryScoreMax,
            'entry_score_limit' => $this->entryScoreLimit,
            'min_yesterday_move' => $this->minYesterdayMove,
            'min_vol_mult' => $this->minVolMult,
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
     * - $minMovePct: 0.5+
     * - $volMult: 2.0–4.0
     */
    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 15,
        float $minMovePct = 1.2,
        float $volMult = 3.5,
        int $limit = 60
    ): array {
        $minScore = $this->entryScoreMin;
        $maxScore = $this->entryScoreMax;
        $topN = $this->entryScoreLimit;
        $minYesterdayMove = $this->minYesterdayMove;
        $minVolMult = $this->minVolMult;

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
        // 1) Get Yesterday's Big Movers (5%+ gainers with volume)
        // -----------------------------
        $yesterdayGainers = $this->getYesterdaysBigMovers(
            $assetType,
            $tradeDate,
            $minYesterdayMove,
            $minVolMult
        );

        if (empty($yesterdayGainers)) {
            return [];
        }

        $symbols = array_column($yesterdayGainers, 'symbol');

        // Add market movers to universe if enabled (top explosive movers from recent days)
        $moversLimit = (int) config('trading.market_movers.pipeline_a', 0);
        if ($moversLimit > 0) {
            $movers = app(\App\Services\MarketMoversService::class)->getTodaysTopMoversFromCache(null, $moversLimit);
            $symbols = array_values(array_unique(array_merge($symbols, $movers)));
        }

        if (empty($symbols)) {
            return [];
        }

        // -----------------------------
        // 2) Get yesterday's high prices for breakout detection
        // -----------------------------
        $placeholders = implode(',', array_fill(0, count($symbols), '?'));

        $yesterdayHighs = $this->dbSelect("
            SELECT symbol, high as yesterday_high
            FROM daily_prices
            WHERE date = (
                SELECT MAX(date) FROM daily_prices 
                WHERE date < ? AND asset_type = ?
            )
            AND asset_type = ?
            AND symbol IN ($placeholders)
        ", array_merge([$tradeDate, $assetType, $assetType], $symbols));

        $yesterdayHighBySymbol = [];
        foreach ($yesterdayHighs as $row) {
            $yesterdayHighBySymbol[$row->symbol] = (float) $row->yesterday_high;
        }

        // -----------------------------
        // 3) 5m momentum breakout candidates
        // -----------------------------
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
)
SELECT
  symbol,
  asset_type,
  signal_ts_est,
  close,
  high,
  vwap,
  day_open,
  volume,
  avg_vol_20bars as avg_vol,
    ((close - day_open) / NULLIF(day_open, 0)) * 100 AS move_from_open_pct,
    (volume / NULLIF(avg_vol_20bars, 0)) AS vol_ratio,
    ((close - vwap) / NULLIF(vwap, 0)) * 100 AS vwap_dist_pct,
  atr_pct
FROM current_state
WHERE ((close - day_open) / NULLIF(day_open, 0)) * 100 >= 1.0
    AND ((volume / NULLIF(avg_vol_20bars, 0)) >= 0.8 OR avg_vol_20bars IS NULL)
    AND close >= vwap * 0.98
ORDER BY ((((close - day_open) / NULLIF(day_open, 0)) * 100) * COALESCE((volume / NULLIF(avg_vol_20bars, 0)), 1.0)) DESC
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
        // 4) Score momentum breakouts
        // -----------------------------
        $spyMovePct = $this->getSpyMovement($asOfTsEst, $lookbackMinutes);

        $cands = [];
        foreach ($rows5m as $r) {
            $symbol = (string) $r->symbol;
            $moveFromOpen = (float) $r->move_from_open_pct;
            $volRatio = (float) $r->vol_ratio;
            $vwapDist = (float) $r->vwap_dist_pct;
            $yesterdayHigh = $yesterdayHighBySymbol[$symbol] ?? null;
            $currentPrice = (float) $r->close;

            // Momentum breakout score (0-100)
            $score = 0.0;

            // 1) Intraday move from open = 35 points (1% = 7pts, 5% = 35pts)
            $score += min(35.0, $moveFromOpen * 7);

            // 2) Volume confirmation = 25 points (0.8x = 0pts, 1.5x = 15pts, 3x+ = 25pts)
            if ($volRatio > 0) {
                $score += min(25.0, max(0, ($volRatio - 0.8) * 15));
            }

            // 3) Price strength vs VWAP = 20 points (at VWAP = 10pts, 2% above = 20pts)
            $score += min(20.0, max(0, 10 + ($vwapDist * 5)));

            // 4) Breaking yesterday's high = 15 bonus points
            if ($yesterdayHigh && $currentPrice > $yesterdayHigh) {
                $breakoutPct = (($currentPrice - $yesterdayHigh) / $yesterdayHigh) * 100;
                $score += min(15.0, 10.0 + ($breakoutPct * 5));
            } elseif ($yesterdayHigh && $currentPrice >= $yesterdayHigh * 0.95) {
                // Near yesterday's high (within 5%) = 5 bonus points
                $score += 5.0;
            }

            // 5) Relative strength vs SPY = 5 points
            if ($spyMovePct > 0) {
                $rsMult = $moveFromOpen / $spyMovePct;
                $score += min(5.0, max(0, ($rsMult - 0.5) * 2.5));
            } else {
                $score += 5.0; // Full points if SPY is flat/down
            }

            // Round to nearest integer
            $score = round($score);

            $atrPct = $r->atr_pct ?? null;
            $atr = ($atrPct && $currentPrice) ? round(($atrPct / 100) * $currentPrice, 6) : null;

            $cands[] = [
                'symbol' => $symbol,
                'asset_type' => (string) $r->asset_type,
                'signal_ts_est' => (string) $r->signal_ts_est,
                'move_pct' => $moveFromOpen,
                'vol_ratio' => $volRatio,
                'vwap_dist_pct' => $vwapDist,
                'score' => $score,
                'yesterday_high' => $yesterdayHigh,
                'current_price' => $currentPrice,
                'breaking_high' => $yesterdayHigh && $currentPrice > $yesterdayHigh,
                'atr' => $atr,
                'atr_pct' => $atrPct,
            ];
        }

        if (empty($cands)) {
            return [];
        }

        // -----------------------------
        // 5) Filter by score threshold and rank
        // -----------------------------
        $ranked = [];
        foreach ($cands as $c) {
            $score = (float) $c['score'];

            // Apply score filter
            if ($score < $minScore || $score > $maxScore) {
                continue;
            }

            $ranked[] = $c;
        }

        if (empty($ranked)) {
            return [];
        }

        // Sort by score descending
        usort($ranked, fn ($a, $b) => ($b['score'] <=> $a['score']));

        $finalLimit = min($limit, $topN, count($ranked));
        $ranked = array_slice($ranked, 0, $finalLimit);

        // Output signals
        $out = [];
        foreach ($ranked as $r) {
            $out[] = [
                'symbol' => (string) $r['symbol'],
                'asset_type' => (string) $r['asset_type'],
                'signal_type' => 'MOMENTUM_BREAKOUT',
                'signal_ts_est' => (string) $r['signal_ts_est'],
                'score' => (int) $r['score'],
                'atr' => $r['atr'] ?? null,
                'atr_pct' => $r['atr_pct'] ?? null,
                'meta' => [
                    'version' => $this->version,
                    'move_from_open_pct' => round((float) $r['move_pct'], 2),
                    'vol_ratio' => round((float) $r['vol_ratio'], 2),
                    'vwap_dist_pct' => round((float) $r['vwap_dist_pct'], 2),
                    'breaking_yesterday_high' => $r['breaking_high'],
                    'yesterday_high' => round((float) $r['yesterday_high'], 2),
                    'current_price' => round((float) $r['current_price'], 2),
                    'score_min' => $minScore,
                    'score_max' => $maxScore,
                    'spy_move_pct' => round((float) $spyMovePct, 2),
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
     * Get yesterday's big movers (5%+ with volume) as momentum candidates
     */
    private function getYesterdaysBigMovers(
        string $assetType,
        string $tradeDate,
        float $minMovePct,
        float $minVolMult
    ): array {
        // Get yesterday's trading date
        $yesterday = DB::selectOne('
            SELECT MAX(date) as prev_date
            FROM daily_prices
            WHERE date < ?
                AND asset_type = ?
        ', [$tradeDate, $assetType]);

        if (! $yesterday || ! $yesterday->prev_date) {
            return [];
        }

        $yesterdayDate = $yesterday->prev_date;

        // Find stocks that moved 5%+ yesterday with volume expansion
        $movers = $this->dbSelect('
            SELECT 
                dp.symbol,
                dp.open,
                dp.price as close,
                dp.high,
                dp.low,
                dp.volume as yesterday_volume,
                ROUND((dp.price - dp.open) / dp.open * 100, 2) as move_pct,
                ROUND((dp.high - dp.low) / dp.low * 100, 2) as range_pct,
                (
                    SELECT AVG(d2.volume)
                    FROM daily_prices d2
                    WHERE d2.symbol = dp.symbol
                        AND d2.asset_type = dp.asset_type
                        AND d2.date < dp.date
                        AND d2.date >= DATE_SUB(dp.date, INTERVAL 5 DAY)
                ) as avg_volume_5d
            FROM daily_prices dp
            WHERE dp.date = ?
                AND dp.asset_type = ?
                AND (dp.price - dp.open) / dp.open * 100 >= ?
            HAVING avg_volume_5d > 0
                AND dp.volume / avg_volume_5d >= ?
            ORDER BY move_pct DESC
            LIMIT 100
        ', [$yesterdayDate, $assetType, $minMovePct, $minVolMult]);

        return array_map(fn ($m) => (array) $m, $movers);
    }
}
