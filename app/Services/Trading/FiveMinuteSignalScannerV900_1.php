<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Version 900.1 - Momentum Continuation Scanner (LONG)
 *
 * Same intent as V900.0 but redesigned for query performance:
 * - Filters symbols aggressively in PHP before any heavy DB work
 * - Avoids full-table CTE scans over 150K+ rows
 * - Uses targeted per-symbol queries after filtering
 * - Fixes closing bar lookup: 15:55:00 (Alpaca IEX) instead of 16:00:00 (yfinance)
 *
 * Goal: Identify stocks that:
 * - Had significant move yesterday (-5%+ min, configurable)
 * - Show explosive continuation (+2%+ in first 15 mins)
 * - Strong momentum (RSI > 60)
 * - EMA9 > EMA21
 * - Trading above/near Bollinger upper band (BB position > 65%)
 * - High volume (2x+ recent average)
 *
 * ENV / config('trading.*'):
 * - v900.entry_score_min        (default 40)
 * - v900.entry_score_max        (default 100)
 * - v900.entry_score_limit      (default 10)
 * - v900.min_price              (default 3.0)
 * - v900.max_price              (default 500.0)
 * - v900.min_yesterday_move_pct (default -5.0)
 * - v900.min_opening_gap_pct    (default -10.0)
 * - v900.min_early_move_pct     (default 2.0)
 * - v900.min_rsi                (default 60)
 * - v900.min_bb_position        (default 65)
 * - v900.min_volume_mult        (default 2.0)
 * - v900.time_window_start      (default '09:30:00')
 * - v900.time_window_end        (default '10:30:00')
 */
class FiveMinuteSignalScannerV900_1
{
    use HasPriceTables;

    private string $version = 'v900.1';

    private string $name = 'Risk-Off Winners';

    // ── Scanner Configuration (public so entry finders can read) ──
    /** @var float Minimum entry score (0-100) */
    public float $entryScoreMin = 40;

    /** @var float Maximum entry score (0-100) */
    public float $entryScoreMax = 100;

    /** @var int Max number of signals to return */
    public int $entryScoreLimit = 10;

    /** @var float Minimum share price */
    public float $minPrice = 3.0;

    /** @var float Maximum share price */
    public float $maxPrice = 500.0;

    /** @var float Minimum yesterday move % (negative = declining stocks) */
    public float $minYesterdayMovePct = -5.0;

    /** @var float Minimum opening gap % (negative = gap down) */
    public float $minOpeningGapPct = -10.0;

    /** @var float Minimum early session move % */
    public float $minEarlyMovePct = 2.0;

    /** @var float Minimum RSI reading */
    public float $minRsi = 60;

    /** @var float Minimum BB position */
    public float $minBBPosition = 65;

    /** @var float Minimum volume vs average multiplier */
    public float $minVolumeMult = 2.0;

    /** @var string Start of trading window (HH:MM:SS) */
    public string $timeWindowStart = '09:30:00';

    /** @var string End of trading window (HH:MM:SS) */
    public string $timeWindowEnd = '10:30:00';

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
            'entry_score_min' => $this->entryScoreMin,
            'entry_score_max' => $this->entryScoreMax,
            'entry_score_limit' => $this->entryScoreLimit,
            'min_price' => $this->minPrice,
            'max_price' => $this->maxPrice,
            'min_yesterday_move_pct' => $this->minYesterdayMovePct,
            'min_opening_gap_pct' => $this->minOpeningGapPct,
            'min_early_move_pct' => $this->minEarlyMovePct,
            'min_rsi' => $this->minRsi,
            'min_bb_position' => $this->minBBPosition,
            'min_volume_mult' => $this->minVolumeMult,
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

    /**
     * Scan for Momentum Continuation candidates (LONG)
     */
    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minMovePct = 0.0,
        float $volMult = 1.0,
        int $limit = 20
    ): array {
        $minScore = $this->entryScoreMin;
        $maxScore = $this->entryScoreMax;
        $topN = $this->entryScoreLimit;

        $minPrice = $this->minPrice;
        $maxPrice = $this->maxPrice;

        $minYesterdayMovePct = $this->minYesterdayMovePct;
        $minOpeningGapPct = $this->minOpeningGapPct;
        $minEarlyMovePct = $this->minEarlyMovePct;
        $minRsi = $this->minRsi;
        $minBBPosition = $this->minBBPosition;
        $minVolumeMult = $this->minVolumeMult;

        $timeWindowStart = $this->timeWindowStart;
        $timeWindowEnd = $this->timeWindowEnd;

        if ($topN <= 0) {
            $topN = 10;
        }
        if ($maxScore <= 0) {
            $maxScore = 100.0;
        }
        if ($minScore > $maxScore) {
            [$minScore, $maxScore] = [$maxScore, $minScore];
        }

        $limit = max(1, (int) $limit);
        $tradeDate = substr($asOfTsEst, 0, 10);

        // Resolve previous 2 trading days
        $prevTradingDates = DB::table($this->fiveMinuteTable)
            ->where('asset_type', $assetType)
            ->where('trading_date_est', '<', $tradeDate)
            ->distinct()
            ->orderBy('trading_date_est', 'desc')
            ->limit(2)
            ->pluck('trading_date_est')
            ->toArray();

        if (count($prevTradingDates) < 2) {
            Log::warning('[V900.1 Scanner] Need 2 previous trading days', [
                'asset_type' => $assetType,
                'trade_date' => $tradeDate,
                'found' => count($prevTradingDates),
            ]);

            return [];
        }

        $prevTradingDate = $prevTradingDates[0];
        $prevPrevTradingDate = $prevTradingDates[1];

        // Disabled noisy scanner logs.
        // Log::debug('[V900.1 Scanner] Starting scan', [
        //     'asset_type' => $assetType,
        //     'as_of' => $asOfTsEst,
        //     'trade_date' => $tradeDate,
        //     'prev_trade_date' => $prevTradingDate,
        // ]);

        // ── Step 1: Get yesterday closes (15:55 bar) ─────────────────────────
        // Uses idx_5m_asset_date_time_ts: (asset_type, trading_date_est, trading_time_est, ts_est)
        $yesterdayCloses = $this->dbSelect('
            SELECT symbol, price AS prev_close
            FROM five_minute_prices
            WHERE asset_type = ?
              AND trading_date_est = ?
              AND trading_time_est = \'15:55:00\'
              AND price BETWEEN ? AND ?
        ', [$assetType, $prevTradingDate, $minPrice, $maxPrice]);

        if (empty($yesterdayCloses)) {
            Log::debug('[V900.1 Scanner] No yesterday closes found');

            return [];
        }

        $prevCloseMap = [];
        foreach ($yesterdayCloses as $row) {
            $prevCloseMap[$row->symbol] = (float) $row->prev_close;
        }

        // ── Step 2: Get 2-days-ago closes (15:55 bar) ────────────────────────
        $twoDaysAgoCloses = $this->dbSelect('
            SELECT symbol, price AS close_2d
            FROM five_minute_prices
            WHERE asset_type = ?
              AND trading_date_est = ?
              AND trading_time_est = \'15:55:00\'
              AND price BETWEEN ? AND ?
        ', [$assetType, $prevPrevTradingDate, $minPrice, $maxPrice]);

        $prevPrevCloseMap = [];
        foreach ($twoDaysAgoCloses as $row) {
            $prevPrevCloseMap[$row->symbol] = (float) $row->close_2d;
        }

        // ── Step 3: PHP filter — yesterday_move_pct ──────────────────────────
        $qualifyingSymbols = [];
        $yesterdayMoveMap = [];

        foreach ($prevCloseMap as $symbol => $prevClose) {
            if (! isset($prevPrevCloseMap[$symbol])) {
                continue;
            }

            $prevPrevClose = $prevPrevCloseMap[$symbol];
            if ($prevPrevClose <= 0) {
                continue;
            }

            $movePct = (($prevClose - $prevPrevClose) / $prevPrevClose) * 100;

            if ($movePct < $minYesterdayMovePct) {
                continue;
            }

            $qualifyingSymbols[] = $symbol;
            $yesterdayMoveMap[$symbol] = $movePct;
        }

        // Also add market movers if configured
        $moversLimit = (int) config('trading.market_movers.pipeline_f', 0);
        if ($moversLimit > 0) {
            $moverSymbols = app(\App\Services\MarketMoversService::class)->getTodaysTopMoversFromCache(null, $moversLimit);
            foreach ($moverSymbols as $sym) {
                if (! in_array($sym, $qualifyingSymbols, true)) {
                    $qualifyingSymbols[] = $sym;
                }
            }
        }

        if (empty($qualifyingSymbols)) {
            Log::debug('[V900.1 Scanner] No symbols passed yesterday_move_pct filter');

            return [];
        }

        // Log::debug('[V900.1 Scanner] After yesterday move filter', ['count' => count($qualifyingSymbols)]);

        // ── Step 4: Today opening info for qualifying symbols only ────────────
        // today_open_price = 09:30 open, highest_first_15min = max close through 09:45
        $placeholders = implode(',', array_fill(0, count($qualifyingSymbols), '?'));

        $openingRows = $this->dbSelect("
            SELECT
                symbol,
                MAX(CASE WHEN trading_time_est = '09:30:00' THEN open ELSE NULL END) AS today_open_price,
                MAX(CASE WHEN trading_time_est <= '09:45:00' THEN price ELSE NULL END) AS highest_first_15min
            FROM five_minute_prices
            WHERE asset_type = ?
              AND trading_date_est = ?
              AND ts_est <= ?
              AND trading_time_est <= '09:45:00'
              AND symbol IN ({$placeholders})
            GROUP BY symbol
            HAVING today_open_price IS NOT NULL AND highest_first_15min IS NOT NULL
        ", array_merge([$assetType, $tradeDate, $asOfTsEst], $qualifyingSymbols));

        if (empty($openingRows)) {
            Log::debug('[V900.1 Scanner] No opening data found for qualifying symbols');

            return [];
        }

        // PHP filter: opening_gap_pct and early_continuation_pct
        $openingMap = [];

        foreach ($openingRows as $row) {
            $symbol = $row->symbol;
            $prevClose = $prevCloseMap[$symbol] ?? null;

            if (! $prevClose || $prevClose <= 0) {
                continue;
            }

            $todayOpen = (float) $row->today_open_price;
            $highest15 = (float) $row->highest_first_15min;

            if ($todayOpen <= 0) {
                continue;
            }

            $openingGapPct = (($todayOpen - $prevClose) / $prevClose) * 100;
            $earlyContinuationPct = (($highest15 - $todayOpen) / $todayOpen) * 100;

            if ($openingGapPct < $minOpeningGapPct) {
                continue;
            }

            if ($earlyContinuationPct < $minEarlyMovePct) {
                continue;
            }

            $openingMap[$symbol] = [
                'today_open_price' => $todayOpen,
                'highest_first_15min' => $highest15,
                'opening_gap_pct' => $openingGapPct,
                'early_continuation_pct' => $earlyContinuationPct,
            ];
        }

        if (empty($openingMap)) {
            Log::debug('[V900.1 Scanner] No symbols passed opening/continuation filter');

            return [];
        }

        $signalSymbols = array_keys($openingMap);

        // Log::debug('[V900.1 Scanner] After opening filter', ['count' => count($signalSymbols)]);

        // ── Step 5: Yesterday avg_volume for signal symbols only ──────────────
        $placeholders2 = implode(',', array_fill(0, count($signalSymbols), '?'));

        $avgVolumeRows = $this->dbSelect("
            SELECT symbol, AVG(volume) AS avg_volume
            FROM five_minute_prices
            WHERE asset_type = ?
              AND trading_date_est = ?
              AND symbol IN ({$placeholders2})
            GROUP BY symbol
        ", array_merge([$assetType, $prevTradingDate], $signalSymbols));

        $avgVolumeMap = [];
        foreach ($avgVolumeRows as $row) {
            $avgVolumeMap[$row->symbol] = (float) $row->avg_volume;
        }

        // ── Step 6: Today signal bars (time window, indicators) ───────────────
        $placeholders3 = implode(',', array_fill(0, count($signalSymbols), '?'));

        $signalRows = $this->dbSelect("
            SELECT
                symbol,
                ts_est AS signal_ts_est,
                trading_time_est,
                price AS setup_price,
                open, high, low, volume,
                vwap, vwap_dist_pct, above_vwap,
                ema9, ema21, ema9_above_ema21,
                atr, atr_pct,
                rsi_14,
                bb_upper, bb_middle, bb_lower,
                CASE
                    WHEN (bb_upper - bb_lower) > 0
                    THEN ((price - bb_lower) / (bb_upper - bb_lower)) * 100
                    ELSE 0
                END AS bb_position
            FROM five_minute_prices
            WHERE asset_type = ?
              AND trading_date_est = ?
              AND ts_est <= ?
              AND trading_time_est BETWEEN ? AND ?
              AND ema9_above_ema21 = 1
              AND rsi_14 >= ?
              AND symbol IN ({$placeholders3})
            ORDER BY ts_est DESC
        ", array_merge(
            [$assetType, $tradeDate, $asOfTsEst, $timeWindowStart, $timeWindowEnd, $minRsi],
            $signalSymbols
        ));

        if (empty($signalRows)) {
            Log::debug('[V900.1 Scanner] No signal bars found in time window');

            return [];
        }

        // ── Step 7: Score and build signals in PHP ────────────────────────────
        // Keep only the most recent qualifying bar per symbol
        $seenSymbols = [];
        $signals = [];

        foreach ($signalRows as $row) {
            $symbol = $row->symbol;

            if (isset($seenSymbols[$symbol])) {
                continue;
            }

            $seenSymbols[$symbol] = true;

            $bbPosition = (float) $row->bb_position;
            $rsi14 = (float) $row->rsi_14;
            $volume = (float) $row->volume;
            $avgVolume = $avgVolumeMap[$symbol] ?? 0;

            if ($avgVolume <= 0) {
                continue;
            }

            $volumeRatio = $volume / $avgVolume;

            if ($volumeRatio < $minVolumeMult) {
                continue;
            }

            if ($bbPosition < $minBBPosition) {
                continue;
            }

            $opening = $openingMap[$symbol];
            $yesterdayMovePct = $yesterdayMoveMap[$symbol] ?? 0.0;
            $prevClose = $prevCloseMap[$symbol] ?? 0.0;

            $rsiScore = min(20, max(0, ($rsi14 - 60) * 0.5));
            $bbScore = min(25, max(0, ($bbPosition - 65) * 0.5));
            $yesterdayScore = min(15, max(0, ($yesterdayMovePct - (-5)) * 0.3));
            $continuationScore = min(20, max(0, ($opening['early_continuation_pct'] - 2) * 2));
            $volumeScore = min(10, max(0, ($volumeRatio - 2) * 3));
            $vwapScore = ((int) $row->above_vwap === 1) ? 5 : 0;
            $emaScore = ((int) $row->ema9_above_ema21 === 1) ? 5 : 0;

            $score = $rsiScore + $bbScore + $yesterdayScore + $continuationScore + $volumeScore + $vwapScore + $emaScore;

            if ($score < $minScore || $score > $maxScore) {
                continue;
            }

            $signals[] = [
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_type' => 'MOMENTUM_CONTINUATION_SETUP',
                'signal_ts_est' => $row->signal_ts_est,
                'score' => round($score, 2),
                'atr' => round((float) $row->atr, 4),
                'atr_pct' => round((float) $row->atr_pct, 4),
                'meta' => [
                    'version' => $this->version,
                    'setup_price' => round((float) $row->setup_price, 4),
                    'vwap' => round((float) $row->vwap, 4),
                    'ema9' => round((float) $row->ema9, 4),
                    'ema21' => round((float) $row->ema21, 4),
                    'rsi_14' => round($rsi14, 2),
                    'bb_position' => round($bbPosition, 2),
                    'bb_upper' => round((float) $row->bb_upper, 4),
                    'bb_middle' => round((float) $row->bb_middle, 4),
                    'bb_lower' => round((float) $row->bb_lower, 4),
                    'yesterday_move_pct' => round($yesterdayMovePct, 2),
                    'opening_gap_pct' => round($opening['opening_gap_pct'], 2),
                    'early_continuation_pct' => round($opening['early_continuation_pct'], 2),
                    'volume_ratio' => round($volumeRatio, 2),
                    'prev_day_close' => round($prevClose, 4),
                    'score_breakdown' => [
                        'rsi_score' => round($rsiScore, 2),
                        'bb_score' => round($bbScore, 2),
                        'yesterday_score' => round($yesterdayScore, 2),
                        'continuation_score' => round($continuationScore, 2),
                        'volume_score' => round($volumeScore, 2),
                        'vwap_score' => $vwapScore,
                        'ema_score' => $emaScore,
                    ],
                ],
            ];
        }

        // Sort by score descending, return top N
        usort($signals, fn ($a, $b) => $b['score'] <=> $a['score']);
        $signals = array_slice($signals, 0, $topN);

        // Log::debug('[V900.1 Scanner] Scan complete', [
        //     'signals_found' => count($signals),
        //     'top_score' => $signals[0]['score'] ?? null,
        //     'top_symbol' => $signals[0]['symbol'] ?? null,
        // ]);

        return $signals;
    }
}
