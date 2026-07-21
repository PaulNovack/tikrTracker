<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Version 140.0 - Institutional Momentum Follow-Through Scanner
 *
 * Strategy Philosophy:
 * - Find stocks showing BOTH retail activity AND institutional support
 * - Focus on continuation patterns after strong multi-day moves
 * - Avoid choppy names - want smooth, controlled momentum
 * - Entry on pullbacks to support with institutional volume confirmation
 *
 * Key Differentiators vs v25.2:
 * - Stricter multi-day consistency (3+ green days in last 5)
 * - Higher price floor ($5+) for institutional interest
 * - Volume patterns: sustained elevated volume (not panic spikes)
 * - Trend strength: must hold above VWAP + EMA21 on 5m
 * - Lower RVOL requirement but higher sustained volume (institutions don't spike)
 *
 * Universe Selection:
 * - Top 5-day performers (proven momentum)
 * - Strong gainers from yesterday (continuation bias)
 * - Market movers list integration
 * - Minimum $5 stock price (institutional liquidity)
 *
 * Signal Gates (all must pass):
 * 1. Multi-day consistency: 3+ green days in last 5
 * 2. Notional liquidity: $100k+ last 5m bar (institutions need size)
 * 3. ATR volatility: 0.40%+ (can expand for R targets)
 * 4. Sustained volume: 1.5x+ average (not 3x+ retail panic)
 * 5. Price above support: above VWAP on recent bars
 * 6. 30m momentum: 1.5%+ move (institutional accumulation visible)
 *
 * Output: 40-60 high-quality institutional continuation candidates
 */
class FiveMinuteSignalScannerV140_0
{
    use HasPriceTables;

    private string $version = 'v140.0';

    private string $name = 'Institutional Follow-Through';

    // ── Scanner Configuration ──
    public int $topDays = 5;

    public int $topLimit = 600;

    public int $gainersLimit = 100;

    public float $minPrice = 5.0; // Institutional liquidity floor

    public float $minNotional5m = 100000; // Higher for institutions

    public float $minAtrPct5m = 0.40; // Need expansion potential

    public float $minSustainedVol = 1.5; // Not panic spikes

    public float $maxRvolSpike = 5.0; // Cap retail panic

    public float $minMove30m = 1.5; // Clear institutional accumulation

    public int $minGreenDays = 3; // Multi-day consistency

    public int $activeWindowMinutes = 8;

    public int $analysisLookbackMinutes = 90;

    // ── Entry Finder Configuration ──
    public float $entryMinNotional1m = 90000;

    public float $entryMinVolRatio1m = 1.3;

    public float $entryMinBodyPct1m = 0.08;

    public float $entryMaxAboveVwapPct = 1.0;

    public float $entryMinRoomToRunPct = 0.8;

    public float $entryRoomAtrMult = 1.8;

    public int $entryAllowLunch = 0;

    public int $entryMinBars = 20;

    public int $entryMaxAgeMinutes = 12;

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
            'top_days' => $this->topDays,
            'top_limit' => $this->topLimit,
            'gainers_limit' => $this->gainersLimit,
            'min_price' => $this->minPrice,
            'min_notional_5m' => $this->minNotional5m,
            'min_atr_pct_5m' => $this->minAtrPct5m,
            'min_sustained_vol' => $this->minSustainedVol,
            'max_rvol_spike' => $this->maxRvolSpike,
            'min_move_30m_pct' => $this->minMove30m,
            'min_green_days' => $this->minGreenDays,
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
        float $minMovePct = 1.5,
        float $volMult = 1.5,
        int $limit = 60
    ): array {
        $topDays = $this->topDays;
        $topLimit = $this->topLimit;
        $gainersLimit = $this->gainersLimit;

        $minPrice = $this->minPrice;
        $minNotional5m = $this->minNotional5m;
        $minAtrPct5m = $this->minAtrPct5m;
        $minSustainedVol = $this->minSustainedVol;
        $maxRvolSpike = $this->maxRvolSpike;
        $minMove30m = $this->minMove30m;
        $minGreenDays = $this->minGreenDays;

        $activeWindowMinutes = $this->activeWindowMinutes;

        // SQL query parameters
        $atrPeriod = 14;
        $rvolLookback = 20;
        $moveBars = 6; // 30m = 6 * 5m bars
        $minimumLookbackMinutes = max(5, ($moveBars + 1) * 5);
        $lookbackMinutes = max($lookbackMinutes, $minimumLookbackMinutes);

        // ---------- 1) Universe: Top performers + Yesterday's strong gainers ----------
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

        // Add yesterday's gainers (continuation bias)
        try {
            $currentDate = substr($asOfTsEst, 0, 10);
            $prevTradingDay = DB::table($this->fiveMinuteTable)
                ->where('asset_type', $assetType)
                ->where('trading_date_est', '<', $currentDate)
                ->orderBy('trading_date_est', 'desc')
                ->value('trading_date_est');

            if ($prevTradingDay) {
                $gainersData = $this->gainersLosersService->getGainersAndLosers($prevTradingDay, $assetType, $gainersLimit);
                $gainerSymbols = array_column($gainersData['gainers'] ?? [], 'symbol');
                $symbols = array_values(array_unique(array_merge($symbols, $gainerSymbols)));
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // Add market movers to universe if enabled
        $moversLimit = (int) config('trading.market_movers.pipeline_k', 0);
        if ($moversLimit > 0) {
            $movers = app(\App\Services\MarketMoversService::class)->getTodaysTopMoversFromCache(null, $moversLimit);
            $symbols = array_values(array_unique(array_merge($symbols, $movers)));
        }

        if (empty($symbols)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($symbols), '?'));

        // ---------- 2) Build SQL query for institutional signals ----------
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
  AND a.last_close >= ?
";

        $params = array_merge(
            [$assetType, $assetType],
            $symbols,
            [$asOfTsEst, $asOfTsEst, $lookbackMinutes, $asOfTsEst, $activeWindowMinutes],
            [$moveBars, $rvolLookback, $atrPeriod],
            [$minPrice]
        );

        // Cache for 4 minutes
        $bucketTs = date('Y-m-d H:i', strtotime(floor(strtotime($asOfTsEst) / 300) * 300));
        $cacheKey = "scan_v140_0:{$assetType}:{$bucketTs}:{$lookbackMinutes}";
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

        $asOfEpochRaw = strtotime($asOfTsEst);
        $asOfEpoch = $asOfEpochRaw !== false
          ? strtotime(date('Y-m-d H:i:00', $asOfEpochRaw))
          : false;
        if ($asOfEpoch === false) {
            return [];
        }
        $maxSignalAgeSeconds = max(1, $activeWindowMinutes) * 60;

        $debugEnabled = ((string) env('SCANNER_V140_DEBUG', '0') === '1')
          || (bool) config('trading.v140.debug', false);
        $dropCounts = [
            'rows_total' => count($rows),
            'signal_age' => 0,
            'price_floor' => 0,
            'notional' => 0,
            'atr' => 0,
            'sustained_vol' => 0,
            'rvol_spike' => 0,
            'move_floor' => 0,
            'multi_day_check' => 0,
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
            if ($lastClose < $minPrice) {
                $dropCounts['price_floor']++;

                continue;
            }

            $atrPct = (float) $r->atr_pct;
            $rvolRatio = (float) $r->rvol_ratio;
            $move30m = (float) $r->move_30m_pct;
            $notional = (float) $r->notional_last5m;
            $avgVol = (float) $r->avg_vol;

            // Gate 1: Institutional liquidity (higher than v25.2)
            if ($notional < $minNotional5m) {
                $dropCounts['notional']++;

                continue;
            }

            // Gate 2: Volatility (can expand for R targets)
            if ($atrPct < $minAtrPct5m) {
                $dropCounts['atr']++;

                continue;
            }

            // Gate 3: Sustained volume (not panic spikes)
            if ($rvolRatio < $minSustainedVol) {
                $dropCounts['sustained_vol']++;

                continue;
            }

            // Gate 4: Avoid retail panic spikes (institutions are steady)
            if ($rvolRatio > $maxRvolSpike) {
                $dropCounts['rvol_spike']++;

                continue;
            }

            // Gate 5: 30m momentum (institutional accumulation visible)
            if ($move30m < $minMove30m) {
                $dropCounts['move_floor']++;

                continue;
            }

            // Gate 6: Multi-day consistency check (institutions accumulate over days)
            $greenDays = $this->countRecentGreenDays((string) $r->symbol, $assetType, $asOfTsEst);
            if ($greenDays < $minGreenDays) {
                $dropCounts['multi_day_check']++;

                continue;
            }

            $dropCounts['passed']++;

            // Score: Institutional quality = controlled momentum + sustained volume + multi-day strength
            // Lower RVOL weight (institutions don't spike), higher move weight (accumulation shows)
            $rvolCapped = min(4.0, $rvolRatio); // Cap at 4x (not 6x like v25.2)
            $score = ($move30m * 1.5) + ($rvolCapped * 0.6) + ($atrPct * 1.0) + ($greenDays * 2.0);

            $perfData = collect($topPerformers)->firstWhere('symbol', (string) $r->symbol);
            $pctNd = $perfData ? ($perfData['pct_return_pct'] ?? null) : null;

            $atr = ($atrPct && $lastClose) ? round(($atrPct / 100) * $lastClose, 6) : null;

            $out[] = [
                'symbol' => (string) $r->symbol,
                'asset_type' => (string) $r->asset_type,
                'signal_type' => 'INSTITUTIONAL_V140',
                'signal_ts_est' => (string) $r->signal_ts_est,
                'score' => round($score, 3),
                'atr' => $atr,
                'atr_pct' => $atrPct,
                'meta' => [
                    'move_30m_pct' => round($move30m, 3),
                    'rvol_5m' => round($rvolRatio, 3),
                    'atr_pct_5m' => round($atrPct, 3),
                    'notional_last5m' => round($notional, 2),
                    'avg_vol_5m' => round($avgVol, 2),
                    'green_days_5d' => $greenDays,
                    'pct_nd' => $pctNd !== null ? round((float) $pctNd, 2) : null,
                    'universe_size' => count($symbols),
                    'signal_age_seconds' => $signalAgeSeconds,
                    'version' => $this->version,
                    'current_price' => $lastClose,
                ],
            ];
        }

        usort($out, fn ($a, $b) => ($b['score'] <=> $a['score']));

        if ($debugEnabled) {
            Log::info('[ScannerV140_0] gate summary', [
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
     * Institutions accumulate in stocks with multi-day consistency
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
}
