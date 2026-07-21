<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Helper for formatting America/New_York timezone timestamps.
 *
 * Background: The database stores timestamps with "_est" suffix as America/New_York time,
 * which automatically handles DST (EST in winter, EDT in summer).
 */
class EstTimezoneHelper
{
    /**
     * Parse a timestamp stored in America/New_York timezone.
     *
     * This handles DST automatically:
     * - During winter (EST): Shows EST
     * - During summer (EDT): Shows EDT
     *
     * @param  string  $timestamp  Timestamp stored as America/New_York (e.g., "2026-03-09 13:44:00")
     * @param  string  $format  Optional format string (e.g., 'M j g:i A T')
     * @return \Carbon\Carbon|string Carbon instance or formatted string if format provided
     */
    public static function parseEstTimestamp(string $timestamp, ?string $format = null): Carbon|string
    {
        // Parse directly as America/New_York timezone (already DST-aware from database)
        $carbon = Carbon::createFromFormat('Y-m-d H:i:s', $timestamp, 'America/New_York');

        return $format ? $carbon->format($format) : $carbon;
    }

    /**
     * Format a timestamp for display in the current Eastern timezone.
     *
     * @param  string  $timestamp  Timestamp stored as America/New_York
     * @param  string  $format  Format string (default: 'M j g:i A T')
     * @return string Formatted timestamp
     */
    public static function formatEstTimestamp(string $timestamp, string $format = 'M j g:i A T'): string
    {
        return self::parseEstTimestamp($timestamp, $format);
    }

    /**
     * Check if DST is currently active in America/New_York timezone.
     *
     * @return bool True if EDT (daylight saving), false if EST (standard time)
     */
    public static function isDstActive(): bool
    {
        return Carbon::now('America/New_York')->format('T') === 'EDT';
    }
}
