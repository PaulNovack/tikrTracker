<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiagnoseMissingMlScores extends Command
{
    protected $signature = 'ml:diagnose-missing
        {--start= : Restrict to trading_date_est >= this date (YYYY-MM-DD)}
        {--end= : Restrict to trading_date_est <= this date (YYYY-MM-DD)}
        {--pipeline= : Comma-separated pipelines (example: H,I,D)}
        {--limit-groups=30 : Max pipeline/date groups to inspect}';

    protected $description = 'Diagnose why ml_win_prob is NULL by pipeline/date (missing 1m or 5m context).';

    public function handle(): int
    {
        $start = $this->option('start');
        $end = $this->option('end');
        $limitGroups = max(1, (int) $this->option('limit-groups'));
        $pipelines = $this->parsePipelines((string) $this->option('pipeline'));
        $benchmark = (string) config('trading.market_benchmark_symbol', 'QQQ');

        $groupQuery = DB::table('trade_alerts')
            ->select('pipeline_run', 'trading_date_est')
            ->whereNull('ml_win_prob')
            ->whereNotNull('pipeline_run')
            ->whereNotNull('trading_date_est')
            ->orderBy('trading_date_est')
            ->orderBy('pipeline_run');

        if ($start) {
            $groupQuery->where('trading_date_est', '>=', $start);
        }

        if ($end) {
            $groupQuery->where('trading_date_est', '<=', $end);
        }

        if ($pipelines !== []) {
            $groupQuery->whereIn('pipeline_run', $pipelines);
        }

        $groups = $groupQuery->distinct()->limit($limitGroups)->get();

        if ($groups->isEmpty()) {
            $this->info('No rows with NULL ml_win_prob for selected filters.');

            return self::SUCCESS;
        }

        $this->info('Inspecting '.$groups->count().' pipeline/date group(s) with NULL ml_win_prob.');
        $this->line('Benchmark symbol used for market joins: '.$benchmark);
        $this->newLine();

        $totals = [
            'rows' => 0,
            'missing_1m_entry' => 0,
            'missing_5m_signal' => 0,
            'missing_stock_open_5m' => 0,
            'missing_market_signal_5m' => 0,
            'missing_market_open_5m' => 0,
        ];

        foreach ($groups as $group) {
            $pipeline = (string) $group->pipeline_run;
            $date = (string) $group->trading_date_est;

            $row = DB::selectOne(
                <<<'SQL'
SELECT
    COUNT(*) AS rows_total,
    SUM(CASE WHEN omp.ts_est IS NULL THEN 1 ELSE 0 END) AS missing_1m_entry,
    SUM(CASE WHEN fmp.ts_est IS NULL THEN 1 ELSE 0 END) AS missing_5m_signal,
    SUM(CASE WHEN stk_open.ts_est IS NULL THEN 1 ELSE 0 END) AS missing_stock_open_5m,
    SUM(CASE WHEN mkt_fmp.ts_est IS NULL THEN 1 ELSE 0 END) AS missing_market_signal_5m,
    SUM(CASE WHEN mkt_open.ts_est IS NULL THEN 1 ELSE 0 END) AS missing_market_open_5m
FROM trade_alerts ta
LEFT JOIN one_minute_prices_full omp
  ON omp.symbol = ta.symbol
 AND omp.asset_type = ta.asset_type
 AND omp.ts_est = ta.entry_ts_est
LEFT JOIN five_minute_prices_full fmp
  ON fmp.symbol = ta.symbol
 AND fmp.asset_type = ta.asset_type
 AND fmp.ts_est = (
      SELECT MAX(f2.ts_est)
      FROM five_minute_prices_full f2
      WHERE f2.symbol = ta.symbol
        AND f2.asset_type = ta.asset_type
        AND f2.ts_est <= ta.signal_ts_est
 )
LEFT JOIN five_minute_prices_full stk_open
  ON stk_open.symbol = ta.symbol
 AND stk_open.asset_type = ta.asset_type
 AND stk_open.ts_est = (
      SELECT MIN(so.ts_est)
      FROM five_minute_prices_full so
      WHERE so.symbol = ta.symbol
        AND so.asset_type = ta.asset_type
        AND DATE(so.ts_est) = ta.trading_date_est
 )
LEFT JOIN five_minute_prices_full mkt_fmp
    ON mkt_fmp.symbol = ?
 AND mkt_fmp.asset_type = 'stock'
 AND mkt_fmp.ts_est = (
      SELECT MAX(m1.ts_est)
      FROM five_minute_prices_full m1
            WHERE m1.symbol = ?
        AND m1.asset_type = 'stock'
        AND m1.ts_est <= ta.signal_ts_est
 )
LEFT JOIN five_minute_prices_full mkt_open
    ON mkt_open.symbol = ?
 AND mkt_open.asset_type = 'stock'
 AND mkt_open.ts_est = (
      SELECT MIN(m2.ts_est)
      FROM five_minute_prices_full m2
            WHERE m2.symbol = ?
        AND m2.asset_type = 'stock'
        AND DATE(m2.ts_est) = ta.trading_date_est
 )
WHERE ta.ml_win_prob IS NULL
    AND ta.pipeline_run = ?
    AND ta.trading_date_est = ?
SQL,
                [
                    $benchmark,
                    $benchmark,
                    $benchmark,
                    $benchmark,
                    $pipeline,
                    $date,
                ]
            );

            $rows = (int) ($row->rows_total ?? 0);
            if ($rows === 0) {
                continue;
            }

            $missing1m = (int) ($row->missing_1m_entry ?? 0);
            $missing5m = (int) ($row->missing_5m_signal ?? 0);
            $missingStockOpen = (int) ($row->missing_stock_open_5m ?? 0);
            $missingMarketSignal = (int) ($row->missing_market_signal_5m ?? 0);
            $missingMarketOpen = (int) ($row->missing_market_open_5m ?? 0);

            $totals['rows'] += $rows;
            $totals['missing_1m_entry'] += $missing1m;
            $totals['missing_5m_signal'] += $missing5m;
            $totals['missing_stock_open_5m'] += $missingStockOpen;
            $totals['missing_market_signal_5m'] += $missingMarketSignal;
            $totals['missing_market_open_5m'] += $missingMarketOpen;

            $this->line(sprintf(
                '%s %s rows=%d missing_1m=%d missing_5m_signal=%d missing_stock_open_5m=%d missing_market_signal_5m=%d missing_market_open_5m=%d',
                $pipeline,
                $date,
                $rows,
                $missing1m,
                $missing5m,
                $missingStockOpen,
                $missingMarketSignal,
                $missingMarketOpen
            ));
        }

        $this->newLine();
        $this->info('Aggregate across inspected groups:');
        $this->line('rows_total='.$totals['rows']);
        $this->line('missing_1m_entry='.$totals['missing_1m_entry']);
        $this->line('missing_5m_signal='.$totals['missing_5m_signal']);
        $this->line('missing_stock_open_5m='.$totals['missing_stock_open_5m']);
        $this->line('missing_market_signal_5m='.$totals['missing_market_signal_5m']);
        $this->line('missing_market_open_5m='.$totals['missing_market_open_5m']);

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function parsePipelines(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $parts = array_map(
            static fn (string $part): string => strtoupper(trim($part)),
            explode(',', $value)
        );

        return array_values(array_filter(array_unique($parts), static fn (string $part): bool => $part !== ''));
    }
}
