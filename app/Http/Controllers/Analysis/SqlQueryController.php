<?php

namespace App\Http\Controllers\Analysis;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SqlQueryController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;

    private const DEFAULT_SQL = 'SELECT * FROM trade_alerts ORDER BY id DESC';

    public function index(Request $request): Response
    {
        $sql = $this->normalizeSql($request->input('sql'));
        $page = max(1, (int) $request->integer('page', 1));

        [$rows, $columns, $error, $hasNext] = $this->executeQuery($sql, $page);

        $rowCount = count($rows);
        $offset = ($page - 1) * self::DEFAULT_PER_PAGE;

        return Inertia::render('analysis/SqlQuery', [
            'query' => [
                'sql' => $sql,
                'page' => $page,
                'per_page' => self::DEFAULT_PER_PAGE,
            ],
            'results' => [
                'columns' => $columns,
                'rows' => $rows,
                'error' => $error,
                'page' => $page,
                'per_page' => self::DEFAULT_PER_PAGE,
                'has_previous' => $page > 1,
                'has_next' => $hasNext,
                'start_row' => $rowCount === 0 ? 0 : $offset + 1,
                'end_row' => $offset + min($rowCount, self::DEFAULT_PER_PAGE),
            ],
        ]);
    }

    private function normalizeSql(mixed $value): string
    {
        $sql = is_string($value) ? trim($value) : '';

        return $sql !== '' ? $sql : self::DEFAULT_SQL;
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>, 2: string|null, 3: bool}
     */
    private function executeQuery(string $sql, int $page): array
    {
        if (! preg_match('/^\s*(select|with)\b/i', $sql)) {
            return [[], [], 'Only SELECT queries are allowed.', false];
        }

        if (str_contains($sql, ';')) {
            return [[], [], 'Only a single SQL statement is allowed.', false];
        }

        if (preg_match('/\b(insert|update|delete|drop|alter|create|truncate|replace|grant|revoke|call|load_file|into\s+outfile|into\s+dumpfile)\b/i', $sql)) {
            return [[], [], 'This page only allows read-only SELECT queries.', false];
        }

        $offset = ($page - 1) * self::DEFAULT_PER_PAGE;
        $limit = self::DEFAULT_PER_PAGE + 1;

        try {
            $wrappedSql = "select * from ({$sql}) as sql_query_result limit {$limit} offset {$offset}";
            $rawRows = DB::select($wrappedSql);
            $rows = array_map(static fn ($row): array => (array) $row, $rawRows);
            $columns = array_keys($rows[0] ?? []);
            $hasNext = count($rows) > self::DEFAULT_PER_PAGE;

            if ($hasNext) {
                $rows = array_slice($rows, 0, self::DEFAULT_PER_PAGE);
            }

            return [$rows, $columns, null, $hasNext];
        } catch (\Throwable $e) {
            return [[], [], $e->getMessage(), false];
        }
    }
}
