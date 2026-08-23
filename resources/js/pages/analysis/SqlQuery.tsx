import AppLayout from '@/layouts/app-layout';
import Heading from '@/components/heading';
import { Head, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { AlertCircle, ChevronLeft, ChevronRight, Database, Play } from 'lucide-react';
import { useState } from 'react';

interface QueryState {
    sql: string;
    page: number;
    per_page: number;
}

interface QueryResults {
    columns: string[];
    rows: Record<string, unknown>[];
    error: string | null;
    page: number;
    per_page: number;
    has_previous: boolean;
    has_next: boolean;
    start_row: number;
    end_row: number;
}

interface SqlQueryProps {
    query: QueryState;
    results: QueryResults;
}

const DEFAULT_SQL = 'SELECT * FROM trade_alerts ORDER BY id DESC';

export default function SqlQuery({ query, results }: SqlQueryProps) {
    const [sql, setSql] = useState(() => query.sql || DEFAULT_SQL);

    const runQuery = (page = 1) => {
        router.get(
            '/analysis/sql-query',
            {
                sql,
                page,
            },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    };

    const goToPage = (page: number) => {
        if (page < 1) {
            return;
        }

        runQuery(page);
    };

    const formatCellValue = (value: unknown): string => {
        if (value === null || value === undefined) {
            return '—';
        }

        if (typeof value === 'object') {
            return JSON.stringify(value);
        }

        return String(value);
    };

    return (
        <AppLayout>
            <Head title="SQL Query" />

            <div className="flex flex-col gap-6 p-6">
                <Heading
                    title="SQL Query"
                    description="Run a read-only SELECT statement and inspect the first 50 rows at a time. Use ORDER BY for stable pagination."
                />

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Database className="h-4 w-4" />
                            Query Editor
                        </CardTitle>
                        <CardDescription>
                            Read-only SELECT queries only. The page shows 50 rows per page with pagination controls at the bottom.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="sql-query">SQL</Label>
                            <Textarea
                                id="sql-query"
                                value={sql}
                                onChange={(event) => setSql(event.target.value)}
                                className="min-h-40 font-mono text-sm"
                                spellCheck={false}
                                placeholder="SELECT * FROM trade_alerts ORDER BY id DESC"
                            />
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <Button onClick={() => runQuery(1)} className="gap-2">
                                <Play className="h-4 w-4" />
                                Run Query
                            </Button>
                            <div className="text-sm text-muted-foreground">
                                Page size: <span className="font-medium text-foreground">{query.per_page.toLocaleString()}</span>
                            </div>
                        </div>

                        {results.error && (
                            <div className="flex items-start gap-3 rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive">
                                <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
                                <div>{results.error}</div>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Results</CardTitle>
                        <CardDescription>
                            {results.rows.length > 0
                                ? `Showing rows ${results.start_row.toLocaleString()} to ${results.end_row.toLocaleString()} on page ${results.page.toLocaleString()}.`
                                : 'No rows returned for the current query.'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {results.rows.length > 0 && results.columns.length > 0 ? (
                            <div className="overflow-hidden rounded-lg border">
                                <div className="max-h-[60vh] overflow-auto">
                                    <table className="w-max min-w-full table-auto border-separate border-spacing-0 text-xs">
                                        <thead className="sticky top-0 z-10 bg-background">
                                            <tr>
                                                {results.columns.map((column) => (
                                                    <th
                                                        key={column}
                                                        className="max-w-44 border-b px-2 py-2 text-left align-middle font-mono text-[10px] uppercase tracking-wide text-muted-foreground"
                                                        title={column}
                                                    >
                                                        <div className="max-w-44 truncate">{column}</div>
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {results.rows.map((row, rowIndex) => (
                                                <tr key={`${results.page}-${rowIndex}`} className="border-b transition-colors hover:bg-muted/50">
                                                    {results.columns.map((column) => {
                                                        const value = formatCellValue(row[column]);

                                                        return (
                                                            <td
                                                                key={`${results.page}-${rowIndex}-${column}`}
                                                                className="max-w-44 border-b px-2 py-1 align-top font-mono text-[11px] leading-4"
                                                                title={value}
                                                            >
                                                                <div className="max-w-44 truncate whitespace-nowrap">{value}</div>
                                                            </td>
                                                        );
                                                    })}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        ) : (
                            <div className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                                {results.error ? 'Fix the SQL error above and run the query again.' : 'Run a SELECT query to populate the grid.'}
                            </div>
                        )}

                        <div className="flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                            <div className="text-sm text-muted-foreground">
                                Page <span className="font-medium text-foreground">{results.page.toLocaleString()}</span>
                            </div>
                            <div className="flex items-center gap-2">
                                <Button variant="outline" onClick={() => goToPage(results.page - 1)} disabled={!results.has_previous} className="gap-2">
                                    <ChevronLeft className="h-4 w-4" />
                                    Previous
                                </Button>
                                <Button variant="outline" onClick={() => goToPage(results.page + 1)} disabled={!results.has_next} className="gap-2">
                                    Next
                                    <ChevronRight className="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
