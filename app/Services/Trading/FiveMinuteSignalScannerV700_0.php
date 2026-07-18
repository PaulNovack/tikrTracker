<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * Version 700.0 - Risk-Off Winners / 4-Green-Days Style Picker (LONG)
 *
 * This replaces the old "VWAP Rejection Short Scanner" logic but keeps the same class + scan() signature.
 *
 * Goal: Identify symbols that can stay green across risk-off tape:
 * - Persistently above VWAP (time-above-VWAP)
 * - 5m trend up (EMA9 > EMA21)
 * - Relative Strength vs market proxy (QQQ/ONEQ) over the lookback window
 * - Controlled pullbacks / net progress (not choppy)
 *
 * IMPORTANT:
 * - This will naturally pick inverse/leveraged products during Nasdaq selloffs, because their RS vs market is huge.
 *   You can allow or exclude those using config flags.
 *
 * ENV / config('trading.*'):
 * - v700.entry_score_min / v700.entry_score_max
 * - v700.entry_score_limit
 *
 * - v700.market_proxy_symbol (default ONEQ) // Nasdaq proxy for RS
 * - v700.require_market_risk_off (default false) // If true, only pick when proxy below VWAP
 * - v700.allow_leveraged_inverse (default true) // If false, tries to exclude common patterns (best effort)
 *
 * - v700.min_above_vwap_bars (default 7) // ~35 min at 5m
 * - v700.min_rs_pct (default 0.80) // RS vs proxy over lookback window (percentage points)
 * - v700.min_vol_ratio (default 1.10) // last bar volume / avg volume in window
 * - v700.min_atr_pct (default 0.20)
 * - v700.min_rsi (default 50)
 * - v700.min_net_progress (default 0.12) // avoids chop (0..1)
 */
class FiveMinuteSignalScannerV700_0
{
    use HasPriceTables;

    private string $version = 'v700.0';

    private string $name = 'Risk-Off Winners';

    // ── Scanner Configuration (public so entry finders can read) ──
    /** @var float Minimum entry score (0-100) */
    public float $entryScoreMin = 80;

    /** @var float Maximum entry score (0-100) */
    public float $entryScoreMax = 100;

    /** @var int Max number of signals to return */
    public int $entryScoreLimit = 15;

    /** @var string Market proxy symbol for RS calculation */
    public string $marketProxySymbol = 'ONEQ';

    /** @var bool Only pick when proxy is below VWAP (risk-off mode) */
    public bool $requireMarketRiskOff = false;

    /** @var bool Allow leveraged/inverse products */
    public bool $allowLeveragedInverse = true;

    /** @var int Min 5m bars above VWAP for persistence */
    public int $minAboveVwapBars = 7;

    /** @var float Min relative strength % vs market proxy */
    public float $minRsPct = 0.80;

    /** @var float Minimum volume ratio vs average */
    public float $minVolRatio = 1.10;

    /** @var float Minimum ATR% for volatility */
    public float $minAtrPct = 0.20;

    /** @var float Minimum RSI reading */
    public float $minRsi = 50;

    /** @var float Minimum net progress (0-1, higher = less choppy) */
    public float $minNetProgress = 0.12;

    /** @var float Minimum volume vs 20-bar average (active stock filter) */
    public float $minVolMult = 0.5;

    /** @var float Minimum share price */
    public float $minPrice = 3.0;

    /** @var float Maximum share price */
    public float $maxPrice = 500.0;

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
            'entry_score_min' => $this->entryScoreMin,
            'entry_score_max' => $this->entryScoreMax,
            'entry_score_limit' => $this->entryScoreLimit,
            'market_proxy_symbol' => $this->marketProxySymbol,
            'require_market_risk_off' => $this->requireMarketRiskOff,
            'allow_leveraged_inverse' => $this->allowLeveragedInverse,
            'min_above_vwap_bars' => $this->minAboveVwapBars,
            'min_rs_pct' => $this->minRsPct,
            'min_vol_ratio' => $this->minVolRatio,
            'min_atr_pct' => $this->minAtrPct,
            'min_rsi' => $this->minRsi,
            'min_net_progress' => $this->minNetProgress,
            'min_vol_mult' => $this->minVolMult,
            'min_price' => $this->minPrice,
            'max_price' => $this->maxPrice,
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
     * Scan for Risk-Off Winners candidates (LONG)
     */
    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minMovePct = -0.5,
        float $volMult = 1.0,
        int $limit = 30
    ): array {
        $minScore = $this->entryScoreMin;
        $maxScore = $this->entryScoreMax;
        $topN = $this->entryScoreLimit;

        // NEW: market proxy + gates
        $marketProxy = $this->marketProxySymbol;
        $requireMarketRiskOff = $this->requireMarketRiskOff;
        $allowLeveragedInverse = $this->allowLeveragedInverse;

        // NEW: RS / strength filters
        $minAboveVwapBars = $this->minAboveVwapBars;
        $minRsPct = $this->minRsPct;
        $minVolRatio = $this->minVolRatio;
        $minAtrPct = $this->minAtrPct;
        $minRsi = $this->minRsi;
        $minNetProgress = $this->minNetProgress;

        if ($topN <= 0) {
            $topN = 15;
        }
        if ($maxScore <= 0) {
            $maxScore = 100.0;
        }
        if ($minScore > $maxScore) {
            [$minScore, $maxScore] = [$maxScore, $minScore];
        }

        $lookbackMinutes = max(30, (int) $lookbackMinutes);
        $limit = max(1, (int) $limit);

        $tradeDate = substr($asOfTsEst, 0, 10);

        // Universe: reuse existing "weak stocks" function but broaden it for winners.
        // We keep the function name for compatibility, but internally it now returns "active liquid symbols".
        $activeStocks = $this->getWeakStocks($assetType, $tradeDate, $asOfTsEst, $lookbackMinutes);

        if (empty($activeStocks)) {
            return [];
        }

        $symbols = array_values(array_unique(array_column($activeStocks, 'symbol')));
        if (empty($symbols)) {
            return [];
        }

        // Optional: exclude leveraged/inverse products (best effort pattern match)
        if (! $allowLeveragedInverse) {
            $symbols = array_values(array_filter($symbols, function ($s) {
                $s = strtoupper((string) $s);
                // Very rough filter (you can customize)
                // Ex: many single-stock inverse ETFs end with Z (IONZ/PLTZ/QBTZ/RGTZ)
                if (str_ends_with($s, 'Z')) {
                    return false;
                }

                // GraniteShares / Defiance style: sometimes 2x short tickers have patterns like CONI/TSLQ/etc.
                // Can't reliably detect without a reference table, so keep minimal.
                return true;
            }));
        }

        if (empty($symbols)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($symbols), '?'));

        // Market tape gate: proxy below VWAP?
        $marketRiskOff = $this->isSpyBelowVwap($asOfTsEst, $marketProxy);
        if ($requireMarketRiskOff && ! $marketRiskOff) {
            return [];
        }

        $sql5m = "
WITH sym_bars AS (
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
    f.atr_pct,
    f.rsi_14,
    f.ema9_above_ema21,
    CASE WHEN f.price > f.vwap THEN 1 ELSE 0 END AS above_vwap
  FROM five_minute_prices f
  WHERE f.asset_type = ?
    AND f.symbol IN ($placeholders)
    AND f.ts_est <= ?
    AND f.trading_date_est = DATE(?)
    AND f.ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
),
mkt_bars AS (
  SELECT
    ts_est,
    price AS m_close
  FROM five_minute_prices
  WHERE asset_type = 'stock'
    AND symbol = ?
    AND trading_date_est = DATE(?)
    AND ts_est <= ?
    AND ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
),
aligned AS (
  SELECT
    s.*,
    m.m_close
  FROM sym_bars s
  LEFT JOIN mkt_bars m
    ON m.ts_est = s.ts_est
),
latest_bar AS (
  SELECT symbol, asset_type, MAX(ts_est) AS last_ts_est
  FROM aligned
  GROUP BY symbol, asset_type
),
window_calcs AS (
  SELECT
    a.symbol,
    a.asset_type,
    a.ts_est,
    a.close,
    a.m_close,
    a.volume,
    a.low,
    a.high,
    a.above_vwap,
    FIRST_VALUE(a.close) OVER (PARTITION BY a.symbol, a.asset_type ORDER BY a.ts_est) AS first_close,
    LAST_VALUE(a.close) OVER (
      PARTITION BY a.symbol, a.asset_type
      ORDER BY a.ts_est
      ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING
    ) AS last_close,
    FIRST_VALUE(a.m_close) OVER (PARTITION BY a.symbol, a.asset_type ORDER BY a.ts_est) AS m_first_close,
    LAST_VALUE(a.m_close) OVER (
      PARTITION BY a.symbol, a.asset_type
      ORDER BY a.ts_est
      ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING
    ) AS m_last_close
  FROM aligned a
),
window_stats AS (
  SELECT
    wc.symbol,
    wc.asset_type,
    SUM(wc.above_vwap) AS bars_above_vwap,
    COUNT(*) AS total_bars,
    AVG(wc.volume) AS avg_vol,
    MIN(wc.low) AS window_low,
    MAX(wc.high) AS window_high,
    MIN(wc.first_close) AS first_close,
    MIN(wc.last_close) AS last_close,
    MIN(wc.m_first_close) AS m_first_close,
    MIN(wc.m_last_close) AS m_last_close
  FROM window_calcs wc
  GROUP BY wc.symbol, wc.asset_type
),
chop AS (
  SELECT
    a.symbol,
    a.asset_type,
    SUM(CASE WHEN a.close >= a.open THEN 1 ELSE 0 END) AS green_bars,
    SUM((a.high - a.low)) AS total_range,
    MIN(a.ts_est) AS start_ts,
    MAX(a.ts_est) AS end_ts
  FROM aligned a
  GROUP BY a.symbol, a.asset_type
),
current AS (
  SELECT
    a.symbol,
    a.asset_type,
    a.ts_est AS signal_ts_est,
    a.close,
    a.high,
    a.low,
    a.vwap,
    a.volume,
    a.atr_pct,
    a.rsi_14,
    a.ema9_above_ema21,
    ws.bars_above_vwap,
    ws.total_bars,
    ws.avg_vol,
    ws.first_close,
    ws.last_close,
    ws.m_first_close,
    ws.m_last_close,
    (a.volume / NULLIF(ws.avg_vol, 0)) AS vol_ratio,
    (
      ((ws.last_close / NULLIF(ws.first_close, 0)) - 1.0)
      -
      ((ws.m_last_close / NULLIF(ws.m_first_close, 0)) - 1.0)
    ) * 100.0 AS rs_pct,
    c.green_bars,
    c.total_range
  FROM aligned a
  INNER JOIN latest_bar lb
    ON a.symbol = lb.symbol
   AND a.asset_type = lb.asset_type
   AND a.ts_est = lb.last_ts_est
  INNER JOIN window_stats ws
    ON ws.symbol = a.symbol
   AND ws.asset_type = a.asset_type
  INNER JOIN chop c
    ON c.symbol = a.symbol
   AND c.asset_type = a.asset_type
  WHERE a.close >= a.vwap
)
SELECT
  symbol,
  asset_type,
  signal_ts_est,
  close,
  high,
  low,
  vwap,
  volume,
  atr_pct,
  rsi_14,
  ema9_above_ema21,
  bars_above_vwap,
  total_bars,
  vol_ratio,
  rs_pct,
  green_bars,
  total_range
FROM current
WHERE bars_above_vwap >= ?
  AND (rs_pct >= ? OR rs_pct IS NULL)
  AND (vol_ratio >= ? OR vol_ratio IS NULL)
  AND (atr_pct >= ? OR atr_pct IS NULL)
  AND (rsi_14 >= ? OR rsi_14 IS NULL)
LIMIT ?
";

        $params5m = array_merge(
            [$assetType],
            $symbols,
            [$asOfTsEst, $asOfTsEst, $asOfTsEst, $lookbackMinutes],
            [$marketProxy, $asOfTsEst, $asOfTsEst, $asOfTsEst, $lookbackMinutes],
            [$minAboveVwapBars, $minRsPct, $minVolRatio, $minAtrPct, $minRsi],
            [$limit * 3]
        );

        $rows5m = $this->dbSelect($sql5m, $params5m);
        if (empty($rows5m)) {
            return [];
        }

        // Score candidates
        $cands = [];
        foreach ($rows5m as $r) {
            $symbol = (string) $r->symbol;

            $barsAbove = (int) ($r->bars_above_vwap ?? 0);
            $totalBars = (int) ($r->total_bars ?? 0);
            $timeAbovePct = ($totalBars > 0) ? ($barsAbove / $totalBars) : 0.0;

            $rsPct = $r->rs_pct !== null ? (float) $r->rs_pct : 0.0;
            $volRatio = $r->vol_ratio !== null ? (float) $r->vol_ratio : 1.0;
            $atrPct = $r->atr_pct !== null ? (float) $r->atr_pct : null;
            $rsi = $r->rsi_14 !== null ? (float) $r->rsi_14 : null;

            // Net progress: abs net move / total range over window (0..1)
            // We approximate using last close vs VWAP distance and green bars; if you store more window metrics you can improve.
            // We'll compute a quick net progress proxy using (close-low)/(high-low) and green bar ratio.
            $high = (float) ($r->high ?? 0);
            $low = (float) ($r->low ?? 0);
            $close = (float) ($r->close ?? 0);
            $range = max(1e-9, ($high - $low));
            $posInBar = ($close - $low) / $range; // closer to 1 is strong

            $greenBars = (int) ($r->green_bars ?? 0);
            $greenPct = ($totalBars > 0) ? ($greenBars / $totalBars) : 0.0;

            $score = 0.0;

            // A) Relative strength (0..35)
            // More forgiving: start scoring from 0%, not 0.5%
            $score += min(35.0, max(0.0, $rsPct * 7.0));

            // B) Time above VWAP (0..25)
            // More forgiving: start scoring from 30% above VWAP, not 55%
            $score += min(25.0, max(0.0, ($timeAbovePct - 0.30) * 35.0));

            // C) Volume confirmation (0..15)
            // More forgiving: start scoring from 0.7x, not 1.0x
            $score += min(15.0, max(0.0, ($volRatio - 0.7) * 18.75));

            // D) ATR% (0..10)
            if ($atrPct !== null) {
                $score += min(10.0, max(0.0, ($atrPct - 0.10) * 20.0));
            } else {
                $score += 4.0;
            }

            // E) RSI strength (0..8)
            if ($rsi !== null) {
                // More forgiving: 40 => 0, 50 => 2.5, 60 => 5, 70 => 7.5
                $score += min(8.0, max(0.0, ($rsi - 40.0) * 0.25));
            } else {
                $score += 3.0;
            }

            // F) Bar strength proxy + green ratio (0..7)
            $score += min(7.0, max(0.0, ($posInBar * 4.0) + ($greenPct * 3.0) - 2.5));

            // G) Market risk-off alignment bonus (0..5)
            $score += $marketRiskOff ? 5.0 : 0.0;

            $score = round(min(100.0, $score));

            $atr = ($atrPct && $close) ? round(($atrPct / 100) * $close, 6) : null;

            // Net progress filter: approximate; if you have better precomputed metrics, swap in here.
            $netProgressApprox = ($posInBar + $greenPct) / 2.0;
            if ($netProgressApprox < $minNetProgress) {
                continue;
            }

            $cands[] = [
                'symbol' => $symbol,
                'asset_type' => (string) $r->asset_type,
                'signal_ts_est' => (string) $r->signal_ts_est,
                'score' => $score,
                'rs_pct' => $rsPct,
                'time_above_vwap_pct' => $timeAbovePct * 100.0,
                'vol_ratio' => $volRatio,
                'atr' => $atr,
                'atr_pct' => $atrPct,
                'rsi_14' => $rsi,
                'current_price' => $close,
                'vwap' => (float) ($r->vwap ?? 0),
                'market_proxy' => $marketProxy,
                'market_risk_off' => $marketRiskOff,
                'net_progress_approx' => $netProgressApprox,
            ];
        }

        if (empty($cands)) {
            return [];
        }

        // Filter by score window and rank
        $ranked = [];
        foreach ($cands as $c) {
            $s = (float) $c['score'];
            if ($s < $minScore || $s > $maxScore) {
                continue;
            }
            $ranked[] = $c;
        }

        if (empty($ranked)) {
            return [];
        }

        usort($ranked, fn ($a, $b) => ($b['score'] <=> $a['score']));

        $finalLimit = min($limit, $topN, count($ranked));
        $ranked = array_slice($ranked, 0, $finalLimit);

        // Format output
        $out = [];
        foreach ($ranked as $r) {
            $out[] = [
                'symbol' => (string) $r['symbol'],
                'asset_type' => (string) $r['asset_type'],
                'signal_type' => 'RISK_OFF_WINNER_LONG',
                'signal_ts_est' => (string) $r['signal_ts_est'],
                'score' => (int) $r['score'],
                'atr' => $r['atr'] ?? null,
                'atr_pct' => $r['atr_pct'] ?? null,
                'meta' => [
                    'version' => $this->version,
                    'goal' => 'risk-off winners long candidates (4-green-days style)',
                    'market_proxy' => $r['market_proxy'],
                    'market_risk_off' => (bool) $r['market_risk_off'],
                    'rs_pct' => round((float) $r['rs_pct'], 2),
                    'time_above_vwap_pct' => round((float) $r['time_above_vwap_pct'], 1),
                    'vol_ratio' => round((float) $r['vol_ratio'], 2),
                    'net_progress_approx' => round((float) $r['net_progress_approx'], 3),
                    'current_price' => round((float) $r['current_price'], 4),
                    'vwap' => round((float) $r['vwap'], 4),
                    'rsi_14' => $r['rsi_14'] ? round((float) $r['rsi_14'], 1) : null,
                    'score_min' => $minScore,
                    'score_max' => $maxScore,
                ],
            ];
        }

        return $out;
    }

    /**
     * REUSED NAME (compatibility): now returns "active liquid symbols" instead of "weak stocks".
     */
    private function getWeakStocks(
        string $assetType,
        string $tradeDate,
        string $asOfTsEst,
        int $lookbackMinutes
    ): array {
        // Re-purpose filters for a broader active universe
        $minVolMult = $this->minVolMult;
        $minPrice = $this->minPrice;
        $maxPrice = $this->maxPrice;

        // Instead of requiring down move, just require activity + sane price
        $sql = '
SELECT DISTINCT
    f.symbol,
    f.asset_type,
    MAX(f.price) AS current_price
FROM five_minute_prices f
WHERE f.asset_type = ?
    AND f.trading_date_est = DATE(?)
    AND f.ts_est <= ?
    AND f.ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
    AND f.price >= ?
    AND f.price <= ?
GROUP BY f.symbol, f.asset_type
HAVING COUNT(*) >= 5
ORDER BY MAX(f.price) DESC
LIMIT 1200
';

        $params = [
            $assetType,
            $asOfTsEst,
            $asOfTsEst,
            $asOfTsEst,
            $lookbackMinutes,
            $minPrice,
            $maxPrice,
        ];

        $results = $this->dbSelect($sql, $params);

        return array_map(fn ($r) => (array) $r, $results);
    }

    /**
     * REUSED NAME (compatibility): checks proxy below VWAP (risk-off tape confirmation).
     * You can pass ONEQ/QQQ/etc.
     */
    private function isSpyBelowVwap(string $asOfTsEst, string $proxySymbol = 'ONEQ'): bool
    {
        $result = DB::selectOne("
            SELECT price, vwap
            FROM five_minute_prices
            WHERE symbol = ?
              AND asset_type = 'stock'
              AND ts_est <= ?
            ORDER BY ts_est DESC
            LIMIT 1
        ", [$proxySymbol, $asOfTsEst]);

        if (! $result || ! $result->vwap) {
            return false;
        }

        return (float) $result->price < (float) $result->vwap;
    }
}
