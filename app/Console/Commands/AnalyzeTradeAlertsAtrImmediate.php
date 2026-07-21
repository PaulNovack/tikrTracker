<?php

namespace App\Console\Commands;

use App\Services\TradingSettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnalyzeTradeAlertsAtrImmediate extends Command
{
    protected $signature = 'analyze:trade-alerts-atr-immediate
        {--algo-version=v12.4 : Algorithm version to analyze}
        {--pipeline= : Pipeline run to filter (A or B)}
        {--atr-multiplier= : ATR multiplier for stop distance (uses config default 4.0x if not specified)}
        {--fixed-stop-pct= : Use fixed percentage stop instead of ATR (e.g., 1.0 for 1%)}
        {--show-details : Show detailed trade-by-trade results}
        {--min-atr-pct= : Minimum ATR percentage threshold}
        {--max-atr-pct= : Maximum ATR percentage threshold}
        {--write-results : Write analysis results back to trade_alerts table}
        {--only-unanalyzed : Skip alerts that already have exit_price set}
        {--use-full-tables : Use one_minute_prices_full for price lookup}
    ';

    protected $description = 'Analyze trade alerts performance using ATR stops IMMEDIATELY from entry (no profit requirement)';

    private array $tradeResults = [];

    private int $winners = 0;

    private int $losers = 0;

    private float $totalPnL = 0.0;

    private float $totalRiskAdjustedPnL = 0.0;

    public function handle(): int
    {
        // Set unlimited execution time for large datasets
        set_time_limit(0);
        ini_set('max_execution_time', '0');

        $algoVersion = $this->option('algo-version');
        $pipeline = $this->option('pipeline');
        // Read from DB-backed settings if not specified (falls back to config defaults)
        $atrMultiplier = $this->option('atr-multiplier')
            ? (float) $this->option('atr-multiplier')
            : (float) TradingSettingService::getStopLossAtrMultiplier();
        $fixedStopPct = $this->option('fixed-stop-pct');
        $showDetails = (bool) $this->option('show-details');
        $minAtrPct = $this->option('min-atr-pct');
        $maxAtrPct = $this->option('max-atr-pct');
        $useFullTables = (bool) $this->option('use-full-tables');
        $writeResults = (bool) $this->option('write-results');
        $onlyUnanalyzed = (bool) $this->option('only-unanalyzed');
        $oneMinuteTable = $useFullTables ? 'one_minute_prices_full' : 'one_minute_prices';

        // Determine which table to query based on pipeline config
        $tableName = 'trade_alerts';
        if ($pipeline) {
            $pipelineLower = strtolower($pipeline);
            $noFilterConfig = config("trading.alert_{$pipelineLower}_no_filter_finder", false);
            $tableName = $noFilterConfig ? 'trade_alerts_unfiltered' : 'trade_alerts';
            $this->line("📊 Using table: {$tableName} (NO_FILTER_FINDER=".($noFilterConfig ? 'true' : 'false').')');
        }

        $this->info('📊 Analyzing Trade Alerts with IMMEDIATE ATR-Based Stops');
        $this->info("🔢 Version: {$algoVersion}");
        if ($pipeline) {
            $this->info("🔀 Pipeline: {$pipeline}");
        }
        $this->line("📈 Price Table: {$oneMinuteTable}");

        if ($fixedStopPct) {
            $this->info("💼 Strategy: {$fixedStopPct}% FIXED trailing stop ACTIVE FROM ENTRY (no profit requirement)");
        } else {
            $this->info("💼 Strategy: {$atrMultiplier}x ATR trailing stop ACTIVE FROM ENTRY (no profit requirement)");
        }

        if ($writeResults) {
            $this->info('💾 Write Results: YES - Results will be written to database');
        }

        if ($minAtrPct !== null) {
            $this->info("📉 Min ATR%: {$minAtrPct}%");
        }
        if ($maxAtrPct !== null) {
            $this->info("📈 Max ATR%: {$maxAtrPct}%");
        }
        $this->newLine();

        $query = "
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
                pipeline_run,
                created_at
            FROM {$tableName}
            WHERE version = ?
        ";

        $params = [$algoVersion];

        if ($pipeline) {
            $query .= ' AND pipeline_run = ?';
            $params[] = $pipeline;
        }

        if ($minAtrPct !== null) {
            $query .= ' AND atr_pct >= ?';
            $params[] = (float) $minAtrPct;
        }

        if ($maxAtrPct !== null) {
            $query .= ' AND atr_pct <= ?';
            $params[] = (float) $maxAtrPct;
        }

        if ($onlyUnanalyzed) {
            $query .= ' AND exit_price IS NULL';
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
            $result = $this->analyzeAlert($alert, $atrMultiplier, $tableName, $oneMinuteTable, $fixedStopPct);
            if ($result) {
                $this->tradeResults[] = $result;

                if ($result['is_winner']) {
                    $this->winners++;
                } else {
                    $this->losers++;
                }

                $this->totalPnL += $result['pnl_percent'];
                $this->totalRiskAdjustedPnL += $result['risk_adjusted_return'];

                // Write results to database if flag is set
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

    private function analyzeAlert(object $alert, float $atrMultiplier, string $tableName = 'trade_alerts', string $oneMinuteTable = 'one_minute_prices', ?string $fixedStopPct = null): ?array
    {
        $symbol = (string) $alert->symbol;
        $entryPrice = (float) $alert->entry;
        // Use the stop from the alert (respects 1% max and ATR logic from entry finder)
        $initialStopPrice = (float) ($alert->stop ?? ($entryPrice * 0.99));
        $entryTime = (string) $alert->entry_ts_est;

        // Determine trailing stop percentage
        if ($fixedStopPct !== null) {
            // Use fixed percentage stop (e.g., 1% for BIASED1)
            $trailingStopPct = (float) $fixedStopPct;
        } else {
            // Recalculate stop using current config (not stored value) for accurate backtesting
            $atr = (float) ($alert->atr ?? 0);
            $configMultiplier = (float) TradingSettingService::getStopLossAtrMultiplier();
            $minPct = (float) TradingSettingService::getStopLossAtrMinPct();
            $maxPct = (float) TradingSettingService::getStopLossAtrMaxPct();

            // Recalculate: ATR * multiplier / entry price * 100 = stop %
            $calculatedPct = ($atr > 0 && $entryPrice > 0)
                ? (($atr * $configMultiplier) / $entryPrice) * 100.0
                : $minPct;
            $trailingStopPct = max($minPct, min($maxPct, $calculatedPct));
        }

        $atrPct = (float) $alert->atr_pct;

        $riskPct = (float) ($alert->risk_pct ?? 0);
        if ($riskPct <= 0 && $initialStopPrice > 0 && $entryPrice > 0) {
            $riskPct = abs((($entryPrice - $initialStopPrice) / $entryPrice) * 100);
        }

        $score = (float) ($alert->score ?? 0);
        $volRatio = (float) ($alert->vol_ratio ?? 0);

        // Exit at end of day 15:59
        $marketClose = date('Y-m-d', strtotime($entryTime)).' 15:59:00';

        $bars = DB::select(
            "
                        SELECT ts_est, open, high, low, price as close
                        FROM {$oneMinuteTable}
                        WHERE asset_type = ? AND symbol = ?
                            AND ts_est > ? AND ts_est <= ?
                        ORDER BY ts_est ASC
                        ",
            ['stock', $symbol, $entryTime, $marketClose]
        );

        if (empty($bars)) {
            return null;
        }

        // Start with fixed initial stop, activate trailing after 1% gain
        $highestPrice = $entryPrice;
        $lowestPrice = $entryPrice; // Track lowest price for max adverse excursion
        $currentStop = $initialStopPrice; // Use fixed stop initially
        $trailingActive = false;
        $activationThreshold = $entryPrice * 1.01; // 1% gain threshold
        $exitPrice = null;
        $exitTime = null;
        $exitReason = null;

        foreach ($bars as $bar) {
            $high = (float) $bar->high;
            $low = (float) $bar->low;
            $open = (float) $bar->open;

            // Track lowest price reached for max adverse excursion
            if ($low < $lowestPrice) {
                $lowestPrice = $low;
            }

            // Check if stopped out - account for gaps
            if ($low <= $currentStop) {
                // If opened below stop, you're filled at open (gap down slippage)
                // Otherwise, you're filled at stop price
                $exitPrice = ($open < $currentStop) ? $open : $currentStop;
                $exitTime = (string) $bar->ts_est;
                $exitReason = 'atr_stop';
                break;
            }

            // Update highest price
            if ($high > $highestPrice) {
                $highestPrice = $high;

                // Activate trailing stop once we hit 1% gain
                if (! $trailingActive && $highestPrice >= $activationThreshold) {
                    $trailingActive = true;
                    $currentStop = $highestPrice * (1 - ($trailingStopPct / 100));
                } elseif ($trailingActive) {
                    // Trail the stop as price moves up
                    $newTrailingStop = $highestPrice * (1 - ($trailingStopPct / 100));

                    if ($newTrailingStop > $currentStop) {
                        $currentStop = $newTrailingStop;
                    }
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

        // Filter out impossible P&L (bad data like reverse splits, data errors)
        // Real intraday moves rarely exceed -50% in one bar
        if ($pnlPercent < -50.0) {
            return null;  // Skip this trade - likely bad data
        }

        $isWinner = $pnlPercent > 0;

        $riskAdjustedReturn = $riskPct > 0 ? ($pnlPercent / $riskPct) : 0.0;

        // Calculate max adverse excursion (worst unrealized loss during trade)
        $maxAdverseExcursion = ($entryPrice > 0) ? (($lowestPrice - $entryPrice) / $entryPrice) * 100 : 0.0;

        // Calculate hold time in minutes
        $entryTimestamp = strtotime($entryTime);
        $exitTimestamp = strtotime($exitTime);
        $holdTimeMinutes = ($exitTimestamp - $entryTimestamp) / 60;

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
            'max_adverse_excursion' => $maxAdverseExcursion,
            'hold_time_minutes' => $holdTimeMinutes,
            'score' => $score,
            'vol_ratio' => $volRatio,
            'atr_pct' => $atrPct,
            'atr_trailing_stop_pct' => $trailingStopPct,
            'target_hit' => $targetHit,
            'is_winner' => $isWinner,
            'was_stopped_out' => $wasStoppedOut,
            'table_name' => $tableName,
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

        $this->info('🎯 IMMEDIATE ATR-BASED STOP PERFORMANCE');
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
        $this->info('🔍 DETAILED TRADE RESULTS (Immediate ATR Stops):');

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
                    : ($trade['was_stopped_out'] ? '🛑 ATR-STOP' : '❌ EOD'),
            ], $this->tradeResults)
        );
    }

    private function updateAlertWithResults(object $alert, array $result): void
    {
        $tableName = $result['table_name'] ?? 'trade_alerts';

        DB::table($tableName)
            ->where('symbol', $alert->symbol)
            ->where('entry_ts_est', $alert->entry_ts_est)
            ->where('version', $alert->version)
            ->update([
                'exit_price' => $result['exit_price'],
                'exit_ts_est' => $result['exit_time'],
                'exit_reason' => $result['exit_reason'],
                'pnl_percent' => round($result['pnl_percent'], 2),
                'pnl_dollar' => round($result['pnl_dollar'], 4),
                'max_adverse_excursion' => round($result['max_adverse_excursion'], 4),
                'hold_time_minutes' => round($result['hold_time_minutes'], 0),
                'r_multiple' => round($result['risk_adjusted_return'], 2),
                'target_hit' => $result['target_hit'],
                'suggested_trailing_stop_pct' => round($result['atr_trailing_stop_pct'], 4),  // Update to current config
                'analyzed' => true,
                'analyzed_at' => now(),
            ]);
    }
}
