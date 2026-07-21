<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Version 1600.0 - Early Momentum Signal Scanner
 *
 * Goals:
 * - Stop producing 160-200+ noisy alerts/day
 * - Produce a smaller set of "3% capable" candidates (10-30/day typical),
 *   based on volatility + liquidity + RVOL + move.
 *
 * Core ideas:
 * - Universe: top performers (5d) + prior-day losers (bounce candidates)
 * - Gate: 5m ATR% (volatility) + notional (liquidity) + RVOL (activity) + 30m move
 * - Score: combines move, rvol, atr% (favor names that can run)
 */
class FiveMinuteSignalScannerV1600_0
{
    use HasPriceTables;

    private string $version = 'v1600.0';

    private string $name = 'Early Momentum Pre-Breakout';

    // ── Scanner Configuration (public so entry finders can read) ──
    /** @var int Number of top-performing days for universe */
    public int $topDays = 5;

    /** @var int Max symbols in universe */
    public int $topLimit = 650;

    /** @var int Max prior-day losers to add */
    public int $losersLimit = 120;

    /** @var float Minimum $ notional per 5m bar for liquidity */
    public float $minNotional5m = 150000.0;

    /** @var float Minimum ATR% (14-period 5m) for volatility */
    public float $minAtrPct5m = 0.55;

    /** @var float Minimum relative volume vs 20-bar avg */
    public float $minRvol5m = 1.25;

    /** @var float Minimum % move over last 30 min */
    public float $minMove30m = 0.45;

    /** @var int Window minutes for recent activity check */
    public int $activeWindowMinutes = 8;

    /** @var string Comma-separated priority symbols for score boost */
    public string $prioritySymbols = '';

    /** @var float Score multiplier for priority symbols */
    public float $priorityBoost = 0.75;

    /** @var float Pre-breakout volume multiplier threshold */
    public float $preBreakoutRvolMult = 1.6;

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
            'priority_symbols' => $this->prioritySymbols,
            'priority_boost' => $this->priorityBoost,
            'pre_breakout_rvol_mult' => $this->preBreakoutRvolMult,
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
        int $lookbackMinutes = 60,
        float $minMovePct = 0.4,
        float $volMult = 1.5,
        int $limit = 60              // how many to return to the pipeline for refinement
    ): array {
        $topDays = $this->topDays;
        $topLimit = $this->topLimit;
        $losersLimit = $this->losersLimit;

        $minNotional5m = $this->minNotional5m;
        $minAtrPct5m = $this->minAtrPct5m;
        $minRvol5m = $this->minRvol5m;
        $minMove30m = $this->minMove30m;

        $activeWindowMinutes = $this->activeWindowMinutes;
        $atrPeriod = (int) ($cfg['atr_period_5m'] ?? 14);
        $rvolLookback = (int) ($cfg['rvol_lookback_5m'] ?? 20);
        $moveBars = (int) ($cfg['move_bars_5m'] ?? 6); // 6*5m = 30m
        $prioritySymbolsRaw = (string) ($cfg['priority_symbols'] ?? '');
        $priorityBoost = $this->priorityBoost;
        $preBreakoutRvolMult = $this->preBreakoutRvolMult;
        // Ensure lookback covers every rolling window used by gates.
        // 5m windows required:
        // - moveBars + 1 closes for 30m move calc
        // - rvolLookback bars for RVOL baseline
        // - atrPeriod bars for ATR baseline
        $minimumRequiredBars = max($moveBars + 1, $rvolLookback, $atrPeriod);
        $minimumLookbackMinutes = max(5, $minimumRequiredBars * 5);
        $lookbackMinutes = max($lookbackMinutes, $minimumLookbackMinutes);
        $prioritySymbols = array_values(array_filter(array_map(
            static fn ($s) => strtoupper(trim((string) $s)),
            explode(',', $prioritySymbolsRaw)
        )));
        $prioritySet = array_fill_keys($prioritySymbols, true);

        // ---------- 1) Universe: top performers + prior-day losers ----------
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

        // Add market movers to universe if enabled (top explosive movers from recent days)
        $moversLimit = (int) config('trading.market_movers.pipeline_l', config('trading.market_movers.pipeline_h', 0));
        if ($moversLimit > 0) {
            $tradeDate = substr($asOfTsEst, 0, 10);
            $movers = app(\App\Services\MarketMoversService::class)->getTodaysTopMoversFromCache($tradeDate, $moversLimit);
            $symbols = array_values(array_unique(array_merge($symbols, $movers)));
        }

        if (! empty($prioritySymbols)) {
            $symbols = array_values(array_unique(array_merge($prioritySymbols, $symbols)));
        }

        if (empty($symbols)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($symbols), '?'));

        // ---------- 2) Compute "3% capable" gates on 5m ----------
        // Requires five_minute_prices has: ts_est, price, high, low, volume
        // We compute:
        // - last_close, last_vol, avg_vol (20), rvol
        // - 30m move vs LAG(close, moveBars)
        // - ATR (14) using TR with LAG(close,1)
        //
        // Notes:
        // - If your table doesn't have high/low columns, replace TR with abs(close - prev_close) proxy.
        $sql = "
WITH universe AS (
  SELECT ? AS asset_type, symbol
  FROM (SELECT 1) t
  CROSS JOIN (
    SELECT DISTINCT symbol
    FROM five_minute_prices
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
    LAG(f.price, 1) OVER (PARTITION BY f.symbol, f.asset_type ORDER BY f.ts_est) AS prev_close,
    ROW_NUMBER() OVER (PARTITION BY f.symbol, f.asset_type ORDER BY f.ts_est DESC) AS rn_desc
  FROM five_minute_prices f
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
    MAX(CASE WHEN rn_desc = 1 THEN ts_est END) AS signal_ts_est,
    MAX(CASE WHEN rn_desc = 1 THEN close END)  AS last_close,
    MAX(CASE WHEN rn_desc = 1 THEN volume END) AS last_vol,
    MAX(CASE WHEN rn_desc = 1 + ? THEN close END) AS close_nback
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

        // Cache for 4 minutes — 5m bars only update every 5 minutes so this is safe.
        // Symbol list excluded from cache key: the universe is built from getBestPerformers()
        // which is deterministic for a given asOfTsEst, so all concurrent callers share one entry.
        // Lock prevents stampede on cold cache.
        $bucketTs = date('Y-m-d H:i', strtotime(floor(strtotime($asOfTsEst) / 300) * 300));
        $cacheKey = "scan_v1600_0:{$assetType}:{$bucketTs}:{$lookbackMinutes}";
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
                // Another process (e.g. backtest) holds the lock — don't block the live pipeline.
                // Use cached result if available, otherwise run the query directly.
                $rows = Cache::get($cacheKey) ?? $this->dbSelect($sql, $params);
            }
        }

        // SPY relative strength (optional gate)
        $spyMove30m = $this->getSpyMovement30m($asOfTsEst, $assetType, $moveBars);

        $asOfEpochRaw = strtotime($asOfTsEst);
        $asOfEpoch = $asOfEpochRaw !== false
          ? strtotime(date('Y-m-d H:i:00', $asOfEpochRaw))
          : false;
        if ($asOfEpoch === false) {
            return [];
        }
        $maxSignalAgeSeconds = max(1, $activeWindowMinutes) * 60;

        $debugEnabled = (bool) config('trading.v1600.debug', false);
        $dropCounts = [
            'rows_total' => count($rows),
            'signal_age' => 0,
            'last_close' => 0,
            'notional' => 0,
            'atr' => 0,
            'activity' => 0,
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

            // Gate 1: volatility (must be capable of expansion)
            if ($atrPct < $minAtrPct5m) {
                $dropCounts['atr']++;

                continue;
            }

            // Gate 2: activity / catalyst proxy:
            // Must have either strong RVOL or a strong 30m impulse.
            if (! ($rvolRatio >= $minRvol5m || $move30m >= $minMove30m)) {
                $dropCounts['activity']++;

                continue;
            }

            // Gate 3: basic move floor (keeps totally flat names out)
            $isPriority = isset($prioritySet[strtoupper((string) $r->symbol)]);
            $isPreBreakout = $rvolRatio >= ($minRvol5m * $preBreakoutRvolMult);
            if ($move30m < $minMovePct && ! ($isPriority || $isPreBreakout)) {
                $dropCounts['move_floor']++;

                continue;
            }

            // Gate 4 (optional): RS vs QQQM when enabled
            $enableRsFilter = (bool) config('trading.enable_relative_strength_filter', false);
            $minRsMult = (float) config('trading.v1600.scanner.min_rs_mult_vs_spy', 1.10);
            if ($enableRsFilter && $spyMove30m > 0.10 && $move30m < $spyMove30m * $minRsMult) {
                $dropCounts['rs_filter']++;

                continue;
            }

            $dropCounts['passed']++;

            // Score: favor high move, high rvol, high atr%
            // (cap RVOL effect to avoid insane spikes)
            $rvolCapped = min(6.0, $rvolRatio);
            $score = ($move30m * 1.2) + ($rvolCapped * 1.0) + ($atrPct * 0.8);
            if ($isPriority) {
                $score += $priorityBoost;
            }

            $perfData = collect($topPerformers)->firstWhere('symbol', (string) $r->symbol);
            $pctNd = $perfData ? ($perfData['pct_return_pct'] ?? null) : null;

            // Calculate ATR in dollars for FILTERED_OUT entries
            $atr = ($atrPct && $r->last_close) ? round(($atrPct / 100) * $r->last_close, 6) : null;

            $out[] = [
                'symbol' => (string) $r->symbol,
                'asset_type' => (string) $r->asset_type,
                'signal_type' => 'MOMO_5M_V1600',
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
                    'universe_size' => count($symbols),
                    'signal_age_seconds' => $signalAgeSeconds,
                    'version' => $this->version,
                    'current_price' => $r->last_close ?? null,
                ],
            ];
        }

        usort($out, fn ($a, $b) => ($b['score'] <=> $a['score']));

        if ($debugEnabled) {
            Log::info('[ScannerV25_2] gate summary', [
                'as_of' => $asOfTsEst,
                'asset_type' => $assetType,
                'universe_size' => count($symbols),
                'gates' => $dropCounts,
                'returned' => min(max(1, $limit), count($out)),
            ]);
        }

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
FROM five_minute_prices
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
