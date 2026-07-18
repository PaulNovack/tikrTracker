<?php

namespace App\Console\Commands\Analysis;

use App\Services\Market\BestPerformers5mService;
use App\Services\Market\BuyZoneFromTopPerformersService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BacktestBuyZone extends Command
{
    protected $signature = 'backtest:buy-zone
        {--from= : Start date YYYY-MM-DD}
        {--to= : End date YYYY-MM-DD}
        {--start-time=09:45 : Start time HH:MM in EST}
        {--end-time=15:30 : End time HH:MM in EST}
        {--interval=15 : Minutes between scans}
        {--asset-type=stock : Asset type to analyze}
        {--days=7 : Number of days for 7d window}
        {--limit=10 : Max candidates per interval}
        {--exit-time=15:55 : Time to exit positions (HH:MM EST)}
        {--min-rvol=1.5 : Minimum RVOL threshold}
        {--min-vol=0 : Minimum total volume across 7d period}
        {--clear : Clear existing buy_zone_alerts before running}
        {--show-details : Show detailed trade results}';

    protected $description = 'Backtest buy zone strategy over multiple days with P&L tracking';

    private int $totalAlerts = 0;

    private int $winners = 0;

    private int $losers = 0;

    private float $totalPnL = 0.0;

    private float $totalRiskAdjustedPnL = 0.0;

    private array $tradeResults = [];

    // Viable vs Non-Viable tracking
    private int $viableWinners = 0;

    private int $viableLosers = 0;

    private float $viablePnL = 0.0;

    private int $nonViableWinners = 0;

    private int $nonViableLosers = 0;

    private float $nonViablePnL = 0.0;

    public function __construct(
        private readonly BestPerformers5mService $bestPerformersService,
        private readonly BuyZoneFromTopPerformersService $buyZoneService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Set unlimited execution time for backtesting
        set_time_limit(0);
        ini_set('max_execution_time', '0');

        $from = $this->option('from');
        $to = $this->option('to');

        if (! $from || ! $to) {
            $this->error('--from and --to dates are required (YYYY-MM-DD)');

            return 1;
        }

        $startTime = $this->option('start-time');
        $endTime = $this->option('end-time');
        $interval = (int) $this->option('interval');
        $assetType = $this->option('asset-type');
        $days = (int) $this->option('days');
        $limit = (int) $this->option('limit');
        $exitTime = $this->option('exit-time');
        $minRvol = (float) $this->option('min-rvol');
        $minVol = (int) $this->option('min-vol');
        $clear = (bool) $this->option('clear');
        $showDetails = (bool) $this->option('show-details');

        if ($clear) {
            DB::table('buy_zone_alerts')->truncate();
            $this->info('✓ Cleared existing buy_zone_alerts');
            $this->newLine();
        }

        $this->info('🚀 Backtesting Buy Zone Strategy');
        $this->info("📅 Date Range: {$from} to {$to}");
        $this->info("⏰ Time Range: {$startTime} - {$endTime} EST (every {$interval} minutes)");
        $this->info('💼 Strategy: 1% initial stop → 2% trailing stop at +2% profit');
        $this->info("⏱️  Exit Strategy: Hold until {$exitTime} EST or stop hit");
        $this->info("🎯 Filters: RVOL >= {$minRvol}, {$days}d window");
        $this->newLine();

        $startDate = CarbonImmutable::parse($from, 'America/New_York');
        $endDate = CarbonImmutable::parse($to, 'America/New_York');

        // Get market schedules for the date range to skip holidays
        $marketSchedules = DB::table('market_schedules')
            ->where('market_type', 'stock')
            ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->whereIn('status', ['open', 'half_day'])
            ->pluck('date')
            ->map(fn ($date) => $date instanceof \DateTime ? $date->format('Y-m-d') : $date)
            ->toArray();

        $currentDate = $startDate;

        while ($currentDate->lte($endDate)) {
            $dateStr = $currentDate->format('Y-m-d');

            // Skip weekends and holidays (check market_schedules)
            if ($currentDate->isWeekend() || ! in_array($dateStr, $marketSchedules)) {
                $currentDate = $currentDate->addDay();

                continue;
            }

            $this->line("📊 Processing {$dateStr}...");

            $this->backtestDay(
                $dateStr,
                $startTime,
                $endTime,
                $interval,
                $assetType,
                $days,
                $limit,
                $exitTime,
                $minRvol,
                $minVol
            );

            $currentDate = $currentDate->addDay();
        }

        $this->newLine();
        $this->displayResults($showDetails);

        return 0;
    }

    private function backtestDay(
        string $date,
        string $startTime,
        string $endTime,
        int $interval,
        string $assetType,
        int $days,
        int $limit,
        string $exitTime,
        float $minRvol,
        int $minVol
    ): void {
        $startDateTime = CarbonImmutable::parse("{$date} {$startTime}:00", 'America/New_York');
        $endDateTime = CarbonImmutable::parse("{$date} {$endTime}:00", 'America/New_York');

        $currentTime = $startDateTime;

        while ($currentTime->lte($endDateTime)) {
            // Keep time in EST throughout - don't convert to UTC
            $currentTimeStr = $currentTime->format('Y-m-d H:i:s');

            // Get top performers
            $topPerformers = $this->bestPerformersService->getBestPerformers([
                'assetType' => $assetType,
                'testDateTime' => $currentTimeStr,
                'days' => $days,
                'minBars' => 200,
                'minVol' => $minVol,
                'rthOnly' => true,
                'limit' => 300,
                'tz' => 'America/New_York',
            ]);

            if (empty($topPerformers)) {
                $currentTime = $currentTime->addMinutes($interval);

                continue;
            }

            $symbols = array_column($topPerformers, 'symbol');

            // Filter to buy zone candidates
            $candidates = $this->buyZoneService->filterBuyZone($symbols, [
                'assetType' => $assetType,
                'days' => $days,
                'tz' => 'America/New_York',
                'testDateTime' => $currentTimeStr,
                'minRvol' => $minRvol,
            ]);

            // Take top N by score
            $candidates = array_slice($candidates, 0, $limit);

            foreach ($candidates as $candidate) {
                $this->processCandidate($candidate, $currentTimeStr, $exitTime);
            }

            $currentTime = $currentTime->addMinutes($interval);
        }
    }

    private function processCandidate(array $candidate, string $analysisTime, string $exitTime): void
    {
        $symbol = $candidate['symbol'];
        $entryPrice = $candidate['entry_price'];
        $stopPrice = $candidate['stop_price'];
        $riskPct = $candidate['risk_pct'];

        // Check if we already have an alert for this symbol near this time (prevent duplicates)
        $existing = DB::selectOne('
            SELECT id FROM buy_zone_alerts
            WHERE symbol = ?
              AND analysis_ts_est >= ?
              AND analysis_ts_est <= ?
        ', [$symbol, date('Y-m-d H:i:s', strtotime($analysisTime) - 1800), $analysisTime]);

        if ($existing) {
            return; // Skip duplicate
        }

        // Store alert
        $alertId = DB::table('buy_zone_alerts')->insertGetId([
            'asset_id' => $candidate['asset_id'],
            'symbol' => $symbol,
            'asset_type' => 'stock',
            'analysis_ts_est' => $analysisTime,
            'high_7d' => $candidate['high_7d'],
            'low_7d' => $candidate['low_7d'],
            'dist_from_7d_high_pct' => $candidate['dist_from_7d_high_pct'],
            'retracement_pct' => $candidate['retracement_pct'],
            'rvol' => $candidate['rvol'],
            'ema_state' => $candidate['ema_state'],
            'entry_price' => $entryPrice,
            'stop_price' => $stopPrice,
            'risk_per_share' => $candidate['risk_per_share'],
            'risk_pct' => $riskPct,
            'stop_viable_1pct' => $candidate['stop_viable_1pct'],
            'recommended_shares' => $candidate['recommended_shares'],
            'position_notional' => $candidate['position_notional'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->totalAlerts++;

        // Simulate trade
        $result = $this->simulateTrade($symbol, $entryPrice, $stopPrice, $analysisTime, $exitTime);

        $isViable = $candidate['stop_viable_1pct'];

        if ($result) {
            // Update alert with P&L
            DB::table('buy_zone_alerts')->where('id', $alertId)->update([
                'exit_price' => $result['exit_price'],
                'exit_ts_est' => $result['exit_time'],
                'exit_reason' => $result['exit_reason'],
                'pnl_percent' => $result['pnl_percent'],
                'pnl_dollars' => $result['pnl_dollars'],
                'risk_adjusted_return' => $result['risk_adjusted_return'],
                'is_winner' => $result['is_winner'],
                'highest_price' => $result['highest_price'],
                'mae_pct' => $result['mae_pct'],
                'updated_at' => now(),
            ]);

            if ($result['is_winner']) {
                $this->winners++;
                if ($isViable) {
                    $this->viableWinners++;
                } else {
                    $this->nonViableWinners++;
                }
            } else {
                $this->losers++;
                if ($isViable) {
                    $this->viableLosers++;
                } else {
                    $this->nonViableLosers++;
                }
            }

            $this->totalPnL += $result['pnl_percent'];
            $this->totalRiskAdjustedPnL += $result['risk_adjusted_return'];

            if ($isViable) {
                $this->viablePnL += $result['pnl_percent'];
            } else {
                $this->nonViablePnL += $result['pnl_percent'];
            }

            $this->tradeResults[] = $result;
        } else {
            // No bars found - mark as NO_DATA
            DB::table('buy_zone_alerts')->where('id', $alertId)->update([
                'exit_reason' => 'NO_DATA',
                'updated_at' => now(),
            ]);
        }
    }

    private function simulateTrade(
        string $symbol,
        float $entryPrice,
        float $initialStopPrice,
        string $entryTime,
        string $targetExitTime
    ): ?array {
        // Exit at target time (e.g., 15:55) or when stopped out
        $exitTimestamp = date('Y-m-d', strtotime($entryTime)).' '.$targetExitTime.':00';

        // Don't trade after 3:30 PM (not enough time)
        if (strtotime($entryTime) > strtotime(date('Y-m-d', strtotime($entryTime)).' 15:30:00')) {
            return null;
        }

        $bars = DB::select('
            SELECT ts_est, high, low, price as close
            FROM one_minute_prices
            WHERE asset_type = ? AND symbol = ?
              AND ts_est > ? AND ts_est <= ?
            ORDER BY ts_est ASC
        ', ['stock', $symbol, $entryTime, $exitTimestamp]);

        if (empty($bars)) {
            return null;
        }

        // Strategy: 1% initial stop → 2% trailing stop at +2% profit
        $currentStop = $initialStopPrice;
        $highestPrice = $entryPrice;
        $lowestPrice = $entryPrice;
        $exitPrice = null;
        $exitTs = null;
        $exitReason = null;

        foreach ($bars as $bar) {
            $high = (float) $bar->high;
            $low = (float) $bar->low;
            $close = (float) $bar->close;

            // Update highest price
            if ($high > $highestPrice) {
                $highestPrice = $high;

                $profitPct = (($highestPrice - $entryPrice) / $entryPrice) * 100;

                // Activate 2% trailing stop at +2% profit
                if ($profitPct >= 2.0) {
                    $newStop = $highestPrice * 0.98;
                    if ($newStop > $currentStop) {
                        $currentStop = $newStop;
                    }
                }
            }

            // Track lowest for MAE
            if ($low < $lowestPrice) {
                $lowestPrice = $low;
            }

            // Check if stopped out
            if ($low <= $currentStop) {
                $exitPrice = $currentStop;
                $exitTs = $bar->ts_est;
                $exitReason = 'STOP_LOSS';
                break;
            }
        }

        // If not stopped out, exit at last close
        if (! $exitPrice) {
            $lastBar = end($bars);
            $exitPrice = (float) $lastBar->close;
            $exitTs = $lastBar->ts_est;
            $exitReason = 'TIME_EXIT';
        }

        $pnlPercent = (($exitPrice - $entryPrice) / $entryPrice) * 100;
        $isWinner = $pnlPercent > 0;
        $riskPct = abs((($entryPrice - $initialStopPrice) / $entryPrice) * 100);
        $riskAdjustedReturn = $riskPct > 0 ? $pnlPercent / $riskPct : 0;
        $maePct = (($lowestPrice - $entryPrice) / $entryPrice) * 100;

        // Calculate P&L in dollars (assume 100 shares)
        $shares = 100;
        $pnlDollars = ($exitPrice - $entryPrice) * $shares;

        return [
            'symbol' => $symbol,
            'entry_price' => $entryPrice,
            'exit_price' => $exitPrice,
            'exit_time' => $exitTs,
            'exit_reason' => $exitReason,
            'pnl_percent' => $pnlPercent,
            'pnl_dollars' => $pnlDollars,
            'is_winner' => $isWinner,
            'risk_adjusted_return' => $riskAdjustedReturn,
            'highest_price' => $highestPrice,
            'mae_pct' => $maePct,
        ];
    }

    private function displayResults(bool $showDetails): void
    {
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('  BACKTEST RESULTS');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->newLine();

        $totalTrades = $this->winners + $this->losers;

        if ($totalTrades === 0) {
            $this->warn('No completed trades to analyze');

            return;
        }

        $winRate = ($this->winners / $totalTrades) * 100;
        $avgPnL = $this->totalPnL / $totalTrades;
        $avgRiskAdjusted = $this->totalRiskAdjustedPnL / $totalTrades;

        // Calculate average winner/loser
        $winnerPnLs = array_filter(array_column($this->tradeResults, 'pnl_percent'), fn ($p) => $p > 0);
        $loserPnLs = array_filter(array_column($this->tradeResults, 'pnl_percent'), fn ($p) => $p <= 0);

        $avgWinner = count($winnerPnLs) > 0 ? array_sum($winnerPnLs) / count($winnerPnLs) : 0;
        $avgLoser = count($loserPnLs) > 0 ? array_sum($loserPnLs) / count($loserPnLs) : 0;

        // Profit factor
        $grossProfit = array_sum($winnerPnLs);
        $grossLoss = abs(array_sum($loserPnLs));
        $profitFactor = $grossLoss > 0 ? $grossProfit / $grossLoss : 0;

        $this->line("📊 Total Alerts Generated: {$this->totalAlerts}");
        $this->line("✅ Total Trades: {$totalTrades}");
        $this->line("🏆 Winners: {$this->winners} (".number_format($winRate, 1).'%)');
        $this->line("❌ Losers: {$this->losers}");
        $this->newLine();

        $pnlColor = $this->totalPnL >= 0 ? 'info' : 'error';
        $this->{$pnlColor}('💰 Total P&L: '.number_format($this->totalPnL, 2).'%');
        $this->line('📈 Average P&L: '.number_format($avgPnL, 2).'%');
        $this->line('📊 Risk-Adjusted Return: '.number_format($avgRiskAdjusted, 2).'R');
        $this->newLine();

        $this->line('🎯 Average Winner: +'.number_format($avgWinner, 2).'%');
        $this->line('🎯 Average Loser: '.number_format($avgLoser, 2).'%');
        $this->line('⚖️  Profit Factor: '.number_format($profitFactor, 2));
        $this->newLine();

        // Compare to baseline (40% win rate, 0.5R)
        if ($winRate > 40 && $avgRiskAdjusted > 0.5) {
            $this->info('🎉 SUCCESS: Strategy beats 40% baseline!');
        } elseif ($winRate > 40) {
            $this->warn('⚠️  High win rate but low risk-adjusted return');
        } else {
            $this->error('❌ Below 40% baseline win rate');
        }

        // Viable vs Non-Viable Breakdown
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('  VIABLE vs NON-VIABLE BREAKDOWN');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->newLine();

        // Viable stats
        $viableTrades = $this->viableWinners + $this->viableLosers;
        if ($viableTrades > 0) {
            $viableWinRate = ($this->viableWinners / $viableTrades) * 100;
            $viableAvgPnL = $this->viablePnL / $viableTrades;

            $this->line('✅ VIABLE STOPS (1% stop viable):');
            $this->line("   Total Trades: {$viableTrades}");
            $this->line("   Winners: {$this->viableWinners} (".number_format($viableWinRate, 1).'%)');
            $this->line("   Losers: {$this->viableLosers}");
            $this->line('   Avg P&L: '.number_format($viableAvgPnL, 2).'%');
            $this->line('   Total P&L: '.number_format($this->viablePnL, 2).'%');
        } else {
            $this->line('✅ VIABLE STOPS: No trades');
        }

        $this->newLine();

        // Non-viable stats
        $nonViableTrades = $this->nonViableWinners + $this->nonViableLosers;
        if ($nonViableTrades > 0) {
            $nonViableWinRate = ($this->nonViableWinners / $nonViableTrades) * 100;
            $nonViableAvgPnL = $this->nonViablePnL / $nonViableTrades;

            $this->line('❌ NON-VIABLE STOPS (>1% risk):');
            $this->line("   Total Trades: {$nonViableTrades}");
            $this->line("   Winners: {$this->nonViableWinners} (".number_format($nonViableWinRate, 1).'%)');
            $this->line("   Losers: {$this->nonViableLosers}");
            $this->line('   Avg P&L: '.number_format($nonViableAvgPnL, 2).'%');
            $this->line('   Total P&L: '.number_format($this->nonViablePnL, 2).'%');
        } else {
            $this->line('❌ NON-VIABLE STOPS: No trades');
        }

        if ($showDetails && ! empty($this->tradeResults)) {
            $this->newLine();
            $this->line('═══ TRADE DETAILS ═══');
            $this->line('Showing all '.count($this->tradeResults).' trades:');
            $this->newLine();
            $this->table(
                ['Symbol', 'Entry', 'Exit', 'P&L %', 'R-Multiple', 'Exit Reason'],
                array_map(fn ($t) => [
                    $t['symbol'],
                    number_format($t['entry_price'], 2),
                    number_format($t['exit_price'], 2),
                    number_format($t['pnl_percent'], 2),
                    number_format($t['risk_adjusted_return'], 2).'R',
                    $t['exit_reason'],
                ], $this->tradeResults) // All trades
            );
        }
    }
}
