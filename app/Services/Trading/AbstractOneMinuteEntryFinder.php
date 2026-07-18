<?php

namespace App\Services\Trading;

use App\Contracts\Trading\OneMinuteEntryFinderContract;

/**
 * Abstract base class for all one-minute entry finders.
 *
 * Enforces a contract that all entry finder implementations must fulfill:
 * - getVersion(): returns the finder version string
 * - getName(): returns the human-readable finder name
 * - entryConfig(): returns the finder's configuration as an array
 * - findBestLong(): executes the entry logic and returns a result (template method)
 *
 * Provides shared helpers for 1-minute bar queries, VWAP/EMA calculation,
 * time window validation, and debug logging.
 */
abstract class AbstractOneMinuteEntryFinder implements OneMinuteEntryFinderContract
{
    use HasPriceTables;

    protected string $version;

    /** @var array<string, int> Debug counters */
    private static array $dbg = [
        'called' => 0,
        'not_enough_bars' => 0,
        'time_blocked' => 0,
        'fail_notional_1m' => 0,
        'reject_extended' => 0,
        'reject_no_room' => 0,
        'returned' => 0,
        'bad_data_extreme_drop' => 0,
    ];

    /**
     * Get the finder version string (e.g. 'v25.2').
     */
    abstract public function getVersion(): string;

    /**
     * Get the human-readable finder name (e.g. 'Quality-First Entry').
     */
    abstract public function getName(): string;

    /**
     * Get the finder configuration as a key-value array.
     *
     * Subclasses should return their specific config values (min_notional, max_above_vwap, etc.).
     *
     * @return array<string, mixed>
     */
    abstract public function entryConfig(): array;

    /**
     * Core entry logic — subclasses implement this to find the best entry point.
     *
     * @param  string  $symbol  The stock symbol
     * @param  string  $assetType  'stock' or 'crypto'
     * @param  string  $signalTsEst  The signal timestamp in EST
     * @param  string  $asOfTsEst  The "as of" timestamp in EST
     * @return array|null  Entry data array or null if no entry found
     */
    abstract protected function doFindBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
    ): ?array;

    /**
     * TEMPLATE METHOD — finds the best long entry and returns a standardized result.
     *
     * Subclasses implement doFindBestLong(); this method validates and wraps the result.
     *
     * @return array{ok: int, best_entry: array|null, reason?: string, meta?: array}
     */
    final public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
    ): ?array {
        self::$dbg['called']++;

        $entry = $this->doFindBestLong($symbol, $assetType, $signalTsEst, $asOfTsEst);

        if ($entry === null) {
            $this->maybeLogDebug();

            return ['ok' => 0, 'best_entry' => null, 'reason' => 'no_entry'];
        }

        $this->validateEntry($entry, $symbol);

        self::$dbg['returned']++;
        $this->maybeLogDebug();

        $entry['symbol'] = $symbol;
        $entry['asset_type'] = $assetType;
        $entry['signal_ts_est'] = $signalTsEst;

        return [
            'ok' => 1,
            'best_entry' => $entry,
            'meta' => [
                'version' => $this->getVersion(),
                'as_of_ts_est' => $asOfTsEst,
            ],
        ];
    }

    /**
     * Validate that an entry result contains all required keys.
     *
     * @param  array<string, mixed>  $entry
     * @param  string  $symbol
     *
     * @throws \RuntimeException
     */
    protected function validateEntry(array $entry, string $symbol): void
    {
        $required = ['entry_price', 'stop_loss', 'entry_type'];

        foreach ($required as $key) {
            if (! array_key_exists($key, $entry)) {
                throw new \RuntimeException(
                    sprintf('%s::doFindBestLong() missing required key "%s" for symbol "%s".', static::class, $key, $symbol)
                );
            }
        }
    }

    /**
     * Fetch 1-minute bars for a symbol from market open to as-of time.
     *
     * @return array<int, object> Array of bar objects with ts, open, high, low, close, volume
     */
    protected function fetchOneMinuteBars(
        string $symbol,
        string $assetType,
        string $marketOpen,
        string $asOfTsEst,
    ): array {
        $tradeDate = substr($marketOpen, 0, 10);

        return $this->dbSelect('
            SELECT ts_est, `open`, high, low, price AS close, volume
            FROM one_minute_prices
            WHERE asset_type = ?
              AND symbol = ?
              AND trading_date_est = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ', [$assetType, $symbol, $tradeDate, $marketOpen, $asOfTsEst]);
    }

    /**
     * Fetch 5-minute bars for choppiness detection.
     *
     * @return array<int, object>
     */
    protected function fetchFiveMinuteBarsForAnalysis(
        string $symbol,
        string $assetType,
        string $marketOpen,
        string $asOfTsEst,
    ): array {
        $tradeDate = substr($marketOpen, 0, 10);

        return $this->dbSelect('
            SELECT ts_est, open, high, low, price
            FROM five_minute_prices
            WHERE asset_type = ?
              AND symbol = ?
              AND trading_date_est = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ', [$assetType, $symbol, $tradeDate, $marketOpen, $asOfTsEst]);
    }

    /**
     * Check whether the current time is within an allowed trading window
     * (avoid lunch chop unless explicitly allowed).
     */
    protected function isAllowedTime(string $time, bool $allowLunch = false): bool
    {
        $ts = strtotime($time);
        $h = (int) date('H', $ts);
        $m = (int) date('i', $ts);

        $minutes = ($h * 60) + $m;

        // Always allow first 30 minutes and last 30 minutes
        if ($minutes < 640 || $minutes > 945) {  // before 10:40 or after 15:45 UTC
            return true;
        }

        // Block lunch window (11:30-13:30 EST = 16:30-18:30 UTC)
        if (! $allowLunch && $minutes >= 690 && $minutes <= 810) {
            return false;
        }

        return true;
    }

    /**
     * Calculate 5-minute bar choppiness metrics (body %, overlap).
     *
     * @param  array<int, object>  $bars
     * @return array{body_pct_avg: float, overlap_pct: float}
     */
    protected function calculate5MinChoppiness(array $bars): array
    {
        $bodyPcts = [];
        $overlaps = [];

        for ($i = 0; $i < count($bars); $i++) {
            $o = (float) $bars[$i]->open;
            $c = (float) $bars[$i]->price;
            $h = (float) $bars[$i]->high;
            $l = (float) $bars[$i]->low;

            $range = $h - $l;
            $bodyPcts[] = ($range > 0) ? abs($c - $o) / $range : 0;

            if ($i > 0) {
                $prevH = (float) $bars[$i - 1]->high;
                $prevL = (float) $bars[$i - 1]->low;
                $overlap = max(0, min($h, $prevH) - max($l, $prevL));
                $combinedRange = max($h, $prevH) - min($l, $prevL);
                $overlaps[] = ($combinedRange > 0) ? $overlap / $combinedRange : 0;
            }
        }

        return [
            'body_pct_avg' => count($bodyPcts) > 0 ? array_sum($bodyPcts) / count($bodyPcts) : 0,
            'overlap_pct' => count($overlaps) > 0 ? array_sum($overlaps) / count($overlaps) : 0,
        ];
    }

    /**
     * Check for extreme price drops (reverse splits, bad data).
     * Returns true if the bars pass quality checks.
     *
     * @param  array<int, object>  $bars
     */
    protected function hasValidPriceData(array $bars): bool
    {
        for ($i = 1; $i < count($bars); $i++) {
            $prevClose = (float) $bars[$i - 1]->close;
            $currentOpen = (float) $bars[$i]->open;

            if ($prevClose > 0) {
                $dropPct = (($currentOpen - $prevClose) / $prevClose) * 100.0;
                if ($dropPct < -50.0) {
                    self::$dbg['bad_data_extreme_drop']++;

                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Log debug counters periodically if debug mode is enabled.
     */
    protected function maybeLogDebug(): void
    {
        if (! $this->isDebugEnabled()) {
            return;
        }
        if (self::$dbg['called'] > 0 && self::$dbg['called'] % 20000 === 0) {
            \Illuminate\Support\Facades\Log::info('['.static::class.'] debug counters', self::$dbg);
        }
    }

    protected function isDebugEnabled(): bool
    {
        return (bool) config('trading.entry_finder_debug', false);
    }
}
