<?php

namespace App\Services\Trading;

/**
 * Version 2000.0 - Market Movers Universe Scanner
 *
 * Universe:
 * - Symbols that appeared in the last 5 market-movers days
 * - Only the live/current trading day bar is surfaced for now
 *
 * This intentionally avoids extra filtering so the pipeline can alert on the
 * entire universe first and add refinement later.
 */
class FiveMinuteSignalScannerV2000_0 extends AbstractSignalScanner
{
    private string $version = 'v2000.0';

    private string $name = 'Market Movers Universe';

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
        'min_notional_5m' => 0,
        'min_atr_pct_5m' => 0,
        'min_rvol_5m' => 0,
        'min_move_30m_pct' => 0,
        'move_bars_5m' => 6,
        'atr_period_5m' => 14,
        'rvol_lookback_5m' => 20,
];
    }

    protected function doScan(
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minMovePct = 0.4,
        float $volMult = 1.5,
        int $limit = 10000, bool $skipCache = false, ?string $symbol = null): array
    {
        $tradeDate = substr($asOfTsEst, 0, 10);

        $universeRows = $this->dbSelect('
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
            GROUP BY p.symbol
            ORDER BY days_appeared DESC, max_gain_pct DESC, p.symbol
        ');

        if (empty($universeRows)) {
            return [];
        }

        $symbols = array_values(array_filter(array_map(
            static fn ($row) => (string) ($row->symbol ?? ''),
            $universeRows
        )));

        if (empty($symbols)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($symbols), '?'));
        $latestRows = $this->dbSelect("\n            SELECT\n                ranked.symbol,\n                ranked.ts_est,\n                ranked.price AS close_price,\n                ranked.open,\n                ranked.high,\n                ranked.low,\n                ranked.volume,\n                ranked.atr,\n                ranked.atr_pct,\n                ranked.trading_date_est\n            FROM (\n                SELECT\n                    f.symbol,\n                    f.ts_est,\n                    f.price,\n                    f.open,\n                    f.high,\n                    f.low,\n                    f.volume,\n                    f.atr,\n                    f.atr_pct,\n                    f.trading_date_est,\n                    ROW_NUMBER() OVER (PARTITION BY f.symbol ORDER BY f.ts_est DESC) AS rn\n                FROM five_minute_prices f\n                WHERE f.asset_type = ?\n                  AND f.trading_date_est = ?\n                  AND f.ts_est <= ?\n                  AND f.symbol IN ($placeholders)\n            ) ranked\n            WHERE ranked.rn = 1\n            ORDER BY ranked.ts_est DESC, ranked.symbol ASC\n            LIMIT ?\n        ", array_merge(['stock', $tradeDate, $asOfTsEst], $symbols, [max(1, $limit)]));

        if (empty($latestRows)) {
            return [];
        }

        $latestBySymbol = [];
        foreach ($latestRows as $row) {
            $latestBySymbol[(string) $row->symbol] = $row;
        }

        $out = [];
        foreach ($universeRows as $rank => $universeRow) {
            $symbol = (string) $universeRow->symbol;
            $bar = $latestBySymbol[$symbol] ?? null;

            if (! $bar) {
                continue;
            }

            $daysAppeared = (int) $universeRow->days_appeared;
            $maxGainPct = (float) $universeRow->max_gain_pct;
            $score = round(($daysAppeared * 10) + $maxGainPct, 3);

            $out[] = [
                'symbol' => $symbol,
                'asset_type' => 'stock',
                'signal_type' => 'MOMO_5D_UNIVERSE',
                'signal_ts_est' => (string) $bar->ts_est,
                'score' => $score,
                'atr' => $bar->atr !== null ? (float) $bar->atr : null,
                'atr_pct' => $bar->atr_pct !== null ? (float) $bar->atr_pct : null,
                'meta' => [
                    'version' => $this->version,
                    'universe_rank' => $rank + 1,
                    'universe_size' => count($universeRows),
                    'days_appeared' => $daysAppeared,
                    'max_gain_pct' => $maxGainPct,
                    'current_price' => (float) $bar->close_price,
                    'current_volume' => (float) $bar->volume,
                    'trading_date' => (string) $bar->trading_date_est,
                    'universe_days' => 5,
                    'lookback_minutes' => $lookbackMinutes,
                    'min_move_pct' => $minMovePct,
                    'vol_mult' => $volMult,
                    'move_30m_pct' => null,
                    'rvol_5m' => null,
                    'atr_pct_5m' => $bar->atr_pct !== null ? (float) $bar->atr_pct : null,
                    'notional_last5m' => (float) $bar->close_price * (float) $bar->volume,
                    'spy_move_30m_pct' => null,
                    'signal_age_seconds' => 0,
                ],
            ];

            if (count($out) >= max(1, $limit)) {
                break;
            }
        }

        return $out;
    }
}
