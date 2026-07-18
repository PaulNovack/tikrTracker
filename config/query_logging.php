<?php

return [
    /*
     * Enable or disable SQL query logging.
     * Set to true to start logging all queries to the sql_logs table.
     * Set to false to disable logging (minimal performance impact).
     */
    'log_queries' => env('LOG_QUERIES', false),

    /*
     * Log the stack trace for each query.
     * This can help identify where queries are coming from in your application.
     * Only used if log_queries is true.
     */
    'log_stack_trace' => env('LOG_QUERY_STACK_TRACE', false),

    /*
     * Maximum number of queries to keep in the log.
     * Older entries are automatically deleted.
     * Set to 0 to disable automatic cleanup.
     */
    'max_log_entries' => env('MAX_SQL_LOG_ENTRIES', 100000),
];
