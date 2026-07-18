<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Version 101.0 - Multi-Day Surge Scanner
 *
 * Combines the best elements of Pipelines H, I, L, and E:
 * - H (v25.2): Quality-first institutional filters
 * - I (v17.0): Wide intraday_universe
 * - L (v1600.0): Early detection with low thresholds, pre-breakout
 * - E (v400.0): Multi-day structure (above VWAP, EMA9>EMA21 required)
 *
 * Hard gates (vs V1600.0):
 * - above_vwap = 1  (new, from E)
 * - EMA9 > EMA21    (new, from E)
 * - ATR% >= 0.45
 * - Notional >= $100K
 * - RVOL >= 1.5x OR move30m >= 0.35%
 *
 * Universe: intraday_universe + BestPerformers5m (5d) + prior losers + movers + Redis streak symbols
 */
class FiveMinuteSignalScannerV101_0
{
    use HasPriceTables;

    private string $version = 'v101.0';

    private string $name = 'Multi-Day Surge';

    // ── Scanner Configuration ──
    /** @var int Number of top-performing days for BestPerformers universe */
    public int $topDays = 5;

    /** @var int Max symbols from BestPerformers */
    public int $topLimit = 500;

    /** @var int Max prior-day losers to add */
    public int $losersLimit = 50;

    /** @var float Minimum $ notional per 5m bar */
    public float $minNotional5m = 100000.0;

    /** @var float Minimum ATR% (14-period 5m) */
    public float $minAtrPct5m = 0.45;

    /** @var float Minimum relative volume vs 20-bar avg */
    public float $minRvol5m = 1.50;

    /** @var float Minimum % move over last 30 min */
    public float $minMove30m = 0.35;

    /** @var int Window minutes for recent activity check */
    public int $activeWindowMinutes = 5;

    /** @var float Pre-breakout RVOL multiplier threshold */
    public float $preBreakoutRvolMult = 1.8;

    /** @var float Priority symbol score boost */
    public float $priorityBoost = 3.0;

    public function __construct(
        private readonly BestPerformers5mService $bestPerformersService,
        private readonly GainersLosersAnalysisService $gainersLosersService,
    ) {}

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
            'top_days' => $this->topDays,
            'top_limit' => $this->topLimit,
            'losers_limit' => $this->losersLimit,
            'min_notional_5m' => $this->minNotional5m,
            'min_atr_pct_5m' => $this->minAtrPct5m,
            'min_rvol_5m' => $this->minRvol5m,
            'min_move_30m_pct' => $this->minMove30m,
            'active_window_minutes' => $this->activeWindowMinutes,
            'pre_breakout_rvol_mult' => $this->preBreakoutRvolMult,
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
        int $lookbackMinutes = 60,
        float $minMovePct = 0.35,
        float $volMult = 1.5,
        int $limit = 60
    ): array {
        $topDays = $this->topDays;
        $topLimit = $this->topLimit;
        $losersLimit = $this->losersLimit;
        $minNotional5m = $this->minNotional5m;
        $minAtrPct5m = $this->minAtrPct5m;
        $minRvol5m = $this->minRvol5m;
        $minMove30m = $this->minMove30m;
        $activeWindowMinutes = $this->activeWindowMinutes;
        $preBreakoutRvolMult = $this->preBreakoutRvolMult;

        $cfg = config('trading.v101', []);
        $atrPeriod = (int) ($cfg['atr_period_5m'] ?? 14);
        $rvolLookback = (int) ($cfg['rvol_lookback_5m'] ?? 20);
        $moveBars = (int) ($cfg['move_bars_5m'] ?? 6);
        $prioritySymbolsRaw = (string) ($cfg['priority_symbols'] ?? '');
        $priorityBoost = $this->priorityBoost;

        $minimumRequiredBars = max($moveBars + 1, $rvolLookback, $atrPeriod);
        $minimumLookbackMinutes = max(5, $minimumRequiredBars * 5);
        $lookbackMinutes = max($lookbackMinutes, $minimumLookbackMinutes);

        $prioritySymbols = array_values(array_filter(array_map(
            static fn ($s) => strtoupper(trim((string) $s)),
            explode(',', $prioritySymbolsRaw)
        )));
        $prioritySet = array_fill_keys($prioritySymbols, true);

        // ── Universe: BestPerformers5m + intraday_universe + prior losers + movers + Redis ──
        $topPerformers = $this->bestPerformersService->getBestPerformers([
            'assetType' => $assetType,
            'testDateTime' => $asOfTsEst,
            'days' => $topDays,
            'minBars' => 200,
            'minVol' => 0,
            'rthOnly' => true,
            'limit' => $topLimit,
            'tz' => 'America/New_York',
        ]);

        $symbols = array_column($topPerformers, 'symbol');

        // Add intraday_universe (wide universe from H/I)
        $intradayUniverse = DB::table('intraday_universe')
            ->where('asset_type', $assetType)
            ->orderBy('symbol')
            ->pluck('symbol')
            ->map(static fn ($s) => (string) $s)
            ->all();
        $symbols = array_values(array_unique(array_merge($symbols, $intradayUniverse)));

        // Add prior-day losers (bounce candidates)
        try {
            $currentDate = substr($asOfTsEst, 0, 10);
            $prevTradingDay = DB::table($this->fiveMinuteTable)
                ->where('asset_type', $assetType)
                ->where('trading_date_est', '<', $currentDate)
                ->orderBy('trading_date_est', 'desc')
                ->value('trading_date_est');

            if ($prevTradingDay) {
                $losersData = $this->gainersLosersService->getGainersAndLosers($prevTradingDay, $assetType, $losersLimit);
                $loserSymbols = array_column($losersData['losers'] ?? [], 'symbol');
                $symbols = array_values(array_unique(array_merge($symbols, $loserSymbols)));
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // Add market movers
        $moversLimit = (int) config('trading.market_movers.pipeline_c', config('trading.market_movers.pipeline_h', 0));
        if ($moversLimit > 0) {
            $tradeDate = substr($asOfTsEst, 0, 10);
            $movers = app(\App\Services\MarketMoversService::class)->getTodaysTopMoversFromCache($tradeDate, $moversLimit);
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

        if (! empty($prioritySymbols)) {
            $symbols = array_values(array_unique(array_merge($prioritySymbols, $symbols)));
        }

        if (empty($symbols)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($symbols), '?'));

        // ── SQL: same CTE structure as V1600.0, extended with vwap/ema columns ──
        $sql = "
WITH universe AS (
  SELECT ? AS asset_type, symbol
  FROM (SELECT 1) t
  CROSS JOIN (
    SELECT DISTINCT symbol
    FROM {$this->fiveMinuteTable}
    WHERE asset_type = ?
      AND symbol IN ($placeholders)
  ) s
),
base AS (
  SELECT
    f.symbol,
    f.asset_type,
    f.ts_est,
    f.price AS close,
    f.high,
    f.low,
    f.volume,
    f.vwap,
    f.above_vwap,
    f.ema9,
    f.ema21,
    LAG(f.price, 1) OVER (PARTITION BY f.symbol, f.asset_type ORDER BY f.ts_est) AS prev_close,
    ROW_NUMBER() OVER (PARTITION BY f.symbol, f.asset_type ORDER BY f.ts_est DESC) AS rn_desc
  FROM {$this->fiveMinuteTable} f
  JOIN universe u
    ON u.symbol = f.symbol AND u.asset_type = f.asset_type
  WHERE f.ts_est <= ?
    AND f.ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
),
recent AS (
  SELECT *
  FROM base
  WHERE ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
),
agg_last AS (
  SELECT
    symbol,
    asset_type,
    MAX(CASE WHEN rn_desc = 1 THEN ts_est END)      AS signal_ts_est,
    MAX(CASE WHEN rn_desc = 1 THEN close END)        AS last_close,
    MAX(CASE WHEN rn_desc = 1 THEN volume END)       AS last_vol,
    MAX(CASE WHEN rn_desc = 1 + ? THEN close END)    AS close_nback,
    MAX(CASE WHEN rn_desc = 1 THEN above_vwap END)   AS last_above_vwap,
    MAX(CASE WHEN rn_desc = 1 THEN ema9 END)         AS last_ema9,
    MAX(CASE WHEN rn_desc = 1 THEN ema21 END)        AS last_ema21
  FROM base
  GROUP BY symbol, asset_type
),
rvol AS (
  SELECT
    b.symbol,
    b.asset_type,
    AVG(b.volume) AS avg_vol
  FROM base b
  WHERE b.rn_desc <= ?
  GROUP BY b.symbol, b.asset_type
),
atr AS (
  SELECT
    b.symbol,
    b.asset_type,
    AVG(
      GREATEST(
        (b.high - b.low),
        ABS(b.high - COALESCE(b.prev_close, b.close)),
        ABS(b.low  - COALESCE(b.prev_close, b.close))
      )
    ) AS atr_val
  FROM base b
  WHERE b.rn_desc <= ?
  GROUP BY b.symbol, b.asset_type
),
activity AS (
  SELECT symbol, asset_type, MAX(ts_est) AS last_seen_ts
  FROM recent
  GROUP BY symbol, asset_type
)
SELECT
  a.symbol,
  a.asset_type,
  a.signal_ts_est,
  a.last_close,
  a.last_vol,
  a.last_above_vwap,
  a.last_ema9,
  a.last_ema21,
  r.avg_vol,
  (a.last_vol / NULLIF(r.avg_vol, 0)) AS rvol_ratio,
  ((a.last_close - a.close_nback) / NULLIF(a.close_nback, 0)) * 100 AS move_30m_pct,
  t.atr_val,
  (t.atr_val / NULLIF(a.last_close, 0)) * 100 AS atr_pct,
  (a.last_close * a.last_vol) AS notional_last5m,
  act.last_seen_ts
FROM agg_last a
JOIN rvol r ON r.symbol=a.symbol AND r.asset_type=a.asset_type
JOIN atr  t ON t.symbol=a.symbol AND t.asset_type=a.asset_type
JOIN activity act ON act.symbol=a.symbol AND act.asset_type=a.asset_type
WHERE a.close_nback IS NOT NULL
";

        $params = array_merge(
            [$assetType, $assetType],
            $symbols,
            [$asOfTsEst, $asOfTsEst, $lookbackMinutes, $asOfTsEst, $activeWindowMinutes],
            [$moveBars, $rvolLookback, $atrPeriod]
        );

        $bucketTs = date('Y-m-d H:i', strtotime(floor(strtotime($asOfTsEst) / 300) * 300));
        $cacheKey = "scan_v101_0:{$assetType}:{$bucketTs}:{$lookbackMinutes}";
        $rows = Cache::get($cacheKey);
        if ($rows === null) {
            $lock = Cache::lock("lock:{$cacheKey}", 60);
            if ($lock->get()) {
                try {
                    $rows = $this->dbSelect($sql, $params);
                    Cache::put($cacheKey, $rows, 240);
                } finally {
                    $lock->release();
                }
            } else {
                $rows = Cache::get($cacheKey) ?? $this->dbSelect($sql, $params);
            }
        }

        $spyMove30m = $this->getSpyMovement30m($asOfTsEst, $assetType, $moveBars);

        $asOfEpochRaw = strtotime($asOfTsEst);
        $asOfEpoch = $asOfEpochRaw !== false
            ? strtotime(date('Y-m-d H:i:00', $asOfEpochRaw))
            : false;
        if ($asOfEpoch === false) {
            return [];
        }
        $maxSignalAgeSeconds = max(1, $activeWindowMinutes) * 60;

        $dropCounts = [
            'rows_total' => count($rows),
            'signal_age' => 0,
            'last_close' => 0,
            'notional' => 0,
            'atr' => 0,
            'activity' => 0,
            'below_vwap' => 0,
            'ema_not_aligned' => 0,
            'move_floor' => 0,
            'rs_filter' => 0,
            'passed' => 0,
        ];

        $out = [];
        foreach ($rows as $r) {
            $signalAgeSeconds = $asOfEpoch - strtotime((string) $r->signal_ts_est);
            if ($signalAgeSeconds < 0 || $signalAgeSeconds > $maxSignalAgeSeconds) {
                $dropCounts['signal_age']++;

                continue;
            }

            $lastClose = (float) $r->last_close;
            if ($lastClose <= 0) {
                $dropCounts['last_close']++;

                continue;
            }

            $atrPct = (float) $r->atr_pct;
            $rvolRatio = (float) $r->rvol_ratio;
            $move30m = (float) $r->move_30m_pct;
            $notional = (float) $r->notional_last5m;

            // Gate 0: min liquidity
            if ($notional < $minNotional5m) {
                $dropCounts['notional']++;

                continue;
            }

            // Gate 1: volatility
            if ($atrPct < $minAtrPct5m) {
                $dropCounts['atr']++;

                continue;
            }

            // Gate 2: activity — must have RVOL or 30m impulse
            if (! ($rvolRatio >= $minRvol5m || $move30m >= $minMove30m)) {
                $dropCounts['activity']++;

                continue;
            }

            // Gate 3: must be above intraday VWAP (from E — key multi-day quality filter)
            if (! (bool) $r->last_above_vwap) {
                $dropCounts['below_vwap']++;

                continue;
            }

            // Gate 4: EMA9 > EMA21 — bullish trend alignment (from E)
            $ema9 = (float) ($r->last_ema9 ?? 0);
            $ema21 = (float) ($r->last_ema21 ?? 0);
            if ($ema9 <= 0 || $ema21 <= 0 || $ema9 <= $ema21) {
                $dropCounts['ema_not_aligned']++;

                continue;
            }

            // Gate 5: basic move floor
            $isPriority = isset($prioritySet[strtoupper((string) $r->symbol)]);
            $isPreBreakout = $rvolRatio >= ($minRvol5m * $preBreakoutRvolMult);
            if ($move30m < $minMovePct && ! ($isPriority || $isPreBreakout)) {
                $dropCounts['move_floor']++;

                continue;
            }

            // Gate 6 (optional): RS vs benchmark
            $enableRsFilter = (bool) config('trading.enable_relative_strength_filter', false);
            $minRsMult = (float) config('trading.v101.scanner.min_rs_mult_vs_spy', 1.05);
            if ($enableRsFilter && $spyMove30m > 0.10 && $move30m < $spyMove30m * $minRsMult) {
                $dropCounts['rs_filter']++;

                continue;
            }

            $dropCounts['passed']++;

            // Score: move + rvol + atr, with pre-breakout bonus
            $rvolCapped = min(6.0, $rvolRatio);
            $score = ($move30m * 1.2) + ($rvolCapped * 1.0) + ($atrPct * 0.8);

            if ($isPreBreakout) {
                $score += 2.0;
            }
            if ($isPriority) {
                $score += $priorityBoost;
            }

            $perfData = collect($topPerformers)->firstWhere('symbol', (string) $r->symbol);
            $pctNd = $perfData ? ($perfData['pct_return_pct'] ?? null) : null;
            $atr = ($atrPct && $r->last_close) ? round(($atrPct / 100) * $r->last_close, 6) : null;

            $out[] = [
                'symbol' => (string) $r->symbol,
                'asset_type' => (string) $r->asset_type,
                'signal_type' => 'MOMENTUM_ACCELERATION_SURGE_5M_V101',
                'signal_ts_est' => (string) $r->signal_ts_est,
                'score' => round($score, 3),
                'atr' => $atr,
                'atr_pct' => $atrPct,
                'meta' => [
                    'move_30m_pct' => round($move30m, 3),
                    'rvol_5m' => round($rvolRatio, 3),
                    'atr_pct_5m' => round($atrPct, 3),
                    'notional_last5m' => round($notional, 2),
                    'pct_nd' => $pctNd !== null ? round((float) $pctNd, 2) : null,
                    'spy_move_30m_pct' => round($spyMove30m, 3),
                    'ema9_vs_ema21' => round($ema9 - $ema21, 4),
                    'above_vwap' => true,
                    'is_pre_breakout' => $isPreBreakout,
                    'universe_size' => count($symbols),
                    'signal_age_seconds' => $signalAgeSeconds,
                    'version' => $this->version,
                    'current_price' => $r->last_close ?? null,
                ],
            ];
        }

        usort($out, fn ($a, $b) => ($b['score'] <=> $a['score']));

        return array_slice($out, 0, max(1, $limit));
    }

    private function getSpyMovement30m(string $asOfTsEst, string $assetType, int $moveBars): float
    {
        if ($assetType !== 'stock') {
            return 0.0;
        }

        $benchmarkSymbol = config('trading.market_benchmark_symbol', 'QQQM');
        $sql = "
SELECT
  price AS last_close,
  LAG(price, ?) OVER (ORDER BY ts_est) AS prev_close
FROM {$this->fiveMinuteTable}
WHERE symbol = ?
  AND asset_type = 'stock'
  AND ts_est <= ?
ORDER BY ts_est ASC
";
        $rows = $this->dbSelect($sql, [$moveBars, $benchmarkSymbol, $asOfTsEst]);
        if (! $rows) {
            return 0.0;
        }
        $last = end($rows);
        if (! $last || empty($last->prev_close)) {
            return 0.0;
        }
        $prev = (float) $last->prev_close;
        $lc = (float) $last->last_close;
        if ($prev <= 0) {
            return 0.0;
        }

        return (($lc - $prev) / $prev) * 100.0;
    }
}
