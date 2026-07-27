<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v1500.0 Opening Range Breakout entry finder.
 *
 * Uses UsesRedisForEntryFinding to fetch 1-minute bars from Redis instead
 * of MySQL. The parent's ORB entry logic (doFindBestLong) runs unchanged
 * — only the bar data source differs.
 */
class OneMinuteEntryFinderV1500_0Redis extends OneMinuteEntryFinderV1500_0
{
    use UsesRedisForEntryFinding;
}
