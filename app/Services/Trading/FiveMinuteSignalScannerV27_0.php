<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Version 27.0 - Volume-First Scanner (Pipeline Q)
 *
 * Strategy Philosophy:
 * - Wider net than v25.2 but tighter entry quality = more trades with same predictability
 * - Volume is the leading indicator: catch names BEFORE big moves
 * - Relax scanner gates to let through 2x more candidates, then rely on
 *   the entry finder's quality filters to keep predictability high
 * - Add ORB breakout pattern for earlier entries (catch the breakout, not the retest)
 *
 * Key Changes vs v25.2:
 * - Lower notional floor ($50k vs $75k) — catches mid-caps showing divergence
 * - Lower RVOL requirement (1.5x vs 2.0x) — catches early accumulation
 * - Lower RS threshold (1.05x vs 1.10x) — more names in strong markets
 * - Lower move floor (0.6% vs 1.2%) — earlier entries before big acceleration
 * - Wider universe (600 + 100 losers vs 500 + 75)
 * - Price floor $3.00 to filter truly illiquid penny stocks
 * - Multi-day consistency check (2+ green days) to avoid one-day wonders
 * - Adds market movers to universe (when configured)
 *
 * Gates (all must pass):
 * 1. Price floor: $3.00
 * 2. Notional: $50k+ last 5m bar
 * 3. ATR: 0.25%+ (minimal expansion potential)
 * 4. Volume: 1.5x+ average (early accumulation, not panic)
 * 5. RVOL cap: 8.0x max (filter data errors)
 * 6. Move 30m: 0.6%+ (gentle upward drift)
 * 7. Multi-day: 2+ green days in last 5 (some consistency)
 * 8. RS filter (optional): 1.05x SPY
 *
 * Output: 60-100 high-volume early-momentum candidates
 */
class FiveMinuteSignalScannerV27_0
{
    use HasPriceTables;

    private string $version = 'v27.0';

    private string $name = 'Volume-First';

    // ── Scanner Configuration ──
    public int $topDays = 5;

    public int $topLimit = 600;

    public int $losersLimit = 100;

    public float $minPrice = 2.0;

    public float $minNotional5m = 30000;

    public float $minAtrPct5m = 0.15;

    public float $minRvol5m = 1.2;

    public float $minMove30m = 0.4;

    public float $maxRvolSpike = 10.0;

    public int $minGreenDays = 1;

    public float $minRsMultVsSpy = 1.02;

    public int $activeWindowMinutes = 8;

    public int $analysisLookbackMinutes = 90;

    // ── Entry Finder Configuration ──
    public float $entryMinNotional1m = 100000;

    public float $entryMinVolRatio1m = 1.5;

    public float $entryMinBodyPct1m = 0.10;

    public float $entryMaxAboveVwapPct = 0.75;

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
            'min_price' => $this->minPrice,
            'min_notional_5m' => $this->minNotional5m,
            'min_atr_pct_5m' => $this->minAtrPct5m,
            'min_rvol_5m' => $this->minRvol5m,
            'min_move_30m_pct' => $this->minMove30m,
            'max_rvol_spike' => $this->maxRvolSpike,
            'min_green_days' => $this->minGreenDays,
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
        float $minMovePct = 0.6,
        float $volMult = 1.5,
        int $limit = 60
    ): array {
        $topDays = $this->topDays;
        $topLimit = $this->topLimit;
        $losersLimit = $this->losersLimit;

        $minPrice = $this->minPrice;
        $minNotional5m = $this->minNotional5m;
        $minAtrPct5m = $this->minAtrPct5m;
        $minRvol5m = $this->minRvol5m;
        $minMove30m = $this->minMove30m;
        $maxRvolSpike = $this->maxRvolSpike;
        $minGreenDays = $this->minGreenDays;

        $activeWindowMinutes = $this->activeWindowMinutes;
        $atrPeriod = 14;
        $rvolLookback = 20;
        $moveBars = 6; // 6*5m = 30m
        $minimumLookbackMinutes = max(5, ($moveBars + 1) * 5);
        $lookbackMinutes = max($lookbackMinutes, $minimumLookbackMinutes);

        // ---------- 1) Universe: top performers + prior-day losers + market movers ----------
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

        // Add market movers if configured
        $moversLimit = (int) config('trading.market_movers.pipeline_q', 0);
        if ($moversLimit > 0) {
            $movers = app(\App\Services\MarketMoversService::class)->getTodaysTopMoversFromCache(null, $moversLimit);
            $symbols = array_values(array_unique(array_merge($symbols, $movers)));
        }

        if (empty($symbols)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($symbols), '?'));

        // ---------- 2) SQL: volume + momentum gates ----------
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

        // Cache for 4 minutes
        $bucketTs = date('Y-m-d H:i', strtotime(floor(strtotime($asOfTsEst) / 300) * 300));
        $cacheKey = "scan_v27_0:{$assetType}:{$bucketTs}:{$lookbackMinutes}";
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

        // SPY relative strength
        $spyMove30m = $this->getSpyMovement30m($asOfTsEst, $assetType, $moveBars);

        $asOfEpochRaw = strtotime($asOfTsEst);
        $asOfEpoch = $asOfEpochRaw !== false
          ? strtotime(date('Y-m-d H:i:00', $asOfEpochRaw))
          : false;
        if ($asOfEpoch === false) {
            return [];
        }
        $maxSignalAgeSeconds = max(1, $activeWindowMinutes) * 60;

        $debugEnabled = ((string) env('SCANNER_V27_DEBUG', '0') === '1')
          || (bool) config('trading.v27.debug', false);
        $dropCounts = [
            'rows_total' => count($rows),
            'signal_age' => 0,
            'price_floor' => 0,
            'notional' => 0,
            'atr' => 0,
            'rvol_floor' => 0,
            'rvol_spike' => 0,
            'move_floor' => 0,
            'multi_day_check' => 0,
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
                $dropCounts['price_floor']++;

                continue;
            }

            $atrPct = (float) $r->atr_pct;
            $rvolRatio = (float) $r->rvol_ratio;
            $move30m = (float) $r->move_30m_pct;
            $notional = (float) $r->notional_last5m;

            // Gate 1: Price floor
            if ($lastClose < $minPrice) {
                $dropCounts['price_floor']++;

                continue;
            }

            // Gate 2: Notional liquidity
            if ($notional < $minNotional5m) {
                $dropCounts['notional']++;

                continue;
            }

            // Gate 3: ATR volatility floor
            if ($atrPct < $minAtrPct5m) {
                $dropCounts['atr']++;

                continue;
            }

            // Gate 4: RVOL floor (minimum volume activity)
            if ($rvolRatio < $minRvol5m) {
                $dropCounts['rvol_floor']++;

                continue;
            }

            // Gate 5: RVOL cap (data errors / panic spikes)
            if ($rvolRatio > $maxRvolSpike) {
                $dropCounts['rvol_spike']++;

                continue;
            }

            // Gate 6: 30m momentum floor
            if ($move30m < $minMove30m) {
                $dropCounts['move_floor']++;

                continue;
            }

            // Gate 7: Multi-day consistency
            $greenDays = $this->countRecentGreenDays((string) $r->symbol, $assetType, $asOfTsEst);
            if ($greenDays < $minGreenDays) {
                $dropCounts['multi_day_check']++;

                continue;
            }

            // Gate 8 (optional): Relative strength vs SPY
            $enableRsFilter = (bool) config('trading.enable_relative_strength_filter', false);
            $minRsMult = (float) config('trading.v27.scanner.min_rs_mult_vs_spy', 1.05);
            if ($enableRsFilter && $spyMove30m > 0.10 && $move30m < $spyMove30m * $minRsMult) {
                $dropCounts['rs_filter']++;

                continue;
            }

            $dropCounts['passed']++;

            // Score: volume-weighted momentum + multi-day consistency
            // Lower RVOL weight (not chasing spikes), higher move weight + green days
            $rvolCapped = min(5.0, $rvolRatio);
            $score = ($move30m * 1.3) + ($rvolCapped * 0.8) + ($atrPct * 0.6) + ($greenDays * 2.5);

            $perfData = collect($topPerformers)->firstWhere('symbol', (string) $r->symbol);
            $pctNd = $perfData ? ($perfData['pct_return_pct'] ?? null) : null;

            $atr = ($atrPct && $lastClose) ? round(($atrPct / 100) * $lastClose, 6) : null;

            $out[] = [
                'symbol' => (string) $r->symbol,
                'asset_type' => (string) $r->asset_type,
                'signal_type' => 'VOLUME_FIRST_V27',
                'signal_ts_est' => (string) $r->signal_ts_est,
                'score' => round($score, 3),
                'atr' => $atr,
                'atr_pct' => $atrPct,
                'meta' => [
                    'move_30m_pct' => round($move30m, 3),
                    'rvol_5m' => round($rvolRatio, 3),
                    'atr_pct_5m' => round($atrPct, 3),
                    'notional_last5m' => round($notional, 2),
                    'avg_vol_5m' => round((float) $r->avg_vol, 2),
                    'green_days_5d' => $greenDays,
                    'pct_nd' => $pctNd !== null ? round((float) $pctNd, 2) : null,
                    'spy_move_30m_pct' => round($spyMove30m, 3),
                    'universe_size' => count($symbols),
                    'signal_age_seconds' => $signalAgeSeconds,
                    'version' => $this->version,
                    'current_price' => $lastClose,
                ],
            ];
        }

        usort($out, fn ($a, $b) => ($b['score'] <=> $a['score']));

        if ($debugEnabled) {
            Log::info('[ScannerV27_0] gate summary', [
                'as_of' => $asOfTsEst,
                'asset_type' => $assetType,
                'universe_size' => count($symbols),
                'gates' => $dropCounts,
                'returned' => min(max(1, $limit), count($out)),
            ]);
        }

        return array_slice($out, 0, max(1, $limit));
    }

    /**
     * Count how many of the last 5 trading days closed green (close > open)
     */
    private function countRecentGreenDays(string $symbol, string $assetType, string $asOfTsEst): int
    {
        $currentDate = substr($asOfTsEst, 0, 10);

        $days = DB::table('daily_prices')
            ->where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->where('date', '<=', $currentDate)
            ->orderBy('date', 'desc')
            ->limit(5)
            ->get(['open', 'price']);

        $greenCount = 0;
        foreach ($days as $day) {
            if ($day->price > $day->open) {
                $greenCount++;
            }
        }

        return $greenCount;
    }

    /**
     * Get SPY 30m movement for relative strength comparison
     */
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
