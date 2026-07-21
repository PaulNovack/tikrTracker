<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnalyzeTradeAlertsV14TimeExit extends Command
{
    protected $signature = 'analyze:trade-alerts-v14-time-exit
        {--algo-version=v13.0 : Algorithm version to analyze}
        {--atr-multiplier=3.0 : ATR multiplier for stop distance (e.g., 2.5, 3.0)}
        {--time-threshold=30 : Minutes after entry to check profit threshold}
        {--profit-threshold=0.5 : Minimum profit % required after time threshold}
        {--show-details : Show detailed trade-by-trade results}
        {--min-atr-pct= : Minimum ATR percentage threshold}
        {--max-atr-pct= : Maximum ATR percentage threshold}
    ';

    protected $description = 'Analyze V14.0 strategy: 3.0x ATR trailing stop + time-based exit (exit if profit < threshold after X minutes)';

    private array $tradeResults = [];

    private int $winners = 0;

    private int $losers = 0;

    private float $totalPnL = 0.0;

    private float $totalRiskAdjustedPnL = 0.0;

    public function handle(): int
    {
        $algoVersion = $this->option('algo-version');
        $atrMultiplier = (float) $this->option('atr-multiplier');
        $timeThreshold = (int) $this->option('time-threshold');
        $profitThreshold = (float) $this->option('profit-threshold');
        $showDetails = (bool) $this->option('show-details');
        $minAtrPct = $this->option('min-atr-pct');
        $maxAtrPct = $this->option('max-atr-pct');

        $this->info('📊 Analyzing V14.0 Strategy: ATR Trailing Stop + Time-Based Exit');
        $this->info("🔢 Version: {$algoVersion}");
        $this->info("💼 Strategy: {$atrMultiplier}x ATR trailing stop + time-based exit");
        $this->info("⏱️  Time Exit Rule: If profit < {$profitThreshold}% after {$timeThreshold} minutes, close position");

        if ($minAtrPct !== null) {
            $this->info("📉 Min ATR%: {$minAtrPct}%");
        }
        if ($maxAtrPct !== null) {
            $this->info("📈 Max ATR%: {$maxAtrPct}%");
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
                atr,
                atr_pct,
                suggested_trailing_stop,
                suggested_trailing_stop_pct,
                targets,
                version,
                created_at
            FROM trade_alerts
            WHERE version = ?
              AND atr IS NOT NULL
              AND suggested_trailing_stop_pct IS NOT NULL
        ';

        $params = [$algoVersion];

        if ($minAtrPct !== null) {
            $query .= ' AND atr_pct >= ?';
            $params[] = (float) $minAtrPct;
        }

        if ($maxAtrPct !== null) {
            $query .= ' AND atr_pct <= ?';
            $params[] = (float) $maxAtrPct;
        }

        $query .= ' ORDER BY entry_ts_est ASC';

        $alerts = DB::select($query, $params);

        if (empty($alerts)) {
            $this->error("No alerts found for version: {$algoVersion}");

            return 1;
        }

        $this->line('Found '.count($alerts).' alerts with ATR data to analyze...');
        $this->newLine();

        $progressBar = $this->output->createProgressBar(count($alerts));

        foreach ($alerts as $alert) {
            $result = $this->analyzeAlert($alert, $atrMultiplier, $timeThreshold, $profitThreshold);
            if ($result) {
                $this->tradeResults[] = $result;

                if ($result['is_winner']) {
                    $this->winners++;
                } else {
                    $this->losers++;
                }

                $this->totalPnL += $result['pnl_percent'];
                $this->totalRiskAdjustedPnL += $result['risk_adjusted_return'];
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->displayResults($showDetails);

        return 0;
    }

    private function analyzeAlert(object $alert, float $atrMultiplier = 3.0, int $timeThreshold = 30, float $profitThreshold = 0.5): ?array
    {
        $symbol = (string) $alert->symbol;
        $entryPrice = (float) $alert->entry;
        $initialStopPrice = (float) $alert->stop;
        $entryTime = (string) $alert->entry_ts_est;

        // Calculate ATR-based trailing stop using custom multiplier
        $atrPct = (float) $alert->atr_pct;
        $atrTrailingStopPct = $atrPct * $atrMultiplier;

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

        // V14.0: Calculate time threshold timestamp
        $timeThresholdTimestamp = strtotime($entryTime) + ($timeThreshold * 60);

        // Start with ATR-based stop immediately (no initial stop)
        $highestPrice = $entryPrice;
        $currentStop = $entryPrice * (1 - ($atrTrailingStopPct / 100));
        $exitPrice = null;
        $exitTime = null;
        $exitReason = null;

        foreach ($bars as $bar) {
            $high = (float) $bar->high;
            $low = (float) $bar->low;
            $close = (float) $bar->close;
            $barTime = (string) $bar->ts_est;
            $barTimestamp = strtotime($barTime);

            // V14.0: Time-based exit check - if past time threshold and profit below minimum, exit
            if ($barTimestamp >= $timeThresholdTimestamp) {
                $currentProfit = (($high - $entryPrice) / $entryPrice) * 100;
                if ($currentProfit < $profitThreshold) {
                    $exitPrice = $close;
                    $exitTime = $barTime;
                    $exitReason = 'time_exit';
                    break;
                }
            }

            // Stop check - use bar low for pessimistic fill
            if ($low <= $currentStop) {
                $exitPrice = $currentStop;
                $exitTime = $barTime;
                $exitReason = 'atr_stop';
                break;
            }

            // Update highest price
            if ($high > $highestPrice) {
                $highestPrice = $high;

                // Update trailing stop using ATR percentage
                $newTrailingStop = $highestPrice * (1 - ($atrTrailingStopPct / 100));

                // Only move stop up, never down
                if ($newTrailingStop > $currentStop) {
                    $currentStop = $newTrailingStop;
                }
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

        $wasStoppedOut = ($exitReason === 'atr_stop');

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
            'atr_pct' => $atrPct,
            'atr_trailing_stop_pct' => $atrTrailingStopPct,
            'target_hit' => $targetHit,
            'is_winner' => $isWinner,
            'was_stopped_out' => $wasStoppedOut,
        ];
    }

    private function displayResults(bool $showDetails): void
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

        $grossProfit = ! empty($winningTrades)
            ? array_sum(array_column($winningTrades, 'pnl_percent'))
            : 0.0;

        $grossLoss = ! empty($losingTrades)
            ? abs(array_sum(array_column($losingTrades, 'pnl_percent')))
            : 0.0;

        $profitFactor = $grossLoss > 0 ? ($grossProfit / $grossLoss) : 0.0;

        $targetHits = array_count_values(array_column($this->tradeResults, 'target_hit'));
        $stopOuts = (int) array_sum(array_map(fn ($t) => $t['was_stopped_out'] ? 1 : 0, $this->tradeResults));

        $avgAtrPct = $totalTrades > 0
            ? array_sum(array_column($this->tradeResults, 'atr_pct')) / $totalTrades
            : 0.0;

        $this->info('🎯 V14.0 PERFORMANCE: ATR TRAILING STOP + TIME-BASED EXIT');
        $this->line(str_repeat('═', 60));

        $this->table(
            ['Metric', 'Value', 'Status'],
            [
                ['Total Trades', $totalTrades, '📊'],
                ['Winners', $this->winners, '✅'],
                ['Losers', $this->losers, '❌'],
                ['Win Rate', number_format($winRate, 1).'%', $winRate >= 50 ? '✅ GOOD' : ($winRate >= 40 ? '⚠️ OK' : '❌ POOR')],
                ['Average P&L', number_format($avgPnL, 2).'%', $avgPnL > 0 ? '✅ POSITIVE' : '❌ NEGATIVE'],
                ['Total P&L', number_format($this->totalPnL, 2).'%', $this->totalPnL > 0 ? '✅ PROFITABLE' : '❌ UNPROFITABLE'],
                ['Average Winning Trade', number_format($avgWinningTrade, 2).'%', '📈'],
                ['Average Losing Trade', number_format($avgLosingTrade, 2).'%', '📉'],
                ['Gross Profit (sum winners)', number_format($grossProfit, 2).'%', '📈'],
                ['Gross Loss (sum losers)', '-'.number_format($grossLoss, 2).'%', '📉'],
                ['Profit Factor', number_format($profitFactor, 2), $profitFactor > 1.5 ? '✅ EXCELLENT' : ($profitFactor > 1.0 ? '⚠️ BORDERLINE' : '❌ < 1.0')],
                ['Risk-Adjusted Return', number_format($avgRiskAdjustedReturn, 2).'R', $avgRiskAdjustedReturn > 0.5 ? '✅ GOOD' : '⚠️ WEAK'],
                ['Average ATR%', number_format($avgAtrPct, 2).'%', '📊'],
                ['ATR Stop Outs', $stopOuts.' ('.($totalTrades > 0 ? number_format(($stopOuts / $totalTrades) * 100, 1) : '0.0').'%)', '🛑'],
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

        if ($showDetails) {
            $this->showDetailedResults();
        }

        $this->newLine();
        if ($winRate >= 55 && $profitFactor > 1.5) {
            $this->info('🏆 EXCELLENT: ATR trailing stops performing very well!');
        } elseif ($winRate >= 45 && $profitFactor > 1.0) {
            $this->info('✅ GOOD: ATR trailing stops are profitable!');
        } elseif ($winRate >= 40) {
            $this->comment('⚠️ BORDERLINE: ATR stops need refinement');
        } else {
            $this->error('❌ POOR: ATR trailing stops underperforming');
        }
    }

    private function showDetailedResults(): void
    {
        $this->newLine();
        $this->info('🔍 DETAILED TRADE RESULTS (V14.0: ATR + Time Exit):');

        usort($this->tradeResults, fn ($a, $b) => $b['pnl_percent'] <=> $a['pnl_percent']);

        $this->table(
            ['Date/Time', 'Symbol', 'Type', 'Score', 'Vol', 'Entry', 'Exit', 'P&L%', 'R-Mult', 'ATR%', 'Trail%', 'Target', 'Result'],
            array_map(fn ($trade) => [
                substr((string) $trade['entry_time'], 0, 16),
                $trade['symbol'],
                str_replace('_1M', '', (string) $trade['entry_type']),
                number_format((float) $trade['score'], 1),
                number_format((float) $trade['vol_ratio'], 1).'x',
                number_format((float) $trade['entry_price'], 2),
                number_format((float) $trade['exit_price'], 2),
                (($trade['pnl_percent'] >= 0 ? '+' : '').number_format((float) $trade['pnl_percent'], 2).'%'),
                number_format((float) $trade['risk_adjusted_return'], 1).'R',
                number_format((float) $trade['atr_pct'], 2).'%',
                number_format((float) $trade['atr_trailing_stop_pct'], 2).'%',
                (string) $trade['target_hit'],
                $trade['is_winner']
                    ? '✅ WIN'
                    : ($trade['exit_reason'] === 'time_exit' ? '⏱️ TIME-EXIT' : ($trade['was_stopped_out'] ? '🛑 ATR-STOP' : '❌ EOD')),
            ], $this->tradeResults)
        );
    }
}
