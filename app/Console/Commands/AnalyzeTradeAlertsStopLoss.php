<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnalyzeTradeAlertsStopLoss extends Command
{
    protected $signature = 'analyze:trade-alerts-stop-loss
        {--stop-loss=0.75 : Fixed percentage stop loss to use (e.g., 0.75 for 0.75%)}
        {--from-date= : Start date for analysis (YYYY-MM-DD)}
        {--to-date= : End date for analysis (YYYY-MM-DD)}
        {--ml-threshold= : Filter alerts by ML win probability threshold (e.g., 0.65)}
        {--show-details : Show detailed trade-by-trade results}
        {--write-results : Write analysis results back to trade_alerts table}
    ';

    protected $description = 'Analyze all trade alerts performance using fixed percentage TRAILING stop loss (moves up with price)';

    private array $tradeResults = [];

    private int $winners = 0;

    private int $losers = 0;

    private float $totalPnL = 0.0;

    private float $totalRiskAdjustedPnL = 0.0;

    public function handle(): int
    {
        $stopLossPct = (float) $this->option('stop-loss');
        $fromDate = $this->option('from-date');
        $toDate = $this->option('to-date');
        $mlThreshold = $this->option('ml-threshold');
        $showDetails = (bool) $this->option('show-details');
        $writeResults = (bool) $this->option('write-results');
        $this->info('📊 Analyzing All Trade Alerts with Fixed Stop Loss');
        $this->info("💼 Stop Loss: {$stopLossPct}%");

        if ($fromDate) {
            $this->info("📅 From Date: {$fromDate}");
        }
        if ($toDate) {
            $this->info("📅 To Date: {$toDate}");
        }
        if ($mlThreshold !== null) {
            $this->info("🤖 ML Threshold: >= {$mlThreshold}");
        }
        if ($writeResults) {
            $this->warn('⚠️  Write mode enabled - will update trade_alerts table with results');
        }

        $query = '
            SELECT
                symbol,
                entry_type,
                entry_ts_est,
                entry,
                stop,
                risk_pct,
                score,
                vol_ratio,
                atr,
                atr_pct,
                ml_win_prob,
                version,
                pipeline_run,
                created_at
            FROM trade_alerts
            WHERE entry_ts_est IS NOT NULL
              AND entry IS NOT NULL
        ';

        $params = [];

        if ($fromDate) {
            $query .= ' AND DATE(entry_ts_est) >= ?';
            $params[] = $fromDate;
        }

        if ($toDate) {
            $query .= ' AND DATE(entry_ts_est) <= ?';
            $params[] = $toDate;
        }

        if ($mlThreshold !== null) {
            $query .= ' AND ml_win_prob >= ?';
            $params[] = (float) $mlThreshold;
        }

        $query .= ' ORDER BY entry_ts_est ASC';

        $alerts = DB::select($query, $params);

        if (empty($alerts)) {
            $this->error('No alerts found matching criteria');

            return 1;
        }

        $this->line('Found '.count($alerts).' alerts to analyze...');
        $this->newLine();

        $progressBar = $this->output->createProgressBar(count($alerts));

        foreach ($alerts as $alert) {
            $result = $this->analyzeAlert($alert, $stopLossPct);
            if ($result) {
                $this->tradeResults[] = $result;

                if ($result['is_winner']) {
                    $this->winners++;
                } else {
                    $this->losers++;
                }

                $this->totalPnL += $result['pnl_percent'];
                $this->totalRiskAdjustedPnL += $result['risk_adjusted_return'];

                if ($writeResults) {
                    $this->updateAlertWithResults($alert, $result);
                }
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->displayResults($showDetails);

        return 0;
    }

    private function analyzeAlert(object $alert, float $stopLossPct): ?array
    {
        $symbol = (string) $alert->symbol;
        $entryPrice = (float) $alert->entry;
        $entryTime = (string) $alert->entry_ts_est;

        // Calculate initial stop price based on fixed percentage
        $initialStopPrice = $entryPrice * (1 - ($stopLossPct / 100));
        $currentStopPrice = $initialStopPrice;

        $riskPct = $stopLossPct;
        $score = (float) ($alert->score ?? 0);
        $volRatio = (float) ($alert->vol_ratio ?? 0);
        $atrPct = (float) ($alert->atr_pct ?? 0);
        $mlWinProb = (float) ($alert->ml_win_prob ?? 0);

        // Exit at end of day 15:59
        $marketClose = date('Y-m-d', strtotime($entryTime)).' 15:59:00';

        $bars = DB::select('
            SELECT ts_est, open, high, low, price as close
            FROM one_minute_prices
            WHERE asset_type = ? AND symbol = ?
              AND ts_est > ? AND ts_est <= ?
            ORDER BY ts_est ASC
        ', ['stock', $symbol, $entryTime, $marketClose]);

        if (empty($bars)) {
            return null;
        }

        $lowestPrice = $entryPrice;
        $highestPrice = $entryPrice;
        $exitPrice = null;
        $exitTime = null;
        $exitReason = null;

        foreach ($bars as $bar) {
            $high = (float) $bar->high;
            $low = (float) $bar->low;
            $open = (float) $bar->open;
            $close = (float) $bar->close;

            // Track lowest price reached for max adverse excursion
            if ($low < $lowestPrice) {
                $lowestPrice = $low;
            }

            // Track highest price reached
            if ($high > $highestPrice) {
                $highestPrice = $high;

                // Trail the stop up: keep same percentage distance from highest price
                $newStopPrice = $highestPrice * (1 - ($stopLossPct / 100));

                // Only move stop up, never down
                if ($newStopPrice > $currentStopPrice) {
                    $currentStopPrice = $newStopPrice;
                }
            }

            // Check if stopped out (using current trailing stop)
            if ($low <= $currentStopPrice) {
                // If opened below stop, you're filled at open (gap down slippage)
                // Otherwise, you're filled at stop price
                $exitPrice = ($open < $currentStopPrice) ? $open : $currentStopPrice;
                $exitTime = (string) $bar->ts_est;
                $exitReason = 'stop_loss';
                break;
            }
        }

        // If no stop hit, exit at market close
        if ($exitPrice === null) {
            $lastBar = end($bars);
            $exitPrice = (float) $lastBar->close;
            $exitTime = (string) $lastBar->ts_est;
            $exitReason = 'market_close';
        }

        $pnlDollar = $exitPrice - $entryPrice;
        $pnlPercent = ($entryPrice > 0) ? (($pnlDollar / $entryPrice) * 100) : 0.0;
        $isWinner = $pnlPercent > 0;

        $riskAdjustedReturn = $riskPct > 0 ? ($pnlPercent / $riskPct) : 0.0;

        // Calculate max adverse excursion (worst unrealized loss during trade)
        $maxAdverseExcursion = ($entryPrice > 0) ? (($lowestPrice - $entryPrice) / $entryPrice) * 100 : 0.0;

        // Calculate hold time in minutes
        $entryTimestamp = strtotime($entryTime);
        $exitTimestamp = strtotime($exitTime);
        $holdTimeMinutes = ($exitTimestamp - $entryTimestamp) / 60;

        return [
            'symbol' => $symbol,
            'entry_type' => (string) $alert->entry_type,
            'entry_price' => $entryPrice,
            'exit_price' => $exitPrice,
            'stop_price' => $currentStopPrice, // Final trailing stop price
            'initial_stop_price' => $initialStopPrice, // Original stop price at entry
            'highest_price' => $highestPrice, // Track how high price went
            'entry_time' => $entryTime,
            'exit_time' => $exitTime,
            'exit_reason' => $exitReason,
            'pnl_percent' => $pnlPercent,
            'pnl_dollar' => $pnlDollar,
            'risk_pct' => $riskPct,
            'risk_adjusted_return' => $riskAdjustedReturn,
            'is_winner' => $isWinner,
            'score' => $score,
            'vol_ratio' => $volRatio,
            'atr_pct' => $atrPct,
            'ml_win_prob' => $mlWinProb,
            'max_adverse_excursion' => $maxAdverseExcursion,
            'hold_time_minutes' => $holdTimeMinutes,
            'version' => (string) $alert->version,
            'pipeline_run' => (string) $alert->pipeline_run,
        ];
    }

    private function updateAlertWithResults(object $alert, array $result): void
    {
        DB::table('trade_alerts')
            ->where('symbol', $alert->symbol)
            ->where('entry_ts_est', $alert->entry_ts_est)
            ->where('version', $alert->version)
            ->where('pipeline_run', $alert->pipeline_run)
            ->update([
                'exit_price' => $result['exit_price'],
                'exit_ts_est' => $result['exit_time'],
                'pnl_percent' => $result['pnl_percent'],
                'pnl_dollar' => $result['pnl_dollar'],
                'exit_reason' => $result['exit_reason'],
                'updated_at' => now(),
            ]);
    }

    private function displayResults(bool $showDetails): void
    {
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('  ANALYSIS RESULTS');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->newLine();

        $totalTrades = count($this->tradeResults);
        $winRate = $totalTrades > 0 ? ($this->winners / $totalTrades) * 100 : 0;
        $avgPnL = $totalTrades > 0 ? $this->totalPnL / $totalTrades : 0;
        $avgRiskAdjusted = $totalTrades > 0 ? $this->totalRiskAdjustedPnL / $totalTrades : 0;

        $this->line("📊 Total Trades: {$totalTrades}");
        $this->line("✅ Winners: {$this->winners}");
        $this->line("❌ Losers: {$this->losers}");
        $this->line(sprintf('📈 Win Rate: %.1f%%', $winRate));
        $this->line(sprintf('💰 Average P&L: %.2f%%', $avgPnL));
        $this->line(sprintf('💰 Total P&L: %.2f%%', $this->totalPnL));
        $this->line(sprintf('⚖️  Avg Risk-Adjusted Return: %.2fR', $avgRiskAdjusted));
        $this->newLine();

        // Group by ML score ranges
        if ($showDetails) {
            $this->displayMLScoreBreakdown();
            $this->newLine();
            $this->displayPipelineBreakdown();
            $this->newLine();
            $this->displayDetailedTrades();
        }
    }

    private function displayMLScoreBreakdown(): void
    {
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('  ML SCORE BREAKDOWN');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->newLine();

        $ranges = [
            '< 60%' => [0, 0.60],
            '60-65%' => [0.60, 0.65],
            '65-70%' => [0.65, 0.70],
            '70-75%' => [0.70, 0.75],
            '75%+' => [0.75, 1.00],
        ];

        foreach ($ranges as $label => [$min, $max]) {
            $trades = array_filter($this->tradeResults, function ($trade) use ($min, $max) {
                $prob = $trade['ml_win_prob'];

                return $prob >= $min && $prob < $max;
            });

            $count = count($trades);
            if ($count === 0) {
                continue;
            }

            $winners = count(array_filter($trades, fn ($t) => $t['is_winner']));
            $winRate = ($count > 0) ? ($winners / $count) * 100 : 0;
            $avgPnL = array_sum(array_column($trades, 'pnl_percent')) / $count;

            $this->line(sprintf(
                '%s: %d trades, %.1f%% WR, %.2f%% avg P&L',
                str_pad($label, 10),
                $count,
                $winRate,
                $avgPnL
            ));
        }
    }

    private function displayPipelineBreakdown(): void
    {
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('  PIPELINE BREAKDOWN');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->newLine();

        $byPipeline = [];
        foreach ($this->tradeResults as $trade) {
            $pipeline = $trade['pipeline_run'] ?: 'Unknown';
            if (! isset($byPipeline[$pipeline])) {
                $byPipeline[$pipeline] = [];
            }
            $byPipeline[$pipeline][] = $trade;
        }

        ksort($byPipeline);

        foreach ($byPipeline as $pipeline => $trades) {
            $count = count($trades);
            $winners = count(array_filter($trades, fn ($t) => $t['is_winner']));
            $winRate = ($count > 0) ? ($winners / $count) * 100 : 0;
            $avgPnL = array_sum(array_column($trades, 'pnl_percent')) / $count;

            $this->line(sprintf(
                'Pipeline %s: %d trades, %.1f%% WR, %.2f%% avg P&L',
                str_pad($pipeline, 2),
                $count,
                $winRate,
                $avgPnL
            ));
        }
    }

    private function displayDetailedTrades(): void
    {
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('  DETAILED TRADE RESULTS');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->newLine();

        foreach ($this->tradeResults as $trade) {
            $result = $trade['is_winner'] ? '✅' : '❌';
            $this->line(sprintf(
                '%s %s | Entry: $%.2f → Exit: $%.2f | P&L: %.2f%% | ML: %.1f%% | Pipeline: %s | %s',
                $result,
                str_pad($trade['symbol'], 6),
                $trade['entry_price'],
                $trade['exit_price'],
                $trade['pnl_percent'],
                $trade['ml_win_prob'] * 100,
                $trade['pipeline_run'],
                $trade['exit_reason']
            ));
        }
    }
}
