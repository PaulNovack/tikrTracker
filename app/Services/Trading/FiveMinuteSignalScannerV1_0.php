<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * Pipeline M Version 1.0 - Momentum Movers Scanner (TradeThatSwing Methodology)
 *
 * Purpose: Scan for stocks making explosive intraday moves AFTER the open
 * Key Criteria (from TradeThatSwing):
 * - Change from Open: 3%+ (NOT overnight gaps - focus on intraday momentum)
 * - Relative Volume: 1.5x+ average
 * - Price Range: $6-35 (sweet spot for day trading)
 * - Average Volume: 20M+ daily (liquidity for entries/exits)
 * - ATR: 0.25-0.50+ (adequate volatility)
 *
 * Philosophy: Find stocks making BIG MOVES during the trading session, not pre-market gaps.
 * These are the stocks day traders can actually profit from using price action triggers.
 */
class FiveMinuteSignalScannerV1_0
{
    use HasPriceTables;

    private string $version = 'v1.0';

    private string $name = 'Biased Scanner';

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Scan for momentum movers matching TradeThatSwing criteria
     *
     * @param  string  $assetType  stock|crypto
     * @param  string  $asOfTsEst  Current timestamp EST (YYYY-mm-dd HH:MM:SS)
     * @param  float  $minChangeFromOpen  Minimum % change from open (default 3.0%)
     * @param  float  $minRelativeVolume  Minimum relative volume (default 1.5x)
     * @param  int  $minAvgVolume  Minimum average daily volume (default 20M)
     * @param  float  $minPrice  Minimum price filter (default $6)
     * @param  float  $maxPrice  Maximum price filter (default $35)
     * @param  float  $minATR  Minimum ATR filter (default 0.25)
     * @param  int  $limit  Maximum results to return
     * @return array Array of qualifying signals
     */
    public function scan(
        string $assetType,
        string $asOfTsEst,
        float $minChangeFromOpen = 3.0,
        float $minRelativeVolume = 1.5,
        int $minAvgVolume = 20000000,
        float $minPrice = 6.0,
        float $maxPrice = 35.0,
        float $minATR = 0.25,
        int $limit = 50
    ): array {
        $tradingDate = substr($asOfTsEst, 0, 10);

        // Step 1: Calculate change_from_open and relative_volume for today's bars
        // This populates the momentum columns we added to five_minute_prices
        $this->calculateMomentumMetrics($assetType, $tradingDate, $asOfTsEst);

        // Step 2: Find stocks meeting TradeThatSwing criteria RIGHT NOW
        $signals = $this->findMomentumMovers(
            $assetType,
            $tradingDate,
            $asOfTsEst,
            $minChangeFromOpen,
            $minRelativeVolume,
            $minAvgVolume,
            $minPrice,
            $maxPrice,
            $minATR,
            $limit
        );

        // Step 3: Store qualified movers to momentum_movers table for consolidation tracking
        $this->storeMomentumMovers($signals, $asOfTsEst);

        return $signals;
    }

    /**
     * Calculate change_from_open and relative_volume for all bars up to asOfTsEst
     */
    private function calculateMomentumMetrics(string $assetType, string $tradingDate, string $asOfTsEst): void
    {
        // Get opening prices for all symbols today (first 5m bar price)
        $openPrices = DB::table($this->fiveMinuteTable)
            ->select('symbol', DB::raw('MIN(price) as open'))
            ->where('asset_type', $assetType)
            ->where('trading_date_est', $tradingDate)
            ->where('ts_est', '>=', $tradingDate.' 09:30:00')
            ->where('ts_est', '<=', $tradingDate.' 09:35:00')
            ->groupBy('symbol')
            ->get()
            ->keyBy('symbol');

        // Get average volumes for each symbol (from daily_prices or calculate from 5m data)
        $avgVolumes = DB::table('daily_prices')
            ->select('symbol', DB::raw('AVG(volume) as avg_volume'))
            ->where('asset_type', $assetType)
            ->where('date', '>=', DB::raw("DATE_SUB('$tradingDate', INTERVAL 20 DAY)"))
            ->where('date', '<', $tradingDate)
            ->groupBy('symbol')
            ->get()
            ->keyBy('symbol');

        // Update change_from_open and relative_volume for bars up to asOfTsEst
        $bars = DB::table($this->fiveMinuteTable)
            ->select('id', 'symbol', 'price', 'volume', 'ts_est')
            ->where('asset_type', $assetType)
            ->where('trading_date_est', $tradingDate)
            ->where('ts_est', '<=', $asOfTsEst)
            ->whereNull('change_from_open') // Only calculate if not already done
            ->get();

        foreach ($bars as $bar) {
            $openPrice = $openPrices[$bar->symbol]->open ?? null;
            $avgVolume = $avgVolumes[$bar->symbol]->avg_volume ?? null;

            if (! $openPrice || ! $avgVolume || $avgVolume == 0) {
                continue;
            }

            $changeFromOpen = (($bar->price - $openPrice) / $openPrice) * 100;

            // Relative volume = (Current total volume / Minutes elapsed) / (Avg daily volume / 390 minutes)
            $timeStr = substr($bar->ts_est, 11);
            $minutesElapsed = $this->calculateMinutesFromOpen($timeStr);
            if ($minutesElapsed <= 0) {
                continue;
            }

            // Estimate cumulative volume for the day so far (simplified - sum 5m bars)
            $cumulativeVolume = DB::table($this->fiveMinuteTable)
                ->where('symbol', $bar->symbol)
                ->where('trading_date_est', $tradingDate)
                ->where('ts_est', '<=', $bar->ts_est)
                ->sum('volume');

            $currentRate = $cumulativeVolume / $minutesElapsed;
            $avgRate = $avgVolume / 390; // 390 minutes in trading day
            $relativeVolume = $avgRate > 0 ? $currentRate / $avgRate : 0;

            DB::table($this->fiveMinuteTable)
                ->where('id', $bar->id)
                ->update([
                    'change_from_open' => round($changeFromOpen, 4),
                    'relative_volume' => round($relativeVolume, 4),
                ]);
        }
    }

    /**
     * Calculate minutes elapsed since market open (9:30 AM)
     */
    private function calculateMinutesFromOpen(string $timeStr): int
    {
        $parts = explode(':', $timeStr);
        $hour = (int) $parts[0];
        $minute = (int) $parts[1];

        $minutesFromMidnight = ($hour * 60) + $minute;
        $openMinutes = (9 * 60) + 30; // 9:30 AM

        return max(0, $minutesFromMidnight - $openMinutes);
    }

    /**
     * Find stocks currently meeting TradeThatSwing momentum criteria
     */
    private function findMomentumMovers(
        string $assetType,
        string $tradingDate,
        string $asOfTsEst,
        float $minChangeFromOpen,
        float $minRelativeVolume,
        int $minAvgVolume,
        float $minPrice,
        float $maxPrice,
        float $minATR,
        int $limit
    ): array {
        // Get latest bar for each symbol at asOfTsEst
        $subquery = DB::table($this->fiveMinuteTable)
            ->select('symbol', DB::raw('MAX(ts_est) as latest_ts'))
            ->where('asset_type', $assetType)
            ->where('trading_date_est', $tradingDate)
            ->where('ts_est', '<=', $asOfTsEst)
            ->groupBy('symbol');

        $signals = DB::table($this->fiveMinuteTable.' as f')
            ->joinSub($subquery, 'latest', function ($join) {
                $join->on('f.symbol', '=', 'latest.symbol')
                    ->on('f.ts_est', '=', 'latest.latest_ts');
            })
            ->leftJoin('daily_prices as d', function ($join) use ($tradingDate) {
                $join->on('f.symbol', '=', 'd.symbol')
                    ->where('d.date', '>=', DB::raw("DATE_SUB('$tradingDate', INTERVAL 20 DAY)"))
                    ->where('d.date', '<', $tradingDate);
            })
            ->select(
                'f.symbol',
                'f.price',
                'f.volume',
                'f.change_from_open',
                'f.relative_volume',
                'f.ts_est as signal_ts_est',
                DB::raw('AVG(d.volume) as avg_volume'),
                DB::raw('AVG(d.high - d.low) as avg_range'),
                DB::raw('AVG((d.high - d.low) / d.price * 100) as avg_range_pct')
            )
            ->where('f.asset_type', $assetType)
            ->where('f.trading_date_est', $tradingDate)
            ->where('f.change_from_open', '>=', $minChangeFromOpen) // 3%+ change from open
            ->where('f.relative_volume', '>=', $minRelativeVolume) // 1.5x+ relative volume
            ->where('f.price', '>=', $minPrice) // $6+ price
            ->where('f.price', '<=', $maxPrice) // $35 or less
            ->whereNotNull('f.change_from_open')
            ->whereNotNull('f.relative_volume')
            ->groupBy('f.symbol', 'f.price', 'f.volume', 'f.change_from_open', 'f.relative_volume', 'f.ts_est')
            ->having('avg_volume', '>=', $minAvgVolume) // 20M+ avg volume
            ->having('avg_range', '>=', $minATR) // 0.25+ ATR (using avg range as proxy)
            ->orderBy('f.change_from_open', 'desc') // Sort by biggest movers
            ->limit($limit)
            ->get();

        return $signals->map(function ($row) use ($assetType) {
            return [
                'symbol' => $row->symbol,
                'asset_type' => $assetType,
                'signal_type' => 'MOMENTUM_MOVER',
                'signal_ts_est' => $row->signal_ts_est,
                'price' => $row->price,
                'score' => $this->calculateSignalScore($row),
                'meta' => [
                    'change_from_open' => $row->change_from_open,
                    'relative_volume' => $row->relative_volume,
                    'avg_volume' => $row->avg_volume,
                    'current_price' => $row->price,
                    'avg_range' => $row->avg_range ?? 0,
                    'avg_range_pct' => $row->avg_range_pct ?? 0,
                ],
                'atr' => $row->avg_range ?? 0,
                'atr_pct' => $row->avg_range_pct ?? 0,
            ];
        })->toArray();
    }

    /**
     * Calculate signal quality score (0-100)
     * Higher scores = stronger momentum + volume confirmation
     */
    private function calculateSignalScore($row): float
    {
        $score = 0;

        // Change from open score (0-40 points): 3% = 20pts, 6% = 30pts, 9%+ = 40pts
        $changeScore = min(40, ($row->change_from_open / 3.0) * 20);
        $score += $changeScore;

        // Relative volume score (0-30 points): 1.5x = 15pts, 2.0x = 22pts, 3.0x+ = 30pts
        $volScore = min(30, (($row->relative_volume - 1.0) / 2.0) * 30);
        $score += $volScore;

        // ATR score (0-20 points): 0.25 = 10pts, 0.50+ = 20pts
        $atrScore = min(20, (($row->atr ?? 0) / 0.50) * 20);
        $score += $atrScore;

        // Price range bonus (0-10 points): $15-25 = 10pts, outside = 5pts
        if ($row->price >= 15 && $row->price <= 25) {
            $score += 10;
        } else {
            $score += 5;
        }

        return round(min(100, $score), 2);
    }

    /**
     * Store momentum movers to database for consolidation pattern tracking
     */
    private function storeMomentumMovers(array $signals, string $asOfTsEst): void
    {
        $scanTime = substr($asOfTsEst, 11, 8);

        foreach ($signals as $signal) {
            DB::table('momentum_movers')->updateOrInsert(
                [
                    'symbol' => $signal['symbol'],
                    'trading_date_est' => substr($asOfTsEst, 0, 10),
                    'scan_time_est' => $scanTime,
                ],
                [
                    'change_from_open' => $signal['meta']['change_from_open'],
                    'relative_volume' => $signal['meta']['relative_volume'],
                    'current_volume' => $signal['meta']['avg_volume'] * $signal['meta']['relative_volume'],
                    'price' => $signal['price'],
                    'atr' => $signal['meta']['avg_range'] ?? 0,
                    'is_consolidating' => false, // Will be updated by consolidation detector
                    'updated_at' => now(),
                    'created_at' => DB::raw('IFNULL(created_at, NOW())'),
                ]
            );
        }
    }
}
