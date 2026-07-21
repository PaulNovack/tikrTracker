<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\DB;

/**
 * Version 600.0 - Momentum Continuation "3-5% Feasible" Scanner
 *
 * Goal: produce fewer, higher-quality candidates that can realistically run 3–5%+
 *
 * What it targets:
 * - Stocks with strong intraday continuation + volume
 * - Preferably breaking yesterday's high
 * - Enough ATR% / range to make 3–5% achievable
 *
 * ENV / config('trading.*'):
 * - v600.entry_score_min / v600.entry_score_max
 * - v600.entry_score_limit
 * - v600.min_yesterday_move (default 5.0)
 * - v600.min_vol_mult (default 1.5)
 *
 * New v600 feasibility gates:
 * - v600.min_move_from_open (default 1.25)  // stronger than v90
 * - v600.min_vol_ratio (default 1.35)
 * - v600.min_atr_pct (default 0.35)         // must be able to move
 * - v600.max_vwap_dist_below (default 2.0)  // allow close slightly below vwap, but not much
 */
class FiveMinuteSignalScannerV600_0
{
    use HasPriceTables;

    private string $version = 'v600.0';

    private string $name = 'Hybrid Big-Move Breakout';

    // ── Scanner Configuration (public so entry finders can read) ──
    /** @var float Minimum entry score (0-100) */
    public float $entryScoreMin = 40;

    /** @var float Maximum entry score (0-100) */
    public float $entryScoreMax = 100;

    /** @var int Max number of signals to return */
    public int $entryScoreLimit = 25;

    /** @var float Minimum yesterday move % */
    public float $minYesterdayMove = 5.0;

    /** @var float Minimum volume multiplier vs average */
    public float $minVolMult = 1.5;

    /** @var float Minimum % move from open for 3-5% big-move feasibility */
    public float $minMoveFromOpen = 1.8;

    /** @var float Minimum volume ratio vs 20-bar average */
    public float $minVolRatio = 1.25;

    /** @var float Minimum ATR% for sufficient volatility */
    public float $minAtrPct = 0.10;

    /** @var float Max % below VWAP allowed */
    public float $maxVwapBelowPct = 1.0;

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
            'entry_score_min' => $this->entryScoreMin,
            'entry_score_max' => $this->entryScoreMax,
            'entry_score_limit' => $this->entryScoreLimit,
            'min_yesterday_move' => $this->minYesterdayMove,
            'min_vol_mult' => $this->minVolMult,
            'min_move_from_open' => $this->minMoveFromOpen,
            'min_vol_ratio' => $this->minVolRatio,
            'min_atr_pct' => $this->minAtrPct,
            'max_vwap_below_pct' => $this->maxVwapBelowPct,
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

        // New feasibility filters for 3-5% movers (tightened)
        $minMoveFromOpen = $this->minMoveFromOpen;
        $minVolRatio = $this->minVolRatio;
        $minAtrPct = $this->minAtrPct;
        $maxVwapBelowPct = $this->maxVwapBelowPct; // close >= vwap*(1 - 0.01)

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

        // 1) Yesterday big movers
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
        $moversLimit = (int) config('trading.market_movers.pipeline_c', 0);
        if ($moversLimit > 0) {
            $movers = app(\App\Services\MarketMoversService::class)->getTodaysTopMoversFromCache(null, $moversLimit);
            $symbols = array_values(array_unique(array_merge($symbols, $movers)));
        }

        if (empty($symbols)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($symbols), '?'));

        // 2) Yesterday highs
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

        // 3) 5m candidates (stronger momentum + feasibility filters)
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
  SELECT symbol, asset_type, MAX(ts_est) AS last_ts_est
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
    ) AS avg_vol_20bars,
    MAX(td.high) OVER (PARTITION BY td.symbol ORDER BY td.ts_est ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS hod
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
    ((hod - close) / NULLIF(close, 0)) * 100 AS dist_to_hod_pct,
  atr_pct
FROM current_state
WHERE ((close - day_open) / NULLIF(day_open, 0)) * 100 >= ?
    AND ((volume / NULLIF(avg_vol_20bars, 0)) >= ? OR avg_vol_20bars IS NULL)
    AND (atr_pct >= ? OR atr_pct IS NULL)
    AND close >= vwap * (1 - (? / 100.0))
    AND (((hod - close) / NULLIF(close, 0)) * 100) <= 0.60
ORDER BY
    ((((close - day_open) / NULLIF(day_open, 0)) * 100) * COALESCE((volume / NULLIF(avg_vol_20bars, 0)), 1.0)) DESC,
    atr_pct DESC
LIMIT ?
";

        $params5m = array_merge(
            [$assetType],
            $symbols,
            [$asOfTsEst],
            [$asOfTsEst],
            [$minMoveFromOpen, $minVolRatio, $minAtrPct, $maxVwapBelowPct],
            [$limit]
        );

        $rows5m = $this->dbSelect($sql5m, $params5m);
        if (empty($rows5m)) {
            return [];
        }

        // 4) Score candidates (weighted toward "big move readiness")
        $spyMovePct = $this->getSpyMovement($asOfTsEst, $lookbackMinutes);

        $cands = [];
        foreach ($rows5m as $r) {
            $symbol = (string) $r->symbol;
            $moveFromOpen = (float) $r->move_from_open_pct;
            $volRatio = (float) ($r->vol_ratio ?? 0);
            $vwapDist = (float) ($r->vwap_dist_pct ?? 0);
            $atrPct = $r->atr_pct !== null ? (float) $r->atr_pct : null;

            // QQQM filter: Require market clearly bullish AND stock outperforming (optional)
            $enableRsFilter = (bool) config('trading.enable_relative_strength_filter', false);
            if ($enableRsFilter) {
                if ($spyMovePct <= 0.3) {
                    continue; // Skip if QQQM not clearly bullish (need > +0.3%)
                }
                if ($moveFromOpen < $spyMovePct * 1.5) {
                    continue; // Stock must be 150%+ of QQQM move
                }
            }

            $yesterdayHigh = $yesterdayHighBySymbol[$symbol] ?? null;
            $currentPrice = (float) $r->close;

            $score = 0.0;

            // A) Move from open (0..40)
            // stronger: 1.25% => ~10, 3% => ~24, 5% => 40
            $score += min(40.0, max(0.0, ($moveFromOpen - 1.0) * 8.0));

            // B) Volume (0..25)
            // 1.35x => small, 2.0x => good, 3.5x => max
            if ($volRatio > 0) {
                $score += min(25.0, max(0.0, ($volRatio - 1.1) * 10.0));
            }

            // C) VWAP strength (0..12)
            // Above VWAP preferred. Slightly below OK.
            $score += min(12.0, max(0.0, 7.0 + ($vwapDist * 3.0)));

            // D) ATR% (0..15) - big move feasibility
            if ($atrPct !== null) {
                // 0.35 => ~0, 0.60 => ~8, 1.00 => 15
                $score += min(15.0, max(0.0, ($atrPct - 0.35) * 25.0));
            } else {
                $score += 6.0; // unknown ATR, give partial
            }

            // E) Break yesterday high bonus (0..12)
            if ($yesterdayHigh && $currentPrice > $yesterdayHigh) {
                $breakoutPct = (($currentPrice - $yesterdayHigh) / $yesterdayHigh) * 100;
                $score += min(12.0, 7.0 + ($breakoutPct * 3.0));
            } elseif ($yesterdayHigh && $currentPrice >= $yesterdayHigh * 0.985) {
                $score += 4.0;
            }

            // F) Relative strength vs SPY (0..6)
            if ($spyMovePct > 0) {
                $rsMult = $moveFromOpen / $spyMovePct;
                $score += min(6.0, max(0.0, ($rsMult - 0.7) * 3.0));
            } else {
                $score += 6.0;
            }

            $score = round(min(100.0, $score));

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

        // 5) Filter by score thresholds and rank
        $ranked = [];
        foreach ($cands as $c) {
            $s = (float) $c['score'];
            if ($s < $minScore || $s > $maxScore) {
                continue;
            }

            // Additional "big move feasibility" gate:
            // If ATR% is known and too small, skip (3–5% will be rare).
            $atrPct = $c['atr_pct'];
            if ($atrPct !== null && $atrPct < $minAtrPct) {
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

        $out = [];
        foreach ($ranked as $r) {
            $out[] = [
                'symbol' => (string) $r['symbol'],
                'asset_type' => (string) $r['asset_type'],
                'signal_type' => 'MOMENTUM_BREAKOUT_3_5PCT',
                'signal_ts_est' => (string) $r['signal_ts_est'],
                'score' => (int) $r['score'],
                'atr' => $r['atr'] ?? null,
                'atr_pct' => $r['atr_pct'] ?? null,
                'meta' => [
                    'version' => $this->version,
                    'goal' => '3-5%+ feasible runners',
                    'move_from_open_pct' => round((float) $r['move_pct'], 2),
                    'vol_ratio' => round((float) $r['vol_ratio'], 2),
                    'vwap_dist_pct' => round((float) $r['vwap_dist_pct'], 2),
                    'breaking_yesterday_high' => (bool) $r['breaking_high'],
                    'yesterday_high' => $r['yesterday_high'] ? round((float) $r['yesterday_high'], 4) : null,
                    'current_price' => round((float) $r['current_price'], 4),
                    'score_min' => $minScore,
                    'score_max' => $maxScore,
                    'spy_move_pct' => round((float) $spyMovePct, 2),
                    'min_move_from_open' => $minMoveFromOpen,
                    'min_vol_ratio' => $minVolRatio,
                    'min_atr_pct' => $minAtrPct,
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

    private function getYesterdaysBigMovers(
        string $assetType,
        string $tradeDate,
        float $minMovePct,
        float $minVolMult
    ): array {
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
            LIMIT 120
        ', [$yesterdayDate, $assetType, $minMovePct, $minVolMult]);

        return array_map(fn ($m) => (array) $m, $movers);
    }
}
