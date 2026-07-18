<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add indexes to speed up the ML training query in train_stock_winner_model_v2.py.
     *
     * 1. trade_alerts: composite (pipeline_run, entry_ts_est) for the ta_base CTE filter:
     *    WHERE pipeline_run IN ('H','I','D') AND entry_ts_est BETWEEN ... AND pnl_percent IS NOT NULL
     *
     * 2. five_minute_prices_full: composite (symbol, asset_type, trading_date_est, ts_est)
     *    for the correlated MIN subquery used by stk_open and mkt_open joins:
     *    SELECT MIN(ts_est) WHERE symbol=? AND asset_type=? AND DATE(ts_est)=?
     */
    public function up(): void
    {
        $this->createIndexSafely('trade_alerts', 'idx_ta_ml_training', ['pipeline_run', 'entry_ts_est', 'pnl_percent']);

        if (Schema::hasTable('five_minute_prices_full')) {
            $this->createIndexSafely('five_minute_prices_full', 'idx_fmp_full_symbol_date_ts', ['symbol', 'asset_type', 'trading_date_est', 'ts_est']);
        }
    }

    public function down(): void
    {
        $this->dropIndexSafely('trade_alerts', 'idx_ta_ml_training');

        if (Schema::hasTable('five_minute_prices_full')) {
            $this->dropIndexSafely('five_minute_prices_full', 'idx_fmp_full_symbol_date_ts');
        }
    }

    /**
     * @param  string[]  $columns
     */
    private function createIndexSafely(string $table, string $indexName, array $columns): void
    {
        if (! $this->indexExists($table, $indexName)) {
            Schema::table($table, function ($blueprint) use ($columns, $indexName) {
                $blueprint->index($columns, $indexName);
            });
        }
    }

    private function dropIndexSafely(string $table, string $indexName): void
    {
        if ($this->indexExists($table, $indexName)) {
            Schema::table($table, function ($blueprint) use ($indexName) {
                $blueprint->dropIndex($indexName);
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = Schema::getIndexes($table);

        foreach ($indexes as $index) {
            if ($index['name'] === $indexName) {
                return true;
            }
        }

        return false;
    }
};
