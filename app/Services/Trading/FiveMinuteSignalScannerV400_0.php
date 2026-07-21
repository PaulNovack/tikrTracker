<?php

namespace App\Services\Trading;

/**
 * Version 400.0 - Multi-Day Pattern Continuation Scanner
 *
 * Uses prior day patterns to identify continuation trades available NOW.
 * Analyzes multi-day momentum but only alerts on current entry opportunities.
 *
 * 5-Minute Trend Gate (all must be true):
 * - Price > VWAP (strongest continuation filter)
 * - EMA9 > EMA21 and both rising (slope matters)
 * - Higher highs + higher lows pattern (HH/HL structure across days)
 * - Pullbacks hold above EMA9 or EMA21 (buyers defending support)
 * - Continuation of prior day's strength
 * - Currently above VWAP and ready to enter
 *
 * Continuation Pressure Signals:
 * - Consecutive higher lows with compressing range (coil)
 * - Breaks prior 5m high and holds (not instant rejection)
 * - Green candles closing near their highs (demand)
 * - Volume expansion on impulse legs vs pullbacks
 *
 * Strong Filters (~10 picks/day):
 * - Relative volume > normal (big volume on impulse)
 * - ATR/range filter (meaningful intraday movement)
 *
 * ENV / config('trading.v400.*'):
 * - min_atr_pct: Minimum ATR % for tradeable movement (default 2.5%)
 * - min_vol_ratio: Minimum volume ratio vs 20-bar avg (default 1.5x)
 * - max_pullback_pct: Maximum allowed pullback % (default 60%)
 * - min_impulse_bars: Minimum green 5m bars in impulse (default 2)
 * - lookback_bars: How many 5m bars to analyze (default 18 = 90min)
 */
class FiveMinuteSignalScannerV400_0
{
    use HasPriceTables;

    private string $version = 'v400.0';

    private string $name = 'Multi-Day Pattern Continuation';

    // ── Scanner Configuration (public so entry finders can read) ──
    public float $minAtrPct = 2.0;

    public float $minVolRatio = 2.5;

    public float $maxPullbackPct = 60.0;

    public int $minImpulseBars = 1;

    public int $lookbackBars = 40;

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
            'min_atr_pct' => $this->minAtrPct,
            'min_vol_ratio' => $this->minVolRatio,
            'max_pullback_pct' => $this->maxPullbackPct,
            'min_impulse_bars' => $this->minImpulseBars,
            'lookback_bars' => $this->lookbackBars,
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
        string $asOfTsEst,
        int $lookbackMinutes = 90,
        float $minMovePct = 1.2,
        float $volMult = 3.5,
        int $limit = 50
    ): array {
        // Configuration - institutional quality
        $minAtrPct = $this->minAtrPct;
        $minVolRatio = $this->minVolRatio;
        $maxPullbackPct = $this->maxPullbackPct;
        $minImpulseBars = $this->minImpulseBars;
        $lookbackBars = $this->lookbackBars; // 40 bars = 200 minutes = 3+ hours for multi-day patterns

        $lookbackMinutes = max(180, (int) $lookbackMinutes); // Minimum 3 hours for multi-day patterns
        $limit = max(1, (int) $limit);
        $tradeDate = substr($asOfTsEst, 0, 10);
        $priorTradeDate = date('Y-m-d', strtotime($tradeDate.' -3 days')); // Look back 3 days

        $lookbackStart = date('Y-m-d H:i:s', strtotime($asOfTsEst." -{$lookbackMinutes} minutes"));

        // Add market movers to universe if enabled
        $moversLimit = (int) config('trading.market_movers.pipeline_e', 0);
        $moverSymbols = [];
        if ($moversLimit > 0) {
            $moverSymbols = app(\App\Services\MarketMoversService::class)->getTodaysTopMoversFromCache(null, $moversLimit);
        }

        // Build SQL with optional movers filter
        $moversFilter = '';
        $moversBindings = [];
        if (! empty($moverSymbols)) {
            $placeholders = implode(',', array_fill(0, count($moverSymbols), '?'));
            $moversFilter = " OR f.symbol IN ($placeholders) ";
            $moversBindings = $moverSymbols;
        }

        // Main continuation scanner SQL
        $sql = '
WITH recent_bars AS (
    SELECT
        f.symbol,
        f.asset_type,
        f.ts_est,
        f.price AS close,
        f.open,
        f.high,
        f.low,
        f.volume,
        f.vwap,
        f.vwap_dist_pct,
        f.above_vwap,
        f.ema9,
        f.ema21,
        f.ema9_ema21_spread,
        f.ema9_above_ema21,
        f.atr_pct,
        f.rsi_14,
        ROW_NUMBER() OVER (PARTITION BY f.symbol, f.asset_type ORDER BY f.ts_est DESC) as rn
    FROM five_minute_prices f
    WHERE f.asset_type = ?
      AND f.ts_est <= ?
      AND f.ts_est >= ?
      AND f.trading_date_est >= ?  -- Changed from = to >= for multi-day patterns
      AND f.trading_date_est <= ?  -- Current day constraint
      '.$moversFilter.'
),
latest_bar AS (
    SELECT *
    FROM recent_bars
    WHERE rn = 1
      AND ts_est >= ?  -- Only alert on current day opportunities
),
bar_sequence AS (
    SELECT *
    FROM recent_bars
    WHERE rn <= ?
    ORDER BY symbol, asset_type, ts_est ASC
),
-- Calculate lagged values for higher low detection
bar_sequence_with_lag AS (
    SELECT
        bs.*,
        LAG(bs.low, 1) OVER (PARTITION BY bs.symbol, bs.asset_type ORDER BY bs.ts_est) as prev_low
    FROM bar_sequence bs
),
-- Identify pullback characteristics
pullback_analysis AS (
    SELECT
        bs.symbol,
        bs.asset_type,
        COUNT(CASE WHEN bs.close < bs.open THEN 1 END) as red_bars,
        COUNT(CASE WHEN bs.close > bs.open THEN 1 END) as green_bars,
        COUNT(CASE WHEN bs.close >= (bs.high - (bs.high - bs.low) * 0.3) THEN 1 END) as closes_near_high,
        MAX(bs.high) as period_high,
        MIN(bs.low) as period_low,
        MAX(bs.high) - MIN(bs.low) as total_range,
        AVG(bs.volume) as avg_volume,
        MAX(CASE WHEN bs.rn = 1 THEN bs.volume END) as latest_volume,
        -- Calculate if we have higher lows (now using pre-calculated prev_low)
        COUNT(CASE WHEN bs.low > bs.prev_low THEN 1 END) as higher_low_count,
        -- Check EMA slope (is EMA9 rising?)
        (MAX(CASE WHEN bs.rn = 1 THEN bs.ema9 END) - 
         MAX(CASE WHEN bs.rn = 5 THEN bs.ema9 END)) / 
         NULLIF(MAX(CASE WHEN bs.rn = 5 THEN bs.ema9 END), 0) * 100 as ema9_slope_pct,
        -- Check for VWAP violations
        COUNT(CASE WHEN bs.close < bs.vwap AND bs.above_vwap = 0 THEN 1 END) as vwap_violations
    FROM bar_sequence_with_lag bs
    GROUP BY bs.symbol, bs.asset_type
),
-- Volume profile analysis
volume_analysis AS (
    SELECT
        bs.symbol,
        bs.asset_type,
        AVG(CASE WHEN bs.close > bs.open THEN bs.volume ELSE NULL END) as avg_green_vol,
        AVG(CASE WHEN bs.close < bs.open THEN bs.volume ELSE NULL END) as avg_red_vol,
        AVG(bs.volume) as avg_total_vol
    FROM bar_sequence bs
    GROUP BY bs.symbol, bs.asset_type
),
-- Final scoring
scored_signals AS (
    SELECT
        lb.symbol,
        lb.asset_type,
        lb.ts_est as signal_ts_est,
        lb.close as signal_price,
        lb.vwap,
        lb.ema9,
        lb.ema21,
        lb.atr_pct,
        lb.rsi_14,
        pa.period_high,
        pa.period_low,
        pa.red_bars,
        pa.green_bars,
        pa.closes_near_high,
        pa.higher_low_count,
        pa.ema9_slope_pct,
        pa.vwap_violations,
        va.avg_green_vol,
        va.avg_red_vol,
        pa.latest_volume,
        pa.avg_volume,
        
        -- Trend gate checks
        CASE WHEN lb.above_vwap = 1 THEN 25 ELSE 0 END as score_vwap,
        CASE WHEN lb.ema9_above_ema21 = 1 AND pa.ema9_slope_pct > 0 THEN 20 ELSE 0 END as score_ema_trend,
        CASE WHEN pa.higher_low_count >= 3 THEN 15 ELSE pa.higher_low_count * 5 END as score_hh_hl,
        CASE 
            WHEN lb.close >= lb.ema9 THEN 15
            WHEN lb.close >= lb.ema21 THEN 10
            ELSE 0 
        END as score_support,
        CASE 
            WHEN pa.vwap_violations = 0 THEN 15
            WHEN pa.vwap_violations = 1 THEN 8
            ELSE 0
        END as score_no_vwap_break,
        CASE 
            WHEN pa.closes_near_high >= 5 THEN 10
            WHEN pa.closes_near_high >= 3 THEN 7
            ELSE pa.closes_near_high * 2
        END as score_demand,
        CASE 
            WHEN va.avg_green_vol > va.avg_red_vol * 1.3 THEN 10
            WHEN va.avg_green_vol > va.avg_red_vol THEN 5
            ELSE 0
        END as score_volume_profile,
        CASE 
            WHEN pa.latest_volume >= pa.avg_volume * ? THEN 10
            WHEN pa.latest_volume >= pa.avg_volume * 1.2 THEN 5
            ELSE 0
        END as score_volume_surge
        
    FROM latest_bar lb
    INNER JOIN pullback_analysis pa 
        ON lb.symbol = pa.symbol AND lb.asset_type = pa.asset_type
    INNER JOIN volume_analysis va
        ON lb.symbol = va.symbol AND lb.asset_type = va.asset_type
    WHERE lb.above_vwap = 1  -- Must be above VWAP
      AND lb.ema9_above_ema21 = 1  -- Must have bullish EMA alignment
      AND lb.atr_pct >= ?  -- Must have meaningful movement potential
      AND lb.atr_pct <= 6  -- Filter out overly volatile stocks
      AND pa.vwap_violations <= 3  -- Allow more VWAP violations for multi-day patterns
      AND pa.green_bars >= ?  -- Must have some green bars in sequence
      AND (pa.latest_volume / NULLIF(pa.avg_volume, 0)) >= 0.35  -- Minimum volume activity
      AND (pa.latest_volume / NULLIF(pa.avg_volume, 0)) <= 8  -- Filter out extreme vol spikes
      AND lb.close >= 1  -- Minimum price for liquidity
      AND lb.close <= 80  -- Maximum price to avoid expensive stocks
      AND (TIME(lb.ts_est) BETWEEN "09:35:00" AND "11:00:00" 
           OR TIME(lb.ts_est) BETWEEN "13:30:00" AND "15:30:00")  -- Avoid lunch chop
)
SELECT
    symbol,
    asset_type,
    signal_ts_est,
    signal_price,
    vwap,
    ema9,
    ema21,
    atr_pct,
    rsi_14,
    period_high,
    period_low,
    red_bars,
    green_bars,
    closes_near_high,
    higher_low_count,
    ema9_slope_pct,
    vwap_violations,
    latest_volume,
    avg_volume,
    (score_vwap + score_ema_trend + score_hh_hl + score_support + 
     score_no_vwap_break + score_demand + score_volume_profile + score_volume_surge) as continuation_score,
    score_vwap,
    score_ema_trend,
    score_hh_hl,
    score_support,
    score_no_vwap_break,
    score_demand,
    score_volume_profile,
    score_volume_surge
FROM scored_signals
WHERE (score_vwap + score_ema_trend + score_hh_hl + score_support + 
       score_no_vwap_break + score_demand + score_volume_profile + score_volume_surge) >= 50  -- Lowered from 70 for more signals
ORDER BY continuation_score DESC, latest_volume DESC
LIMIT ?
';

        $bindings = [
            $assetType,          // f.asset_type = ?
            $asOfTsEst,          // f.ts_est <= ?
            $lookbackStart,      // f.ts_est >= ?
            $priorTradeDate,     // f.trading_date_est >= ? (multi-day lookback for patterns)
            $tradeDate,          // f.trading_date_est <= ? (current day)
            ...$moversBindings,  // Movers symbols (if any)
            $tradeDate.' 09:30:00', // ts_est >= ? (latest_bar must be current day)
            $lookbackBars,       // rn <= ?
            $minVolRatio,        // pa.latest_volume >= pa.avg_volume * ?
            $minAtrPct,          // lb.atr_pct >= ?
            $minImpulseBars,     // pa.green_bars >= ?
            $limit,              // LIMIT ?
        ];

        $results = $this->dbSelect($sql, $bindings);

        if (empty($results)) {
            return [];
        }

        $signals = [];
        foreach ($results as $r) {
            // Use bar close time (bar open + 5 min) as signal_ts_est — the 5m pattern is
            // only confirmed once the bar closes, so staleness and price-extension checks
            // should anchor to the close, not the open.
            $barCloseTsEst = date('Y-m-d H:i:s', strtotime($r->signal_ts_est.' +5 minutes'));

            $signals[] = [
                'symbol' => $r->symbol,
                'asset_type' => $r->asset_type,
                'signal_ts_est' => $barCloseTsEst,
                'signal_type' => 'TREND_CONTINUATION',
                'price' => (float) $r->signal_price,
                'score' => (float) ($r->continuation_score ?? 0), // Scanner score (expected by pipeline)
                'vwap' => (float) ($r->vwap ?? 0),
                'ema9' => (float) ($r->ema9 ?? 0),
                'ema21' => (float) ($r->ema21 ?? 0),
                'atr_pct' => (float) ($r->atr_pct ?? 0),
                'rsi_14' => (float) ($r->rsi_14 ?? 0),
                'meta' => [
                    'version' => $this->version,
                    'continuation_score' => (float) ($r->continuation_score ?? 0),
                    'period_high' => (float) ($r->period_high ?? 0),
                    'period_low' => (float) ($r->period_low ?? 0),
                    'red_bars' => (int) ($r->red_bars ?? 0),
                    'green_bars' => (int) ($r->green_bars ?? 0),
                    'closes_near_high' => (int) ($r->closes_near_high ?? 0),
                    'higher_low_count' => (int) ($r->higher_low_count ?? 0),
                    'ema9_slope_pct' => (float) ($r->ema9_slope_pct ?? 0),
                    'vwap_violations' => (int) ($r->vwap_violations ?? 0),
                    'vol_ratio' => (float) ($r->latest_volume ?? 0) / max(1, (float) ($r->avg_volume ?? 1)),
                    'atr' => (float) ($r->atr_pct ?? 0) * (float) $r->signal_price / 100.0, // Calculate ATR from ATR%
                    'score_breakdown' => [
                        'vwap' => (int) ($r->score_vwap ?? 0),
                        'ema_trend' => (int) ($r->score_ema_trend ?? 0),
                        'hh_hl' => (int) ($r->score_hh_hl ?? 0),
                        'support' => (int) ($r->score_support ?? 0),
                        'no_vwap_break' => (int) ($r->score_no_vwap_break ?? 0),
                        'demand' => (int) ($r->score_demand ?? 0),
                        'volume_profile' => (int) ($r->score_volume_profile ?? 0),
                        'volume_surge' => (int) ($r->score_volume_surge ?? 0),
                    ],
                ],
            ];
        }

        return $signals;
    }
}
