<?php

namespace App\Services\Trading;

/**
 * Abstract base class for all signal scanners.
 *
 * Enforces a contract that all scanner implementations must fulfill:
 * - getVersion(): returns the scanner version string
 * - getName(): returns the human-readable scanner name
 * - scanConfig(): returns the scanner's configuration as an array
 * - scan(): executes the signal scan and returns results
 *
 * Provides shared helpers for table resolution, raw queries,
 * and benchmark (SPY/QQQM) movement calculation.
 */
abstract class AbstractSignalScanner
{
    use HasPriceTables;

    /**
     * Get the scanner version string (e.g. 'v25.2').
     */
    abstract public function getVersion(): string;

    /**
     * Get the human-readable scanner name (e.g. 'Quality-First').
     */
    abstract public function getName(): string;

    /**
     * Get the scanner configuration as a key-value array.
     *
     * @return array<string, mixed>
     */
    abstract public function scanConfig(): array;

    /**
     * Execute the signal scan and return an array of candidate signals.
     *
     * This is a TEMPLATE METHOD — subclasses implement doScan(), and this method
     * validates that every returned row has the required keys before returning.
     *
     * @param  string  $assetType  'stock' or 'crypto'
     * @param  string  $asOfTsEst  Timestamp to scan as-of (e.g. '2026-07-17 10:30:00')
     * @param  int  $lookbackMinutes  How far back to look for data
     * @param  float  $minMovePct  Minimum percentage move threshold
     * @param  float  $volMult  Volume multiplier threshold (legacy param)
     * @param  int  $limit  Maximum number of signals to return
     * @param  bool  $skipCache  If true, bypass Redis caching (for backtest mode)
     * @return array<int, array{
     *     symbol: string,
     *     asset_type: string,
     *     signal_type: string,
     *     signal_ts_est: string,
     *     score: float,
     *     atr: float|null,
     *     atr_pct: float,
     *     meta: array{
     *         move_30m_pct: float,
     *         rvol_5m: float,
     *         atr_pct_5m: float,
     *         notional_last5m: float,
     *         pct_nd: float|null,
     *         spy_move_30m_pct: float,
     *         universe_size: int,
     *         signal_age_seconds: int,
     *         version: string,
     *         current_price: float|null
     *     }
     * }>
     */
    final public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minMovePct = 1.2,
        float $volMult = 3.5,
        int $limit = 60,
        bool $skipCache = false
    ): array {
        $rows = $this->doScan($assetType, $asOfTsEst, $lookbackMinutes, $minMovePct, $volMult, $limit, $skipCache);

        foreach ($rows as $i => $row) {
            $this->validateSignalRow($row, $i);
        }

        return $rows;
    }

    /**
     * Subclasses implement this to perform the actual scan logic.
     *
     * The return value is validated by the parent scan() method — every row
     * must contain all required keys (see scan() PHPDoc for the full shape).
     *
     * @return array<int, array<string, mixed>>
     */
    abstract protected function doScan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes,
        float $minMovePct,
        float $volMult,
        int $limit,
        bool $skipCache
    ): array;

    /**
     * Validate that a single signal row contains all required keys.
     *
     * @param  array<string, mixed>  $row
     * @param  int  $index  Row index for error messages
     *
     * @throws \RuntimeException
     */
    protected function validateSignalRow(array $row, int $index): void
    {
        $required = ['symbol', 'asset_type', 'signal_type', 'signal_ts_est', 'score', 'atr', 'atr_pct', 'meta'];
        $metaRequired = [
            'move_30m_pct', 'rvol_5m', 'atr_pct_5m', 'notional_last5m',
            'spy_move_30m_pct', 'universe_size', 'signal_age_seconds', 'version', 'current_price',
        ];

        foreach ($required as $key) {
            if (! array_key_exists($key, $row)) {
                throw new \RuntimeException(
                    sprintf('%s::doScan() row %d missing required key "%s".', static::class, $index, $key)
                );
            }
        }

        /** @var array<string, mixed> $meta */
        $meta = $row['meta'];
        foreach ($metaRequired as $key) {
            if (! array_key_exists($key, $meta)) {
                throw new \RuntimeException(
                    sprintf('%s::doScan() row %d missing required meta key "%s".', static::class, $index, $key)
                );
            }
        }
    }

    /**
     * Calculate the 30-minute percentage movement of the benchmark symbol (QQQM/SPY).
     *
     * Used for relative-strength filtering — if the benchmark rips +2% and a candidate
     * is only up +1.5%, the candidate is merely riding the tide, not showing true strength.
     */
    protected function getSpyMovement30m(string $asOfTsEst, string $assetType, int $moveBars): float
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
