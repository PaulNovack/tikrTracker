<?php

namespace App\Services\Trading;

/**
 * Version 2000.0 - Market Movers Universe Entry Finder
 *
 * This finder keeps the pipeline compatible while the new universe is being
 * introduced. It turns each scanner signal into a simple, pipeline-safe long
 * entry without adding any additional filter logic yet.
 */
class OneMinuteEntryFinderV2000_0
{
    use HasPriceTables;

    private string $version = 'v2000.0';

    public function getVersion(): string
    {
        return $this->version;
    }

    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        ...$rest
    ): array {
        $signalBar = $this->dbSelect('
            SELECT ts_est, price, open, high, low, volume, atr, atr_pct
            FROM five_minute_prices
            WHERE symbol = ?
              AND asset_type = ?
              AND ts_est = ?
            LIMIT 1
        ', [$symbol, $assetType, $signalTsEst]);

        if (empty($signalBar)) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'signal_not_found',
            ];
        }

        $signalBar = $signalBar[0];
        $entryPrice = (float) $signalBar->price;
        $atr = $signalBar->atr !== null ? (float) $signalBar->atr : null;
        $atrPct = $signalBar->atr_pct !== null ? (float) $signalBar->atr_pct : null;

        $recentOneMinuteBars = $this->dbSelect('
            SELECT ts_est, price, open, high, low, volume
            FROM one_minute_prices
            WHERE symbol = ?
              AND asset_type = ?
              AND trading_date_est = DATE(?)
              AND ts_est <= ?
            ORDER BY ts_est DESC
            LIMIT 20
        ', [$symbol, $assetType, $asOfTsEst, $asOfTsEst]);

        $entryTsEst = $signalTsEst;
        $volRatio = null;

        if (! empty($recentOneMinuteBars)) {
            $latestBar = $recentOneMinuteBars[0];
            $entryTsEst = (string) $latestBar->ts_est;
            $entryPrice = (float) $latestBar->price;

            $volumeSamples = array_slice($recentOneMinuteBars, 1);
            $volumeValues = array_values(array_filter(array_map(
                static fn ($bar) => isset($bar->volume) ? (float) $bar->volume : null,
                $volumeSamples
            )));

            if (! empty($volumeValues)) {
                $avgVolume = array_sum($volumeValues) / count($volumeValues);
                if ($avgVolume > 0) {
                    $volRatio = round(((float) $latestBar->volume) / $avgVolume, 3);
                }
            }
        }

        if ($entryPrice <= 0) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'invalid_entry_price',
            ];
        }

        $riskBuffer = max($entryPrice * 0.02, $atr !== null ? $atr * 1.25 : 0.0);
        $stopPrice = max(0.01, round($entryPrice - $riskBuffer, 4));
        $riskPerShare = round($entryPrice - $stopPrice, 4);
        $riskPct = round(($riskPerShare / $entryPrice) * 100, 2);
        $suggestedTrailingStop = $atr !== null ? round($atr * 2.0, 4) : round($entryPrice * 0.015, 4);
        $suggestedTrailingStopPct = round(($suggestedTrailingStop / $entryPrice) * 100, 2);
        $targets = $this->buildTargets($entryPrice, $riskPerShare);
        $universeStats = $this->getUniverseStats($symbol);

        $score = round(
            (($universeStats['days_appeared'] ?? 1) * 10) + ($universeStats['max_gain_pct'] ?? 0),
            3
        );

        $bestEntry = [
            'type' => 'UNIVERSE_ALERT_1M',
            'entry_ts_est' => $entryTsEst,
            'entry' => round($entryPrice, 4),
            'stop' => $stopPrice,
            'risk_pct' => $riskPct,
            'risk_per_share' => $riskPerShare,
            'score' => $score,
            'vol_ratio' => $volRatio,
            'atr' => $atr,
            'atr_pct' => $atrPct,
            'suggested_trailing_stop' => $suggestedTrailingStop,
            'suggested_trailing_stop_pct' => $suggestedTrailingStopPct,
            'targets' => $targets,
            'meta' => [
                'version' => $this->version,
                'signal_ts_est' => $signalTsEst,
                'as_of_ts_est' => $asOfTsEst,
                'days_appeared' => $universeStats['days_appeared'] ?? null,
                'max_gain_pct' => $universeStats['max_gain_pct'] ?? null,
            ],
        ];

        return [
            'ok' => 1,
            'best_entry' => $bestEntry,
            'meta' => [
                'version' => $this->version,
                'as_of_ts_est' => $asOfTsEst,
            ],
        ];
    }

    public function findBestShort(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        ...$rest
    ): array {
        return [
            'ok' => 0,
            'best_entry' => null,
            'reason' => 'short_not_implemented',
        ];
    }

    private function buildTargets(float $entryPrice, float $riskPerShare): array
    {
        $riskPerShare = max(0.01, $riskPerShare);

        return [
            '1R' => round($entryPrice + $riskPerShare, 4),
            '2R' => round($entryPrice + ($riskPerShare * 2), 4),
            '3R' => round($entryPrice + ($riskPerShare * 3), 4),
            '3pct' => round($entryPrice * 1.03, 4),
            '4pct' => round($entryPrice * 1.04, 4),
            '5pct' => round($entryPrice * 1.05, 4),
        ];
    }

    private function getUniverseStats(string $symbol): array
    {
        $rows = $this->dbSelect('
            SELECT
                p.symbol,
                COUNT(DISTINCT p.trading_date_est) AS days_appeared,
                ROUND(MAX(((p.price - p.open) / p.open) * 100), 2) AS max_gain_pct
            FROM five_minute_prices p
            JOIN (
                SELECT trading_date
                FROM market_movers
                ORDER BY trading_date DESC
                LIMIT 5
            ) d ON d.trading_date = p.trading_date_est
            WHERE p.open > 0
              AND ((p.price - p.open) / p.open) * 100 >= 4
              AND p.symbol = ?
            GROUP BY p.symbol
            LIMIT 1
        ', [$symbol]);

        if (empty($rows)) {
            return [];
        }

        return [
            'days_appeared' => (int) $rows[0]->days_appeared,
            'max_gain_pct' => (float) $rows[0]->max_gain_pct,
        ];
    }
}
