<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

trait HasPriceTables
{
    protected string $fiveMinuteTable = 'five_minute_prices';

    protected string $oneMinuteTable = 'one_minute_prices';

    public function setFullTable(bool $full): void
    {
        $this->fiveMinuteTable = $full ? 'five_minute_prices_full' : 'five_minute_prices';
        $this->oneMinuteTable = $full ? 'one_minute_prices_full' : 'one_minute_prices';
    }

    /**
     * Resolve table name placeholders in a SQL string.
     * Uses word-boundary regex to avoid double-replacing when the target
     * table name already contains the base name (e.g. one_minute_prices_full).
     */
    protected function resolveSql(string $sql): string
    {
        if ($this->fiveMinuteTable !== 'five_minute_prices') {
            $sql = preg_replace('/\bfive_minute_prices\b/', $this->fiveMinuteTable, $sql);
        }
        if ($this->oneMinuteTable !== 'one_minute_prices') {
            $sql = preg_replace('/\bone_minute_prices\b/', $this->oneMinuteTable, $sql);
        }

        return $sql;
    }

    /**
     * Execute a raw SQL query, resolving table names first.
     *
     * @param  array<mixed>  $bindings
     * @return array<mixed>
     */
    protected function dbSelect(string $sql, array $bindings = []): array
    {
        // Use disk-based temp tables for large CTE queries to avoid
        // "Table './tmp/#sql...' doesn't exist" errors from tmp_table_size overflow.
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $pdo->exec('SET SESSION big_tables = 1');

        return $connection->select($this->resolveSql($sql), $bindings);
    }
}
