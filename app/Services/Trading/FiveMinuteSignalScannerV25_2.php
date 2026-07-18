<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Version 25.2 - Signal Scanner (Quality-first)
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
class FiveMinuteSignalScannerV25_2 extends AbstractSignalScanner
{
    private string $version = 'v25.2';

    private string $name = 'Quality-First';

    // ── Scanner Configuration (public so entry finders can read) ──
    public int $topDays = 5;

    public int $topLimit = 500;

    public int $losersLimit = 75;

    public float $minNotional5m = 75000;

    public float $minAtrPct5m = 0.35;

    public float $minRvol5m = 2.0;

    public float $minMove30m = 1.2;

    public float $minRsMultVsSpy = 1.20;

    public int $activeWindowMinutes = 6;

    public int $analysisLookbackMinutes = 90;

    // ── Entry Finder Configuration (public so entry finders can read) ──
    public float $entryMinNotional1m = 80000;

    public float $entryMinVolRatio1m = 1.0;

    public float $entryMinBodyPct1m = 0.05;

    public float $entryMaxAboveVwapPct = 0.90;

    public float $entryMinRoomToRunPct = 0.6;

    public float $entryRoomAtrMult = 1.5;

    public int $entryAllowLunch = 0;

    public int $entryMinBars = 15;

    public int $entryMaxAgeMinutes = 10;

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
            'min_rs_mult_vs_spy' => $this->minRsMultVsSpy,
            'active_window_minutes' => $this->activeWindowMinutes,
            'analysis_lookback_minutes' => $this->analysisLookbackMinutes,
            'entry_min_notional_1m' => $this->entryMinNotional1m,
            'entry_min_vol_ratio_1m' => $this->entryMinVolRatio1m,
            'entry_min_body_pct_1m' => $this->entryMinBodyPct1m,
            'entry_max_above_vwap_pct' => $this->entryMaxAboveVwapPct,
            'entry_min_room_to_run_pct' => $this->entryMinRoomToRunPct,
            'entry_room_atr_mult' => $this->entryRoomAtrMult,
            'entry_allow_lunch' => $this->entryAllowLunch,
            'entry_min_bars' => $this->entryMinBars,
            'entry_max_age_minutes' => $this->entryMaxAgeMinutes,
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

    /**
     * Override to propagate table mode to dependent services.
     */
    public function setFullTable(bool $full): void
    {
        parent::setFullTable($full);
        $this->bestPerformersService->setFullTable($full);
        $this->gainersLosersService->setFullTable($full);
    }

    protected function doScan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes,
        float $minMovePct,
        float $volMult,
        int $limit,
        bool $skipCache
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
        $minimumLookbackMinutes = max(5, ($moveBars + 1) * 5);
        $lookbackMinutes = max($lookbackMinutes, $minimumLookbackMinutes);

        // ---------- 1) Universe: intraday_universe (pre-built, fast, cached 8h) ----------
        $universeCacheKey = "scan_v25_2:universe_symbols:{$assetType}";
        $symbols = Cache::get($universeCacheKey);
        if ($symbols === null) {
            $symbols = DB::table('intraday_universe')
                ->select('symbol')
                ->where('asset_type', $assetType)
                ->orderBy('symbol')
                ->pluck('symbol')
                ->all();

            // Add market movers to universe if enabled
            $moversLimit = (int) config('trading.market_movers.pipeline_h', 0);
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

            // Cache for 8 hours — universe only needs to be refreshed once per trading day
            Cache::put($universeCacheKey, $symbols, 28800);
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
        // Skip cache in backtest mode — each time slot is unique and caching serves stale data.
        $rows = null;
        if (! $skipCache) {
            $bucketTs = date('Y-m-d H:i', strtotime(floor(strtotime($asOfTsEst) / 300) * 300));
            $cacheKey = "scan_v25_2:{$assetType}:{$bucketTs}:{$lookbackMinutes}";
            $rows = Cache::get($cacheKey);
        }
        if ($rows === null) {
            if ($skipCache) {
                $rows = $this->dbSelect($sql, $params);
            } else {
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

        $debugEnabled = ((string) env('SCANNER_V25_DEBUG', '0') === '1')
          || (bool) config('trading.v25.debug', false);
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
            if ($move30m < $minMovePct) {
                $dropCounts['move_floor']++;

                continue;
            }

            // Gate 4 (optional): RS vs QQQM when enabled
            $enableRsFilter = (bool) config('trading.enable_relative_strength_filter', false);
            $minRsMult = (float) config('trading.v25.scanner.min_rs_mult_vs_spy', 1.10);
            if ($enableRsFilter && $spyMove30m > 0.10 && $move30m < $spyMove30m * $minRsMult) {
                $dropCounts['rs_filter']++;

                continue;
            }

            $dropCounts['passed']++;

            // Score: favor high move, high rvol, high atr%
            // (cap RVOL effect to avoid insane spikes)
            $rvolCapped = min(6.0, $rvolRatio);
            $score = ($move30m * 1.2) + ($rvolCapped * 1.0) + ($atrPct * 0.8);

            $perfData = null;  // Universe comes from intraday_universe — no per-performer enrichment data
            $pctNd = null;

            // Calculate ATR in dollars for FILTERED_OUT entries
            $atr = ($atrPct && $r->last_close) ? round(($atrPct / 100) * $r->last_close, 6) : null;

            $out[] = [
                'symbol' => (string) $r->symbol,
                'asset_type' => (string) $r->asset_type,
                'signal_type' => 'MOMO_5M_V25',
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
}
