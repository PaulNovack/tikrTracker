<?php

namespace App\Services\Trading;

/**
 * Version 16.0 - Quality-First 1m Entry Finder
 *
 * Goal: Improve average P&L by taking fewer, cleaner entries and avoiding:
 * - chasing (overextended entries)
 * - very low volume confirmations
 * - crazy risk (huge stop distance) or noise risk (tiny stop distance)
 *
 * IMPORTANT: This class does NOT hardcode a freshness cutoff (like 6 minutes).
 * Let the pipeline control freshness via --stale.
 *
 * Patterns (intentionally small):
 * - OR_BREAKOUT: break above opening range high with VWAP support + volume
 * - VWAP_RECLAIM_1M: reclaim VWAP with strong candle + volume, not extended
 * - EMA_PULLBACK: trend pullback to EMA9/EMA21 then bounce with volume
 *
 * Output shape matches your pipeline.
 */
class OneMinuteEntryFinderV16_0
{
    use HasPriceTables;

    private string $version = 'v16.0';

    // Risk & quality guardrails (tuneable constants)
    private float $minRiskPct = 0.35;  // too tight tends to noise-stop

    private float $maxRiskPct = 2.25;  // too wide tends to bad R:R

    private float $maxExtFromVWAPPct = 0.80; // "don't chase" vs vwap

    private float $maxExtFromEMA21Pct = 1.20;

    private float $minVolRatio = 2.0;  // global min volume confirmation

    private float $maxVolRatio = 80.0; // avoid insane spikes

    private float $atrTrailMult = 2.5; // suggested trailing stop in ATR

    public function getVersion(): string
    {
        return $this->version;
    }

    private function getTimeMultiplier(string $tsEst): float
    {
        $hour = (int) substr($tsEst, 11, 2);
        $minute = (int) substr($tsEst, 14, 2);
        $timeDecimal = $hour + ($minute / 60.0);

        if ($timeDecimal >= 15.0 && $timeDecimal < 16.0) {
            return 1.15;
        } // power hour
        if ($timeDecimal >= 11.0 && $timeDecimal < 14.0) {
            return 0.85;
        } // lunch chop

        return 1.0;
    }

    private function calculateATR(array $bars, int $period = 14): float
    {
        if (count($bars) < $period + 1) {
            return 0.0;
        }

        $trs = [];
        for ($i = 1; $i < count($bars); $i++) {
            $high = (float) $bars[$i]['high'];
            $low = (float) $bars[$i]['low'];
            $prevClose = (float) $bars[$i - 1]['close'];

            $trs[] = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose)
            );
        }

        $count = min($period, count($trs));
        $sum = 0.0;
        for ($i = count($trs) - $count; $i < count($trs); $i++) {
            $sum += $trs[$i];
        }

        return $count > 0 ? $sum / $count : 0.0;
    }

    /**
     * Find best LONG entry (quality-first).
     */
    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        int $beforeMinutes = 12,
        int $afterMinutes = 0,
        int $volLookback = 20,
        int $pivotLookback = 15,
        string $fillModel = 'next_open'
    ): array {
        $assetType = strtolower(trim($assetType));
        if (! in_array($assetType, ['stock', 'crypto'], true)) {
            return ['ok' => false, 'error' => 'assetType must be stock|crypto'];
        }

        // Use AS-OF date for market open anchor (important for live correctness)
        $tradeDate = substr($asOfTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';

        $analysisEnd = $asOfTsEst;
        $analysisStart = date('Y-m-d H:i:s', strtotime($asOfTsEst." -{$beforeMinutes} minutes"));

        if ($analysisStart < $marketOpen) {
            $analysisStart = $marketOpen;
        }

        // Need data from market open to analysisEnd to compute VWAP/EMAs reliably.
        $tradingDate = substr($signalTsEst, 0, 10);
        $bars = $this->dbSelect('
            SELECT ts_est, `open`, `high`, `low`, `price` AS `close`, COALESCE(`volume`,0) AS volume
            FROM one_minute_prices
            WHERE asset_type = ? AND symbol = ?
              AND trading_date_est = ?
              AND ts_est >= ? AND ts_est <= ?
            ORDER BY ts_est ASC
        ', [$assetType, $symbol, $tradingDate, $marketOpen, $analysisEnd]);

        if (! $bars || count($bars) < 35) {
            return [
                'ok' => false,
                'error' => 'Not enough 1m bars (market closed or missing).',
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'range_est' => [$marketOpen, $analysisEnd],
                'bars_found' => $bars ? count($bars) : 0,
            ];
        }

        // Validate data quality: reject if extreme price drops (reverse splits, bad data)
        for ($i = 1; $i < count($bars); $i++) {
            $prevClose = (float) $bars[$i - 1]->close;
            $currentOpen = (float) $bars[$i]->open;
            if ($prevClose > 0 && (($currentOpen - $prevClose) / $prevClose) * 100.0 < -50.0) {
                return ['ok' => false, 'error' => 'Bad data - extreme drop', 'symbol' => $symbol];
            }
        }

        // Build normalized series with VWAP + EMA9/EMA21 + opening range high (first 5 minutes)
        $norm = [];
        $cumPV = 0.0;
        $cumV = 0.0;

        $ema9 = null;
        $ema21 = null;
        $m9 = 2.0 / (9 + 1);
        $m21 = 2.0 / (21 + 1);

        $orHigh = null;
        $orCount = 0;

        foreach ($bars as $r) {
            $ts = (string) $r->ts_est;
            $o = (float) ($r->open ?? 0);
            $h = (float) ($r->high ?? 0);
            $l = (float) ($r->low ?? 0);
            $c = (float) ($r->close ?? 0);
            $v = (float) ($r->volume ?? 0);

            // VWAP
            $typ = ($h + $l + $c) / 3.0;
            if ($v > 0) {
                $cumPV += $typ * $v;
                $cumV += $v;
            }
            $vwap = ($cumV > 0) ? ($cumPV / $cumV) : $c;

            // EMAs
            $ema9 = ($ema9 === null) ? $c : ($c * $m9 + $ema9 * (1 - $m9));
            $ema21 = ($ema21 === null) ? $c : ($c * $m21 + $ema21 * (1 - $m21));

            // Opening range (first 5 bars from market open)
            if ($orCount < 5) {
                $orCount++;
                $orHigh = ($orHigh === null) ? $h : max($orHigh, $h);
            }

            $norm[] = [
                'ts_est' => $ts,
                'open' => $o,
                'high' => $h,
                'low' => $l,
                'close' => $c,
                'volume' => $v,
                'vwap' => $vwap,
                'ema9' => $ema9,
                'ema21' => $ema21,
                'or_high' => $orHigh,
            ];
        }

        // Helper arrays
        $vols = array_map(fn ($b) => (float) $b['volume'], $norm);

        // Find start index for analysis window (only consider triggers inside analysisStart..analysisEnd)
        $idxStart = 0;
        for ($i = 0; $i < count($norm); $i++) {
            if ($norm[$i]['ts_est'] >= $analysisStart) {
                $idxStart = $i;
                break;
            }
        }

        // Prefer entries not before the 5m signal time (optional safety)
        $idxSignal = 0;
        for ($i = 0; $i < count($norm); $i++) {
            if ($norm[$i]['ts_est'] >= $signalTsEst) {
                $idxSignal = $i;
                break;
            }
        }

        $idxFrom = max($idxStart, $idxSignal);

        $volAvgBefore = function (int $i) use ($vols, $volLookback): float {
            $start = max(0, $i - $volLookback);
            if ($start >= $i) {
                return 0.0;
            }
            $slice = array_slice($vols, $start, $i - $start);

            return count($slice) ? array_sum($slice) / count($slice) : 0.0;
        };

        $computeFill = function (int $triggerIdx) use ($norm, $fillModel): array {
            if ($fillModel === 'close') {
                return [$norm[$triggerIdx]['ts_est'], (float) $norm[$triggerIdx]['close']];
            }
            $nextIdx = $triggerIdx + 1;
            if ($nextIdx >= count($norm)) {
                return [$norm[$triggerIdx]['ts_est'], (float) $norm[$triggerIdx]['close']];
            }

            // Safety check: prevent time travel bugs by verifying same trading date
            $curDate = substr($norm[$triggerIdx]['ts_est'], 0, 10);
            $nextDate = substr($norm[$nextIdx]['ts_est'], 0, 10);
            if ($curDate !== $nextDate) {
                return [$norm[$triggerIdx]['ts_est'], (float) $norm[$triggerIdx]['close']];
            }

            $open = (float) $norm[$nextIdx]['open'];
            if ($open <= 0) {
                $open = (float) $norm[$nextIdx]['close'];
            }

            return [$norm[$nextIdx]['ts_est'], $open];
        };

        // Unified candidate list (then pick best by score, with risk/ext filters)
        $candidates = [];

        // Common filters
        $passesGlobalFilters = function (array $cur, float $volRatio): bool {
            if ($volRatio < $this->minVolRatio || $volRatio > $this->maxVolRatio) {
                return false;
            }

            // Don't chase: distance from VWAP
            $vwap = (float) $cur['vwap'];
            $c = (float) $cur['close'];
            if ($vwap > 0) {
                $extV = abs($c - $vwap) / $vwap * 100.0;
                if ($extV > $this->maxExtFromVWAPPct) {
                    return false;
                }
            }

            // Don't chase: distance from EMA21
            $ema21 = (float) $cur['ema21'];
            if ($ema21 > 0) {
                $extE = abs($c - $ema21) / $ema21 * 100.0;
                if ($extE > $this->maxExtFromEMA21Pct) {
                    return false;
                }
            }

            return true;
        };

        $passesRisk = function (float $entry, float $stop): bool {
            if ($entry <= 0 || $stop <= 0) {
                return false;
            }
            $riskPct = abs(($entry - $stop) / $entry) * 100.0;

            return $riskPct >= $this->minRiskPct && $riskPct <= $this->maxRiskPct;
        };

        // ------------------------------------------------------------
        // Pattern 1: VWAP reclaim (cross up VWAP + strong body + volume)
        // ------------------------------------------------------------
        for ($i = max(1, $idxFrom); $i < count($norm); $i++) {
            $prev = $norm[$i - 1];
            $cur = $norm[$i];

            $reclaim = ($prev['close'] < $prev['vwap']) && ($cur['close'] > $cur['vwap']);
            if (! $reclaim) {
                continue;
            }

            // Strong candle body (avoid tiny crosses)
            $body = ($cur['open'] > 0) ? abs($cur['close'] - $cur['open']) / $cur['open'] * 100.0 : 0.0;
            if ($cur['close'] <= $cur['open'] || $body < 0.25) {
                continue;
            }

            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ($cur['volume'] / $baseVol) : 0.0;

            if (! $passesGlobalFilters($cur, $volRatio)) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i);
            $stop = min((float) $cur['low'], (float) $cur['vwap'] * 0.998);

            if (! $passesRisk($entryPx, $stop)) {
                continue;
            }

            $distFromVWAP = ((float) $cur['vwap'] > 0) ? ((($cur['close'] - $cur['vwap']) / $cur['vwap']) * 100.0) : 0.0;

            $score =
                min(3.0, $volRatio) +
                min(1.5, $body / 0.35) +
                max(0.0, 1.2 - abs($distFromVWAP)); // prefer closer to VWAP

            $score *= $this->getTimeMultiplier($cur['ts_est']);

            $candidates[] = [
                'type' => 'VWAP_RECLAIM_1M',
                'trigger_ts_est' => $cur['ts_est'],
                'entry_ts_est' => $entryTs,
                'entry' => round($entryPx, 6),
                'stop' => round($stop, 6),
                'vwap' => round((float) $cur['vwap'], 6),
                'vol_ratio' => round($volRatio, 3),
                'score' => round($score, 3),
                'notes' => 'VWAP reclaim with strong green body + volume (quality-first).',
            ];

            // only first valid hit
            break;
        }

        // ------------------------------------------------------------
        // Pattern 2: Opening Range Breakout (above OR high + above VWAP + volume)
        // ------------------------------------------------------------
        for ($i = max(6, $idxFrom); $i < count($norm); $i++) {
            $cur = $norm[$i];
            $prev = $norm[$i - 1];

            $orHigh = (float) ($cur['or_high'] ?? 0.0);
            if ($orHigh <= 0) {
                continue;
            }

            $breaks = ($prev['high'] <= $orHigh) && ($cur['close'] > $orHigh);
            if (! $breaks) {
                continue;
            }

            // Must be above VWAP too (avoid fake OR pops under VWAP)
            if ((float) $cur['close'] <= (float) $cur['vwap']) {
                continue;
            }

            // Green body strength
            $body = ($cur['open'] > 0) ? (($cur['close'] - $cur['open']) / $cur['open'] * 100.0) : 0.0;
            if ($cur['close'] <= $cur['open'] || $body < 0.60) {
                continue;
            }

            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ($cur['volume'] / $baseVol) : 0.0;

            if (! $passesGlobalFilters($cur, $volRatio)) {
                continue;
            }

            // Not too extended above OR
            $distAboveOR = ($orHigh > 0) ? ((($cur['close'] - $orHigh) / $orHigh) * 100.0) : 999.0;
            if ($distAboveOR > 1.40) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i);
            $stop = min((float) $cur['low'], $orHigh * 0.998);

            if (! $passesRisk($entryPx, $stop)) {
                continue;
            }

            $score =
                min(3.0, $volRatio) +
                min(2.0, $body / 0.70) +
                max(0.0, 1.3 - $distAboveOR);

            $score *= $this->getTimeMultiplier($cur['ts_est']);

            $candidates[] = [
                'type' => 'OR_BREAKOUT',
                'trigger_ts_est' => $cur['ts_est'],
                'entry_ts_est' => $entryTs,
                'entry' => round($entryPx, 6),
                'stop' => round($stop, 6),
                'or_high' => round($orHigh, 6),
                'vwap' => round((float) $cur['vwap'], 6),
                'vol_ratio' => round($volRatio, 3),
                'score' => round($score, 3),
                'notes' => 'Opening range breakout above OR high + VWAP + strong volume.',
            ];

            break;
        }

        // ------------------------------------------------------------
        // Pattern 3: EMA pullback bounce (trend continuation entry)
        // ------------------------------------------------------------
        for ($i = max(22, $idxFrom); $i < count($norm); $i++) {
            $prev = $norm[$i - 1];
            $cur = $norm[$i];

            // Trend filter: EMA9 > EMA21 and both rising
            if (! ((float) $cur['ema9'] > (float) $cur['ema21'])) {
                continue;
            }

            $ema9_3 = (float) ($norm[max(0, $i - 3)]['ema9'] ?? $cur['ema9']);
            $ema21_5 = (float) ($norm[max(0, $i - 5)]['ema21'] ?? $cur['ema21']);
            if ((float) $cur['ema9'] <= $ema9_3) {
                continue;
            }
            if ((float) $cur['ema21'] <= $ema21_5) {
                continue;
            }

            // Pullback: prev low near EMA9/EMA21, current closes back above EMA9
            $ema9 = (float) $cur['ema9'];
            $ema21 = (float) $cur['ema21'];
            if ($ema9 <= 0 || $ema21 <= 0) {
                continue;
            }

            $prevNearEma9 = abs((float) $prev['low'] - $ema9) / $ema9 < 0.0045;   // within 0.45%
            $prevNearEma21 = abs((float) $prev['low'] - $ema21) / $ema21 < 0.0060; // within 0.60%
            if (! ($prevNearEma9 || $prevNearEma21)) {
                continue;
            }

            if ((float) $cur['close'] <= $ema9) {
                continue;
            } // bounce
            if ((float) $cur['close'] <= (float) $cur['open']) {
                continue;
            }

            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ($cur['volume'] / $baseVol) : 0.0;

            if (! $passesGlobalFilters($cur, $volRatio)) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i);

            // Stop: below EMA21 or swing low
            $stop = min((float) $cur['low'], $ema21 * 0.997);

            if (! $passesRisk($entryPx, $stop)) {
                continue;
            }

            $body = ($cur['open'] > 0) ? abs($cur['close'] - $cur['open']) / $cur['open'] * 100.0 : 0.0;
            $distFromEma9 = abs(((float) $cur['close'] - $ema9) / $ema9) * 100.0;

            $score =
                min(3.0, $volRatio) +
                min(1.5, $body / 0.45) +
                max(0.0, 1.4 - $distFromEma9);

            $score *= $this->getTimeMultiplier($cur['ts_est']);

            $candidates[] = [
                'type' => 'EMA_PULLBACK',
                'trigger_ts_est' => $cur['ts_est'],
                'entry_ts_est' => $entryTs,
                'entry' => round($entryPx, 6),
                'stop' => round($stop, 6),
                'ema9' => round($ema9, 6),
                'ema21' => round($ema21, 6),
                'vwap' => round((float) $cur['vwap'], 6),
                'vol_ratio' => round($volRatio, 3),
                'score' => round($score, 3),
                'notes' => 'Trend pullback to EMA with bounce + volume confirmation.',
            ];

            break;
        }

        // Rank candidates by score
        usort($candidates, fn ($a, $b) => ($b['score'] <=> $a['score']));
        $best = $candidates[0] ?? null;

        if ($best) {
            $entry = (float) $best['entry'];
            $stop = (float) $best['stop'];
            $risk = max(1e-9, $entry - $stop);
            $riskPct = ($entry > 0) ? ($risk / $entry) * 100.0 : 0.0;

            $best['risk_per_share'] = round($risk, 6);
            $best['risk_pct'] = round($riskPct, 3);
            $best['targets'] = [
                '1R' => round($entry + 1.0 * $risk, 6),
                '2R' => round($entry + 2.0 * $risk, 6),
                '3R' => round($entry + 3.0 * $risk, 6),
            ];

            // ATR suggestion computed on normalized bars
            $atr = $this->calculateATR($norm, 14);
            $best['atr'] = round($atr, 6);
            $best['atr_pct'] = ($entry > 0) ? round(($atr / $entry) * 100.0, 3) : 0.0;
            $best['suggested_trailing_stop'] = round($atr * $this->atrTrailMult, 6);
            $best['suggested_trailing_stop_pct'] = ($entry > 0)
                ? round((($atr * $this->atrTrailMult) / $entry) * 100.0, 3)
                : 0.0;
        }

        return [
            'ok' => (bool) $best,
            'symbol' => $symbol,
            'asset_type' => $assetType,
            'signal_ts_est' => $signalTsEst,
            'analysis_window_est' => [$analysisStart, $analysisEnd],
            'market_open_est' => $marketOpen,
            'bars_loaded' => count($norm),
            'best_entry' => $best,
            'candidates' => $candidates,
        ];
    }
}
