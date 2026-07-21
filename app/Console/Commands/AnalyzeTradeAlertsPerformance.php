<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnalyzeTradeAlertsPerformance extends Command
{
    protected $signature = 'analyze:trade-alerts-performance
        {--signal-type=MOMO_5M : Signal type to analyze}
        {--entry-type= : Entry type to filter (VWAP_RECLAIM_1M, PIVOT_HIGH_BREAK, or leave blank for both)}
        {--min-score= : Minimum score threshold (e.g., 4.0, 5.0)}
        {--min-vol= : Minimum volume ratio threshold (e.g., 2.2, 3.0)}
        {--no-target=* : Exclude specific targets (e.g., none, 1R, 2R, 3R)}
        {--show-details : Show detailed trade-by-trade results}
        {--compare-baseline : Compare with baseline 40% win rate}
        {--algo-version= : Filter by algorithm version (v1, v2, etc.)}
    ';

    protected $description = 'Analyze actual P&L performance of generated trade alerts';

    private array $tradeResults = [];

    private int $winners = 0;

    private int $losers = 0;

    private float $totalPnL = 0.0;

    private float $totalRiskAdjustedPnL = 0.0;

    public function handle(): int
    {
        $signalType = (string) $this->option('signal-type');
        $entryType = $this->option('entry-type');
        $minScore = $this->option('min-score');
        $minVol = $this->option('min-vol');
        $noTarget = $this->option('no-target');
        $showDetails = (bool) $this->option('show-details');
        $compareBaseline = (bool) $this->option('compare-baseline');
        $algoVersion = $this->option('algo-version');

        $this->info('📊 Analyzing Trade Alerts Performance');
        $this->info("🎯 Signal Type: {$signalType}");
        if ($entryType) {
            $this->info("🎯 Entry Type: {$entryType}");
        }
        $this->info('💼 Strategy: 1% initial stop → 2% trailing stop at +2% profit');
        if ($minScore !== null && $minScore !== '') {
            $this->info("📈 Min Score: {$minScore}");
        }
        if ($minVol !== null && $minVol !== '') {
            $this->info("📊 Min Volume: {$minVol}x");
        }
        if (! empty($noTarget)) {
            $this->info('🚫 Excluding Targets: '.implode(', ', $noTarget));
        }
        if ($algoVersion) {
            $this->info("🔢 Version: {$algoVersion}");
        }
        $this->newLine();

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
                targets,
                version,
                created_at
            FROM trade_alerts
            WHERE signal_type = ?
        ';

        $params = [$signalType];

        if ($entryType) {
            $query .= ' AND entry_type = ?';
            $params[] = (string) $entryType;
        }

        if ($minScore !== null && $minScore !== '') {
            $query .= ' AND score >= ?';
            $params[] = (float) $minScore;
        }

        if ($minVol !== null && $minVol !== '') {
            $query .= ' AND vol_ratio >= ?';
            $params[] = (float) $minVol;
        }

        if ($algoVersion) {
            $query .= ' AND version = ?';
            $params[] = (string) $algoVersion;
        }

        $query .= ' ORDER BY entry_ts_est ASC';

        $alerts = DB::select($query, $params);

        if (empty($alerts)) {
            $this->error("No alerts found for signal type: {$signalType}");

            return 1;
        }

        $this->line('Found '.count($alerts).' alerts to analyze...');
        $this->newLine();

        $progressBar = $this->output->createProgressBar(count($alerts));

        foreach ($alerts as $alert) {
            $result = $this->analyzeAlert($alert);
            if ($result) {
                // Filter out trades based on --no-target option
                if (! empty($noTarget) && in_array($result['target_hit'], $noTarget)) {
                    $progressBar->advance();

                    continue;
                }

                $this->tradeResults[] = $result;

                if ($result['is_winner']) {
                    $this->winners++;
                } else {
                    $this->losers++;
                }

                $this->totalPnL += $result['pnl_percent'];
                $this->totalRiskAdjustedPnL += $result['risk_adjusted_return'];

                $this->updateAlertWithResults($alert, $result);
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Display results
        $this->displayResults($showDetails, $compareBaseline);

        return 0;
    }

    private function analyzeAlert(object $alert): ?array
    {
        $symbol = (string) $alert->symbol;
        $entryPrice = (float) $alert->entry;
        $initialStopPrice = (float) $alert->stop;
        $entryTime = (string) $alert->entry_ts_est;

        // Calculate risk_pct from entry and stop if not stored in DB
        $riskPct = (float) ($alert->risk_pct ?? 0);
        if ($riskPct <= 0 && $initialStopPrice > 0 && $entryPrice > 0) {
            $riskPct = abs((($entryPrice - $initialStopPrice) / $entryPrice) * 100);
        }

        $score = (float) ($alert->score ?? 0);
        $volRatio = (float) ($alert->vol_ratio ?? 0);

        // Exit at end of day 15:59
        $marketClose = date('Y-m-d', strtotime($entryTime)).' 15:59:00';

        $bars = DB::select('
            SELECT ts_est, high, low, price as close
            FROM one_minute_prices
            WHERE asset_type = ? AND symbol = ?
              AND ts_est > ? AND ts_est <= ?
            ORDER BY ts_est ASC
        ', ['stock', $symbol, $entryTime, $marketClose]);

        if (empty($bars)) {
            return null;
        }

        $currentStop = $initialStopPrice;
        $highestPrice = $entryPrice;
        $trailingStopActive = false;
        $exitPrice = null;
        $exitTime = null;
        $exitReason = null;

        foreach ($bars as $bar) {
            $high = (float) $bar->high;
            $low = (float) $bar->low;

            // Stop check (uses bar low => pessimistic fill, consistent)
            if ($low <= $currentStop) {
                $exitPrice = $currentStop;
                $exitTime = (string) $bar->ts_est;

                if ($trailingStopActive) {
                    $exitReason = 'trailing_stop';
                } else {
                    $exitReason = 'initial_stop';
                }

                break;
            }

            // Update highest price
            if ($high > $highestPrice) {
                $highestPrice = $high;
            }

            // Profit based on highest excursion
            $profitPct = (($highestPrice - $entryPrice) / $entryPrice) * 100;

            // Activate 2% trailing stop at +2% profit
            if ($profitPct >= 2.0) {
                $trailingStopActive = true;
                $newTrailingStop = $highestPrice * 0.98;
                if ($newTrailingStop > $currentStop) {
                    $currentStop = $newTrailingStop;
                }
            }
        }

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

        $targets = json_decode((string) ($alert->targets ?? ''), true);
        $targetHit = 'none';
        if (is_array($targets) && $isWinner) {
            if (isset($targets['3R']) && $exitPrice >= (float) $targets['3R']) {
                $targetHit = '3R';
            } elseif (isset($targets['2R']) && $exitPrice >= (float) $targets['2R']) {
                $targetHit = '2R';
            } elseif (isset($targets['1R']) && $exitPrice >= (float) $targets['1R']) {
                $targetHit = '1R';
            }
        }

        // Count ALL stop-based exits (no more break_even_stop)
        $wasStoppedOut = in_array($exitReason, ['initial_stop', 'trailing_stop'], true);

        return [
            'symbol' => $symbol,
            'entry_type' => (string) $alert->entry_type,
            'entry_price' => $entryPrice,
            'exit_price' => $exitPrice,
            'entry_time' => $entryTime,
            'exit_time' => $exitTime,
            'exit_reason' => $exitReason,
            'pnl_percent' => $pnlPercent,
            'pnl_dollar' => $pnlDollar,
            'risk_pct' => $riskPct,
            'risk_adjusted_return' => $riskAdjustedReturn,
            'score' => $score,
            'vol_ratio' => $volRatio,
            'target_hit' => $targetHit,
            'is_winner' => $isWinner,
            'was_stopped_out' => $wasStoppedOut,
            'trailing_stop_activated' => $trailingStopActive,
        ];
    }

    private function displayResults(bool $showDetails, bool $compareBaseline): void
    {
        $totalTrades = count($this->tradeResults);
        $winRate = $totalTrades > 0 ? ($this->winners / $totalTrades) * 100 : 0.0;
        $avgPnL = $totalTrades > 0 ? $this->totalPnL / $totalTrades : 0.0;
        $avgRiskAdjustedReturn = $totalTrades > 0 ? $this->totalRiskAdjustedPnL / $totalTrades : 0.0;

        $winningTrades = array_filter($this->tradeResults, fn ($t) => (bool) $t['is_winner']);
        $losingTrades = array_filter($this->tradeResults, fn ($t) => ! (bool) $t['is_winner']);

        $avgWinningTrade = ! empty($winningTrades)
            ? array_sum(array_column($winningTrades, 'pnl_percent')) / count($winningTrades)
            : 0.0;

        $avgLosingTrade = ! empty($losingTrades)
            ? array_sum(array_column($losingTrades, 'pnl_percent')) / count($losingTrades)
            : 0.0;

        // ✅ Correct Profit Factor (sum of winners / abs(sum of losers))
        $grossProfit = ! empty($winningTrades)
            ? array_sum(array_column($winningTrades, 'pnl_percent'))
            : 0.0;

        $grossLoss = ! empty($losingTrades)
            ? abs(array_sum(array_column($losingTrades, 'pnl_percent')))
            : 0.0;

        $profitFactor = $grossLoss > 0 ? ($grossProfit / $grossLoss) : 0.0;

        $targetHits = array_count_values(array_column($this->tradeResults, 'target_hit'));
        $stopOuts = (int) array_sum(array_map(fn ($t) => $t['was_stopped_out'] ? 1 : 0, $this->tradeResults));

        $this->info('🎯 OPTIMIZED SYSTEM PERFORMANCE RESULTS');
        $this->line(str_repeat('═', 60));

        $this->table(
            ['Metric', 'Value', 'Status'],
            [
                ['Total Trades', $totalTrades, '📊'],
                ['Winners', $this->winners, '✅'],
                ['Losers', $this->losers, '❌'],
                ['Win Rate', number_format($winRate, 1).'%', $winRate >= 55 ? '🎉 EXCELLENT' : ($winRate >= 45 ? '👍 IMPROVED' : '⚠️ NEEDS WORK')],
                ['Average P&L', number_format($avgPnL, 2).'%', $avgPnL > 0 ? '✅ POSITIVE' : '❌ NEGATIVE'],
                ['Total P&L', number_format($this->totalPnL, 2).'%', $this->totalPnL > 0 ? '✅ PROFITABLE' : '❌ UNPROFITABLE'],
                ['Average Winning Trade', number_format($avgWinningTrade, 2).'%', '📈'],
                ['Average Losing Trade', number_format($avgLosingTrade, 2).'%', '📉'],
                ['Gross Profit (sum winners)', number_format($grossProfit, 2).'%', '📈'],
                ['Gross Loss (sum losers)', '-'.number_format($grossLoss, 2).'%', '📉'],
                ['Profit Factor', number_format($profitFactor, 2), $profitFactor > 1.5 ? '✅ GOOD' : ($profitFactor > 1.0 ? '⚠️ BORDERLINE' : '❌ < 1.0')],
                ['Risk-Adjusted Return', number_format($avgRiskAdjustedReturn, 2), $avgRiskAdjustedReturn > 0.5 ? '✅ GOOD' : '⚠️ WEAK'],
                ['Stop Outs', $stopOuts.' ('.($totalTrades > 0 ? number_format(($stopOuts / $totalTrades) * 100, 1) : '0.0').'%)', '🛑'],
            ]
        );

        if (! empty($targetHits)) {
            $this->newLine();
            $this->info('🎯 Target Achievement:');
            foreach (['3R', '2R', '1R', 'none'] as $target) {
                $count = $targetHits[$target] ?? 0;
                $pct = $totalTrades > 0 ? ($count / $totalTrades) * 100 : 0;
                $this->line("   {$target}: {$count} trades (".number_format($pct, 1).'%)');
            }
        }

        if ($compareBaseline) {
            $this->newLine();
            $this->info('📈 COMPARISON WITH BASELINE (40% Win Rate, -0.52% Avg Return):');
            $this->table(
                ['Metric', 'Baseline', 'Optimized', 'Improvement'],
                [
                    ['Win Rate', '40.0%', number_format($winRate, 1).'%', ($winRate >= 40 ? '+' : '').number_format($winRate - 40, 1).'%'],
                    ['Avg Return', '-0.52%', number_format($avgPnL, 2).'%', ($avgPnL >= -0.52 ? '+' : '').number_format($avgPnL + 0.52, 2).'%'],
                    ['Total Return', 'Negative', number_format($this->totalPnL, 2).'%', $this->totalPnL > 0 ? '✅ POSITIVE' : '❌ STILL NEGATIVE'],
                ]
            );
        }

        if ($showDetails) {
            $this->showDetailedResults();
        }

        $this->newLine();
        if ($winRate >= 60 && $avgPnL > 0.5) {
            $this->info('🏆 OUTSTANDING: System significantly outperforms baseline!');
        } elseif ($winRate >= 50 && $avgPnL > 0) {
            $this->info('🎉 SUCCESS: Major improvement over 40% baseline!');
        } elseif ($winRate > 40) {
            $this->comment('👍 PROGRESS: Win rate improved but needs refinement');
        } else {
            $this->error('⚠️ UNDERPERFORM: Still below baseline, needs more optimization');
        }
    }

    private function showDetailedResults(): void
    {
        $this->newLine();
        $this->info('🔍 DETAILED TRADE RESULTS:');

        usort($this->tradeResults, fn ($a, $b) => $b['pnl_percent'] <=> $a['pnl_percent']);

        $this->table(
            ['Date/Time', 'Symbol', 'Type', 'Entry', 'Exit', 'P&L%', 'Risk%', 'R-Multiple', 'Score', 'Vol', 'Target', 'Result'],
            array_map(fn ($trade) => [
                substr((string) $trade['entry_time'], 0, 16), // Show YYYY-MM-DD HH:MM
                $trade['symbol'],
                str_replace('_1M', '', (string) $trade['entry_type']),
                number_format((float) $trade['entry_price'], 2),
                number_format((float) $trade['exit_price'], 2),
                (($trade['pnl_percent'] >= 0 ? '+' : '').number_format((float) $trade['pnl_percent'], 2).'%'),
                number_format((float) $trade['risk_pct'], 2).'%',
                number_format((float) $trade['risk_adjusted_return'], 1).'R',
                number_format((float) $trade['score'], 1),
                number_format((float) $trade['vol_ratio'], 2).'x',
                (string) $trade['target_hit'],
                $trade['is_winner']
                    ? ($trade['was_stopped_out'] ? '⚠️ WIN' : '✅ WIN')
                    : ($trade['was_stopped_out'] ? '🛑 STOP' : '❌ LOSS'),
            ], $this->tradeResults)
        );
    }

    private function updateAlertWithResults(object $alert, array $result): void
    {
        DB::table('trade_alerts')
            ->where('symbol', $alert->symbol)
            ->where('entry_ts_est', $alert->entry_ts_est)
            ->where('signal_type', 'MOMO_5M')
            ->where('version', $alert->version)
            ->update([
                'exit_price' => $result['exit_price'],
                'exit_ts_est' => $result['exit_time'],
                'exit_reason' => $result['exit_reason'],
                'pnl_percent' => round($result['pnl_percent'], 2),
                'pnl_dollar' => round($result['pnl_dollar'], 4),
                'r_multiple' => round($result['risk_adjusted_return'], 2),
                'target_hit' => $result['target_hit'],
                'analyzed' => true,
                'analyzed_at' => now(),
            ]);
    }
}
