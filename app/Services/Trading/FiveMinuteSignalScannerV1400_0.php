<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Version 1400.0 - Tight Stops Clean Trend Scanner
 *
 * Strategy: Identify smooth 2-hour uptrends with minimal drawdowns suitable for tight stops
 *
 * Requirements:
 * - Lookback: 120 minutes (24 five-minute bars)
 * - Pattern: Clean uptrend with max 1% peak-to-trough drawdown
 * - Minimum: 0.5% price gain from start to end
 * - Scoring: Risk-adjusted (Trend % ÷ Max Drawdown %)
 * - Target: Stocks suitable for 0.5-1% tight stop loss
 *
 * This captures low-choppiness intraday trends with favorable risk/reward for tight stops.
 */
class FiveMinuteSignalScannerV1400_0
{
    use HasPriceTables;

    private string $version = 'v1400.0';

    private string $name = 'Tight Stops Clean Trend';

    // ── Scanner Configuration (public so entry finders can read) ──
    public int $lookbackMinutes = 120;

    public float $minTrendPct = 0.50;

    public float $maxDrawdownPct = 1.00;

    public int $minBars = 3;

    public float $minPrice = 3.0;

    public float $maxPrice = 500.0;

    public int $topN = 50;

    public string $timeWindowStart = '09:40:00';

    public string $timeWindowEnd = '15:30:00';

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
            'lookback_minutes' => $this->lookbackMinutes,
            'min_trend_pct' => $this->minTrendPct,
            'max_drawdown_pct' => $this->maxDrawdownPct,
            'min_bars' => $this->minBars,
            'min_price' => $this->minPrice,
            'max_price' => $this->maxPrice,
            'top_n' => $this->topN,
            'time_window_start' => $this->timeWindowStart,
            'time_window_end' => $this->timeWindowEnd,
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

    public function scan(
        string $assetType,
        string $asOfTsEst
    ): array {
        // Load all config from trading.v1400
        $lookbackMinutes = $this->lookbackMinutes;
        $minTrendPct = $this->minTrendPct;
        $maxDrawdownPct = $this->maxDrawdownPct;
        $minBars = $this->minBars;
        $minPrice = $this->minPrice;
        $maxPrice = $this->maxPrice;
        $topN = $this->topN;
        $timeWindowStart = $this->timeWindowStart;
        $timeWindowEnd = $this->timeWindowEnd;

        // \Log::debug("[V1400 Scanner] lookback={$lookbackMinutes}min, minTrend>={$minTrendPct}%, maxDD<={$maxDrawdownPct}%, asOf={$asOfTsEst}");

        $tradeDate = substr($asOfTsEst, 0, 10);
        $lookbackStartTsEst = date('Y-m-d H:i:s', strtotime($asOfTsEst) - ($lookbackMinutes * 60));

        // Add market movers to universe if enabled
        $moversLimit = (int) config('trading.market_movers.pipeline_m', 0);
        $moverClause = '';
        if ($moversLimit > 0) {
            $moverSymbols = app(\App\Services\MarketMoversService::class)->getTodaysTopMoversFromCache(null, $moversLimit);
            if (! empty($moverSymbols)) {
                $moverClause = "AND f.symbol IN ('".implode("','", $moverSymbols)."')";
            }
        }
        // Find clean 2-hour trends with minimal drawdowns
        $sql = "
WITH price_window AS (
    SELECT 
        f.symbol,
        f.asset_type,
        f.trading_date_est,
        f.ts_est,
        f.trading_time_est,
        f.price as close,
        f.open,
        f.high,
        f.low,
        f.volume,
        f.vwap,
        ROW_NUMBER() OVER (PARTITION BY f.symbol ORDER BY f.ts_est ASC) as bar_num,
        ROW_NUMBER() OVER (PARTITION BY f.symbol ORDER BY f.ts_est DESC) as reverse_bar_num,
        COUNT(*) OVER (PARTITION BY f.symbol) as total_bars
    FROM five_minute_prices f
    WHERE f.asset_type = ?
        AND f.trading_date_est = ?
        AND f.ts_est >= ?
        AND f.ts_est <= ?
        AND f.trading_time_est BETWEEN ? AND ?
        AND f.price BETWEEN ? AND ?
        AND f.open > 0
        {$moverClause}
),
-- Calculate running highs to find peak prices for drawdown calculation
running_peaks AS (
    SELECT 
        symbol,
        asset_type,
        trading_date_est,
        ts_est,
        close,
        bar_num,
        reverse_bar_num,
        total_bars,
        MAX(close) OVER (PARTITION BY symbol ORDER BY ts_est ASC ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as peak_so_far
    FROM price_window
),
-- Calculate metrics for each symbol
trend_metrics AS (
    SELECT 
        symbol,
        asset_type,
        trading_date_est,
        total_bars,
        MAX(CASE WHEN reverse_bar_num = 1 THEN ts_est END) as latest_ts_est,
        MAX(CASE WHEN reverse_bar_num = 1 THEN close END) as latest_close,
        MAX(CASE WHEN bar_num = 1 THEN close END) as start_close,
        MAX(peak_so_far) as overall_peak,
        -- Calculate max drawdown: find largest peak-to-trough decline
        MAX(
            ((peak_so_far - close) / NULLIF(peak_so_far, 0)) * 100
        ) as max_drawdown_pct
    FROM running_peaks
    GROUP BY symbol, asset_type, trading_date_est, total_bars
    HAVING total_bars >= ?
),
-- Calculate final metrics and risk score
scored_trends AS (
    SELECT 
        symbol,
        asset_type,
        trading_date_est,
        latest_ts_est as signal_ts_est,
        latest_close as setup_price,
        start_close,
        overall_peak,
        total_bars as bars,
        ROUND(((latest_close - start_close) / NULLIF(start_close, 0)) * 100, 3) as trend_pct,
        ROUND(COALESCE(max_drawdown_pct, 0), 3) as max_drawdown_pct,
        -- Risk-adjusted score: higher trend with lower drawdown = better
        ROUND(
            ((latest_close - start_close) / NULLIF(start_close, 0)) * 100 / 
            GREATEST(NULLIF(max_drawdown_pct, 0), 0.10),  -- Avoid division by zero, min 0.10%
            2
        ) as risk_score
    FROM trend_metrics
    WHERE ((latest_close - start_close) / NULLIF(start_close, 0)) * 100 >= ?  -- Min trend %
        AND COALESCE(max_drawdown_pct, 0) <= ?  -- Max drawdown %
        AND latest_close >= start_close  -- Must be uptrending
)
SELECT 
    symbol,
    asset_type,
    trading_date_est,
    signal_ts_est,
    setup_price,
    start_close,
    overall_peak,
    bars,
    trend_pct,
    max_drawdown_pct,
    risk_score,
    100 as entry_score
FROM scored_trends
ORDER BY risk_score DESC, trend_pct DESC
LIMIT ?
        ";

        $params = [
            $assetType,
            $tradeDate,
            $lookbackStartTsEst,
            $asOfTsEst,
            $timeWindowStart,
            $timeWindowEnd,
            $minPrice,
            $maxPrice,
            $minBars,
            $minTrendPct,
            $maxDrawdownPct,
            $topN,
        ];

        // Cache for 4 minutes — 5m bars update every 5 minutes, so the trend
        // universe is stable within a 5m window. Multiple pipeline fires per
        // minute will share one DB hit.
        $bucketTs = date('Y-m-d H:i', strtotime(floor(strtotime($asOfTsEst) / 300) * 300));
        $cacheKey = "scan_v1400:{$assetType}:{$bucketTs}";
        $rows = Cache::remember($cacheKey, 240, fn () => $this->dbSelect($sql, $params));

        if (empty($rows)) {
            // \Log::debug('[V1400 Scanner] No signals found matching clean trend criteria');

            return [];
        }

        \Log::debug('[V1400 Scanner] Found '.count($rows).' clean trend signals');

        return array_map(function ($row) {
            return [
                'symbol' => $row->symbol,
                'asset_type' => $row->asset_type,
                'signal_type' => 'CLEAN_TREND_2H',
                'signal_ts_est' => $row->signal_ts_est,
                'score' => $row->entry_score,
                'setup_price' => (float) $row->setup_price,
                'trend_pct' => (float) $row->trend_pct,
                'max_drawdown_pct' => (float) $row->max_drawdown_pct,
                'risk_score' => (float) $row->risk_score,
                'bars' => (int) $row->bars,
                'meta' => [
                    'setup_price' => (float) $row->setup_price,
                    'start_close' => (float) $row->start_close,
                    'overall_peak' => (float) $row->overall_peak,
                    'trend_pct' => (float) $row->trend_pct,
                    'max_drawdown_pct' => (float) $row->max_drawdown_pct,
                    'risk_score' => (float) $row->risk_score,
                    'bars' => (int) $row->bars,
                ],
            ];
        }, $rows);
    }
}
