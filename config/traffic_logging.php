<?php

return [
    /**
     * Enable or disable traffic logging.
     * When disabled, the TrafficLog middleware will not insert records into the database.
     *
     * Defaults to true if not specified.
     */
    'enabled' => env('TRAFFIC_LOGGING_ENABLED', true),
];
