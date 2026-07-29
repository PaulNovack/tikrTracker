<?php

namespace App\Services\Trading;

/**
 * Version 830.0 - Multi-Day Trend + Range Position Scanner (LONG)
 *
 * Strategy: Identify stocks in strong multi-day trends with high-quality intraday entries
 *
 * Requirements:
 * - Daily context: 10%+ gain over prior 5 days (strong trend)
 * - Intraday position: Entry at top 20% of 60-minute range (breakout/continuation)
 * - Based on v810 EMA momentum pullback foundation
 *
 * Key Finding from Analysis:
 * - Strong 5-day trend (10-15%) + Top 20% range position = 59.76% win rate (82 trades)
 * - Very Strong 5-day trend (>15%) + Top 20% range position = 58.65% win rate (133 trades)
 * - This filter reduces v810's 2,908 trades to ~215 highest-quality setups
 *
 * This captures stocks with established momentum continuing through intraday breakouts.
 */
class FiveMinuteSignalScannerV830_0
{
    use HasPriceTables;

    private string $version = 'v830.0';

    private string $name = 'Intraday Breakout/Reversal';

    // ── Scanner Configuration (public so entry finders can read) ──
    public float $entryScoreMin;

    public float $entryScoreMax;

    public int $entryScoreLimit;

    public float $minPrice;

    public float $maxPrice;

    public string $timeWindowStart;

    public string $timeWindowEnd;

    public float $minDailyTrendPct;

    public float $minRangePosition;

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
            'entry_score_min' => $this->entryScoreMin,
            'entry_score_max' => $this->entryScoreMax,
            'entry_score_limit' => $this->entryScoreLimit,
            'min_price' => $this->minPrice,
            'max_price' => $this->maxPrice,
            'time_window_start' => $this->timeWindowStart,
            'time_window_end' => $this->timeWindowEnd,
            'min_daily_trend_pct' => $this->minDailyTrendPct,
            'min_range_position' => $this->minRangePosition,
        ];
    }

    public function __construct()
    {
        $this->entryScoreMin = (float) env('TRADING_V830_ENTRY_SCORE_MIN', 50);
        $this->entryScoreMax = (float) env('TRADING_V830_ENTRY_SCORE_MAX', 100);
        $this->entryScoreLimit = (int) env('TRADING_V830_ENTRY_SCORE_LIMIT', 25);
        $this->minPrice = (float) env('TRADING_V830_MIN_PRICE', 5.0);
        $this->maxPrice = (float) env('TRADING_V830_MAX_PRICE', 300.0);
        $this->timeWindowStart = env('TRADING_V830_TIME_WINDOW_START', '09:50:00');
        $this->timeWindowEnd = env('TRADING_V830_TIME_WINDOW_END', '14:30:00');
        $this->minDailyTrendPct = (float) env('TRADING_V830_MIN_DAILY_TREND_PCT', 10.0);
        $this->minRangePosition = (float) env('TRADING_V830_MIN_RANGE_POSITION', 0.80);
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
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minMovePct = -0.5,
        float $volMult = 1.0,
        int $limit = 50
    ): array {
        $minScore = $this->entryScoreMin;
        $maxScore = $this->entryScoreMax;
        $topN = $this->entryScoreLimit;
        $minPrice = $this->minPrice;
        $maxPrice = $this->maxPrice;
        $timeWindowStart = $this->timeWindowStart;
        $timeWindowEnd = $this->timeWindowEnd;
        $minDailyTrendPct = $this->minDailyTrendPct;
        $minRangePosition = $this->minRangePosition;

        \Log::debug("[V830 Scanner] minScore={$minScore}, dailyTrend>={$minDailyTrendPct}%, rangePos>={$minRangePosition}, asOf={$asOfTsEst}");

        if ($topN <= 0) {
            $topN = 25;
        }
        if ($maxScore <= 0) {
            $maxScore = 100.0;
        }
        if ($minScore > $maxScore) {
            [$minScore, $maxScore] = [$maxScore, $minScore];
        }

        $limit = max(1, (int) $limit);
        $tradeDate = substr($asOfTsEst, 0, 10);

        // Add market movers to universe if enabled
        $moversLimit = (int) config('trading.market_movers.pipeline_j', 0);
        $moverSymbols = [];
        if ($moversLimit > 0) {
            $moverSymbols = app(\App\Services\MarketMoversService::class)->getTodaysTopMoversFromCache(null, $moversLimit);
        }

        // Build SQL movers filter
        $moversFilter = '';
        $moversBindings = [];
        if (! empty($moverSymbols)) {
            $placeholders = implode(',', array_fill(0, count($moverSymbols), '?'));
            $moversFilter = " OR f.symbol IN ($placeholders) ";
            $moversBindings = $moverSymbols;
        }

        // Simplified query without window functions - uses subqueries instead for speed
        $sql = '
SELECT
    f.symbol,
    f.trading_date_est,
    f.ts_est AS signal_ts_est,
    f.trading_time_est,
    f.price AS setup_price,
    f.open,
    f.high,
    f.low,
    f.volume,
    f.vwap,
    f.vwap_dist_pct,
    f.ema9,
    f.ema21,
    f.ema9_ema21_spread,
    f.atr,
    f.atr_pct,
    f.rsi_14,
    
    -- Day open from earliest bar for this symbol/date
    (SELECT open FROM five_minute_prices 
     WHERE symbol = f.symbol 
       AND asset_type = f.asset_type 
       AND trading_date_est = f.trading_date_est
     ORDER BY ts_est ASC LIMIT 1
    ) AS day_open,
    
    -- 20-bar avg volume (bars before current)
    (SELECT AVG(volume) FROM five_minute_prices
     WHERE symbol = f.symbol
       AND asset_type = f.asset_type  
       AND trading_date_est = f.trading_date_est
       AND ts_est < f.ts_est
     ORDER BY ts_est DESC LIMIT 20
    ) AS avg_volume_20,
    
    -- 60-minute (12 bars) range - last hour including current bar
    (SELECT MIN(price) FROM five_minute_prices
     WHERE symbol = f.symbol
       AND asset_type = f.asset_type
       AND trading_date_est = f.trading_date_est  
       AND ts_est <= f.ts_est
       AND ts_est >= DATE_SUB(f.ts_est, INTERVAL 60 MINUTE)
    ) AS range_60m_low,
    
    (SELECT MAX(price) FROM five_minute_prices
     WHERE symbol = f.symbol
       AND asset_type = f.asset_type
       AND trading_date_est = f.trading_date_est
       AND ts_est <= f.ts_est
       AND ts_est >= DATE_SUB(f.ts_est, INTERVAL 60 MINUTE)
    ) AS range_60m_high,
    
    -- 5-day trend: price 5 trading days ago
    (SELECT price FROM daily_prices
     WHERE symbol = f.symbol
       AND asset_type = f.asset_type
       AND date < f.trading_date_est
     ORDER BY date DESC
     LIMIT 1 OFFSET 4
    ) AS price_5d_ago
    
FROM five_minute_prices f
    WHERE f.trading_date_est = ?
  AND f.ts_est <= ?
  AND f.ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
  AND f.trading_time_est BETWEEN ? AND ?
  AND f.price BETWEEN ? AND ?
  AND f.ema9_above_ema21 = 1
  AND f.above_vwap = 1
  AND f.rsi_14 BETWEEN 50 AND 70
  AND f.atr_pct BETWEEN 0.20 AND 3.00
  AND f.ema9 > f.ema21
  AND f.ema9_ema21_spread > 0
  '.$moversFilter.'
HAVING day_open > 0
  AND avg_volume_20 > 0
  AND ((setup_price - day_open) / day_open * 100) BETWEEN 0.2 AND 8.0
  AND ((setup_price - ema9) / ema9 * 100) BETWEEN 0.10 AND 0.50
  AND CASE 
      WHEN (range_60m_high - range_60m_low) > 0 
      THEN (setup_price - range_60m_low) / (range_60m_high - range_60m_low)
      ELSE 0.5
  END >= ?
  AND (price_5d_ago IS NULL OR price_5d_ago = 0 OR ((setup_price - price_5d_ago) / price_5d_ago * 100) >= ?)
ORDER BY 
  ((setup_price - day_open) / day_open * 100) DESC,
  f.ema9_ema21_spread DESC
LIMIT ?
';

        $params = [
            $tradeDate,
            $asOfTsEst,
            $asOfTsEst,
            $lookbackMinutes,
            $timeWindowStart,
            $timeWindowEnd,
            $minPrice,
            $maxPrice,
            ...$moversBindings,
            $minRangePosition,
            $minDailyTrendPct,
            $limit * 2,
        ];
        $rows = $this->dbSelect($sql, $params);

        if (empty($rows)) {
            \Log::debug('[V830 Scanner] No rows returned from SQL query');

            return [];
        }

        // Filter out signals older than 30 minutes to prevent stale alerts
        $maxAgeMinutes = 30;
        $cutoffTime = \Carbon\Carbon::parse($asOfTsEst, 'America/New_York')->subMinutes($maxAgeMinutes);
        $originalCount = count($rows);

        $rows = array_filter($rows, function ($r) use ($cutoffTime) {
            $signalTime = \Carbon\Carbon::parse($r->signal_ts_est, 'America/New_York');

            return $signalTime >= $cutoffTime;
        });

        $filteredCount = $originalCount - count($rows);
        if ($filteredCount > 0) {
            \Log::debug("[V830 Scanner] Filtered out {$filteredCount} stale signals older than {$maxAgeMinutes} minutes");
        }

        if (empty($rows)) {
            \Log::debug('[V830 Scanner] All rows filtered out due to age');

            return [];
        }

        \Log::debug('[V830 Scanner] Processing '.count($rows).' raw rows');

        $cands = [];
        foreach ($rows as $r) {
            $symbol = (string) $r->symbol;
            $setupPrice = (float) ($r->setup_price ?? 0);
            $ema9 = (float) ($r->ema9 ?? 0);
            $ema21 = (float) ($r->ema21 ?? 0);
            $vwapDistPct = (float) ($r->vwap_dist_pct ?? 0);
            $rsi = (float) ($r->rsi_14 ?? 50);
            $atrPct = (float) ($r->atr_pct ?? 0);
            $volume = (float) ($r->volume ?? 0);

            // Calculate derived fields
            $dayOpen = (float) ($r->day_open ?? 0);
            $avgVolume20 = (float) ($r->avg_volume_20 ?? 0);
            $range60mLow = (float) ($r->range_60m_low ?? 0);
            $range60mHigh = (float) ($r->range_60m_high ?? 0);

            $dayChangePct = ($dayOpen > 0) ? (($setupPrice - $dayOpen) / $dayOpen) * 100 : 0;
            $volRatio = ($avgVolume20 > 0) ? ($volume / $avgVolume20) : 0;

            $rangeSpread = $range60mHigh - $range60mLow;
            $rangePosition60m = ($rangeSpread > 0) ? (($setupPrice - $range60mLow) / $rangeSpread) : 0.5;

            $ema9DistPct = ($ema9 > 0) ? (($setupPrice - $ema9) / $ema9) * 100 : 999;
            $ema9_21_spread = ($ema21 > 0) ? (($ema9 - $ema21) / $ema21) * 100 : 0;

            $score = 0.0;

            // A) EMA9 proximity (0..30)
            if ($ema9DistPct >= 0.10 && $ema9DistPct <= 0.50) {
                $score += 30.0 - (($ema9DistPct - 0.10) * 75.0);
            }

            // B) EMA trend strength (0..25)
            if ($ema9_21_spread >= 0.3) {
                $score += min(25.0, ($ema9_21_spread / 2.0) * 25.0);
            }

            // C) Range position (0..20)
            if ($rangePosition60m >= 0.80) {
                $score += 15.0 + (($rangePosition60m - 0.80) * 25.0);
            }

            // D) Day momentum (0..15)
            if ($dayChangePct >= 0.2 && $dayChangePct <= 8.0) {
                if ($dayChangePct <= 2.0) {
                    $score += ($dayChangePct / 2.0) * 15.0;
                } else {
                    $score += 15.0 - (($dayChangePct - 2.0) / 6.0) * 7.5;
                }
            }

            // F) RSI sweet spot (0..5)
            if ($rsi >= 50 && $rsi <= 70) {
                if ($rsi >= 55 && $rsi <= 65) {
                    $score += 5.0;
                } else {
                    $score += 5.0 - abs($rsi - 60) * 0.25;
                }
            }

            $score = round(min(100.0, max(0.0, $score)), 2);

            if ($score < $minScore || $score > $maxScore) {
                continue;
            }

            $cands[] = [
                'symbol' => $symbol,
                'signal_type' => 'multi_day_trend',
                'signal_ts_est' => $r->signal_ts_est,
                'trading_date_est' => $r->trading_date_est,
                'trading_time_est' => $r->trading_time_est,
                'setup_price' => $setupPrice,
                'score' => $score,
                'meta' => [
                    'vwap' => (float) $r->vwap,
                    'vwap_dist_pct' => $vwapDistPct,
                    'ema9' => $ema9,
                    'ema21' => $ema21,
                    'ema9_dist_pct' => round($ema9DistPct, 3),
                    'ema9_21_spread_pct' => round($ema9_21_spread, 3),
                    'rsi_14' => $rsi,
                    'atr' => (float) $r->atr,
                    'atr_pct' => $atrPct,
                    'day_change_pct' => $dayChangePct,
                    'vol_ratio' => $volRatio,
                    'range_position_60m' => $rangePosition60m,
                ],
            ];
        }

        \Log::debug('[V830 Scanner] Scored '.count($cands)." candidates, returning top {$topN}");

        usort($cands, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($cands, 0, $topN);
    }
}
