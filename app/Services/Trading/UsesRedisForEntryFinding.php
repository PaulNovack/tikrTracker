<?php

namespace App\Services\Trading;

use App\Repositories\RedisBarRepository;

/**
 * Trait UsesRedisForEntryFinding - Redis data source for entry finders.
 */
trait UsesRedisForEntryFinding
{
    private ?RedisBarRepository $_redisRepo = null;

    private function redisRepo(): RedisBarRepository
    {
        return $this->_redisRepo ??= new RedisBarRepository;
    }

    protected function doFindBestLong(string $symbol, string $signalTsEst, string $asOfTsEst): ?array
    {
        $cfg = $this->entryConfig();
        if (! $this->isAllowedTime($asOfTsEst, (bool) ($cfg['allow_lunch'] ?? false))) {
            return null;
        }
        $tradeDate = substr($signalTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';
        $bars = $this->redisRepo()->getBars('1m', $symbol, 'stock', $marketOpen, $asOfTsEst, 420);
        if (count($bars) < (int) ($cfg['min_bars'] ?? 90)) {
            return null;
        }
        $norm = $this->_redisBuildNorm($bars);
        $entry = $norm[count($norm) - 1];
        $prev = count($norm) >= 2 ? $norm[count($norm) - 2] : null;
        if (! $this->_redisPassesGates($entry, $cfg)) {
            return null;
        }
        $entryPrice = $entry['close'];
        $atr = $this->_redisComputeAtr($norm, 14);
        $atrStop = $entryPrice - ($atr * (float) ($cfg['room_atr_mult'] ?? 2.5));
        $entryType = $this->_redisClassifyEntryType($entry, $prev, $norm);
        if ($entryType === null) {
            return null;
        }

        return ['entry_price' => round($entryPrice, 8), 'stop_loss' => round($atrStop, 8), 'entry_type' => $entryType,
            'entry_ts_est' => $asOfTsEst, 'entry_meta' => ['atr' => round($atr, 6), 'vwap' => round($entry['vwap'], 6),
                'ema9' => round($entry['ema_f'], 6), 'ema21' => round($entry['ema_s'], 6), 'hod' => round($entry['hod'], 6),
                'or_high' => $entry['or_high'] ?? null, 'bars_loaded' => count($bars)]];
    }

    private function _redisPassesGates(array $entry, array $cfg): bool
    {
        if (($entry['close'] * $entry['volume']) < (float) ($cfg['min_notional_1m'] ?? 100000)) {
            return false;
        }
        if ($entry['open'] > 0 && abs($entry['close'] - $entry['open']) / $entry['open'] * 100 < (float) ($cfg['min_body_pct'] ?? 0.40)) {
            return false;
        }
        if ($entry['vwap'] > 0) {
            $a = (($entry['close'] - $entry['vwap']) / $entry['vwap']) * 100;
            $m = (float) ($cfg['max_above_vwap_entry_pct'] ?? $cfg['entry_max_vwap_extension_pct'] ?? 0.60);
            if ($a > $m) {
                return false;
            }
        }
        $r = $entry['close'] > 0 ? (($entry['hod'] - $entry['close']) / $entry['close']) * 100 : 0;
        if ($r < (float) ($cfg['min_room_to_run_pct'] ?? 0.8)) {
            return false;
        }
        if (! empty($cfg['require_trend_align']) && $entry['ema_f'] <= $entry['ema_s']) {
            return false;
        }
        if (isset($cfg['entry_min_vol_ratio'],$entry['volume'],$entry['avg_vol_20']) && $entry['avg_vol_20'] > 0 && (float) $cfg['entry_min_vol_ratio'] > $entry['volume'] / $entry['avg_vol_20']) {
            return false;
        }

        return true;
    }

    private function _redisClassifyEntryType(array $entry, ?array $prev, array $norm): ?string
    {
        if ($prev !== null && $entry['vwap'] > 0 && ! ($prev['close'] > $prev['vwap']) && $entry['close'] > $entry['vwap'] && $entry['close'] > $entry['open']) {
            return 'VWAP_RECLAIM_STRONG';
        }
        if (($entry['or_high'] ?? 0) > 0) {
            $d = (($entry['close'] - $entry['or_high']) / $entry['or_high']) * 100;
            if ($d > -0.5 && $d < 1.0 && $entry['close'] > $entry['open']) {
                return 'ORB_RETEST';
            }
        }
        if ($prev !== null && $entry['ema_f'] > 0) {
            $d = (($entry['low'] - $entry['ema_f']) / $entry['ema_f']) * 100;
            if ($d > -0.5 && $d < 0.3 && $entry['close'] > $entry['open']) {
                return 'EMA9_PULLBACK';
            }
        }

        return $entry['close'] > $entry['open'] ? 'MOMENTUM' : null;
    }

    private function _redisBuildNorm(array $bars): array
    {
        $kF = 2.0 / 10;
        $kS = 2.0 / 22;
        $cPV = 0.0;
        $cV = 0.0;
        $eF = null;
        $eS = null;
        $hod = 0.0;
        $oH = null;
        $oC = 0;
        $norm = [];
        foreach ($bars as $b) {
            $h = $b->high;
            $l = $b->low;
            $c = $b->close;
            $v = $b->volume;
            if ($h > $hod) {
                $hod = $h;
            } $t = ($h + $l + $c) / 3.0;
            if ($v > 0) {
                $cPV += $t * $v;
                $cV += $v;
            } $vw = $cV > 0 ? $cPV / $cV : $c;
            $eF = $eF === null ? $c : ($c * $kF + $eF * (1 - $kF));
            $eS = $eS === null ? $c : ($c * $kS + $eS * (1 - $kS));
            if ($oC < 5) {
                $oC++;
                $oH = $oH === null ? $h : max($oH, $h);
            }
            $norm[] = ['ts' => $b->tsEst, 'open' => $b->open, 'high' => $h, 'low' => $l, 'close' => $c, 'volume' => $v, 'vwap' => $vw, 'ema_f' => $eF, 'ema_s' => $eS, 'hod' => $hod, 'or_high' => $oH];
        }

        return $norm;
    }

    private function _redisComputeAtr(array $norm, int $period): float
    {
        if (count($norm) < $period + 2) {
            return 0.0;
        } $trs = [];
        for ($i = 1,$n = count($norm); $i < $n; $i++) {
            $p = (float) $norm[$i - 1]['close'];
            $h = (float) $norm[$i]['high'];
            $l = (float) $norm[$i]['low'];
            $trs[] = max($h - $l, abs($h - $p), abs($l - $p));
        }
        $c = min($period, count($trs));
        $s = 0.0;
        for ($i = count($trs) - $c; $i < count($trs); $i++) {
            $s += $trs[$i];
        }

        return $c > 0 ? $s / $c : 0.0;
    }
}
