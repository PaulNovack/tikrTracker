<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\DB;

/**
 * Version 18.0 - Signal Scanner (Quality + Tunable)
 *
 * Keeps your proven universe approach:
 * 1) Top performers from last N days (5m data)
 * 2) Add top losers from prev trading day (bounce opportunities)
 *
 * Improvements:
 * - Adds liquidity gates (price / avg vol / avg notional)
 * - Uses RVOL within lookback window (last_vol / avg_vol)
 * - Optional trend filter (reduce junk spikes)
 * - Adds anti-spike volume sanity
 * - Adds tunable minScore to control output count (10–20 target)
 */
class FiveMinuteSignalScannerV18_0
{
    use HasPriceTables;

    private string $version = 'v18.0';

    private string $name = 'Earnings Momentum';

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
     * Output format:
     * [
     *   [
     *     'symbol' => 'AAPL',
     *     'asset_type' => 'stock',
     *     'signal_type' => 'MOMO_5M',
     *     'signal_ts_est' => 'YYYY-mm-dd HH:MM:SS',
     *     'score' => 12.345,
     *     'meta' => [...],
     *   ],
     * ]
     */
    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minMovePct = 0.5,
        float $volMult = 2.2,
        int $limit = 80
    ): array {
        // =========================
        // Tunables (.env)
        // =========================
        $universeDays = (int) env('SCAN5_UNIVERSE_DAYS', 7);
        $universeTop = (int) env('SCAN5_UNIVERSE_TOP', 400);
        $losersCount = (int) env('SCAN5_UNIVERSE_LOSERS', 60);

        $activeWindowMinutes = (int) env('SCAN5_ACTIVE_WINDOW_MINUTES', 20);

        $minPrice = (float) env('SCAN5_MIN_PRICE', 1.50);

        // Liquidity gates (5m)
        $minAvgVol5 = (float) env('SCAN5_MIN_AVG_VOL_5M', 20000);            // average shares per 5m bar in lookback
        $minAvgNotional5 = (float) env('SCAN5_MIN_AVG_NOTIONAL_5M', 300000); // average $ per 5m bar in lookback

        // Anti-spike sanity
        $maxVolRatio = (float) env('SCAN5_MAX_VOL_RATIO', 60.0);

        // Relative strength knob
        $rsMult = (float) env('SCAN5_RS_MULT', 1.10);

        // Optional trend filter (can turn off if too restrictive)
        $requireTrend = (string) env('SCAN5_REQUIRE_TREND', '0') === '1';

        // Primary “count knob”
        $minScore = (float) env('SCAN5_MIN_SCORE', 7.0);

        // =========================

        // -------- Universe: top performers --------
        $topPerformers = $this->bestPerformersService->getBestPerformers([
            'assetType' => $assetType,
            'testDateTime' => $asOfTsEst,
            'days' => $universeDays,
            'minBars' => 200,
            'minVol' => 0,
            'rthOnly' => true,
            'limit' => $universeTop,
            'tz' => 'America/New_York',
        ]);

        $symbols = array_column($topPerformers, 'symbol');

        // -------- Add previous day losers --------
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
                    $losersCount
                );

                $loserSymbols = array_column($losersData['losers'], 'symbol');
                $symbols = array_unique(array_merge($symbols, $loserSymbols));
            }
        } catch (\Exception $e) {
            // ignore
        }

        if (empty($symbols)) {
            return [];
        }

        // In 5m bars, N bars back ~ lookbackMinutes/5
        $nback = max(2, (int) floor($lookbackMinutes / 5));

        // IN clause placeholders
        $placeholders = implode(',', array_fill(0, count($symbols), '?'));

        /**
         * Strategy:
         * - For each symbol in the universe:
         *   - grab bars within lookback window (and ensure active in last activeWindowMinutes)
         *   - compute:
         *       last_close, prev_close (nback bars ago), move_pct
         *       avg_vol, last_vol, vol_ratio (rvol)
         *       avg_notional = avg(close*vol)
         *       last_notional
         *       simple trend proxies (optional)
         */
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
    (f.price * f.volume) AS notional,
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
    MAX(CASE WHEN rn = 1 THEN close END) AS last_close,
    MAX(CASE WHEN rn = 1 THEN volume END) AS last_vol,
    MAX(CASE WHEN rn = 1 THEN notional END) AS last_notional,

    MAX(CASE WHEN rn = 1 + ? THEN close END) AS prev_close,

    AVG(volume) AS avg_vol,
    AVG(notional) AS avg_notional,

    -- Trend proxies: compare last close vs avg close in window, and last close vs max close excluding last bar
    AVG(close) AS avg_close,
    MAX(CASE WHEN rn > 1 THEN close END) AS prior_max_close
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
  last_notional,
  avg_notional,
  avg_close,
  prior_max_close,
  ((last_close - prev_close) / NULLIF(prev_close, 0)) * 100 AS move_pct,
  (last_vol / NULLIF(avg_vol, 0)) AS vol_ratio
FROM agg
WHERE prev_close IS NOT NULL
  AND last_close >= ?
  AND avg_vol >= ?
  AND avg_notional >= ?
  AND ((last_close - prev_close) / NULLIF(prev_close, 0)) * 100 >= ?
  AND (last_vol / NULLIF(avg_vol, 0)) >= ?
  AND (last_vol / NULLIF(avg_vol, 0)) <= ?
  -- combined impulse gate (keeps only meaningful move+volume combos)
  AND (((last_close - prev_close) / NULLIF(prev_close, 0)) * 100 * (last_vol / NULLIF(avg_vol, 0))) >= 5.2
ORDER BY (((last_close - prev_close) / NULLIF(prev_close, 0)) * 100 * (last_vol / NULLIF(avg_vol, 0))) DESC
LIMIT ?
";

        $params = array_merge(
            // last_bar
            [$assetType],
            $symbols,
            [$asOfTsEst],
            [$asOfTsEst],
            [$activeWindowMinutes],

            // bars
            [$assetType],
            $symbols,
            [$asOfTsEst],
            [$asOfTsEst],
            [$lookbackMinutes],

            // agg nback
            [$nback],

            // filters
            [$minPrice],
            [$minAvgVol5],
            [$minAvgNotional5],
            [$minMovePct],
            [$volMult],
            [$maxVolRatio],

            // limit
            [$limit]
        );

        $rows = $this->dbSelect($sql, $params);

        // Relative strength vs SPY
        $spyMovePct = $this->getSpyMovement($asOfTsEst, $lookbackMinutes);

        $out = [];
        foreach ($rows as $r) {
            $stockMovePct = (float) $r->move_pct;
            $volRatio = (float) $r->vol_ratio;
            $lastClose = (float) $r->last_close;

            // RS filter:
            // - if SPY up, require stock_move >= SPY * rsMult
            // - if SPY flat/down, allow any positive move (keeps many momo)
            if ($spyMovePct > 0 && $stockMovePct < $spyMovePct * $rsMult) {
                continue;
            }

            // Optional trend filter (helps avoid pure mean-reversion spikes)
            if ($requireTrend) {
                $avgClose = (float) $r->avg_close;
                $priorMax = (float) $r->prior_max_close;

                // require last close above average close in window
                if ($avgClose > 0 && $lastClose < $avgClose * 1.003) {
                    continue;
                }

                // require a “break” or near-break of prior highs (helps continuation setups)
                if ($priorMax > 0 && $lastClose < $priorMax * 0.998) {
                    continue;
                }
            }

            // Liquidity bonus (prefers thicker names without hard-cutting)
            $avgNotional = (float) $r->avg_notional;
            $liqBonus = 1.0;
            if ($avgNotional >= 800000) {
                $liqBonus = 1.25;
            } elseif ($avgNotional >= 500000) {
                $liqBonus = 1.15;
            } elseif ($avgNotional >= 300000) {
                $liqBonus = 1.05;
            }

            // Score: move * rvol * liquidity bonus
            $score = $stockMovePct * $volRatio * $liqBonus;

            if ($score < $minScore) {
                continue;
            }

            // 7d perf meta (from universe)
            $perfData = collect($topPerformers)->firstWhere('symbol', $r->symbol);
            $pctWindow = $perfData ? $perfData['pct_return_pct'] : null;

            $out[] = [
                'symbol' => (string) $r->symbol,
                'asset_type' => (string) $r->asset_type,
                'signal_type' => 'MOMO_5M',
                'signal_ts_est' => (string) $r->signal_ts_est,
                'score' => round($score, 3),
                'meta' => [
                    'move_pct' => round($stockMovePct, 3),
                    'vol_ratio' => round($volRatio, 3),
                    'last_close' => (float) $r->last_close,
                    'prev_close' => (float) $r->prev_close,
                    'avg_vol' => round((float) $r->avg_vol, 0),
                    'avg_notional' => round($avgNotional, 0),
                    'last_notional' => round((float) $r->last_notional, 0),
                    'pct_window' => $pctWindow !== null ? round($pctWindow, 2) : null,
                    'spy_move_pct' => round($spyMovePct, 3),
                    'relative_strength' => $spyMovePct > 0 ? round($stockMovePct / $spyMovePct, 2) : null,
                    'universe_filtered' => true,
                    'universe_size' => count($symbols),
                    'require_trend' => $requireTrend,
                    'liq_bonus' => $liqBonus,
                ],
            ];
        }

        // Highest score first
        usort($out, fn ($a, $b) => ($b['score'] <=> $a['score']));

        return $out;
    }

    /**
     * SPY movement percentage over lookback period.
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
            return 0.0;
        }

        return (($result->last_close - $result->prev_close) / $result->prev_close) * 100.0;
    }
}
