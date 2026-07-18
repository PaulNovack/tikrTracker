<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

class OneMinuteEntryFinderV50_0
{
    use HasPriceTables;

    private string $version = 'v50.0';

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Drop-in compatible signature.
     */
    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        int $beforeMinutes = 15,
        int $afterMinutes = 30,
        int $volLookback = 20,
        int $pivotLookback = 15,
        string $fillModel = 'next_open' // next_open|close
    ): array {
        $minScore = (float) config('trading.entry_score_min', 80);
        $maxScore = (float) config('trading.entry_score_max', 100);
        if ($maxScore <= 0) {
            $maxScore = 100.0;
        }
        if ($minScore > $maxScore) {
            [$minScore, $maxScore] = [$maxScore, $minScore];
        }

        // Live analysis window relative to NOW (asOf), not signal time.
        $analysisEnd = $asOfTsEst;
        $analysisStart = date('Y-m-d H:i:s', strtotime($asOfTsEst." -{$beforeMinutes} minutes"));

        // Market open in your ts_est convention (fixed UTC-5). Use date from signalTsEst.
        $tradeDate = substr($signalTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';

        if ($analysisStart < $marketOpen) {
            $analysisStart = $marketOpen;
        }

        // We need bars from market open to analysisEnd to support VWAP/EMA consistency.
        $from = $marketOpen;
        $to = $analysisEnd;

        // Pull precomputed fields + avg_vol_20 (window) so scoring is self-contained.
        $bars = $this->dbSelect('
            SELECT
              ts_est,
              price,
              `open`,
              `high`,
              `low`,
              volume,
              vwap,
              vwap_dist_pct,
              above_vwap,
              ema9,
              ema21,
              ema9_ema21_spread,
              ema9_above_ema21,
              atr,
              atr_pct,
              AVG(volume) OVER (
                PARTITION BY symbol, asset_type
                ORDER BY ts_est
                ROWS BETWEEN 20 PRECEDING AND 1 PRECEDING
              ) AS avg_vol_20
            FROM one_minute_prices
            WHERE asset_type = ?
              AND symbol = ?
              AND trading_date_est = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ', [$assetType, $symbol, $tradeDate, $from, $to]);

        if (! $bars || count($bars) < 25) {
            return [
                'ok' => false,
                'error' => 'Not enough 1m data in range (market closed or missing bars).',
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'range_est' => [$from, $to],
                'bars_found' => $bars ? count($bars) : 0,
            ];
        }

        // Validate data quality: reject if extreme price drops (reverse splits, bad data)
        for ($i = 1; $i < count($bars); $i++) {
            $prevClose = (float) $bars[$i - 1]->price;
            $currentOpen = (float) $bars[$i]->open;
            if ($prevClose > 0 && (($currentOpen - $prevClose) / $prevClose) * 100.0 < -50.0) {
                return ['ok' => false, 'error' => 'Bad data - extreme drop', 'symbol' => $symbol];
            }
        }

        // Get 5-minute bars to check for downtrends
        $fiveMinBars = $this->dbSelect('
            SELECT ts_est, ema9_above_ema21, above_vwap
            FROM five_minute_prices
            WHERE asset_type = ?
              AND symbol = ?
              AND trading_date_est = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ', [$assetType, $symbol, $tradeDate, $from, $to]);

        // Build a lookup for 5-minute trend at any given time
        $fiveMinTrend = [];
        foreach ($fiveMinBars as $bar) {
            $fiveMinTrend[(string) $bar->ts_est] = (int) ($bar->ema9_above_ema21 ?? 0);
        }

        // Helper to check if 5-minute trend is up at a given 1-minute timestamp
        $is5MinTrendUp = function ($ts1m) use ($fiveMinTrend): bool {
            // Find the most recent 5-minute bar at or before this 1-minute timestamp
            $relevantBar = null;
            foreach ($fiveMinTrend as $ts5m => $trend) {
                if ($ts5m <= $ts1m) {
                    $relevantBar = $trend;
                } else {
                    break;
                }
            }

            return $relevantBar === 1;
        };

        // Compute entry candidates inside the LIVE window only.
        $candidates = [];
        $best = null;

        $computeFill = function (int $i) use ($bars, $fillModel): array {
            if ($fillModel === 'close') {
                return [(string) $bars[$i]->ts_est, (float) $bars[$i]->price];
            }
            $next = $i + 1;
            if ($next >= count($bars)) {
                return [(string) $bars[$i]->ts_est, (float) $bars[$i]->price];
            }

            // Safety check: prevent time travel bugs by verifying same trading date
            $curDate = substr((string) $bars[$i]->ts_est, 0, 10);
            $nextDate = substr((string) $bars[$next]->ts_est, 0, 10);
            if ($curDate !== $nextDate) {
                return [(string) $bars[$i]->ts_est, (float) $bars[$i]->price];
            }

            $o = (float) ($bars[$next]->open ?? 0);
            if ($o <= 0) {
                $o = (float) $bars[$next]->price;
            }

            return [(string) $bars[$next]->ts_est, $o];
        };

        for ($i = 0; $i < count($bars); $i++) {
            $ts = (string) $bars[$i]->ts_est;

            // Only bars available at run time and inside live analysis window
            if ($ts < $analysisStart || $ts > $analysisEnd) {
                continue;
            }

            $row = $bars[$i];

            // Hard alignment gates (matches scanner intent)
            if ((int) ($row->ema9_above_ema21 ?? 0) !== 1) {
                continue;
            }
            if ((int) ($row->above_vwap ?? 0) !== 1) {
                continue;
            }

            // NEW: Require 5-minute timeframe to also be in uptrend
            if (! $is5MinTrendUp($ts)) {
                continue;
            }

            $score = $this->computeEntryScore($row);

            // Apply env-configured score window
            if ($score < $minScore || $score > $maxScore) {
                continue;
            }

            // Fill model
            [$entryTs, $entryPx] = $computeFill($i);

            // Stop: ATR-based if available, otherwise percent-based.
            $atr = (float) ($row->atr ?? 0);
            $atrStopDist = ($atr > 0) ? ($atr * 1.8) : 0.0; // 1.8x ATR baseline
            $pctStopDist = $entryPx * 0.0085;               // 0.85% baseline

            $stopDist = max($atrStopDist, $pctStopDist);

            $rawStop = $entryPx - $stopDist;

            // Clamp to 0.7%–1.0% risk like your v17 behavior.
            $minStopPct = 1.0; // looser stop (bigger risk) lower price
            $maxStopPct = 0.7; // tighter stop (smaller risk) higher price

            $minStop = $entryPx * (1 - ($minStopPct / 100));
            $maxStop = $entryPx * (1 - ($maxStopPct / 100));

            $stop = $rawStop;
            if ($stop < $minStop) {
                $stop = $minStop;
            }
            if ($stop > $maxStop) {
                $stop = $maxStop;
            }

            $risk = max(1e-9, $entryPx - $stop);
            $riskPct = ($entryPx > 0) ? ($risk / $entryPx) * 100.0 : 0.0;

            // Calculate suggested trailing stop = 3x ATR (minimum 0.60%)
            $candAtr = (float) ($row->atr ?? 0);
            $trailPct = ($candAtr > 0 && $entryPx > 0)
                ? max(0.60, (($candAtr * 3.0) / $entryPx) * 100.0)
                : 0.60;

            $cand = [
                'type' => 'ENTRY_SCORE_1M',
                'trigger_ts_est' => $ts,
                'entry_ts_est' => $entryTs,
                'entry' => round($entryPx, 6),
                'stop' => round($stop, 6),
                'score' => round($score, 2),
                'atr' => round($candAtr, 6),
                'atr_pct' => (float) ($row->atr_pct ?? 0),
                'suggested_trailing_stop' => round($entryPx * ($trailPct / 100.0), 6),
                'suggested_trailing_stop_pct' => $trailPct,
                'risk_per_share' => round($risk, 6),
                'risk_pct' => round($riskPct, 3),
                'vwap' => $row->vwap !== null ? round((float) $row->vwap, 6) : null,
                'ema9' => $row->ema9 !== null ? round((float) $row->ema9, 6) : null,
                'ema21' => $row->ema21 !== null ? round((float) $row->ema21, 6) : null,
                'notes' => 'Selected by highest EntryScore inside live window; gates: above VWAP + EMA9>EMA21 (1m) + EMA9>EMA21 (5m).',
            ];

            $candidates[] = $cand;

            if ($best === null || $cand['score'] > $best['score']) {
                $best = $cand;
            }
        }

        if ($best) {
            // Targets (R-multiples)
            $r = max(1e-9, (float) $best['risk_per_share']);
            $best['targets'] = [
                '1R' => round((float) $best['entry'] + 1.0 * $r, 6),
                '2R' => round((float) $best['entry'] + 2.0 * $r, 6),
                '3R' => round((float) $best['entry'] + 3.0 * $r, 6),
            ];

            // Suggested trailing stop = 3x ATR (minimum 0.60%)
            $atr = (float) ($best['atr'] ?? 0);
            $entryPrice = (float) $best['entry'];
            $trailPct = ($atr > 0 && $entryPrice > 0)
                ? max(0.60, (($atr * 3.0) / $entryPrice) * 100.0)
                : 0.60;
            $best['suggested_trailing_stop'] = round($entryPrice * ($trailPct / 100.0), 6);
            $best['suggested_trailing_stop_pct'] = $trailPct;
        }

        return [
            'ok' => (bool) $best,
            'symbol' => $symbol,
            'asset_type' => $assetType,
            'signal_ts_est' => $signalTsEst,
            'analysis_window_est' => [$analysisStart, $analysisEnd],
            'market_open_est' => $marketOpen,
            'bars_loaded' => count($bars),
            'best_entry' => $best,
            'candidates' => $candidates,
            'meta' => [
                'entry_score_min' => $minScore,
                'entry_score_max' => $maxScore,
                'version' => $this->version,
                'fill_model' => $fillModel,
            ],
        ];
    }

    /**
     * Compute the same EntryScore formula used by the v50 scanner.
     * Input is a DB row with fields from one_minute_prices + avg_vol_20.
     */
    private function computeEntryScore(object $b): float
    {
        $price = (float) ($b->price ?? 0);
        if ($price <= 0) {
            return 0.0;
        }

        $emaSpread = (float) ($b->ema9_ema21_spread ?? 0);
        $spreadFrac = $emaSpread / $price;

        // spread_strength: clamp((spreadFrac - 0.0005)/(0.0030-0.0005), 0..1)
        $spread_strength = $this->clamp(($spreadFrac - 0.0005) / (0.0030 - 0.0005));

        $vwap_dist_pct = (float) ($b->vwap_dist_pct ?? 0);
        // triangle centered 0.15 with width 0.30 (favor early moves 0-0.3% above VWAP)
        $vwap_dist_score = max(0.0, 1.0 - (abs($vwap_dist_pct - 0.15) / 0.30));

        $atr_pct = (float) ($b->atr_pct ?? 0);
        $atr_low_ok = $this->clamp(($atr_pct - 0.08) / (0.20 - 0.08));
        $atr_high_pen = $this->clamp(($atr_pct - 0.50) / (1.50 - 0.50));
        $atr_score = $atr_low_ok * (1.0 - $atr_high_pen);

        $avg_vol_20 = (float) ($b->avg_vol_20 ?? 0);
        $vol = (float) ($b->volume ?? 0);
        $vol_ratio = ($avg_vol_20 > 0) ? ($vol / $avg_vol_20) : 0.0;
        $vol_score = ($avg_vol_20 > 0)
            ? $this->clamp(($vol_ratio - 0.8) / (2.5 - 0.8))
            : 0.0;

        $high = (float) ($b->high ?? 0);
        $low = (float) ($b->low ?? 0);
        $candle_score = 0.0;
        if ($high > $low) {
            $pos = ($price - $low) / ($high - $low); // 0..1
            $candle_score = $this->clamp(($pos - 0.45) / (0.80 - 0.45));
        }

        $ema9_above_ema21 = (float) ((int) ($b->ema9_above_ema21 ?? 0));
        $above_vwap = (float) ((int) ($b->above_vwap ?? 0));

        // Time bonus: 1.0 before 10:30, 0.5 before 11:00, 0 after
        $ts = (string) ($b->ts_est ?? '');
        $time_bonus = 0.0;
        if ($ts) {
            $timeStr = substr($ts, 11, 8); // HH:MM:SS
            if ($timeStr <= '10:30:00') {
                $time_bonus = 1.0;
            } elseif ($timeStr <= '11:00:00') {
                $time_bonus = 0.5;
            }
        }

        $S_trend = 0.70 * $ema9_above_ema21 + 0.30 * $spread_strength;
        $S_vwap = $above_vwap * $vwap_dist_score;

        $final = 100.0 * (
            0.30 * $S_trend +
            0.25 * $S_vwap +
            0.10 * $atr_score +
            0.20 * $vol_score +
            0.10 * $candle_score +
            0.05 * $time_bonus
        );

        return round($final, 2);
    }

    private function clamp(float $x, float $lo = 0.0, float $hi = 1.0): float
    {
        if ($x < $lo) {
            return $lo;
        }
        if ($x > $hi) {
            return $hi;
        }

        return $x;
    }
}
