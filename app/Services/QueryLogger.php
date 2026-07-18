<?php

namespace App\Services;

use App\Models\SqlLog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;

class QueryLogger
{
    private bool $enabled = false;

    private bool $isLogging = false;

    public function __construct()
    {
        // Enable query logging based on environment or config
        // You can set this to true when you want to start logging
        $this->enabled = config('query_logging.log_queries', false);
    }

    public function handle(QueryExecuted $event): void
    {
        // Check if logging is enabled on each query
        if (! config('query_logging.log_queries', false)) {
            return;
        }

        // Skip logging traffic_logs inserts to avoid double logging
        if (strpos(strtolower($event->sql), 'insert into traffic_logs') === 0) {
            return;
        }

        // Prevent recursive logging - don't log queries from the logging process itself
        if ($this->isLogging) {
            return;
        }

        try {
            $this->isLogging = true;

            // Interpolate bindings into the query to get the real executable query
            $query = $this->interpolateQuery($event->sql, $event->bindings);

            // Detect if this is a cache table query
            $isCacheQuery = $this->isCacheTableQuery($event->sql);

            // Only log the query and basic metadata without heavy binding serialization
            $logData = [
                'query' => $query, // Store full query (longText supports up to 4GB)
                'bindings' => null, // Skip bindings to avoid memory issues
                'execution_time_ms' => $event->time, // in milliseconds
                'connection' => $event->connectionName,
                'request_path' => Request::path(),
                'http_method' => Request::method(),
                'user_id' => Auth::id(),
                'stack_trace' => null, // Skip stack trace by default
            ];

            // Add cached_data only if the column exists in the database
            // (during migrations, the column might not exist yet)
            if (Schema::hasColumn('sql_logs', 'cached_data')) {
                $logData['cached_data'] = $isCacheQuery;
            }

            SqlLog::create($logData);
        } catch (\Exception $e) {
            // Silently fail to prevent logging from breaking the application
            \Log::error('Failed to log SQL query', ['error' => $e->getMessage()]);
        } finally {
            $this->isLogging = false;
        }
    }

    /**
     * Detect if a query is targeting the cache table.
     */
    private function isCacheTableQuery(string $query): bool
    {
        // Convert to lowercase and remove backticks for easier matching
        $queryLower = strtolower(str_replace(['`', "'", '"'], '', $query));

        // Check if query targets the cache or cache_locks table
        // Match patterns like: from cache, into cache, update cache, delete from cache, etc.
        $cachePatterns = [
            'from cache ',
            'into cache ',
            'update cache ',
            'delete from cache',
            'join cache ',
            'from cache_locks',
            'into cache_locks',
            'update cache_locks',
            'delete from cache_locks',
            'join cache_locks',
        ];

        foreach ($cachePatterns as $pattern) {
            if (strpos($queryLower, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Interpolate bindings into the SQL query to get the executable query.
     */
    private function interpolateQuery(string $query, array $bindings): string
    {
        // Replace placeholders with actual values
        $bindings = array_map(function ($binding) {
            // Handle DateTime objects
            if ($binding instanceof \DateTime || $binding instanceof \DateTimeImmutable) {
                return "'".$binding->format('Y-m-d H:i:s')."'";
            }

            if (is_string($binding)) {
                // Check if it looks like a datetime (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)
                if (preg_match('/^\d{4}-\d{2}-\d{2}(\s\d{2}:\d{2}:\d{2})?$/', $binding)) {
                    // It's a datetime string, quote it
                    return "'".$binding."'";
                }

                // Escape single quotes and wrap in quotes for regular strings
                return "'".str_replace("'", "''", $binding)."'";
            }

            if (is_bool($binding)) {
                return $binding ? 1 : 0;
            }

            if ($binding === null) {
                return 'NULL';
            }

            // For numbers, just return as-is
            return (string) $binding;
        }, $bindings);

        // Replace ? placeholders with actual values
        $result = '';
        $paramIndex = 0;

        for ($i = 0; $i < strlen($query); $i++) {
            if ($query[$i] === '?' && isset($bindings[$paramIndex])) {
                $result .= $bindings[$paramIndex];
                $paramIndex++;
            } else {
                $result .= $query[$i];
            }
        }

        // Remove backticks to clean up the query for display
        // Backticks are MySQL specific, removing them makes queries more portable
        $result = str_replace('`', '', $result);

        return $result;
    }

    private function getStackTrace(): ?string
    {
        if (! config('database.log_stack_trace', false)) {
            return null;
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $filtered = [];

        foreach ($trace as $item) {
            // Skip internal framework classes
            if (isset($item['file']) && ! str_contains($item['file'], 'vendor')) {
                $filtered[] = $item['file'].':'.$item['line'];
            }
        }

        return json_encode(array_slice($filtered, 0, 5));
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
