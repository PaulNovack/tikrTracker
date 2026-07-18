<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Version 35.0 - Signal Scanner (Quote-Triggered, Quality-first)
 *
 * Goals:
 * - Real-time quote / 1-minute update → build partial 5-minute candle
 * - Detect early momentum before 5-minute bar completes
 * - Run 1-minute entry confirmation immediately
 * - Create alert immediately (eliminates 5-minute bar completion delay)
 *
 * Core ideas:
 * - Same quality gates as v25.2 (ATR%, notional, RVOL, move)
 * - Universe: top performers (5d) + prior-day losers (bounce candidates)
 * - Gate: 5m ATR% (volatility) + notional (liquidity) + RVOL (activity) + 30m move
 * - Score: combines move, rvol, atr% (favor names that can run)
 * - Key difference: uses partial 5m candles built from live 1m data
 */
class FiveMinuteSignalScannerV35_0
{
    use HasPriceTables;

    private string $version = 'v35.0';

    private string $name = 'Quality-First (Quote-Triggered)';

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
        float $minMovePct = 1.2,     // institutional quality (was 0.5)
        float $volMult = 3.5,        // institutional volume (was 2.0)
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
        $minimumLookbackMinutes = max(5, ($moveBars + 1) * 5);
        $lookbackMinutes = max($lookbackMinutes, $minimumLookbackMinutes);

        // ---------- 1) Universe: intraday_universe (pre-built, fast, cached 8h) ----------
        $universeCacheKey = "scan_v35_0:universe_symbols:{$assetType}";
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

            // Cache for 8 hours — universe only needs to be refreshed once per trading day
            Cache::put($universeCacheKey, $symbols, 28800);
        }

        if (empty($symbols)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($symbols), '?'));

        // ---------- 2) Partial 5m candles from 1-minute data ----------
        // v35.0 KEY DIFFERENCE: Instead of querying five_minute_prices (completed 5m bars),
        // we build partial 5-minute candles from the one_minute_prices table.
        // This allows detecting momentum mid-bar — as soon as 1+ minutes have elapsed
        // in the current 5-minute window, we have actionable data.
        //
        // 5-minute bucket = floor(minute / 5) * 5, grouped by (ts_est truncated to 5m).
        // High = MAX(high), Low = MIN(low), Close = price of most recent 1m bar,
        // Volume = SUM(volume).
        $sql = "
WITH universe AS (
  SELECT ? AS asset_type, symbol
  FROM (SELECT 1) t
  CROSS JOIN (
    SELECT DISTINCT symbol
    FROM one_minute_prices
    WHERE asset_type = ?
      AND symbol IN ($placeholders)
  ) s
),
-- Build partial 5-minute candles from 1-minute bars
one_min_agg AS (
  SELECT
    symbol,
    asset_type,
    DATE_FORMAT(ts_est, '%Y-%m-%d %H:%i:00') AS min_ts,
    high,
    low,
    price,
    volume,
    -- 5-minute bucket floor
    CONCAT(
      DATE(ts_est),
      ' ',
      LPAD(HOUR(ts_est), 2, '0'),
      ':',
      LPAD(FLOOR(MINUTE(ts_est) / 5) * 5, 2, '0'),
      ':00'
    ) AS five_min_bucket
  FROM one_minute_prices
  WHERE asset_type = ?
    AND symbol IN ($placeholders)
    AND ts_est <= ?
    AND ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
),
partial_5m AS (
  SELECT
    symbol,
    asset_type,
    five_min_bucket AS ts_est,
    MAX(high) AS high,
    MIN(low) AS low,
    -- Close = price of most recent 1m bar in bucket
    (SELECT o2.price
     FROM one_minute_prices o2
     WHERE o2.symbol = om.symbol
       AND o2.asset_type = om.asset_type
       AND o2.ts_est >= om.five_min_bucket
       AND o2.ts_est < DATE_ADD(om.five_min_bucket, INTERVAL 5 MINUTE)
     ORDER BY o2.ts_est DESC
     LIMIT 1) AS close,
    SUM(volume) AS volume,
    COUNT(*) AS bar_count
  FROM one_min_agg om
  GROUP BY symbol, asset_type, five_min_bucket
),
base AS (
  SELECT
    symbol,
    asset_type,
    ts_est,
    close,
    high,
    low,
    volume,
    LAG(close, 1) OVER (PARTITION BY symbol, asset_type ORDER BY ts_est) AS prev_close,
    ROW_NUMBER() OVER (PARTITION BY symbol, asset_type ORDER BY ts_est DESC) AS rn_desc
  FROM partial_5m
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
            [$assetType],
            $symbols,
            [$asOfTsEst, $asOfTsEst, $lookbackMinutes, $asOfTsEst, $activeWindowMinutes],
            [$moveBars, $rvolLookback, $atrPeriod]
        );

        // Cache for 60 seconds — partial candles update every 1 minute.
        $bucketTs = date('Y-m-d H:i', strtotime(floor(strtotime($asOfTsEst) / 60) * 60));
        $cacheKey = "scan_v35_0:{$assetType}:{$bucketTs}:{$lookbackMinutes}";
        $rows = Cache::get($cacheKey);
        if ($rows === null) {
            $lock = Cache::lock("lock:{$cacheKey}", 30);
            if ($lock->get()) {
                try {
                    $rows = $this->dbSelect($sql, $params);
                    Cache::put($cacheKey, $rows, 55);
                } finally {
                    $lock->release();
                }
            } else {
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

        $debugEnabled = ((string) env('SCANNER_V35_DEBUG', '0') === '1')
          || (bool) config('trading.v35.debug', false);
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
                'signal_type' => 'MOMO_5M_V35',
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
            Log::info('[ScannerV35_0] gate summary', [
                'as_of' => $asOfTsEst,
                'asset_type' => $assetType,
                'universe_size' => count($symbols),
                'gates' => $dropCounts,
                'returned' => min(max(1, $limit), count($out)),
                'partial_candle_mode' => true,
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
