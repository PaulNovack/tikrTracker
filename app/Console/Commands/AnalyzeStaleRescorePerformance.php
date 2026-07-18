<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnalyzeStaleRescorePerformance extends Command
{
    protected $signature = 'analyze:stale-rescore-performance
        {--days=30 : Lookback window in days}
        {--pipeline= : Optional pipeline filter (A-O)}';

    protected $description = 'Compare paper-trading stale-rescore cohort vs baseline by hit rate, expectancy, max drawdown, and slippage';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $pipeline = strtoupper((string) $this->option('pipeline'));
        $from = now('UTC')->subDays($days);

        $this->info('Stale Rescore Paper Performance Comparison');
        $this->line("Lookback: last {$days} days (from {$from->format('Y-m-d H:i:s')} UTC)");
        if ($pipeline !== '') {
            $this->line("Pipeline filter: {$pipeline}");
        }
        $this->newLine();

        $trades = $this->fetchClosedPaperTrades($from, $pipeline);

        if ($trades->isEmpty()) {
            $this->warn('No closed paper trades found for the selected filters.');

            return self::SUCCESS;
        }

        $staleRescoreTrades = $trades->filter(fn (object $t): bool => (bool) $t->is_stale_rescore)->values();
        $baselineTrades = $trades->filter(fn (object $t): bool => ! (bool) $t->is_stale_rescore)->values();

        $staleMetrics = $this->calculateMetrics($staleRescoreTrades);
        $baselineMetrics = $this->calculateMetrics($baselineTrades);

        $this->table(
            ['Cohort', 'Trades', 'Hit Rate', 'Expectancy', 'Max Drawdown', 'Avg Slippage'],
            [
                [
                    'Stale Rescore',
                    $staleMetrics['trade_count'],
                    $this->formatPercent($staleMetrics['hit_rate']),
                    $this->formatPercent($staleMetrics['expectancy_pct']),
                    $this->formatDrawdown($staleMetrics['max_drawdown_pct'], $staleMetrics['max_drawdown_dollars']),
                    $this->formatPercent($staleMetrics['avg_slippage_pct']),
                ],
                [
                    'Baseline (non-stale)',
                    $baselineMetrics['trade_count'],
                    $this->formatPercent($baselineMetrics['hit_rate']),
                    $this->formatPercent($baselineMetrics['expectancy_pct']),
                    $this->formatDrawdown($baselineMetrics['max_drawdown_pct'], $baselineMetrics['max_drawdown_dollars']),
                    $this->formatPercent($baselineMetrics['avg_slippage_pct']),
                ],
            ]
        );

        $this->newLine();
        $this->line('Notes:');
        $this->line('- Hit Rate = winners / total closed trades');
        $this->line('- Expectancy = average realized return % per closed trade');
        $this->line('- Max Drawdown computed on cumulative realized P&L (dollars)');
        $this->line('- Slippage = (buy fill - alert entry) / alert entry');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, object>
     */
    private function fetchClosedPaperTrades(\Carbon\CarbonInterface $from, string $pipeline): Collection
    {
        $query = DB::table('alpaca_orders as sell')
            ->join('alpaca_orders as buy', 'buy.alpaca_order_id', '=', 'sell.parent_alpaca_order_id')
            ->leftJoin('trade_alerts as ta', 'ta.id', '=', 'buy.trade_alert_id')
            ->where('buy.is_paper', true)
            ->where('sell.side', 'sell')
            ->where('sell.status', 'filled')
            ->whereNotNull('sell.filled_at')
            ->where('sell.filled_at', '>=', $from)
            ->whereNotNull('sell.filled_avg_price')
            ->whereNotNull('buy.filled_avg_price')
            ->whereNotNull('buy.filled_qty')
            ->selectRaw('
                sell.id as sell_id,
                sell.filled_at as closed_at,
                ta.pipeline_run,
                buy.notes,
                buy.filled_avg_price as buy_price,
                sell.filled_avg_price as sell_price,
                buy.filled_qty as qty,
                ta.entry as alert_entry_price,
                CASE WHEN buy.notes LIKE "%stale_rescore:1%" THEN 1 ELSE 0 END as is_stale_rescore,
                (sell.filled_avg_price - buy.filled_avg_price) * buy.filled_qty as pnl_dollars,
                CASE WHEN buy.filled_avg_price > 0
                    THEN ((sell.filled_avg_price - buy.filled_avg_price) / buy.filled_avg_price) * 100
                    ELSE NULL
                END as pnl_pct,
                CASE WHEN ta.entry IS NOT NULL AND ta.entry > 0
                    THEN ((buy.filled_avg_price - ta.entry) / ta.entry) * 100
                    ELSE NULL
                END as slippage_pct
            ')
            ->orderBy('sell.filled_at');

        if ($pipeline !== '') {
            $query->where('ta.pipeline_run', $pipeline);
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, object>  $trades
     * @return array{trade_count:int,hit_rate:?float,expectancy_pct:?float,max_drawdown_pct:?float,max_drawdown_dollars:?float,avg_slippage_pct:?float}
     */
    private function calculateMetrics(Collection $trades): array
    {
        $count = $trades->count();
        if ($count === 0) {
            return [
                'trade_count' => 0,
                'hit_rate' => null,
                'expectancy_pct' => null,
                'max_drawdown_pct' => null,
                'max_drawdown_dollars' => null,
                'avg_slippage_pct' => null,
            ];
        }

        $wins = $trades->filter(fn (object $t): bool => (float) $t->pnl_dollars > 0)->count();
        $hitRate = ($wins / $count) * 100;
        $expectancyPct = (float) $trades->avg('pnl_pct');

        $equity = 0.0;
        $peak = 0.0;
        $maxDrawdownDollars = 0.0;
        $maxDrawdownPct = 0.0;

        foreach ($trades as $trade) {
            $equity += (float) $trade->pnl_dollars;
            if ($equity > $peak) {
                $peak = $equity;
            }

            $drawdownDollars = $peak - $equity;
            if ($drawdownDollars > $maxDrawdownDollars) {
                $maxDrawdownDollars = $drawdownDollars;
            }

            if ($peak > 0) {
                $drawdownPct = ($drawdownDollars / $peak) * 100;
                if ($drawdownPct > $maxDrawdownPct) {
                    $maxDrawdownPct = $drawdownPct;
                }
            }
        }

        return [
            'trade_count' => $count,
            'hit_rate' => $hitRate,
            'expectancy_pct' => $expectancyPct,
            'max_drawdown_pct' => $peak > 0 ? $maxDrawdownPct : null,
            'max_drawdown_dollars' => $maxDrawdownDollars,
            'avg_slippage_pct' => $trades->whereNotNull('slippage_pct')->isNotEmpty()
                ? (float) $trades->whereNotNull('slippage_pct')->avg('slippage_pct')
                : null,
        ];
    }

    private function formatPercent(?float $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return number_format($value, 2).'%';
    }

    private function formatDrawdown(?float $drawdownPct, ?float $drawdownDollars): string
    {
        $dollars = $drawdownDollars !== null ? '$'.number_format($drawdownDollars, 2) : 'n/a';
        if ($drawdownPct === null) {
            return $dollars;
        }

        return number_format($drawdownPct, 2)."% ({$dollars})";
    }
}
